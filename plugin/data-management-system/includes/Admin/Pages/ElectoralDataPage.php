<?php
/**
 * Data Configuration → Electoral Data (ARCH §53–§56, §59–§61; MP §15–§19).
 *
 * Business language only: administrators see "Import Electoral Data",
 * never foreign keys (MP §34). Every button is also checked on the server.
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminActions;
use DMS\Electoral\ElectoralLevel;
use DMS\Errors\DmsException;
use DMS\Imports\ImportStatus;
use DMS\Imports\WorkbookSpec;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class ElectoralDataPage {

	public const SLUG = 'dms-electoral';

	public function __construct( private Plugin $plugin ) {
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'imports.view' ) && ! current_user_can( 'imports.view_history' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only views; actions go through AdminActions.
		$batch = absint( $_GET['batch'] ?? 0 );
		$view  = sanitize_key( (string) wp_unslash( $_GET['view'] ?? '' ) );
		// phpcs:enable
		echo '<div class="wrap dms-wrap">';
		if ( $batch > 0 ) {
			$this->render_batch( $batch );
		} elseif ( 'data' === $view && current_user_can( 'imports.view' ) ) {
			$this->render_data();
		} else {
			$this->render_overview();
		}
		echo '</div>';
	}

	private function render_overview(): void {
		$counts = $this->plugin->electoral()->counts();
		?>
		<h1><?php esc_html_e( 'Electoral Data', 'dms' ); ?></h1>
		<?php if ( current_user_can( 'imports.view' ) ) : ?>
			<div class="dms-tiles">
				<?php
				foreach ( ElectoralLevel::cases() as $level ) {
					printf(
						'<a class="dms-tile" href="%1$s"><span class="dms-tile__label">%2$s</span><span class="dms-tile__value">%3$s</span></a>',
						esc_url(
							self::url(
								array(
									'view'  => 'data',
									'level' => $level->value,
								)
							)
						),
						esc_html( $this->plural( $level ) ),
						esc_html( number_format_i18n( $counts[ $level->value ] ) )
					);
				}
				?>
			</div>
			<p>
				<?php $this->download_button( false, __( 'Download Template', 'dms' ) ); ?>
				<?php $this->download_button( true, __( 'Download Current Data', 'dms' ) ); ?>
				<a class="button" href="<?php echo esc_url( self::url( array( 'view' => 'data' ) ) ); ?>"><?php esc_html_e( 'View Current Data', 'dms' ); ?></a>
			</p>
		<?php endif; ?>

		<?php if ( current_user_can( 'imports.upload' ) && current_user_can( 'imports.validate' ) ) : ?>
			<div class="dms-card">
				<h2><?php esc_html_e( 'Import Electoral Data', 'dms' ); ?></h2>
				<ol class="dms-steps">
					<li><?php esc_html_e( 'Download the official template (or the current data to edit it).', 'dms' ); ?></li>
					<li><?php esc_html_e( 'Fill in the Regions, Constituencies and Polling Stations sheets.', 'dms' ); ?></li>
					<li><?php esc_html_e( 'Upload the workbook. It is checked and you see a preview. Nothing is saved until you confirm.', 'dms' ); ?></li>
				</ol>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php echo AdminActions::fields( 'import_upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<label for="dms-import-file"><?php esc_html_e( 'Completed workbook (.xlsx)', 'dms' ); ?></label>
					<input type="file" id="dms-import-file" name="workbook" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
					<p class="description">
					<?php
					/* translators: %s: maximum file size */
					echo esc_html( sprintf( __( 'Maximum size: %s.', 'dms' ), size_format( $this->plugin->settings()->int( 'import_max_file_bytes' ) ) ) );
					?>
					</p>
					<?php submit_button( __( 'Upload and check', 'dms' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		<?php endif; ?>

		<?php if ( current_user_can( 'imports.view_history' ) ) : ?>
			<h2><?php esc_html_e( 'Import History', 'dms' ); ?></h2>
			<?php $history = $this->plugin->import_batches()->history( 1, 50 ); ?>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Import', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Date', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'File', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Rows', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'New / Updated / Unchanged / Errors', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'By', 'dms' ); ?></th></tr></thead>
				<tbody>
				<?php if ( array() === $history['items'] ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No imports yet.', 'dms' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $history['items'] as $b ) : ?>
					<?php $user = get_user_by( 'id', (int) $b->uploaded_by ); ?>
					<tr>
						<td><a href="<?php echo esc_url( self::url( array( 'batch' => $b->id ) ) ); ?>"><strong><?php echo esc_html( $b->import_reference ); ?></strong></a></td>
						<td><?php echo esc_html( get_date_from_gmt( $b->created_at, get_option( 'date_format' ) . ' H:i' ) ); ?></td>
						<td><?php echo esc_html( $b->filename ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $b->total_rows ) ); ?></td>
						<td><?php echo esc_html( sprintf( '%s / %s / %s / %s', number_format_i18n( (int) $b->created_count ), number_format_i18n( (int) $b->updated_count ), number_format_i18n( (int) $b->unchanged_count ), number_format_i18n( (int) $b->error_count ) ) ); ?></td>
						<td><span class="dms-import-status dms-import-status--<?php echo esc_attr( strtolower( $b->status ) ); ?>"><?php echo esc_html( ImportStatus::label( $b->status ) ); ?></span></td>
						<td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function render_batch( int $batch_id ): void {
		$batch = $this->plugin->import_batches()->find( $batch_id );
		if ( null === $batch ) {
			wp_die( esc_html__( 'Import not found.', 'dms' ), 404 );
		}
		$summary = json_decode( (string) $batch->summary, true );
		$summary = is_array( $summary ) ? $summary : array();
		$user    = get_user_by( 'id', (int) $batch->uploaded_by );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Electoral Data', 'dms' ); ?></a></p>
		<h1>
			<?php echo esc_html( $batch->import_reference ); ?>
			<span class="dms-import-status dms-import-status--<?php echo esc_attr( strtolower( $batch->status ) ); ?>"><?php echo esc_html( ImportStatus::label( $batch->status ) ); ?></span>
		</h1>
		<div class="dms-card">
			<dl class="dms-dl">
				<dt><?php esc_html_e( 'File', 'dms' ); ?></dt><dd><?php echo esc_html( $batch->filename ); ?></dd>
				<dt><?php esc_html_e( 'Uploaded', 'dms' ); ?></dt><dd><?php echo esc_html( get_date_from_gmt( $batch->created_at, get_option( 'date_format' ) . ' H:i' ) . ( $user ? ' — ' . $user->display_name : '' ) ); ?></dd>
				<?php if ( $batch->completed_at ) : ?>
					<dt><?php esc_html_e( 'Finished', 'dms' ); ?></dt><dd><?php echo esc_html( get_date_from_gmt( $batch->completed_at, get_option( 'date_format' ) . ' H:i' ) ); ?><?php echo isset( $summary['duration_seconds'] ) ? esc_html( ' (' . $summary['duration_seconds'] . ' s)' ) : ''; ?></dd>
				<?php endif; ?>
				<?php if ( $batch->rolled_back_at ) : ?>
					<dt><?php esc_html_e( 'Rolled back', 'dms' ); ?></dt><dd><?php echo esc_html( get_date_from_gmt( $batch->rolled_back_at, get_option( 'date_format' ) . ' H:i' ) ); ?></dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'File fingerprint (SHA-256)', 'dms' ); ?></dt><dd><code><?php echo esc_html( $batch->file_hash ); ?></code></dd>
			</dl>
			<?php if ( ! empty( $summary['earlier_batch'] ) ) : ?>
				<p class="notice notice-info inline">
				<?php
				/* translators: %s: earlier import reference */
				echo esc_html( sprintf( __( 'This exact file was already imported as %s. Importing it again changes nothing.', 'dms' ), $summary['earlier_batch'] ) );
				?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $summary['file_error'] ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( (string) $summary['file_error'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( isset( $summary['counts'] ) ) : ?>
			<h2><?php echo ImportStatus::COMPLETED === $batch->status || ImportStatus::ROLLED_BACK === $batch->status ? esc_html__( 'Import Summary', 'dms' ) : esc_html__( 'Import Preview', 'dms' ); ?></h2>
			<table class="widefat striped dms-preview">
				<thead><tr><th scope="col"></th><th scope="col"><?php esc_html_e( 'New', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Updated', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Unchanged', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Errors', 'dms' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( ElectoralLevel::cases() as $level ) : ?>
					<?php $c = $summary['counts'][ $level->value ] ?? array(); ?>
					<tr>
						<th scope="row"><?php echo esc_html( $this->plural( $level ) ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) ( $c['CREATE'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $c['UPDATE'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $c['UNCHANGED'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $c['INVALID'] ?? 0 ) + (int) ( $c['DUPLICATE'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $summary['errors'] ) ) : ?>
			<h2><?php esc_html_e( 'Errors to fix', 'dms' ); ?></h2>
			<p><?php esc_html_e( 'Nothing was imported. Correct these rows in your workbook and upload it again.', 'dms' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dms-inline-form">
				<?php echo AdminActions::fields( 'import_error_report' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch_id ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Download Error Report', 'dms' ); ?></button>
			</form>
			<ol class="dms-errors">
				<?php foreach ( array_slice( $summary['errors'], 0, 200 ) as $e ) : ?>
					<li>
						<?php
						$sheet = WorkbookSpec::sheets()[ $e['level'] ]['sheet'] ?? $e['level'];
						/* translators: 1: sheet, 2: row number, 3: code, 4: message */
						echo esc_html( sprintf( __( '%1$s, row %2$d%3$s: %4$s', 'dms' ), $sheet, (int) $e['row'], '' !== $e['code'] ? ' (' . $e['code'] . ')' : '', $e['message'] ) );
						?>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php if ( count( $summary['errors'] ) > 200 || ! empty( $summary['errors_truncated'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'More errors are listed in the error report.', 'dms' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( ImportStatus::VALIDATED === $batch->status && current_user_can( 'imports.confirm' ) ) : ?>
			<div class="dms-card">
				<p><strong><?php esc_html_e( 'The import is ready to proceed.', 'dms' ); ?></strong></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dms-confirm="<?php esc_attr_e( 'Apply these changes to the electoral data now?', 'dms' ); ?>">
					<?php echo AdminActions::fields( 'import_confirm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch_id ); ?>">
					<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'dms' ); ?></a>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm Import', 'dms' ); ?></button>
				</form>
			</div>
		<?php endif; ?>

		<?php
		if ( in_array( $batch->status, array( ImportStatus::COMPLETED, ImportStatus::ROLLED_BACK, ImportStatus::ROLLBACK_BLOCKED ), true ) ) {
			$this->render_changes( $batch_id );
		}
		if ( in_array( $batch->status, array( ImportStatus::COMPLETED, ImportStatus::ROLLBACK_BLOCKED ), true ) && current_user_can( 'imports.rollback' ) ) {
			$this->render_rollback( $batch_id );
		}
	}

	private function render_changes( int $batch_id ): void {
		$items = $this->plugin->import_batches()->items( $batch_id, array( ImportStatus::ACTION_CREATE, ImportStatus::ACTION_UPDATE ) );
		?>
		<h2><?php esc_html_e( 'Changes made by this import', 'dms' ); ?></h2>
		<?php if ( array() === $items ) : ?>
			<p><?php esc_html_e( 'This import did not add or change any records.', 'dms' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e( 'Type', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Code', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Change', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Before', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'After', 'dms' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $items, 0, 500 ) as $item ) : ?>
				<tr>
					<td><?php echo esc_html( ElectoralLevel::from( $item->entity_type )->label() ); ?></td>
					<td><code><?php echo esc_html( $item->record_code ); ?></code></td>
					<td><?php echo ImportStatus::ACTION_CREATE === $item->action ? esc_html__( 'New', 'dms' ) : esc_html__( 'Updated', 'dms' ); ?></td>
					<td><?php echo esc_html( is_array( $item->previous_values ) ? (string) ( $item->previous_values['name'] ?? '' ) : '—' ); ?></td>
					<td><?php echo esc_html( (string) ( $item->new_values['name'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( count( $items ) > 500 ) : ?>
			<p class="description">
			<?php
			/* translators: %d: number of changes */
			echo esc_html( sprintf( __( 'Showing the first 500 of %d changes.', 'dms' ), count( $items ) ) );
			?>
			</p>
		<?php endif; ?>
		<?php
	}

	private function render_rollback( int $batch_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only impact check.
		$check = ! empty( $_GET['check_rollback'] );
		?>
		<div class="dms-card">
			<h2><?php esc_html_e( 'Rollback Import', 'dms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Rollback removes records this import created and restores records it updated. It is refused if registrations, other records or a later import depend on those changes.', 'dms' ); ?></p>
			<?php if ( ! $check ) : ?>
				<a class="button" href="
				<?php
				echo esc_url(
					self::url(
						array(
							'batch'          => $batch_id,
							'check_rollback' => 1,
						)
					)
				);
				?>
										"><?php esc_html_e( 'Check Rollback Impact', 'dms' ); ?></a>
				<?php
				echo '</div>';
				return;
			endif;
			try {
				$impact = $this->plugin->imports()->rollback_impact( $batch_id );
			} catch ( DmsException $e ) {
				echo '<p>' . esc_html( $e->getMessage() ) . '</p></div>';
				return;
			}
			?>
			<ul class="dms-check-list">
				<?php /* translators: %d: count */ ?>
				<li><?php echo esc_html( sprintf( __( '%d created record(s) to remove.', 'dms' ), $impact['remove'] ) ); ?></li>
				<?php /* translators: %d: count */ ?>
				<li><?php echo esc_html( sprintf( __( '%d updated record(s) to restore.', 'dms' ), $impact['restore'] ) ); ?></li>
			</ul>
			<?php if ( $impact['safe'] ) : ?>
				<p><strong><?php esc_html_e( 'Rollback is safe. No registrations or other records depend on the affected records.', 'dms' ); ?></strong></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dms-confirm="<?php esc_attr_e( 'Roll back this import? Records it created will be removed and records it updated will be restored.', 'dms' ); ?>">
					<?php echo AdminActions::fields( 'import_rollback' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $batch_id ); ?>">
					<input type="hidden" name="confirmed" value="1">
					<a class="button" href="<?php echo esc_url( self::url( array( 'batch' => $batch_id ) ) ); ?>"><?php esc_html_e( 'Cancel', 'dms' ); ?></a>
					<button type="submit" class="button dms-button-danger"><?php esc_html_e( 'Rollback Import', 'dms' ); ?></button>
				</form>
			<?php else : ?>
				<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Rollback blocked', 'dms' ); ?></strong> — <?php esc_html_e( 'Rolling back would break existing relationships, so it cannot continue automatically. Correct the data with a new import instead, or resolve these first:', 'dms' ); ?></p></div>
				<table class="widefat striped">
					<thead><tr><th scope="col"><?php esc_html_e( 'Type', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Code', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Reason', 'dms' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $impact['blockers'], 0, 200 ) as $b ) : ?>
						<tr><td><?php echo esc_html( ElectoralLevel::from( $b['level'] )->label() ); ?></td><td><code><?php echo esc_html( $b['code'] ); ?></code></td><td><?php echo esc_html( $b['reason'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_data(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$level  = ElectoralLevel::tryFrom( strtoupper( sanitize_key( (string) wp_unslash( $_GET['level'] ?? 'REGION' ) ) ) ) ?? ElectoralLevel::REGION;
		$search = trim( sanitize_text_field( (string) wp_unslash( $_GET['s'] ?? '' ) ) );
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		global $wpdb;
		$per    = 50;
		$table  = $level->table();
		$parent = $level->parent();
		$where  = '1 = 1';
		$params = array();
		if ( '' !== $search ) {
			$where   .= ' AND (c.code LIKE %s OR c.name LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$join   = null !== $parent ? "LEFT JOIN {$parent->table()} p ON p.id = c.{$level->parent_column()}" : '';
		$select = null !== $parent ? ', p.code AS parent_code, p.name AS parent_name' : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- identifiers from ElectoralLevel; values are placeholders passed with the spread operator.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT c.code, c.name, c.status{$select} FROM {$table} c {$join} WHERE {$where} ORDER BY c.code LIMIT %d OFFSET %d", ...array_merge( $params, array( $per, ( $paged - 1 ) * $per ) ) ) );
		$count = "SELECT COUNT(*) FROM {$table} c WHERE {$where}";
		$total = (int) ( array() === $params ? $wpdb->get_var( $count ) : $wpdb->get_var( $wpdb->prepare( $count, ...$params ) ) );
		// phpcs:enable
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Electoral Data', 'dms' ); ?></a></p>
		<h1><?php esc_html_e( 'Current Electoral Data', 'dms' ); ?></h1>
		<nav class="nav-tab-wrapper">
			<?php foreach ( ElectoralLevel::cases() as $l ) : ?>
				<a class="nav-tab<?php echo $l === $level ? ' nav-tab-active' : ''; ?>" href="
				<?php
				echo esc_url(
					self::url(
						array(
							'view'  => 'data',
							'level' => $l->value,
						)
					)
				);
				?>
									"<?php echo $l === $level ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $this->plural( $l ) ); ?></a>
			<?php endforeach; ?>
		</nav>
		<form method="get" class="dms-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>"><input type="hidden" name="view" value="data"><input type="hidden" name="level" value="<?php echo esc_attr( $level->value ); ?>">
			<label for="dms-data-search"><?php esc_html_e( 'Search code or name', 'dms' ); ?></label>
			<input type="search" id="dms-data-search" name="s" value="<?php echo esc_attr( $search ); ?>">
			<?php submit_button( __( 'Search', 'dms' ), '', '', false ); ?>
		</form>
		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e( 'Code', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Name', 'dms' ); ?></th>
			<?php
			if ( null !== $parent ) :
				?>
				<th scope="col"><?php echo esc_html( $parent->label() ); ?></th><?php endif; ?><th scope="col"><?php esc_html_e( 'Status', 'dms' ); ?></th></tr></thead>
			<tbody>
			<?php if ( array() === $rows ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No records. Import the official workbook to add electoral data.', 'dms' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $r ) : ?>
				<tr><td><code><?php echo esc_html( $r->code ); ?></code></td><td><?php echo esc_html( $r->name ); ?></td>
				<?php
				if ( null !== $parent ) :
					?>
					<td><?php echo esc_html( $r->parent_name . ' (' . $r->parent_code . ')' ); ?></td><?php endif; ?><td><?php echo esc_html( 'ACTIVE' === $r->status ? __( 'Active', 'dms' ) : __( 'Inactive', 'dms' ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
	}

	private function download_button( bool $with_data, string $label ): void {
		printf(
			'<form method="post" action="%1$s" class="dms-inline-form">%2$s<input type="hidden" name="with_data" value="%3$d"><button type="submit" class="button">%4$s</button></form> ',
			esc_url( admin_url( 'admin-post.php' ) ),
			AdminActions::fields( 'import_template' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$with_data ? 1 : 0,
			esc_html( $label )
		);
	}

	private function plural( ElectoralLevel $level ): string {
		return match ( $level ) {
			ElectoralLevel::REGION          => __( 'Regions', 'dms' ),
			ElectoralLevel::CONSTITUENCY    => __( 'Constituencies', 'dms' ),
			ElectoralLevel::POLLING_STATION => __( 'Polling Stations', 'dms' ),
		};
	}
}
