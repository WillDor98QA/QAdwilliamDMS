<?php
/**
 * Form Builder / Field Configuration (ARCH §42–§44, Decisions §15).
 * Three fixed sections; system fields shown locked; custom personal fields.
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminActions;
use DMS\Forms\FormDefinition;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class FormBuilderPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'settings.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		$can    = current_user_can( 'settings.edit' );
		$off    = $can ? '' : ' disabled';
		$fields = $this->plugin->form_definition()->fields();
		$titles = array(
			'personal'     => __( 'Personal Information', 'dms' ),
			'organization' => __( 'Organization', 'dms' ),
			'electoral'    => __( 'Electoral Information', 'dms' ),
		);
		?>
		<div class="wrap dms-wrap">
			<h1><?php esc_html_e( 'Form Builder', 'dms' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Sections always appear in this order, followed by Consent. Phone, Region, Constituency and Polling Station are always required. Use [dms_registration_form] on any page to show the form.', 'dms' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php echo AdminActions::fields( 'form_save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php foreach ( $titles as $section => $title ) : ?>
					<h2><?php echo esc_html( $title ); ?></h2>
					<table class="widefat striped dms-form-fields">
						<thead><tr><th scope="col"><?php esc_html_e( 'Label', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Order', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Required', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Shown', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Options (one per line)', 'dms' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $fields as $f ) : ?>
							<?php
							if ( $f['section'] !== $section || $f['custom'] ) {
								continue;
							}
							$k    = $f['key'];
							$lock = $f['system'] ? ' disabled' : $off;
							?>
							<tr>
								<td><label class="screen-reader-text" for="dms-f-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $k ); ?></label><input type="text" id="dms-f-<?php echo esc_attr( $k ); ?>" name="fields[<?php echo esc_attr( $k ); ?>][label]" value="<?php echo esc_attr( $f['label'] ); ?>" class="regular-text"<?php echo esc_attr( $off ); ?>> <code><?php echo esc_html( $k ); ?></code><?php echo $f['system'] ? ' <span class="dms-badge">' . esc_html__( 'System', 'dms' ) . '</span>' : ''; ?></td>
								<td><input type="number" class="small-text" name="fields[<?php echo esc_attr( $k ); ?>][order]" value="<?php echo esc_attr( (string) $f['order'] ); ?>" aria-label="<?php esc_attr_e( 'Order', 'dms' ); ?>"<?php echo esc_attr( $off ); ?>></td>
								<td><input type="checkbox" name="fields[<?php echo esc_attr( $k ); ?>][required]" value="1" <?php checked( $f['required'] ); ?> aria-label="<?php esc_attr_e( 'Required', 'dms' ); ?>"<?php echo esc_attr( $lock ); ?>></td>
								<td><input type="checkbox" name="fields[<?php echo esc_attr( $k ); ?>][enabled]" value="1" <?php checked( $f['enabled'] ); ?> aria-label="<?php esc_attr_e( 'Shown', 'dms' ); ?>"<?php echo esc_attr( $lock ); ?>></td>
								<td>
								<?php
								if ( 'select' === $f['type'] ) :
									?>
									<textarea name="fields[<?php echo esc_attr( $k ); ?>][options]" rows="3" aria-label="<?php esc_attr_e( 'Options', 'dms' ); ?>"<?php echo esc_attr( $off ); ?>><?php echo esc_textarea( implode( "\n", $f['options'] ) ); ?></textarea><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>

				<h2><?php esc_html_e( 'Custom personal fields', 'dms' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Added to the Personal Information section. Removing a field hides it from the form; values already collected are kept.', 'dms' ); ?></p>
				<table class="widefat striped dms-form-fields">
					<thead><tr><th scope="col"><?php esc_html_e( 'Key', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Label', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Type', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Required', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Shown', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Options', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Remove', 'dms' ); ?></th></tr></thead>
					<tbody>
					<?php
					$custom   = $this->plugin->form_config()->current()['custom'];
					$custom[] = array(
						'key'      => '',
						'label'    => '',
						'type'     => 'text',
						'required' => false,
						'enabled'  => true,
						'options'  => array(),
					); // Blank row to add one.
					foreach ( $custom as $i => $c ) :
						?>
						<tr>
							<td><input type="text" name="custom[<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( (string) $c['key'] ); ?>" placeholder="voter_id" aria-label="<?php esc_attr_e( 'Key', 'dms' ); ?>"<?php echo esc_attr( $off ); ?>></td>
							<td><input type="text" name="custom[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( (string) $c['label'] ); ?>" aria-label="<?php esc_attr_e( 'Label', 'dms' ); ?>"<?php echo esc_attr( $off ); ?>></td>
							<td><select name="custom[<?php echo (int) $i; ?>][type]" aria-label="<?php esc_attr_e( 'Type', 'dms' ); ?>"<?php echo esc_attr( $off ); ?>>
								<?php foreach ( FormDefinition::CUSTOM_TYPES as $type ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $c['type'], $type ); ?>><?php echo esc_html( ucfirst( $type ) ); ?></option>
								<?php endforeach; ?>
							</select></td>
							<td><input type="checkbox" name="custom[<?php echo (int) $i; ?>][required]" value="1" <?php checked( ! empty( $c['required'] ) ); ?> aria-label="<?php esc_attr_e( 'Required', 'dms' ); ?>"<?php echo esc_attr( $off ); ?>></td>
							<td><input type="checkbox" name="custom[<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( ! empty( $c['enabled'] ) ); ?> aria-label="<?php esc_attr_e( 'Shown', 'dms' ); ?>"<?php echo esc_attr( $off ); ?>></td>
							<td><textarea name="custom[<?php echo (int) $i; ?>][options]" rows="2" aria-label="<?php esc_attr_e( 'Options', 'dms' ); ?>"<?php echo esc_attr( $off ); ?>><?php echo esc_textarea( implode( "\n", (array) $c['options'] ) ); ?></textarea></td>
							<td>
							<?php
							if ( '' !== $c['key'] ) :
								?>
								<input type="checkbox" name="custom[<?php echo (int) $i; ?>][remove]" value="1" aria-label="<?php esc_attr_e( 'Remove', 'dms' ); ?>"<?php echo esc_attr( $off ); ?>><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				if ( $can ) {
					submit_button( __( 'Save form', 'dms' ) );
				}
				?>
			</form>
		</div>
		<?php
	}
}
