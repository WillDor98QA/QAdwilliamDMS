<?php
/**
 * Data Management → Users (ARCH §12, §13, §72.1; MP §30).
 * Native WordPress users managed through UserService; password only on creation.
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminActions;
use DMS\Admin\View;
use DMS\Database\Tables;
use DMS\Electoral\ElectoralLevel;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class UsersPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'users.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view selection.
		$user_id = absint( $_GET['user'] ?? 0 );
		$is_new  = isset( $_GET['new'] );
		// phpcs:enable
		if ( $is_new || $user_id > 0 ) {
			$this->render_form( $user_id );
			return;
		}
		$this->render_list();
	}

	private function managed_user_ids(): array {
		global $wpdb;
		$roles    = Tables::name( Tables::USER_ROLES );
		$profiles = Tables::name( Tables::USER_PROFILES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Tables.
		return array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM {$roles} UNION SELECT user_id FROM {$profiles}" ) );
	}

	public const PER_PAGE = 20;

	private function render_list(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		$search  = trim( sanitize_text_field( (string) wp_unslash( $_GET['s'] ?? '' ) ) );
		$managed = $this->managed_user_ids();
		$ids     = array();
		$total   = 0;
		if ( array() !== $managed ) {
			// The same managed users as before, now searched, sorted by name and paged.
			$query = new \WP_User_Query(
				array(
					'include'        => $managed,
					'fields'         => 'ID',
					'orderby'        => 'display_name',
					'order'          => 'ASC',
					'number'         => self::PER_PAGE,
					'paged'          => View::page_param(),
					'count_total'    => true,
					'search'         => '' !== $search ? '*' . $search . '*' : '',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				)
			);
			$ids   = array_map( 'intval', (array) $query->get_results() );
			$total = (int) $query->get_total();
		}
		?>
		<div class="wrap dms-wrap">
			<div class="dms-page-head">
				<div>
					<p class="dms-eyebrow"><?php esc_html_e( 'Access control', 'dms' ); ?></p>
					<h1><?php esc_html_e( 'Users', 'dms' ); ?></h1>
					<p><?php esc_html_e( 'WordPress users with Data Management roles, their regions and open work.', 'dms' ); ?></p>
				</div>
				<div class="dms-page-head__actions">
			<?php if ( current_user_can( 'users.create' ) ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=dms-users&new=1' ) ); ?>"><?php esc_html_e( 'Add User', 'dms' ); ?></a>
			<?php endif; ?>
				</div>
			</div>
			<hr class="wp-header-end">
			<form method="get" class="dms-filters">
				<input type="hidden" name="page" value="dms-users">
				<label for="dms-user-search"><?php esc_html_e( 'Search name, username or email', 'dms' ); ?></label>
				<input type="search" id="dms-user-search" name="s" value="<?php echo esc_attr( $search ); ?>">
				<?php submit_button( __( 'Search', 'dms' ), '', '', false ); ?>
				<?php if ( '' !== $search ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dms-users' ) ); ?>"><?php esc_html_e( 'Clear', 'dms' ); ?></a>
				<?php endif; ?>
			</form>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Email', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Roles', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Regions', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Open work', 'dms' ); ?></th></tr></thead>
				<tbody>
				<?php if ( array() === $ids ) : ?>
					<tr><td colspan="6" class="dms-empty"><?php echo esc_html( '' !== $search ? __( 'No users match this search.', 'dms' ) : __( 'No users yet.', 'dms' ) ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $ids as $id ) {
					$user = get_user_by( 'id', $id );
					if ( ! $user ) {
						continue;
					}
					$roles   = implode( ', ', array_map( static fn( $r ) => $r->name, $this->plugin->roles()->roles_for_user( $id ) ) );
					$regions = implode( ', ', array_map( fn( int $r ) => $this->plugin->electoral()->find( ElectoralLevel::REGION, $r )->name ?? '#' . $r, $this->plugin->officer_regions()->active_region_ids( $id ) ) );
					$active  = $this->plugin->profiles()->is_active( $id );
					printf(
						'<tr><td><a href="%1$s"><strong>%2$s</strong></a><br><span class="description">%3$s</span></td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$d</td></tr>',
						esc_url( admin_url( 'admin.php?page=dms-users&user=' . $id ) ),
						esc_html( $user->display_name ),
						esc_html( $user->user_login ),
						esc_html( $user->user_email ),
						esc_html( '' !== $roles ? $roles : '—' ),
						esc_html( '' !== $regions ? $regions : '—' ),
						$active ? '<span class="dms-pill dms-pill--green">' . esc_html__( 'Active', 'dms' ) . '</span>' : '<span class="dms-status dms-status--disapproved">' . esc_html__( 'Disabled', 'dms' ) . '</span>',
						(int) $this->plugin->officer_regions()->outstanding_count( $id )
					);
				}
				?>
				</tbody>
			</table>
			<?php View::pagination( $total, self::PER_PAGE, View::page_param() ); ?>
		</div>
		<?php
	}

	private function render_form( int $user_id ): void {
		$user = $user_id ? get_user_by( 'id', $user_id ) : null;
		if ( $user_id && ! $user ) {
			wp_die( esc_html__( 'User not found.', 'dms' ), 404 );
		}
		$can_save = $user_id ? current_user_can( 'users.edit' ) : current_user_can( 'users.create' );
		$held     = $user_id ? $this->plugin->roles()->role_ids_for_user( $user_id ) : array();
		$regions  = $user_id ? $this->plugin->officer_regions()->active_region_ids( $user_id ) : array();
		$disabled = $can_save ? '' : ' disabled';
		?>
		<div class="wrap dms-wrap">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=dms-users' ) ); ?>">&larr; <?php esc_html_e( 'All users', 'dms' ); ?></a></p>
			<h1><?php echo $user ? esc_html( $user->display_name ) : esc_html__( 'Add User', 'dms' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php echo AdminActions::fields( 'user_save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
				<table class="form-table" role="presentation">
					<?php
					$this->text( 'first_name', __( 'First Name', 'dms' ), $user ? (string) get_user_meta( $user_id, 'first_name', true ) : '', $disabled, true );
					$this->text( 'last_name', __( 'Last Name', 'dms' ), $user ? (string) get_user_meta( $user_id, 'last_name', true ) : '', $disabled, true );
					$this->text( 'email', __( 'Email', 'dms' ), $user ? $user->user_email : '', $disabled, true, 'email' );
					if ( ! $user ) {
						$this->text( 'username', __( 'Username', 'dms' ), '', $disabled, true );
						$this->text( 'password', __( 'Password', 'dms' ), '', $disabled, true, 'password', __( 'At least 10 characters. The user can change it later with the normal WordPress password reset.', 'dms' ) );
					}
					?>
					<tr><th scope="row"><?php esc_html_e( 'Roles', 'dms' ); ?></th><td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Roles', 'dms' ); ?></legend>
					<?php foreach ( $this->plugin->roles()->all() as $role ) : ?>
						<label class="dms-check"><input type="checkbox" name="role_ids[]" value="<?php echo esc_attr( (string) $role->id ); ?>" <?php checked( in_array( (int) $role->id, $held, true ) ); ?><?php echo esc_attr( $disabled ); ?>> <?php echo esc_html( $role->name ); ?><?php echo 'ACTIVE' !== $role->status ? ' <em>(' . esc_html__( 'inactive', 'dms' ) . ')</em>' : ''; ?></label>
					<?php endforeach; ?>
					</fieldset></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Regions', 'dms' ); ?></th><td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Regions', 'dms' ); ?></legend>
					<p class="description"><?php esc_html_e( 'Required for officers. New registrations from these regions can be assigned to this user.', 'dms' ); ?></p>
					<?php foreach ( $this->plugin->electoral()->options( ElectoralLevel::REGION ) as $region ) : ?>
						<label class="dms-check"><input type="checkbox" name="region_ids[]" value="<?php echo esc_attr( (string) $region['id'] ); ?>" <?php checked( in_array( $region['id'], $regions, true ) ); ?><?php echo esc_attr( $disabled ); ?>> <?php echo esc_html( $region['name'] ); ?></label>
					<?php endforeach; ?>
					</fieldset></td></tr>
				</table>
				<?php
				if ( $can_save ) {
					submit_button( $user ? __( 'Save user', 'dms' ) : __( 'Create user', 'dms' ) );
				}
				?>
			</form>
			<?php if ( $user && current_user_can( 'users.disable' ) && get_current_user_id() !== $user_id ) : ?>
				<?php $active = $this->plugin->profiles()->is_active( $user_id ); ?>
				<h2><?php echo $active ? esc_html__( 'Disable account', 'dms' ) : esc_html__( 'Enable account', 'dms' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php echo AdminActions::fields( 'user_status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
					<input type="hidden" name="status" value="<?php echo $active ? 'disable' : 'enable'; ?>">
					<?php if ( $active ) : ?>
						<p class="description"><?php esc_html_e( 'Disabled users cannot sign in or receive registrations. Their history is kept. Reassign their open work afterwards.', 'dms' ); ?></p>
						<p><label for="dms-disable-reason"><?php esc_html_e( 'Reason', 'dms' ); ?></label><br><input type="text" id="dms-disable-reason" name="reason" class="regular-text"></p>
					<?php endif; ?>
					<button type="submit" class="button <?php echo $active ? 'dms-button-danger' : 'button-primary'; ?>"><?php echo $active ? esc_html__( 'Disable user', 'dms' ) : esc_html__( 'Enable user', 'dms' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function text( string $name, string $label, string $value, string $disabled, bool $required, string $type = 'text', string $help = '' ): void {
		printf(
			'<tr><th scope="row"><label for="dms-user-%1$s">%2$s%3$s</label></th><td><input type="%4$s" id="dms-user-%1$s" name="%1$s" value="%5$s" class="regular-text"%6$s%7$s autocomplete="%8$s">%9$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			$required ? ' <span class="dms-required" aria-hidden="true">*</span>' : '',
			esc_attr( $type ),
			esc_attr( $value ),
			$required ? ' required' : '',
			esc_attr( $disabled ),
			'password' === $type ? 'new-password' : 'off',
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}
}
