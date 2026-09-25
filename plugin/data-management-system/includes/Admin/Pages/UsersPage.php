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
use DMS\Audit\AuditAction;
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
		$view = sanitize_key( (string) wp_unslash( $_GET['view'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $is_new || $user_id > 0 ) {
			$this->render_form( $user_id );
			return;
		}
		if ( 'removed' === $view ) {
			$this->render_removed();
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
			<?php $this->tabs( 'current', $total ); ?>
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
				<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Email', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Roles', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Regions', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Open work', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Actions', 'dms' ); ?></th></tr></thead>
				<tbody>
				<?php if ( array() === $ids ) : ?>
					<tr><td colspan="7" class="dms-empty"><?php echo esc_html( '' !== $search ? __( 'No users match this search.', 'dms' ) : __( 'No users yet.', 'dms' ) ); ?></td></tr>
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
						'<tr><td><a href="%1$s"><strong>%2$s</strong></a><br><span class="description">%3$s</span></td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$d</td><td>%9$s</td></tr>',
						esc_url( admin_url( 'admin.php?page=dms-users&user=' . $id ) ),
						esc_html( $user->display_name ),
						esc_html( $user->user_login ),
						esc_html( $user->user_email ),
						esc_html( '' !== $roles ? $roles : '—' ),
						esc_html( '' !== $regions ? $regions : '—' ),
						$active ? '<span class="dms-pill dms-pill--green">' . esc_html__( 'Active', 'dms' ) . '</span>' : '<span class="dms-status dms-status--disapproved">' . esc_html__( 'Disabled', 'dms' ) . '</span>',
						(int) $this->plugin->officer_regions()->outstanding_count( $id ),
						$this->row_actions( $id, $active ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
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
			<?php if ( $user && ! in_array( $user_id, $this->managed_user_ids(), true ) ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'This account is not in Data Management (it was removed, or was created elsewhere in WordPress). Give it at least one role and save to add it back. Its earlier history is still linked to it.', 'dms' ); ?></p></div>
			<?php endif; ?>
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
			<?php if ( $user_id && get_current_user_id() !== $user_id && current_user_can( 'users.delete' ) && in_array( $user_id, $this->managed_user_ids(), true ) ) : ?>
				<h2><?php esc_html_e( 'Remove from Data Management', 'dms' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dms-confirm="<?php echo esc_attr( $this->remove_message( $user ) ); ?>" data-dms-confirm-label="<?php esc_attr_e( 'Remove user', 'dms' ); ?>">
					<?php echo AdminActions::fields( 'user_remove' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
					<p class="description"><?php esc_html_e( 'Takes away all Data Management roles and regions, so the user no longer appears here or has any access. Their WordPress account and all history stay, and they can be added back later. Reassign any open registrations first.', 'dms' ); ?></p>
					<p><label for="dms-remove-reason"><?php esc_html_e( 'Reason (optional)', 'dms' ); ?></label><br><input type="text" id="dms-remove-reason" name="reason" class="regular-text"></p>
					<button type="submit" class="button dms-button-danger"><?php esc_html_e( 'Remove user', 'dms' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function tabs( string $current, int $count ): void {
		$removed = count( $this->removed_user_ids() );
		$tabs    = array(
			'current' => array( __( 'In Data Management', 'dms' ), admin_url( 'admin.php?page=dms-users' ), 'current' === $current ? $count : null ),
			'removed' => array( __( 'Removed', 'dms' ), admin_url( 'admin.php?page=dms-users&view=removed' ), $removed ),
		);
		echo '<nav class="dms-tabs" aria-label="' . esc_attr__( 'User lists', 'dms' ) . '"><ul>';
		foreach ( $tabs as $key => [ $label, $url, $n ] ) {
			printf(
				'<li><a href="%1$s"%2$s>%3$s%4$s</a></li>',
				esc_url( $url ),
				$key === $current ? ' class="is-active" aria-current="page"' : '',
				esc_html( $label ),
				null !== $n ? ' <span class="dms-tabs__count">' . esc_html( number_format_i18n( $n ) ) . '</span>' : ''
			);
		}
		echo '</ul></nav>';
	}

	/** Edit / Disable-Enable / Remove, each only with its permission; never on your own row. */
	private function row_actions( int $id, bool $active ): string {
		$out  = array();
		$self = get_current_user_id() === $id;
		if ( current_user_can( 'users.edit' ) ) {
			$out[] = sprintf( '<a class="button button-small" href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=dms-users&user=' . $id ) ), esc_html__( 'Edit', 'dms' ) );
		}
		if ( ! $self && current_user_can( 'users.disable' ) ) {
			$user  = get_user_by( 'id', $id );
			$name  = $user ? $user->display_name : '#' . $id;
			$out[] = sprintf(
				'<form method="post" action="%1$s" class="dms-inline-form"%2$s>%3$s<input type="hidden" name="user_id" value="%4$d"><input type="hidden" name="status" value="%5$s"><button type="submit" class="button button-small%6$s">%7$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				$active
					/* translators: %s: user name */
					? ' data-dms-confirm="' . esc_attr( sprintf( __( 'Disable %s? They will be signed out, cannot sign in and receive no new registrations. Their history is kept.', 'dms' ), $name ) ) . '" data-dms-confirm-label="' . esc_attr__( 'Disable user', 'dms' ) . '"'
					: '',
				AdminActions::fields( 'user_status' ),
				$id,
				$active ? 'disable' : 'enable',
				$active ? ' dms-button-danger' : '',
				$active ? esc_html__( 'Disable', 'dms' ) : esc_html__( 'Enable', 'dms' )
			);
		}
		if ( ! $self && current_user_can( 'users.delete' ) ) {
			$user  = get_user_by( 'id', $id );
			$out[] = sprintf(
				'<form method="post" action="%1$s" class="dms-inline-form" data-dms-confirm="%2$s" data-dms-confirm-label="%3$s">%4$s<input type="hidden" name="user_id" value="%5$d"><button type="submit" class="button button-small dms-button-danger">%6$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $this->remove_message( $user ) ),
				esc_attr__( 'Remove user', 'dms' ),
				AdminActions::fields( 'user_remove' ),
				$id,
				esc_html__( 'Remove', 'dms' )
			);
		}
		return '<div class="dms-row-actions">' . implode( '', $out ) . '</div>';
	}

	private function remove_message( $user ): string {
		/* translators: %s: user name */
		return sprintf( __( 'Remove %s from Data Management? All their roles and regions are taken away and they lose access. Their WordPress account and history are kept, and they can be added back later.', 'dms' ), $user ? $user->display_name : '' );
	}

	/** Users with a removal in the audit log who are not in DMS now. */
	private function removed_user_ids(): array {
		global $wpdb;
		$audit = Tables::name( Tables::AUDIT_LOG );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_id FROM {$audit} WHERE action = %s AND object_type = %s", AuditAction::USER_REMOVED, 'user' ) ) );
		return array_values( array_diff( $ids, $this->managed_user_ids() ) );
	}

	/**
	 * Latest removal entry (date, who, reason) for each given user, in one query.
	 *
	 * @param list<int> $ids
	 * @return array<int,object>
	 */
	private function latest_removals( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}
		global $wpdb;
		$audit = Tables::name( Tables::AUDIT_LOG );
		$in    = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables; IDs are cast to int.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT a.object_id, a.created_at, a.reason, u.display_name AS actor_name FROM {$audit} a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id WHERE a.action = %s AND a.object_type = %s AND a.object_id IN ({$in}) ORDER BY a.id DESC", AuditAction::USER_REMOVED, 'user' ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->object_id ] ??= $row;
		}
		return $out;
	}

	private function render_removed(): void {
		$ids   = $this->removed_user_ids();
		$total = count( $ids );
		$paged = min( View::page_param(), max( 1, (int) ceil( $total / self::PER_PAGE ) ) );
		?>
		<div class="wrap dms-wrap">
		<?php View::page_head( __( 'Access control', 'dms' ), __( 'Users', 'dms' ), __( 'People removed from Data Management. Their WordPress accounts and history are kept.', 'dms' ) ); ?>
		<?php $this->tabs( 'removed', 0 ); ?>
			<table class="widefat">
				<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Email', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Removed', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Actions', 'dms' ); ?></th></tr></thead>
				<tbody>
			<?php if ( array() === $ids ) : ?>
					<tr><td colspan="4" class="dms-empty"><?php esc_html_e( 'Nobody has been removed from Data Management.', 'dms' ); ?></td></tr>
				<?php endif; ?>
			<?php $removals = $this->latest_removals( array_slice( $ids, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ) ); ?>
			<?php foreach ( array_slice( $ids, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $id ) : ?>
					<?php
					$user = get_user_by( 'id', $id );
					if ( ! $user ) {
						continue;
					}
					$entry = $removals[ $id ] ?? null;
					?>
					<tr>
						<td><strong><?php echo esc_html( $user->display_name ); ?></strong><br><span class="description"><?php echo esc_html( $user->user_login ); ?></span></td>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td><?php echo $entry ? esc_html( View::short_date( $entry->created_at ) . ( $entry->actor_name ? ' — ' . $entry->actor_name : '' ) ) : '—'; ?><?php echo $entry && $entry->reason ? '<br><span class="description">' . esc_html( (string) $entry->reason ) . '</span>' : ''; ?></td>
						<td>
						<?php
						if ( current_user_can( 'users.edit' ) ) :
							?>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=dms-users&user=' . $id ) ); ?>"><?php esc_html_e( 'Add back', 'dms' ); ?></a><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php View::pagination( $total, self::PER_PAGE, $paged ); ?>
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
