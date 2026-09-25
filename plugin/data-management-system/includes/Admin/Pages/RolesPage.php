<?php
/**
 * Roles & Permissions (ARCH §14, §16, §17, §18). Menu permissions and action
 * permissions are listed separately under each module. Reserved permissions
 * are shown but cannot be granted.
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminActions;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class RolesPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'roles.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$role_id = absint( $_GET['role'] ?? 0 );
		$is_new  = isset( $_GET['new'] );
		// phpcs:enable
		if ( $is_new || $role_id > 0 ) {
			$this->render_form( $role_id );
			return;
		}
		?>
		<div class="wrap dms-wrap">
			<div class="dms-page-head">
				<div>
					<p class="dms-eyebrow"><?php esc_html_e( 'Access control', 'dms' ); ?></p>
					<h1><?php esc_html_e( 'Roles & Permissions', 'dms' ); ?></h1>
					<p><?php esc_html_e( 'What each role may see and do.', 'dms' ); ?></p>
				</div>
				<div class="dms-page-head__actions">
			<?php if ( current_user_can( 'roles.create' ) ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=dms-roles&new=1' ) ); ?>"><?php esc_html_e( 'Create Role', 'dms' ); ?></a>
			<?php endif; ?>
				</div>
			</div>
			<hr class="wp-header-end">
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Role', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Description', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Permissions', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Users', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'dms' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $this->plugin->roles()->all() as $role ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=dms-roles&role=' . $role->id ) ); ?>"><strong><?php echo esc_html( $role->name ); ?></strong></a><?php echo (int) $role->is_system ? ' <span class="dms-badge">' . esc_html__( 'Protected', 'dms' ) . '</span>' : ''; ?></td>
						<td><?php echo esc_html( (string) $role->description ); ?></td>
						<td><?php echo (int) $role->is_system ? esc_html__( 'All', 'dms' ) : esc_html( (string) count( $this->plugin->roles()->permissions( (int) $role->id ) ) ); ?></td>
						<td><?php echo esc_html( (string) $this->plugin->roles()->user_count( (int) $role->id ) ); ?></td>
						<td><span class="dms-pill dms-pill--<?php echo 'ACTIVE' === $role->status ? 'green' : 'gray'; ?>"><?php echo esc_html( 'ACTIVE' === $role->status ? __( 'Active', 'dms' ) : __( 'Inactive', 'dms' ) ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_form( int $role_id ): void {
		$role = $role_id ? $this->plugin->roles()->find( $role_id ) : null;
		if ( $role_id && null === $role ) {
			wp_die( esc_html__( 'Role not found.', 'dms' ), 404 );
		}
		$system   = $role && (int) $role->is_system;
		$can_save = ! $system && ( $role ? current_user_can( 'roles.edit' ) : current_user_can( 'roles.create' ) );
		$can_perm = $can_save && current_user_can( 'roles.assign_permissions' );
		$held     = $role ? $this->plugin->roles()->permissions( $role_id ) : array();
		$off      = $can_save ? '' : ' disabled';
		?>
		<div class="wrap dms-wrap">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=dms-roles' ) ); ?>">&larr; <?php esc_html_e( 'All roles', 'dms' ); ?></a></p>
			<h1><?php echo $role ? esc_html( $role->name ) : esc_html__( 'Create Role', 'dms' ); ?></h1>
			<?php if ( $system ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'The Administrator role is protected. It always has every permission and cannot be changed or deleted.', 'dms' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php echo AdminActions::fields( 'role_save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="role_id" value="<?php echo esc_attr( (string) $role_id ); ?>">
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="dms-role-name"><?php esc_html_e( 'Role Name', 'dms' ); ?></label></th><td><input type="text" id="dms-role-name" name="name" class="regular-text" required maxlength="100" value="<?php echo esc_attr( $role ? $role->name : '' ); ?>"<?php echo esc_attr( $off ); ?>></td></tr>
					<tr><th scope="row"><label for="dms-role-desc"><?php esc_html_e( 'Description', 'dms' ); ?></label></th><td><textarea id="dms-role-desc" name="description" class="large-text" rows="2"<?php echo esc_attr( $off ); ?>><?php echo esc_textarea( $role ? (string) $role->description : '' ); ?></textarea></td></tr>
					<?php if ( $role ) : ?>
						<tr><th scope="row"><label for="dms-role-status"><?php esc_html_e( 'Status', 'dms' ); ?></label></th><td><select id="dms-role-status" name="status"<?php echo esc_attr( $off ); ?>><option value="ACTIVE" <?php selected( $role->status, 'ACTIVE' ); ?>><?php esc_html_e( 'Active', 'dms' ); ?></option><option value="INACTIVE" <?php selected( $role->status, 'INACTIVE' ); ?>><?php esc_html_e( 'Inactive', 'dms' ); ?></option></select></td></tr>
					<?php endif; ?>
				</table>
				<h2><?php esc_html_e( 'Permissions', 'dms' ); ?></h2>
				<?php if ( ! $can_perm && ! $system ) : ?>
					<p class="description"><?php esc_html_e( 'You can view but not change this role\'s permissions.', 'dms' ); ?></p>
				<?php endif; ?>
				<div class="dms-permission-grid">
				<?php foreach ( $this->plugin->permissions()->grouped() as $group ) : ?>
					<fieldset class="dms-card">
						<legend><strong><?php echo esc_html( $group['label'] ); ?></strong></legend>
						<?php foreach ( $group['permissions'] as $p ) : ?>
							<?php $checked = $system ? ! $p->reserved : in_array( $p->key, $held, true ); ?>
							<label class="dms-check" title="<?php echo esc_attr( $p->key ); ?>">
								<input type="checkbox" name="permissions[]" value="<?php echo esc_attr( $p->key ); ?>" <?php checked( $checked ); ?><?php echo ( ! $can_perm || $p->reserved ) ? ' disabled' : ''; ?>>
								<?php echo esc_html( $p->label ); ?>
								<?php if ( $p->reserved ) : ?>
									<span class="description">(<?php esc_html_e( 'not available in this version', 'dms' ); ?>)</span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endforeach; ?>
				</div>
				<?php
				if ( $can_save ) {
					submit_button( $role ? __( 'Save role', 'dms' ) : __( 'Create role', 'dms' ) );
				}
				?>
			</form>
			<?php if ( $role && ! $system && current_user_can( 'roles.delete' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dms-confirm="<?php esc_attr_e( 'Delete this role? This cannot be undone.', 'dms' ); ?>">
					<?php echo AdminActions::fields( 'role_delete' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="role_id" value="<?php echo esc_attr( (string) $role_id ); ?>">
					<button type="submit" class="button dms-button-danger"><?php esc_html_e( 'Delete role', 'dms' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
