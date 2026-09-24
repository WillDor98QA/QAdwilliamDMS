<?php
/**
 * UI-03: when a Settings or Email Templates save is refused, the page shows
 * what was typed (to correct it) instead of clearing it. Secrets are never kept.
 */

namespace DMS\Tests\Integration;

use DMS\Admin\SettingsPage;
use DMS\Plugin;

final class RefusedSaveDraftTest extends \WP_UnitTestCase {

	use Fixtures;

	public function set_up(): void {
		parent::set_up();
		delete_option( \DMS\Support\Settings::OPTION );
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
		$this->act_as_admin();
		// save() redirects then exits; stop at the redirect instead.
		add_filter(
			'wp_redirect',
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function render( callable $cb ): string {
		ob_start();
		$cb();
		return (string) ob_get_clean();
	}

	public function test_refused_settings_save_keeps_typed_values_but_not_secrets(): void {
		$_POST    = array(
			'_wpnonce'   => wp_create_nonce( SettingsPage::ACTION ),
			'settings'   => array(
				'otp_max_attempts'           => '0', // Out of range: the save is refused.
				'submission_max_per_ip_hour' => '77',
				'sms_sender_id'              => 'MYORG',
				'admin_notification_emails'  => 'a@example.org, b@example.org',
			),
			'credential' => array( 'turnstile_secret_key' => 'SUPER-SECRET-VALUE' ),
		);
		$_REQUEST = $_POST;
		try {
			Plugin::instance()->settings_page()->save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		$this->assertSame( 10, Plugin::instance()->settings()->int( 'submission_max_per_ip_hour' ), 'Nothing was saved' );

		$html = $this->render( array( Plugin::instance()->settings_page(), 'render' ) );
		$this->assertStringContainsString( 'Settings were not saved', $html );
		$this->assertMatchesRegularExpression( '/name="settings\[submission_max_per_ip_hour\]" value="77"/', $html );
		$this->assertMatchesRegularExpression( '/name="settings\[otp_max_attempts\]" value="0"/', $html );
		$this->assertStringContainsString( 'value="MYORG"', $html );
		$this->assertStringContainsString( 'a@example.org, b@example.org', $html );
		$this->assertStringNotContainsString( 'SUPER-SECRET-VALUE', $html );
		$this->assertStringNotContainsString( 'SUPER-SECRET-VALUE', (string) wp_json_encode( wp_load_alloptions() ), 'Secrets are not parked anywhere' );

		$again = $this->render( array( Plugin::instance()->settings_page(), 'render' ) );
		$this->assertMatchesRegularExpression( '/name="settings\[submission_max_per_ip_hour\]" value="10"/', $again, 'Shown once; a reload shows the saved values' );
	}

	public function test_refused_template_save_keeps_typed_text(): void {
		Plugin::instance()->admin_actions()->handle(
			'email_templates_save',
			array( 'templates' => array( 'REGISTRATION_APPROVED' => array( 'subject' => 'Approved {site_name}', 'body' => 'Hello {password}, a long message I typed' ) ) )
		);
		$_GET = array( 'tab' => 'templates' );
		$html = $this->render( array( Plugin::instance()->page_notifications(), 'render' ) );
		$_GET = array();
		$this->assertStringContainsString( 'Hello {password}, a long message I typed', $html );
		$this->assertStringContainsString( 'value="Approved {site_name}"', $html );
		$this->assertFalse( Plugin::instance()->email_templates()->is_customised( 'REGISTRATION_APPROVED' ), 'Nothing was saved' );
	}
}
