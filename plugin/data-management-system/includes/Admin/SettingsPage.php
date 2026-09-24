<?php
/**
 * Data Management → Settings (CR-01 SMS / verification configuration).
 *
 * Viewing requires settings.view; saving requires settings.edit and a valid
 * nonce, and every change goes through SettingsService (validation,
 * dependency checks, audit). Credentials are write-only: the page shows
 * whether each one is set, never its value.
 *
 * @package DMS
 */

namespace DMS\Admin;

use DMS\Errors\DmsException;
use DMS\Errors\ValidationException;
use DMS\Security\TurnstileVerifier;
use DMS\Sms\SmsConfiguration;
use DMS\Sms\SmsGatewayRegistry;
use DMS\Support\SecretStore;
use DMS\Support\Settings;
use DMS\Support\SettingsService;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	public const SLUG   = 'dms-settings';
	public const ACTION = 'dms_save_settings';

	private const NOTICE_TRANSIENT = 'dms_settings_notice_';

	/** @var array<string,mixed> Values typed in a refused save, shown once (UI-03). */
	private array $draft = array();

	public function __construct(
		private Settings $settings,
		private SettingsService $service,
		private SecretStore $secrets,
		private SmsGatewayRegistry $gateways,
		private SmsConfiguration $sms,
		private TurnstileVerifier $turnstile,
	) {
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'admin_notices', array( $this, 'health_notice' ) );
	}

	/** Shown on every admin screen to people who can act on it (CR-01: do not fail silently). */
	public function health_notice(): void {
		if ( ! current_user_can( 'settings.view' ) ) {
			return;
		}
		foreach ( $this->service->health() as $issue ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Data Management:', 'dms' ),
				esc_html( $issue ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
				esc_html__( 'Open settings', 'dms' )
			);
		}
	}

	public function save(): void {
		if ( ! current_user_can( 'settings.edit' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'dms' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above; each value is validated by SettingsService.
		$errors = array();
		try {
			// Credentials and settings are saved together; any failure restores both (R-03).
			$credentials = array();
			foreach ( $this->service->credential_names() as $name ) {
				if ( ! empty( $post['clear'][ $name ] ) ) {
					$credentials[ $name ] = '';
				} elseif ( isset( $post['credential'][ $name ] ) && '' !== trim( (string) $post['credential'][ $name ] ) ) {
					$credentials[ $name ] = (string) $post['credential'][ $name ];
				}
			}
			$values = array();
			foreach ( SettingsService::editable_keys() as $key ) {
				if ( in_array( $key, array( 'otp_enabled', 'turnstile_enabled' ), true ) ) {
					$values[ $key ] = ! empty( $post['settings'][ $key ] );
				} elseif ( isset( $post['settings'][ $key ] ) ) {
					$values[ $key ] = $post['settings'][ $key ];
				}
			}
			$this->service->save_all( $credentials, $values );
		} catch ( ValidationException $e ) {
			$errors = $e->errors;
		} catch ( DmsException $e ) {
			$errors = array( 'form' => $e->getMessage() );
		}

		// A refused save keeps what was typed (UI-03). Credentials are never kept.
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array() === $errors ? array( 'ok' => true ) : array(
				'errors' => $errors,
				'draft'  => $values ?? array(),
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'settings.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dms' ), 403 );
		}
		$can_edit = current_user_can( 'settings.edit' );
		$notice   = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		$disabled    = $can_edit ? '' : ' disabled';
		$this->draft = is_array( $notice ) && is_array( $notice['draft'] ?? null ) ? $notice['draft'] : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Data Management Settings', 'dms' ); ?></h1>

			<?php if ( is_array( $notice ) && ! empty( $notice['ok'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'dms' ); ?></p></div>
			<?php elseif ( is_array( $notice ) && ! empty( $notice['errors'] ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Settings were not saved:', 'dms' ); ?></p><ul>
					<?php foreach ( $notice['errors'] as $message ) : ?>
						<li><?php echo esc_html( (string) $message ); ?></li>
					<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>

				<h2><?php esc_html_e( 'Registration Verification', 'dms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Allow OTP Verification', 'dms' ); ?></th>
						<td>
							<label><input type="checkbox" name="settings[otp_enabled]" value="1" <?php checked( (bool) $this->shown( 'otp_enabled' ) ); ?><?php echo esc_attr( $disabled ); ?>>
							<?php esc_html_e( 'Require applicants to verify their phone number with a one-time code sent by SMS', 'dms' ); ?></label>
							<p class="description"><?php esc_html_e( 'When off, the form has no verification step and registrations are saved with the phone marked as not verified. When on, a registration is only created after the code is verified.', 'dms' ); ?></p>
							<?php $this->status_line( $this->sms->problems(), __( 'SMS is ready — OTP can be switched on.', 'dms' ) ); ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'SMS Provider', 'dms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dms-sms-gateway"><?php esc_html_e( 'Provider', 'dms' ); ?></label></th>
						<td>
							<select id="dms-sms-gateway" name="settings[sms_gateway]"<?php echo esc_attr( $disabled ); ?>>
								<option value=""><?php esc_html_e( '— Not configured —', 'dms' ); ?></option>
								<?php foreach ( $this->gateways->all() as $id => $gateway ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $this->shown( 'sms_gateway' ), $id ); ?>><?php echo esc_html( $gateway->label() ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php $this->text_row( 'sms_sender_id', __( 'Sender ID', 'dms' ), __( 'Up to 11 letters or digits, as registered with your provider.', 'dms' ), $disabled ); ?>
					<?php $this->text_row( 'otp_message_template', __( 'Code message', 'dms' ), __( 'Must contain {code}. {minutes} is replaced with the expiry time.', 'dms' ), $disabled ); ?>
					<?php
					foreach ( $this->gateways->all() as $gateway ) {
						foreach ( $gateway->fields() as $field ) {
							$this->credential_row( $field['key'], $gateway->label() . ' — ' . $field['label'], $can_edit );
						}
					}
					?>
				</table>

				<h2><?php esc_html_e( 'OTP Limits', 'dms' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->number_row( 'otp_expiry_seconds', __( 'Code expiry (seconds)', 'dms' ), $disabled );
					$this->number_row( 'otp_max_attempts', __( 'Attempts per code', 'dms' ), $disabled );
					$this->number_row( 'otp_resend_cooldown_seconds', __( 'Resend cooldown (seconds)', 'dms' ), $disabled );
					$this->number_row( 'otp_max_sends_per_phone_hour', __( 'Codes per phone per hour', 'dms' ), $disabled );
					$this->number_row( 'otp_max_sends_per_phone_day', __( 'Codes per phone per day', 'dms' ), $disabled );
					$this->number_row( 'otp_max_sends_per_ip_hour', __( 'Codes per IP address per hour', 'dms' ), $disabled );
					?>
				</table>

				<h2><?php esc_html_e( 'Anti-bot Protection', 'dms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Cloudflare Turnstile', 'dms' ); ?></th>
						<td>
							<label><input type="checkbox" name="settings[turnstile_enabled]" value="1" <?php checked( array_key_exists( 'turnstile_enabled', $this->draft ) ? (bool) $this->draft['turnstile_enabled'] : $this->turnstile->is_enabled() ); ?><?php echo esc_attr( $disabled ); ?>> <?php esc_html_e( 'Require the Turnstile check on public submissions', 'dms' ); ?></label>
							<?php $this->status_line( $this->turnstile->configuration_problems(), __( 'Turnstile keys are present.', 'dms' ) ); ?>
						</td>
					</tr>
					<?php
					$this->credential_row( TurnstileVerifier::SITEKEY_NAME, __( 'Turnstile site key', 'dms' ), $can_edit );
					$this->credential_row( TurnstileVerifier::SECRET_NAME, __( 'Turnstile secret key', 'dms' ), $can_edit );
					?>
				</table>

				<h2><?php esc_html_e( 'Public Limits & Notifications', 'dms' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->number_row( 'submission_max_per_ip_hour', __( 'Submissions per IP address per hour', 'dms' ), $disabled );
					$this->number_row( 'lookup_max_per_ip_minute', __( 'Electoral lookups per IP address per minute', 'dms' ), $disabled );
					?>
					<tr>
						<th scope="row"><label for="dms-admin-emails"><?php esc_html_e( 'New-registration emails go to', 'dms' ); ?></label></th>
						<td><input type="text" class="regular-text" id="dms-admin-emails" name="settings[admin_notification_emails]" value="<?php echo esc_attr( is_string( $this->shown( 'admin_notification_emails' ) ) ? $this->shown( 'admin_notification_emails' ) : implode( ', ', (array) $this->shown( 'admin_notification_emails' ) ) ); ?>"<?php echo esc_attr( $disabled ); ?>>
						<p class="description"><?php esc_html_e( 'Comma-separated email addresses.', 'dms' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Data Retention', 'dms' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php $this->number_row( 'import_file_retention_days', __( 'Keep uploaded electoral workbooks for (days)', 'dms' ), $disabled ); ?>
					<tr><td colspan="2" class="description" style="padding-left:0"><?php esc_html_e( 'After an import has finished, its uploaded file is deleted after this many days. The import history, its changes and rollback stay available. 0 keeps files forever.', 'dms' ); ?></td></tr>
				</table>

				<?php if ( $can_edit ) : ?>
					<?php submit_button( __( 'Save settings', 'dms' ) ); ?>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/** Value to show: what was just typed when a save was refused (UI-03), else the stored value. */
	private function shown( string $key ): mixed {
		if ( array_key_exists( $key, $this->draft ) && is_scalar( $this->draft[ $key ] ) ) {
			return $this->draft[ $key ];
		}
		return $this->settings->get( $key );
	}

	/** @param list<string> $problems */
	private function status_line( array $problems, string $ok ): void {
		if ( array() === $problems ) {
			printf( '<p class="dms-status dms-status--ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> %s</p>', esc_html( $ok ) );
			return;
		}
		printf( '<p class="dms-status dms-status--problem"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %s</p>', esc_html( implode( ' ', $problems ) ) );
	}

	private function text_row( string $key, string $label, string $help, string $disabled ): void {
		printf(
			'<tr><th scope="row"><label for="dms-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="dms-%1$s" name="settings[%1$s]" value="%3$s"%4$s><p class="description">%5$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( (string) $this->shown( $key ) ),
			esc_attr( $disabled ),
			esc_html( $help )
		);
	}

	private function number_row( string $key, string $label, string $disabled ): void {
		printf(
			'<tr><th scope="row"><label for="dms-%1$s">%2$s</label></th><td><input type="number" class="small-text" id="dms-%1$s" name="settings[%1$s]" value="%3$s"%4$s></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( (string) $this->shown( $key ) ),
			esc_attr( $disabled )
		);
	}

	/** Write-only credential input: shows the source/state, never the value. */
	private function credential_row( string $name, string $label, bool $can_edit ): void {
		$locked = $this->secrets->is_locked_by_config( $name );
		$has    = $this->secrets->has( $name );
		$state  = $locked
			/* translators: %s: constant name */
			? sprintf( __( 'Set in wp-config.php (%s).', 'dms' ), SecretStore::constant_name( $name ) )
			: ( $has ? __( 'Saved. Enter a new value to replace it.', 'dms' ) : __( 'Not set.', 'dms' ) );
		$input_disabled = ( $locked || ! $can_edit ) ? ' disabled' : '';
		printf(
			'<tr><th scope="row"><label for="dms-cred-%1$s">%2$s</label></th><td><input type="password" class="regular-text" id="dms-cred-%1$s" name="credential[%1$s]" value="" autocomplete="new-password"%3$s><p class="description">%4$s</p>%5$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $input_disabled ),
			esc_html( $state ),
			( $has && ! $locked && $can_edit ) ? sprintf( '<label><input type="checkbox" name="clear[%1$s]" value="1"> %2$s</label>', esc_attr( $name ), esc_html__( 'Remove saved value', 'dms' ) ) : ''
		);
	}
}
