<?php
/**
 * Audit Logs (ARCH §11, MP §21). Visible only with audit.view; export needs audit.export.
 *
 * @package DMS
 */

namespace DMS\Admin\Pages;

use DMS\Admin\AdminActions;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class AuditPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'audit.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters, validated by AuditLogQuery.
		$filters = array_map( 'sanitize_text_field', array_intersect_key( (array) wp_unslash( $_GET ), array_flip( array( 'action', 'object_type', 'registration_number', 'date_from', 'date_to', 'paged' ) ) ) );
		$query   = $filters + array( 'page' => $filters['paged'] ?? 1 );
		unset( $query['paged'] );
		$query['action'] = $filters['action'] ?? '';
		$result          = $this->plugin->audit_log()->search( $query );
		$pages           = (int) ceil( $result['total'] / $result['per_page'] );
		?>
		<div class="wrap dms-wrap">
			<div class="dms-page-head">
				<div>
					<p class="dms-eyebrow"><?php esc_html_e( 'Traceability', 'dms' ); ?></p>
					<h1><?php esc_html_e( 'Audit Logs', 'dms' ); ?></h1>
					<p><?php esc_html_e( 'The permanent record of actions on registrations, users, imports and settings.', 'dms' ); ?></p>
				</div>
				<div class="dms-page-head__actions">
			<?php if ( current_user_can( 'audit.export' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dms-inline-form">
					<?php echo AdminActions::fields( 'audit_export' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="filters" value="<?php echo esc_attr( (string) wp_json_encode( $query ) ); ?>">
					<button type="submit" class="page-title-action"><?php esc_html_e( 'Export CSV', 'dms' ); ?></button>
				</form>
			<?php endif; ?>
				</div>
			</div>
			<hr class="wp-header-end">
			<form method="get" class="dms-filters">
				<input type="hidden" name="page" value="dms-audit">
				<label for="dms-audit-action"><?php esc_html_e( 'Action', 'dms' ); ?></label>
				<select id="dms-audit-action" name="action"><option value=""><?php esc_html_e( 'All actions', 'dms' ); ?></option>
					<?php foreach ( $this->plugin->audit_log()->actions() as $a ) : ?>
						<option value="<?php echo esc_attr( $a ); ?>" <?php selected( strtoupper( (string) ( $filters['action'] ?? '' ) ), $a ); ?>><?php echo esc_html( $a ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="dms-audit-number"><?php esc_html_e( 'Registration', 'dms' ); ?></label>
				<input type="text" id="dms-audit-number" name="registration_number" placeholder="REG-2026-000001" value="<?php echo esc_attr( (string) ( $filters['registration_number'] ?? '' ) ); ?>">
				<label for="dms-audit-from"><?php esc_html_e( 'From', 'dms' ); ?></label> <input type="date" id="dms-audit-from" name="date_from" value="<?php echo esc_attr( (string) ( $filters['date_from'] ?? '' ) ); ?>">
				<label for="dms-audit-to"><?php esc_html_e( 'To', 'dms' ); ?></label> <input type="date" id="dms-audit-to" name="date_to" value="<?php echo esc_attr( (string) ( $filters['date_to'] ?? '' ) ); ?>">
				<?php submit_button( __( 'Filter', 'dms' ), '', '', false ); ?>
			</form>
			<p class="description">
			<?php
			/* translators: %s: number of entries */
			echo esc_html( sprintf( __( '%s entries', 'dms' ), number_format_i18n( $result['total'] ) ) );
			?>
			</p>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Time', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Record', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Actor', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Status change', 'dms' ); ?></th><th scope="col"><?php esc_html_e( 'Reason / details', 'dms' ); ?></th></tr></thead>
				<tbody>
				<?php if ( array() === $result['items'] ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No audit entries match these filters.', 'dms' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $result['items'] as $e ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $e->created_at, get_option( 'date_format' ) . ' H:i:s' ) ); ?></td>
						<td><code><?php echo esc_html( $e->action ); ?></code></td>
						<td>
							<?php
							if ( $e->registration_number ) {
								echo esc_html( $e->registration_number );
							} else {
								echo esc_html( $e->object_type . ( $e->object_id ? ' #' . $e->object_id : '' ) );
							}
							?>
						</td>
						<td><?php echo esc_html( $e->actor_name ? $e->actor_name : ucfirst( strtolower( $e->actor_type ) ) ); ?></td>
						<td><?php echo esc_html( trim( ( $e->old_status ?? '' ) . ( $e->new_status ? ' → ' . $e->new_status : '' ) ) ); ?></td>
						<td class="dms-audit-details"><?php echo esc_html( (string) $e->reason ); ?>
						<?php
						if ( $e->metadata ) :
							?>
							<details><summary><?php esc_html_e( 'Details', 'dms' ); ?></summary><pre><?php echo esc_html( (string) wp_json_encode( json_decode( $e->metadata ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></details><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $result['page'],
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}
}
