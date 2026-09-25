<?php
/**
 * CR-01 — the rendered public form contains the OTP step only when OTP is ON,
 * and follows the ARCH §32 section order with consent and anti-bot fields.
 */

namespace DMS\Tests\Integration;

use DMS\Plugin;
use DMS\Security\Honeypot;

final class RegistrationFormRenderTest extends \WP_UnitTestCase {

	use PublicFlowFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_public_flow();
	}

	public function tear_down(): void {
		$this->tear_down_public_flow();
		parent::tear_down();
	}

	/** The <form> element only (the inert <template> used for late OTP activation is excluded). */
	private function form_html(): string {
		$html = do_shortcode( '[dms_registration_form]' );
		$this->assertSame( 1, preg_match( '#<form.*?</form>#s', $html, $m ) );
		return $m[0];
	}

	public function test_otp_step_absent_when_off(): void {
		$this->otp_off();
		$form = $this->form_html();
		$this->assertStringNotContainsString( 'data-dms-otp', $form );
		$this->assertStringNotContainsString( 'name="otp_code"', $form );
		$this->assertStringContainsString( 'Submit registration', $form );
	}

	public function test_otp_step_present_when_on(): void {
		$this->otp_on();
		$form = $this->form_html();
		$this->assertStringContainsString( 'data-dms-otp', $form );
		$this->assertStringContainsString( 'name="otp_code"', $form );
		$this->assertStringContainsString( 'autocomplete="one-time-code"', $form );
		$this->assertStringContainsString( 'Continue to phone verification', $form );
	}

	public function test_sections_follow_the_required_order(): void {
		$form = $this->form_html();
		$positions = array_map(
			static fn( string $s ): int => (int) strpos( $form, 'data-section="' . $s . '"' ),
			array( 'personal', 'organization', 'electoral', 'consent' )
		);
		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions );
		$this->assertNotContains( 0, $positions );
	}

	public function test_consent_honeypot_and_accessible_labels(): void {
		$form = $this->form_html();
		$this->assertStringContainsString( 'name="consent"', $form );
		$this->assertStringContainsString( 'name="' . Honeypot::FIELD . '"', $form );
		$this->assertStringContainsString( '<label for="dms-first-name">', $form );
		$this->assertStringContainsString( 'role="alert"', $form );
	}

	public function test_success_box_offers_register_another_and_return_home(): void {
		$html = do_shortcode( '[dms_registration_form]' );
		$this->assertSame( 1, preg_match( '#<div class="dms-success" data-dms-success tabindex="-1" hidden>.*?</div>\s*</div>#s', $html, $m ) );
		$box = $m[0];
		$this->assertStringContainsString( 'data-dms-success-message role="status"', $box );
		$this->assertMatchesRegularExpression( '#<button type="button"[^>]*data-dms-again>Register another person</button>#', $box );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/' ) ) . '">Return to home</a>', $box );
	}

	public function test_step_controls_render_hidden_until_the_script_takes_over(): void {
		$html = do_shortcode( '[dms_registration_form]' );
		$this->assertStringContainsString( '<ol class="dms-stepper" data-dms-stepper aria-label="Registration steps" hidden></ol>', $html );
		$this->assertMatchesRegularExpression( '#<button type="button"[^>]*data-dms-prev hidden>Back</button>#', $html );
		$this->assertMatchesRegularExpression( '#<button type="button"[^>]*data-dms-next hidden>Continue</button>#', $html );
		$this->assertMatchesRegularExpression( '#<button type="submit"[^>]*data-dms-submit>#', $html, 'Submit stays visible without JavaScript' );
		$this->assertStringContainsString( 'data-dms-step-status aria-live="polite"', $html );
	}

	public function test_regions_rendered_but_lower_levels_load_on_demand(): void {
		$form = $this->form_html();
		$this->assertStringContainsString( 'Region ', $form );
		$this->assertMatchesRegularExpression( '#data-dms-level="constituency" disabled>\s*<option value="">[^<]*</option>\s*</select>#', $form );
	}

	public function test_disabled_field_is_not_rendered_or_required(): void {
		update_option( \DMS\Forms\FormDefinition::OPTION, array( 'fields' => array( 'organization' => array( 'enabled' => false ) ) ) );
		$this->assertStringNotContainsString( 'name="organization"', $this->form_html() );
		$payload = $this->payload();
		unset( $payload['organization'] );
		$this->assertSame( 'created', Plugin::instance()->submissions()->submit( $payload )->status );
	}

	public function test_system_fields_cannot_be_disabled(): void {
		update_option( \DMS\Forms\FormDefinition::OPTION, array( 'fields' => array( 'phone' => array( 'enabled' => false, 'required' => false ) ) ) );
		$this->assertStringContainsString( 'name="phone"', $this->form_html() );
	}

	public function test_custom_field_is_validated_and_stored_in_extra_fields(): void {
		update_option(
			\DMS\Forms\FormDefinition::OPTION,
			array( 'custom' => array( array( 'key' => 'voter_id', 'label' => 'Voter ID', 'type' => 'text', 'required' => true ) ) )
		);
		$this->assertStringContainsString( 'name="custom_voter_id"', $this->form_html() );

		try {
			Plugin::instance()->submissions()->submit( $this->payload() );
			$this->fail( 'Required custom field must be enforced' );
		} catch ( \DMS\Errors\ValidationException $e ) {
			$this->assertArrayHasKey( 'custom_voter_id', $e->errors );
		}
		$result = Plugin::instance()->submissions()->submit( $this->payload( array( 'custom_voter_id' => 'V-123' ) ) );
		$this->assertSame( array( 'voter_id' => 'V-123' ), json_decode( Plugin::instance()->registrations()->get( $result->registration_id )->extra_fields, true ) );
	}
}
