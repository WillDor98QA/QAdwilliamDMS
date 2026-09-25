<?php
/**
 * Registrations: area lists, bulk-action confirmation and the review screen
 * (ARCH §5, §7, §8, §10, §24, §73, §74; MP §26).
 *
 * Every view re-checks access through the services: the area must be visible
 * to the user (RegistrationListService), and a record must pass AccessPolicy
 * (RegistrationWorkflow::view). Actions are POSTed to AdminActions with a nonce.
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminActions;
use DMS\Admin\AdminMenu;
use DMS\Admin\RegistrationsTable;
use DMS\Admin\View;
use DMS\Audit\AuditService;
use DMS\Bulk\BulkActionService;
use DMS\Electoral\ElectoralLevel;
use DMS\Errors\DmsException;
use DMS\Plugin;
use DMS\Workflow\StateMachine;
use DMS\Workflow\Status;
use DMS\Workflow\Transition;

defined( 'ABSPATH' ) || exit;

class RegistrationsPage {

	/** @var array<int,string>|null */
	private ?array $officers = null;

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		$area = $this->current_area();
		if ( null === $area || ! in_array( $area, $this->plugin->lists()->visible_areas( get_current_user_id() ), true ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET views; every change goes through AdminActions with a nonce.
		$registration = absint( $_GET['registration'] ?? 0 );
		$bulk_action  = sanitize_key( (string) wp_unslash( $_GET['action'] ?? '' ) );
		if ( '-1' === $bulk_action || '' === $bulk_action ) {
			$bulk_action = sanitize_key( (string) wp_unslash( $_GET['action2'] ?? '' ) );
		}
		$ids = array_map( 'absint', (array) wp_unslash( $_GET['ids'] ?? array() ) );
		// phpcs:enable

		if ( $registration > 0 ) {
			$this->render_record( $registration, $area );
		} elseif ( '' !== $bulk_action && '-1' !== $bulk_action && array() !== $ids ) {
			$this->render_bulk_confirmation( $area, $bulk_action, $ids );
		} else {
			$this->render_list( $area );
		}
	}

	/** Active, eligible officers (for filters and assignment). */
	public function officer_options(): array {
		if ( null === $this->officers ) {
			global $wpdb;
			$links = \DMS\Database\Tables::name( \DMS\Database\Tables::OFFICER_REGIONS );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
			$ids            = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$links} WHERE status = %s", 'ACTIVE' ) ) );
			$this->officers = array();
			foreach ( $ids as $id ) {
				if ( $this->plugin->capabilities()->user_has_permission( $id, 'assignment.receive' ) ) {
					$user = get_user_by( 'id', $id );
					if ( $user ) {
						$this->officers[ $id ] = $user->display_name;
					}
				}
			}
			asort( $this->officers );
		}
		return $this->officers;
	}

	private function current_area(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_key( (string) wp_unslash( $_GET['page'] ?? '' ) );
		$area = array_search( $page, AdminMenu::AREA_PAGES, true );
		return false === $area ? null : $area;
	}

	/** @return array<string,mixed> */
	private function query(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filters are whitelisted/cast by RegistrationFilter::from_array().
		$get   = wp_unslash( $_GET );
		$query = array_intersect_key( (array) $get, array_flip( array( 'search', 's', 'status', 'region_id', 'constituency_id', 'polling_station_id', 'officer_id', 'unassigned', 'submitted_from', 'submitted_to', 'paged', 'orderby', 'order' ) ) );
		if ( isset( $query['s'] ) ) {
			$query['search'] = $query['s'];
		}
		if ( isset( $query['paged'] ) ) {
			$query['page'] = $query['paged'];
		}
		if ( isset( $query['status'] ) && '' === $query['status'] ) {
			unset( $query['status'] );
		}
		return array_map( static fn( $v ) => is_array( $v ) ? array_map( 'sanitize_text_field', $v ) : sanitize_text_field( (string) $v ), $query );
	}

	private function render_list( string $area ): void {
		$query = $this->query();
		$table = new RegistrationsTable( $this->plugin, $area, $query );
		try {
			$table->prepare_items();
		} catch ( DmsException $e ) {
			wp_die( esc_html( $e->getMessage() ), 403 );
		}
		$titles = array(
			'holding'      => __( 'Holding Area', 'dms' ),
			'assigned'     => __( 'Assigned', 'dms' ),
			'under_review' => __( 'Under Review', 'dms' ),
			'approved'     => __( 'Approved', 'dms' ),
			'bin'          => __( 'Bin', 'dms' ),
		);
		ob_start();
		$this->export_buttons( $area, $query );
		$exports = (string) ob_get_clean();
		?>
		<div class="wrap dms-wrap dms-area dms-area--<?php echo esc_attr( $area ); ?>">
			<?php $this->area_header( $area, $titles[ $area ], $exports ); ?>
			<?php if ( 'holding' === $area ) : ?>
				<?php $this->status_tabs( $query ); ?>
				<div class="dms-ops-grid">
					<?php $this->list_card( $area, $table ); ?>
					<?php $this->holding_side(); ?>
				</div>
			<?php else : ?>
				<?php $this->list_card( $area, $table ); ?>
			<?php endif; ?>
			<?php AdminActions::hidden_form( 'bulk', array( 'area' => $area ) ); ?>
		</div>
		<?php
	}

	/** Area-specific header (reference: dark queue hero, green archive hero, plain page head). */
	private function area_header( string $area, string $title, string $exports ): void {
		$user = get_current_user_id();
		if ( 'holding' === $area ) {
			$count = fn( array $q ): int => (int) $this->plugin->lists()->search( $user, 'holding', array_merge( array( 'per_page' => 1 ), $q ) )['total'];
			$stats = array(
				array( $count( array() ), __( 'Active records', 'dms' ) ),
				array( $count( array( 'unassigned' => 1 ) ), __( 'Unassigned', 'dms' ) ),
				array( $count( array( 'status' => 'UNDER_REVIEW' ) ), __( 'Under review', 'dms' ) ),
			);
			?>
			<div class="dms-hero dms-hero--dark">
				<div>
					<p class="dms-eyebrow"><?php esc_html_e( 'Operations', 'dms' ); ?></p>
					<h1><?php echo esc_html( $title ); ?></h1>
					<p><?php esc_html_e( 'Work queue for registrations awaiting assignment or review.', 'dms' ); ?></p>
					<?php if ( '' !== $exports ) : ?>
						<div class="dms-hero__actions"><?php echo $exports; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></div>
					<?php endif; ?>
				</div>
				<dl class="dms-hero__stats">
					<?php foreach ( $stats as [ $value, $label ] ) : ?>
						<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( number_format_i18n( $value ) ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
			</div>
			<hr class="wp-header-end">
			<?php
			return;
		}
		if ( 'approved' === $area ) {
			?>
			<div class="dms-hero dms-hero--approved">
				<div>
					<p class="dms-eyebrow"><?php esc_html_e( 'Read-only archive', 'dms' ); ?></p>
					<h1><?php esc_html_e( 'Approved Registrations', 'dms' ); ?></h1>
					<p><?php esc_html_e( 'Completed records are locked after approval.', 'dms' ); ?></p>
					<?php if ( '' !== $exports ) : ?>
						<div class="dms-hero__actions"><?php echo $exports; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php endif; ?>
				</div>
				<span class="dms-pill dms-pill--green"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Locked records', 'dms' ); ?></span>
			</div>
			<div class="dms-protection">
				<span class="dms-protection__icon dashicons dashicons-shield" aria-hidden="true"></span>
				<div>
					<strong><?php esc_html_e( 'Approval protection is active', 'dms' ); ?></strong>
					<span><?php esc_html_e( 'Approved records cannot be edited, reassigned, disapproved or deleted.', 'dms' ); ?></span>
				</div>
			</div>
			<hr class="wp-header-end">
			<?php
			return;
		}
		$heads = array(
			'assigned'     => array( __( 'Workflow', 'dms' ), __( 'Registrations assigned to an officer and waiting for review to start.', 'dms' ) ),
			'under_review' => array( __( 'Workflow', 'dms' ), __( 'Registrations an officer is reviewing now.', 'dms' ) ),
			/* translators: %d: number of days */
			'bin'          => array( __( 'Retention', 'dms' ), sprintf( __( 'Disapproved registrations stay in the Bin for %d days from the disapproval date and are then permanently deleted automatically.', 'dms' ), $this->retention_days() ) ),
		);
		View::page_head( $heads[ $area ][0], $title, $heads[ $area ][1], $exports );
	}

	public function retention_days(): int {
		return max( 1, $this->plugin->settings()->int( 'bin_retention_days' ) );
	}

	/** Status tabs for the Holding Area: links on the existing status filter, with real counts. */
	private function status_tabs( array $query ): void {
		$user    = get_current_user_id();
		$current = (string) ( $query['status'] ?? '' );
		$tabs    = array(
			''             => __( 'All', 'dms' ),
			'PENDING'      => __( 'Pending', 'dms' ),
			'ASSIGNED'     => __( 'Assigned', 'dms' ),
			'UNDER_REVIEW' => __( 'Under Review', 'dms' ),
		);
		echo '<nav class="dms-tabs" aria-label="' . esc_attr__( 'Filter by status', 'dms' ) . '"><ul>';
		foreach ( $tabs as $status => $label ) {
			$total = (int) $this->plugin->lists()->search(
				$user,
				'holding',
				'' === $status ? array( 'per_page' => 1 ) : array(
					'per_page' => 1,
					'status'   => $status,
				)
			)['total'];
			printf(
				'<li><a href="%1$s"%2$s>%3$s <span class="dms-tabs__count">%4$s</span></a></li>',
				esc_url( AdminMenu::area_url( 'holding', '' === $status ? array() : array( 'status' => $status ) ) ),
				$current === $status ? ' class="is-active" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $total ) )
			);
		}
		echo '</ul></nav>';
	}

	private function list_card( string $area, RegistrationsTable $table ): void {
		?>
		<div class="dms-card dms-card--flush dms-list-card">
			<form method="get" data-dms-list-form data-dms-area="<?php echo esc_attr( $area ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::AREA_PAGES[ $area ] ); ?>">
				<?php
				$table->search_box( __( 'Search registrations', 'dms' ), 'dms-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/** Holding Area side panel: open assignment exceptions, officer workload, how the queue moves. */
	private function holding_side(): void {
		?>
		<aside class="dms-side-stack" aria-label="<?php esc_attr_e( 'Queue information', 'dms' ); ?>">
			<?php if ( current_user_can( 'assignment.view' ) ) : ?>
				<?php $open = count( $this->plugin->assignment_exceptions()->open_list( 200 ) ); ?>
				<section class="dms-card dms-side-card">
					<h2><?php esc_html_e( 'Assignment exceptions', 'dms' ); ?></h2>
					<?php if ( $open > 0 ) : ?>
						<div class="dms-exception">
							<strong>
							<?php
							/* translators: %d: number of registrations */
							echo esc_html( sprintf( _n( '%d registration unassigned', '%d registrations unassigned', $open, 'dms' ), $open ) );
							?>
							</strong>
							<small><?php esc_html_e( 'No active officer is configured for the region.', 'dms' ); ?></small>
						</div>
					<?php else : ?>
						<p class="dms-empty-inline"><?php esc_html_e( 'Every registration has an officer.', 'dms' ); ?></p>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dms-assignments' ) ); ?>"><?php esc_html_e( 'Review assignments', 'dms' ); ?> <span aria-hidden="true">&rarr;</span></a>
				</section>
				<section class="dms-card dms-side-card">
					<h2><?php esc_html_e( 'Officer workload', 'dms' ); ?></h2>
					<?php View::workload_list( View::officer_workload( $this->plugin ) ); ?>
				</section>
			<?php endif; ?>
			<section class="dms-card dms-side-card">
				<h2><?php esc_html_e( 'Queue guidance', 'dms' ); ?></h2>
				<p class="dms-muted"><?php esc_html_e( 'New registrations are assigned automatically to an active officer for their region. A record becomes Under Review when the assigned officer starts the review. Viewing a record never changes its status.', 'dms' ); ?></p>
			</section>
		</aside>
		<?php
	}

	private function export_buttons( string $area, array $query ): void {
		if ( ! current_user_can( \DMS\Export\ExportService::permission_for( $area ) ) ) {
			return;
		}
		foreach ( array(
			'csv'  => __( 'Export CSV', 'dms' ),
			'xlsx' => __( 'Export Excel', 'dms' ),
		) as $format => $label ) {
			printf(
				'<form method="post" action="%1$s" class="dms-inline-form">%2$s<input type="hidden" name="area" value="%3$s"><input type="hidden" name="format" value="%4$s"><input type="hidden" name="filters" value="%5$s"><button type="submit" class="page-title-action">%6$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				AdminActions::fields( 'export' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nonce/action fields built with esc_attr.
				esc_attr( $area ),
				esc_attr( $format ),
				esc_attr( (string) wp_json_encode( $query ) ),
				esc_html( $label )
			);
		}
	}

	/** Server-side confirmation (works without JavaScript; the JS modal shows the same). */
	private function render_bulk_confirmation( string $area, string $action, array $ids ): void {
		$bulk_actions = array_merge( array_keys( BulkActionService::ACTIONS ), array( 'export_csv', 'export_xlsx' ) );
		if ( ! in_array( $action, $bulk_actions, true ) ) {
			$this->render_list( $area );
			return;
		}
		$destructive = in_array( $action, BulkActionService::DESTRUCTIVE, true );
		$is_export   = str_starts_with( $action, 'export_' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$officer = absint( $_GET['officer_id'] ?? 0 );
		?>
		<div class="wrap dms-wrap">
			<h1><?php esc_html_e( 'Please confirm', 'dms' ); ?></h1>
			<div class="dms-confirm-panel<?php echo $destructive ? ' dms-confirm-panel--danger' : ''; ?>" role="alertdialog" aria-labelledby="dms-confirm-text">
				<p id="dms-confirm-text"><strong><?php echo $destructive ? esc_html__( 'This action permanently deletes the selected records and cannot be undone.', 'dms' ) : esc_html__( 'Are you sure you want to perform this action?', 'dms' ); ?></strong></p>
				<p>
				<?php
				/* translators: %d: number of selected records */
				echo esc_html( sprintf( _n( '%d registration selected.', '%d registrations selected.', count( $ids ), 'dms' ), count( $ids ) ) );
				?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php
					echo AdminActions::fields( $is_export ? 'export' : 'bulk' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					foreach ( $ids as $id ) {
						printf( '<input type="hidden" name="ids[]" value="%d">', (int) $id );
					}
					?>
					<input type="hidden" name="area" value="<?php echo esc_attr( $area ); ?>">
					<input type="hidden" name="bulk_action" value="<?php echo esc_attr( $action ); ?>">
					<input type="hidden" name="format" value="<?php echo esc_attr( 'export_xlsx' === $action ? 'xlsx' : 'csv' ); ?>">
					<input type="hidden" name="officer_id" value="<?php echo esc_attr( (string) $officer ); ?>">
					<input type="hidden" name="confirmed" value="1">
					<?php if ( in_array( $action, array( 'assign', 'reassign' ), true ) ) : ?>
						<p><label for="dms-bulk-reason"><?php esc_html_e( 'Reason (optional)', 'dms' ); ?></label><br><textarea id="dms-bulk-reason" name="reason" rows="2" class="large-text"></textarea></p>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( AdminMenu::area_url( $area ) ); ?>"><?php esc_html_e( 'Cancel', 'dms' ); ?></a>
					<button type="submit" class="button <?php echo $destructive ? 'button-link-delete dms-button-danger' : 'button-primary'; ?>"><?php echo $destructive ? esc_html__( 'Permanently Delete', 'dms' ) : esc_html__( 'Confirm', 'dms' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_record( int $id, string $area ): void {
		try {
			$r = $this->plugin->workflow()->view( $id );
		} catch ( DmsException $e ) {
			wp_die( esc_html( $e->getMessage() ), (int) $e->http_status() );
		}
		$status  = Status::from( $r->status );
		$user_id = get_current_user_id();
		$machine = new StateMachine();
		$is_mine = (int) $r->assigned_officer_id === $user_id;
		$el      = $this->plugin->electoral();
		$officer = $r->assigned_officer_id ? get_user_by( 'id', (int) $r->assigned_officer_id ) : null;
		$name    = trim( implode( ' ', array_filter( array( $r->first_name, $r->middle_name, $r->last_name ) ) ) );
		$region  = $el->find( ElectoralLevel::REGION, (int) $r->region_id )->name ?? '';
		$const   = $el->find( ElectoralLevel::CONSTITUENCY, (int) $r->constituency_id )->name ?? '';
		$station = $el->find( ElectoralLevel::POLLING_STATION, (int) $r->polling_station_id )->name ?? '';
		?>
		<div class="wrap dms-wrap dms-record">
			<p class="dms-back"><a href="<?php echo esc_url( AdminMenu::area_url( $area ) ); ?>">&larr; <?php esc_html_e( 'Back to list', 'dms' ); ?></a></p>
			<div class="dms-page-head dms-record-head">
				<div class="dms-record-head__main">
					<?php echo View::avatar( $name, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div>
						<p class="dms-eyebrow"><?php esc_html_e( 'Registration', 'dms' ); ?></p>
						<h1>
							<?php
							/* translators: %s: registration number */
							echo esc_html( sprintf( __( 'Registration %s', 'dms' ), $r->registration_number ) );
							?>
							<span class="dms-status dms-status--<?php echo esc_attr( strtolower( $status->value ) ); ?>"><?php echo esc_html( $status->label() ); ?></span>
						</h1>
						<p>
						<?php
						/* translators: 1: applicant name, 2: submission date */
						echo esc_html( sprintf( __( '%1$s · submitted %2$s', 'dms' ), $name, View::date( $r->submitted_at ) ) );
						?>
						</p>
					</div>
				</div>
			</div>
			<hr class="wp-header-end">
			<?php $this->lifecycle( $r, $status ); ?>

			<div class="dms-record-layout">
				<div class="dms-record-layout__main">
					<section class="dms-card" aria-labelledby="dms-applicant">
						<h2 id="dms-applicant"><?php esc_html_e( 'Applicant Information', 'dms' ); ?></h2>
						<?php
						$this->dl(
							array(
								__( 'First Name', 'dms' )  => $r->first_name,
								__( 'Middle Name', 'dms' ) => $r->middle_name,
								__( 'Last Name', 'dms' )   => $r->last_name,
								__( 'Date of Birth', 'dms' ) => $r->date_of_birth,
								__( 'Gender', 'dms' )      => $r->gender,
								__( 'Phone', 'dms' )       => $r->phone_normalized . ' — ' . ( (int) $r->phone_verified ? __( 'verified by OTP', 'dms' ) : __( 'not verified', 'dms' ) ),
								__( 'Email', 'dms' )       => $r->email,
								__( 'Residential Address', 'dms' ) => $r->address,
							)
						);
						$extra = json_decode( (string) $r->extra_fields, true );
						if ( is_array( $extra ) && array() !== $extra ) {
							$labels = array();
							foreach ( $this->plugin->form_definition()->fields() as $f ) {
								if ( $f['custom'] ) {
									$labels[ substr( $f['key'], 7 ) ] = $f['label'];
								}
							}
							$custom = array();
							foreach ( $extra as $k => $v ) {
								$custom[ $labels[ $k ] ?? $k ] = is_scalar( $v ) ? (string) $v : '';
							}
							$this->dl( $custom );
						}
						?>
					</section>
					<div class="dms-grid-2 dms-grid-2--even">
						<section class="dms-card" aria-labelledby="dms-organization">
							<h2 id="dms-organization"><?php esc_html_e( 'Organization', 'dms' ); ?></h2>
							<?php $this->dl( array( __( 'Organization', 'dms' ) => $r->organization ) ); ?>
						</section>
						<section class="dms-card" aria-labelledby="dms-electoral">
							<h2 id="dms-electoral"><?php esc_html_e( 'Electoral Information', 'dms' ); ?></h2>
							<ol class="dms-hierarchy">
								<li><span><?php esc_html_e( 'Region', 'dms' ); ?></span><strong><?php echo esc_html( $region ); ?></strong></li>
								<li><span><?php esc_html_e( 'Constituency', 'dms' ); ?></span><strong><?php echo esc_html( $const ); ?></strong></li>
								<li><span><?php esc_html_e( 'Polling Station', 'dms' ); ?></span><strong><?php echo esc_html( $station ); ?></strong></li>
							</ol>
						</section>
					</div>

					<?php if ( $status->is_editable() && current_user_can( 'registrations.edit' ) ) : ?>
						<details class="dms-card dms-disclosure">
							<summary><h2><?php esc_html_e( 'Edit Registration', 'dms' ); ?></h2></summary>
							<p class="description"><?php esc_html_e( 'Every change is recorded in the history with the old and new value. The phone number cannot be changed because it identifies the registration.', 'dms' ); ?></p>
							<?php $this->edit_form( $r ); ?>
						</details>
					<?php endif; ?>

					<section class="dms-card" aria-labelledby="dms-history">
						<h2 id="dms-history"><?php esc_html_e( 'History', 'dms' ); ?></h2>
						<?php $this->history( $id ); ?>
					</section>
				</div>

				<div class="dms-record-layout__side">
					<section class="dms-card" aria-labelledby="dms-workflow">
						<h2 id="dms-workflow"><?php esc_html_e( 'Workflow', 'dms' ); ?></h2>
						<?php
						if ( Status::APPROVED === $status ) {
							echo '<p class="dms-locked"><span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . esc_html__( 'Approved records are locked and cannot be edited.', 'dms' ) . '</p>';
						}
						$this->dl(
							array(
								__( 'Status', 'dms' )      => $status->label(),
								__( 'Assigned Officer', 'dms' ) => $officer ? $officer->display_name : __( 'Unassigned', 'dms' ),
								__( 'Assigned', 'dms' )    => $this->date( $r->assigned_at ),
								__( 'Submitted', 'dms' )   => $this->date( $r->submitted_at ),
								__( 'Review Started', 'dms' ) => $this->date( $r->reviewed_at ),
								__( 'Approved', 'dms' )    => $this->date( $r->approved_at ),
								__( 'Disapproved', 'dms' ) => $this->date( $r->disapproved_at ),
								__( 'Disapproval Reason', 'dms' ) => $r->disapproval_reason,
							)
						);
						?>
					</section>
					<section class="dms-card dms-actions-card" aria-labelledby="dms-actions-title">
						<h2 id="dms-actions-title"><?php esc_html_e( 'Actions', 'dms' ); ?></h2>
						<div class="dms-actions">
							<?php $this->actions( $r, $status, $machine, $is_mine ); ?>
						</div>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	/** Lifecycle bar from the record's own dates (display only). */
	private function lifecycle( object $r, Status $status ): void {
		$final = Status::DISAPPROVED === $status
			? array( __( 'Disapproved', 'dms' ), $r->disapproved_at, 'is-bad' )
			: array( __( 'Approved', 'dms' ), $r->approved_at, 'is-good' );
		$steps = array(
			array( __( 'Submitted', 'dms' ), $r->submitted_at, '' ),
			array( __( 'Assigned', 'dms' ), $r->assigned_at, '' ),
			array( __( 'Under Review', 'dms' ), $r->reviewed_at, '' ),
			$final,
		);
		echo '<ol class="dms-lifecycle" aria-label="' . esc_attr__( 'Registration lifecycle', 'dms' ) . '">';
		foreach ( $steps as [ $label, $date, $tone ] ) {
			$done = ! empty( $date );
			printf(
				'<li class="%1$s"><span class="dms-lifecycle__dot" aria-hidden="true"></span><strong>%2$s</strong><small>%3$s</small></li>',
				esc_attr( trim( ( $done ? 'is-done ' : '' ) . ( $done ? $tone : '' ) ) ),
				esc_html( $label ),
				$done ? esc_html( View::date( $date ) ) : '<span class="screen-reader-text">' . esc_html__( 'not yet', 'dms' ) . '</span><span aria-hidden="true">—</span>'
			);
		}
		echo '</ol>';
	}

	private function actions( object $r, Status $status, StateMachine $machine, bool $is_mine ): void {
		$id   = (int) $r->id;
		$any  = false;
		$form = static function ( string $op, string $label, string $css = 'button', string $extra = '', string $confirm = '' ) use ( $id ): void {
			printf(
				'<form method="post" action="%1$s" class="dms-action-form dms-action-form--%4$s"%8$s>%2$s<input type="hidden" name="registration_id" value="%3$d"><input type="hidden" name="op" value="%4$s">%5$s<button type="submit" class="%6$s">%7$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				AdminActions::fields( 'registration' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- int.
				esc_attr( $op ),
				$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the caller.
				esc_attr( $css ),
				esc_html( $label ),
				'' !== $confirm ? ' data-dms-confirm="' . esc_attr( $confirm ) . '" data-dms-confirm-label="' . esc_attr( $label ) . '"' . ( str_contains( $css, 'danger' ) ? '' : ' data-dms-confirm-tone="primary"' ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here.
			);
		};

		if ( $is_mine && $machine->can( $status, Transition::START_REVIEW ) && current_user_can( 'review.view' ) ) {
			$form( 'start_review', __( 'Start review', 'dms' ), 'button button-primary' );
			$any = true;
		}
		if ( $is_mine && Status::UNDER_REVIEW === $status ) {
			if ( current_user_can( 'review.approve' ) ) {
				$form( 'approve', __( 'Approve', 'dms' ), 'button button-primary', '', __( 'Approve this registration? Approved records are locked and cannot be edited, reassigned or disapproved afterwards.', 'dms' ) );
				$any = true;
			}
			if ( current_user_can( 'review.disapprove' ) ) {
				$form(
					'disapprove',
					__( 'Disapprove', 'dms' ),
					'button dms-button-danger',
					'<label for="dms-reason">' . esc_html__( 'Reason for disapproval (required, at least 5 characters)', 'dms' ) . '</label><textarea id="dms-reason" name="reason" required minlength="5" rows="3" class="large-text"></textarea><p class="description">' . esc_html__( 'Describe the problem with the registration. Do not include personal details — this reason is kept after the record is deleted.', 'dms' ) . '</p>',
					__( 'Disapprove this registration? It moves to the Bin and is deleted automatically when the retention period ends, unless it is restored.', 'dms' )
				);
				$any = true;
			}
		}
		if ( $machine->can( $status, Transition::ASSIGN ) && current_user_can( 'assignment.assign' ) ) {
			$form( 'assign', __( 'Assign', 'dms' ), 'button', $this->officer_select( (int) $r->region_id, (int) $r->assigned_officer_id ) );
			$any = true;
		}
		if ( $machine->can( $status, Transition::REASSIGN ) && current_user_can( 'assignment.reassign' ) ) {
			$form( 'reassign', __( 'Reassign', 'dms' ), 'button', $this->officer_select( (int) $r->region_id, (int) $r->assigned_officer_id ) . '<label for="dms-reassign-reason">' . esc_html__( 'Reason (optional)', 'dms' ) . '</label><input type="text" id="dms-reassign-reason" name="reason" class="regular-text">' );
			$any = true;
		}
		if ( $machine->can( $status, Transition::RESTORE ) && current_user_can( 'bin.restore' ) ) {
			$form( 'restore', __( 'Restore to Holding Area', 'dms' ), 'button button-primary' );
			$any = true;
		}
		if ( $machine->can( $status, Transition::DELETE ) && current_user_can( 'bin.delete' ) ) {
			$form( 'delete', __( 'Delete permanently', 'dms' ), 'button dms-button-danger', '<input type="hidden" name="confirmed" value="0" data-dms-confirm-delete>' );
			$any = true;
		}
		if ( $status->in_holding_area() && current_user_can( 'review.add_note' ) ) {
			$form( 'note', __( 'Add note', 'dms' ), 'button', '<label for="dms-note">' . esc_html__( 'Note', 'dms' ) . '</label><textarea id="dms-note" name="note" rows="2" class="large-text" required maxlength="2000"></textarea>' );
			$any = true;
		}
		if ( ! $any ) {
			echo '<p class="description">' . esc_html__( 'No actions are available to you for this registration.', 'dms' ) . '</p>';
		}
	}

	private function officer_select( int $region_id, int $current ): string {
		$candidates = $this->plugin->assignments()->ranked_candidates( $region_id );
		if ( array() === $candidates ) {
			return '<p class="description">' . esc_html__( 'No active officer is configured for this region.', 'dms' ) . '</p>';
		}
		$html = '<label for="dms-officer">' . esc_html__( 'Officer', 'dms' ) . '</label><select id="dms-officer" name="officer_id" required><option value="">' . esc_html__( 'Choose officer…', 'dms' ) . '</option>';
		foreach ( $candidates as $c ) {
			if ( $c['user_id'] === $current ) {
				continue;
			}
			$user = get_user_by( 'id', $c['user_id'] );
			/* translators: 1: officer name, 2: active workload */
			$html .= sprintf( '<option value="%1$d">%2$s</option>', (int) $c['user_id'], esc_html( sprintf( __( '%1$s (active: %2$d)', 'dms' ), $user ? $user->display_name : '#' . $c['user_id'], $c['workload'] ) ) );
		}
		return $html . '</select>';
	}

	private function edit_form( object $r ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dms-edit-form">';
		echo AdminActions::fields( 'registration' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<input type="hidden" name="registration_id" value="%d"><input type="hidden" name="op" value="edit">', (int) $r->id );
		$extra = json_decode( (string) $r->extra_fields, true );
		echo '<table class="form-table" role="presentation">';
		foreach ( $this->plugin->form_definition()->fields() as $field ) {
			if ( 'phone' === $field['type'] || 'electoral' === $field['type'] ) {
				continue; // Phone is locked (OD-20); electoral fields are rendered below as cascading selects.
			}
			$key   = $field['key'];
			$value = $field['custom'] ? ( is_array( $extra ) ? (string) ( $extra[ substr( $key, 7 ) ] ?? '' ) : '' ) : (string) ( $r->{$key} ?? '' );
			printf( '<tr><th scope="row"><label for="dms-edit-%1$s">%2$s</label></th><td>', esc_attr( $key ), esc_html( $field['label'] ) );
			if ( 'select' === $field['type'] ) {
				printf( '<select id="dms-edit-%1$s" name="changes[%1$s]"><option value=""></option>', esc_attr( $key ) );
				foreach ( $field['options'] as $o ) {
					printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $o ), selected( $value, $o, false ) );
				}
				echo '</select>';
			} elseif ( 'textarea' === $field['type'] ) {
				printf( '<textarea id="dms-edit-%1$s" name="changes[%1$s]" rows="2" class="large-text">%2$s</textarea>', esc_attr( $key ), esc_textarea( $value ) );
			} else {
				$type = array(
					'date'   => 'date',
					'email'  => 'email',
					'number' => 'number',
				)[ $field['type'] ] ?? 'text';
				printf( '<input type="%1$s" id="dms-edit-%2$s" name="changes[%2$s]" value="%3$s" class="regular-text">', esc_attr( $type ), esc_attr( $key ), esc_attr( $value ) );
			}
			echo '</td></tr>';
		}
		// Electoral fields: cascading, re-validated as one chain by the service (ARCH §38).
		$el     = $this->plugin->electoral();
		$levels = array(
			'region_id'          => array( 'region', __( 'Region', 'dms' ), $el->options( ElectoralLevel::REGION ) ),
			'constituency_id'    => array( 'constituency', __( 'Constituency', 'dms' ), $el->options( ElectoralLevel::CONSTITUENCY, (int) $r->region_id ) ),
			'polling_station_id' => array( 'polling_station', __( 'Polling Station', 'dms' ), $el->options( ElectoralLevel::POLLING_STATION, (int) $r->constituency_id ) ),
		);
		foreach ( $levels as $key => [ $level, $label, $options ] ) {
			printf( '<tr><th scope="row"><label for="dms-edit-%1$s">%2$s</label></th><td><select id="dms-edit-%1$s" name="changes[%1$s]" data-dms-cascade="%3$s" required><option value="">%4$s</option>', esc_attr( $key ), esc_html( $label ), esc_attr( $level ), esc_html__( 'Select…', 'dms' ) );
			foreach ( $options as $o ) {
				printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $o['id'], selected( (int) $r->{$key}, $o['id'], false ), esc_html( $o['name'] ) );
			}
			echo '</select></td></tr>';
		}
		echo '</table>';
		echo '<p><label for="dms-edit-reason">' . esc_html__( 'Reason for change (optional)', 'dms' ) . '</label><br><input type="text" id="dms-edit-reason" name="reason" class="large-text"></p>';
		submit_button( __( 'Save changes', 'dms' ) );
		echo '</form>';
	}

	private function history( int $id ): void {
		$entries = array_reverse( $this->plugin->audit()->for_registration( $id ) );
		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'No history yet.', 'dms' ) . '</p>';
			return;
		}
		echo '<ol class="dms-timeline">';
		foreach ( $entries as $e ) {
			$actor = 'SYSTEM' === $e->actor_type ? __( 'System', 'dms' ) : ( 'PUBLIC' === $e->actor_type ? __( 'Public User', 'dms' ) : ( get_user_by( 'id', (int) $e->user_id )->display_name ?? __( 'Unknown user', 'dms' ) ) );
			printf( '<li><time>%1$s</time> <strong>%2$s</strong> — %3$s', esc_html( $this->date( $e->created_at ) ), esc_html( ucwords( strtolower( str_replace( '_', ' ', $e->action ) ) ) ), esc_html( $actor ) );
			if ( $e->reason ) {
				printf( '<br><span class="dms-reason">%s</span>', esc_html( $e->reason ) );
			}
			$meta = json_decode( (string) $e->metadata, true );
			if ( is_array( $meta ) && isset( $meta['changes'] ) && is_array( $meta['changes'] ) ) {
				echo '<ul class="dms-changes">';
				foreach ( $meta['changes'] as $field => $change ) {
					if ( is_array( $change ) ) {
						printf( '<li><code>%1$s</code>: %2$s &rarr; %3$s</li>', esc_html( $field ), esc_html( is_scalar( $change['old'] ?? null ) ? (string) $change['old'] : '—' ), esc_html( is_scalar( $change['new'] ?? null ) ? (string) $change['new'] : '—' ) );
					} else {
						printf( '<li><code>%1$s</code>: %2$s</li>', esc_html( $field ), esc_html( AuditService::SCRUBBED === $change ? __( '[removed]', 'dms' ) : (string) $change ) );
					}
				}
				echo '</ul>';
			}
			echo '</li>';
		}
		echo '</ol>';
	}

	/** @param array<string,mixed> $rows */
	private function dl( array $rows ): void {
		echo '<dl class="dms-dl">';
		foreach ( $rows as $label => $value ) {
			printf( '<dt>%1$s</dt><dd>%2$s</dd>', esc_html( (string) $label ), '' === (string) $value ? '<span aria-hidden="true">—</span>' : esc_html( (string) $value ) );
		}
		echo '</dl>';
	}

	private function date( ?string $utc ): string {
		return $utc ? get_date_from_gmt( $utc, get_option( 'date_format' ) . ' H:i' ) : '';
	}
}
