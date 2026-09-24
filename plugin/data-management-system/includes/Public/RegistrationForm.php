<?php
/**
 * [dms_registration_form] — the public registration form (ARCH §32, MP §9, CR-01).
 *
 * Server-rendered, accessible markup in the fixed order:
 * Personal Information → Organization → Electoral Information → Consent →
 * Phone Verification (only when OTP is ON) → Submit.
 *
 * The script progressively enhances it (cascading selects, OTP step). It
 * also handles an "otp_required" reply even if the step was not rendered,
 * so a cached page stays correct after the OTP setting changes. The server
 * decides whether OTP applies; the client never does.
 *
 * @package DMS
 */

namespace DMS\Public;

use DMS\Api\PublicRegistrationController;
use DMS\Electoral\ElectoralLevel;
use DMS\Electoral\ElectoralRepository;
use DMS\Forms\FormDefinition;
use DMS\Security\Honeypot;
use DMS\Security\TurnstileVerifier;
use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

class RegistrationForm {

	public const SHORTCODE = 'dms_registration_form';


	public function __construct(
		private FormDefinition $form,
		private ElectoralRepository $electoral,
		private Settings $settings,
		private TurnstileVerifier $turnstile,
	) {
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	public function render(): string {
		$this->enqueue();
		$otp_on = $this->settings->bool( 'otp_enabled' );

		ob_start();
		?>
		<form class="dms-form" data-dms-form novalidate>
			<div class="dms-alert" data-dms-alert role="alert" aria-live="assertive" tabindex="-1" hidden></div>
			<?php
			$fields = $this->form->enabled_fields();
			$titles = array(
				'personal'     => __( 'Personal Information', 'dms' ),
				'organization' => __( 'Organization', 'dms' ),
				'electoral'    => __( 'Electoral Information', 'dms' ),
			);
			foreach ( $titles as $section => $title ) {
				$in_section = array_filter( $fields, static fn( array $f ): bool => $f['section'] === $section );
				if ( array() === $in_section ) {
					continue;
				}
				printf( '<fieldset class="dms-section" data-section="%1$s"><legend>%2$s</legend>', esc_attr( $section ), esc_html( $title ) );
				foreach ( $in_section as $field ) {
					$this->render_field( $field );
				}
				echo '</fieldset>';
			}
			?>
			<fieldset class="dms-section" data-section="consent">
				<legend><?php esc_html_e( 'Consent', 'dms' ); ?></legend>
				<div class="dms-field dms-field--checkbox">
					<input type="checkbox" id="dms-consent" name="consent" value="1" required aria-describedby="dms-consent-error">
					<label for="dms-consent"><?php esc_html_e( 'I consent to the collection and processing of my information for the purposes described in the privacy notice.', 'dms' ); ?> <span class="dms-required" aria-hidden="true">*</span></label>
					<p class="dms-error" id="dms-consent-error" data-dms-error-for="consent"></p>
				</div>
			</fieldset>

			<div class="dms-hp" aria-hidden="true">
				<label for="dms-hp-field"><?php esc_html_e( 'Leave this field empty', 'dms' ); ?></label>
				<input type="text" id="dms-hp-field" name="<?php echo esc_attr( Honeypot::FIELD ); ?>" tabindex="-1" autocomplete="off">
			</div>

			<?php if ( $this->turnstile->is_enabled() && null !== $this->turnstile->site_key() ) : ?>
				<div class="dms-captcha">
					<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( (string) $this->turnstile->site_key() ); ?>" data-response-field-name="captcha_token"></div>
					<p class="dms-error" data-dms-error-for="captcha"></p>
				</div>
			<?php endif; ?>

			<?php if ( $otp_on ) : ?>
				<?php echo $this->otp_step_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<?php endif; ?>

			<div class="dms-actions">
				<button type="submit" class="dms-submit" data-dms-submit><?php echo esc_html( $otp_on ? __( 'Continue to phone verification', 'dms' ) : __( 'Submit registration', 'dms' ) ); ?></button>
			</div>
		</form>
		<div class="dms-success" data-dms-success role="status" tabindex="-1" hidden></div>
		<template data-dms-otp-template><?php echo $this->otp_step_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></template>
		<?php
		return (string) ob_get_clean();
	}

	/** Markup of the Phone Verification step (hidden until the server asks for a code). */
	public function otp_step_markup(): string {
		return sprintf(
			'<fieldset class="dms-section dms-otp" data-dms-otp hidden>
				<legend>%1$s</legend>
				<p class="dms-otp-message" data-dms-otp-message></p>
				<div class="dms-field">
					<label for="dms-otp-code">%2$s <span class="dms-required" aria-hidden="true">*</span></label>
					<input type="text" id="dms-otp-code" name="otp_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" aria-describedby="dms-otp-error">
					<p class="dms-error" id="dms-otp-error" data-dms-error-for="otp_code"></p>
				</div>
				<button type="button" class="dms-link-button" data-dms-resend disabled>%3$s</button>
				<span class="dms-resend-timer" data-dms-resend-timer aria-live="polite"></span>
			</fieldset>',
			esc_html__( 'Phone Verification', 'dms' ),
			esc_html__( 'Verification code', 'dms' ),
			esc_html__( 'Resend code', 'dms' )
		);
	}

	/** @param array<string,mixed> $field */
	private function render_field( array $field ): void {
		$key      = (string) $field['key'];
		$id       = 'dms-' . str_replace( '_', '-', $key );
		$error_id = $id . '-error';
		$required = (bool) $field['required'];
		$attrs    = sprintf( 'id="%1$s" name="%2$s" aria-describedby="%3$s"%4$s', esc_attr( $id ), esc_attr( $key ), esc_attr( $error_id ), $required ? ' required aria-required="true"' : '' );

		echo '<div class="dms-field">';
		printf(
			'<label for="%1$s">%2$s%3$s</label>',
			esc_attr( $id ),
			esc_html( (string) $field['label'] ),
			$required ? ' <span class="dms-required" aria-hidden="true">*</span>' : ''
		);

		switch ( $field['type'] ) {
			case 'textarea':
				printf( '<textarea %1$s rows="3" maxlength="%2$d"></textarea>', $attrs, (int) $field['max'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs escaped above.
				break;
			case 'select':
				printf( '<select %s><option value="">%s</option>', $attrs, esc_html__( 'Select…', 'dms' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( (array) $field['options'] as $option ) {
					printf( '<option value="%1$s">%1$s</option>', esc_attr( (string) $option ) );
				}
				echo '</select>';
				break;
			case 'electoral':
				$this->render_electoral( $key, $attrs );
				break;
			default:
				$type  = array(
					'email'  => 'email',
					'date'   => 'date',
					'phone'  => 'tel',
					'number' => 'number',
				)[ $field['type'] ] ?? 'text';
				$extra = 'phone' === $field['type'] ? ' autocomplete="tel" inputmode="tel" placeholder="024 123 4567"' : ( 'email' === $field['type'] ? ' autocomplete="email"' : '' );
				printf( '<input type="%1$s" %2$s maxlength="%3$d"%4$s>', esc_attr( $type ), $attrs, (int) $field['max'], $extra ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		printf( '<p class="dms-error" id="%1$s" data-dms-error-for="%2$s"></p></div>', esc_attr( $error_id ), esc_attr( $key ) );
	}

	/** Regions are listed server-side (a handful); lower levels load on demand (ARCH §36). */
	private function render_electoral( string $key, string $attrs ): void {
		$level    = array(
			'region_id'          => 'region',
			'constituency_id'    => 'constituency',
			'polling_station_id' => 'polling_station',
		)[ $key ];
		$disabled = 'region_id' !== $key ? ' disabled' : '';
		printf( '<select %1$s data-dms-level="%2$s"%3$s><option value="">%4$s</option>', $attrs, esc_attr( $level ), $disabled, esc_html__( 'Select…', 'dms' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( 'region_id' === $key ) {
			foreach ( $this->electoral->options( ElectoralLevel::REGION ) as $option ) {
				printf( '<option value="%1$d">%2$s</option>', (int) $option['id'], esc_html( $option['name'] ) );
			}
		}
		echo '</select>';
	}

	private function enqueue(): void {
		$base = plugin_dir_url( DMS_PLUGIN_FILE ) . 'public/assets/';
		wp_enqueue_style( 'dms-registration-form', $base . 'registration-form.css', array(), DMS_VERSION );
		wp_enqueue_script( 'dms-registration-form', $base . 'registration-form.js', array(), DMS_VERSION, true );
		wp_localize_script(
			'dms-registration-form',
			'dmsRegistration',
			array(
				'restBase' => esc_url_raw( rest_url( PublicRegistrationController::NAMESPACE . '/public/' ) ),
				'i18n'     => array(
					'submit'       => __( 'Submit registration', 'dms' ),
					'continueOtp'  => __( 'Continue to phone verification', 'dms' ),
					'verifySubmit' => __( 'Verify and submit', 'dms' ),
					'working'      => __( 'Please wait…', 'dms' ),
					'select'       => __( 'Select…', 'dms' ),
					'loadFailed'   => __( 'Could not load options. Please try again.', 'dms' ),
					'fixErrors'    => __( 'Please correct the highlighted fields.', 'dms' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'dms' ),
					/* translators: %s: seconds remaining */
					'resendIn'     => __( 'You can request a new code in %s seconds.', 'dms' ),
					/* translators: %s: registration reference number */
					'success'      => __( 'Thank you. Your registration has been received. Your reference number is %s.', 'dms' ),
				),
			)
		);
		if ( $this->turnstile->is_enabled() ) {
			wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- versioned by Cloudflare.
		}
	}
}
