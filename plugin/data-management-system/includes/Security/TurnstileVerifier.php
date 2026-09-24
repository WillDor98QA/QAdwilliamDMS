<?php
/**
 * Cloudflare Turnstile server-side verification (Decisions §14).
 *
 * The site key is public; the secret comes from SecretStore
 * (DMS_TURNSTILE_SECRET_KEY constant, or the encrypted Settings value).
 * When Turnstile is enabled but cannot be verified (misconfigured, network
 * error) the check fails closed.
 *
 * @package DMS
 */

namespace DMS\Security;

use DMS\Support\Logger;
use DMS\Support\SecretStore;
use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

class TurnstileVerifier {

	public const VERIFY_URL   = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	public const SECRET_NAME  = 'turnstile_secret_key';
	public const SITEKEY_NAME = 'turnstile_site_key';

	public function __construct( private Settings $settings, private SecretStore $secrets, private Logger $logger ) {
	}

	public function is_enabled(): bool {
		return $this->settings->bool( 'turnstile_enabled' );
	}

	public function site_key(): ?string {
		return $this->secrets->get( self::SITEKEY_NAME );
	}

	/** @return list<string> Admin-safe problems; empty means ready. */
	public function configuration_problems(): array {
		$problems = array();
		if ( null === $this->site_key() ) {
			$problems[] = __( 'Turnstile site key is missing.', 'dms' );
		}
		if ( ! $this->secrets->has( self::SECRET_NAME ) ) {
			$problems[] = __( 'Turnstile secret key is missing.', 'dms' );
		}
		return $problems;
	}

	/** True when the check passes or Turnstile is switched off. */
	public function verify( string $token, string $ip ): bool {
		if ( ! $this->is_enabled() ) {
			return true;
		}
		$secret = $this->secrets->get( self::SECRET_NAME );
		if ( null === $secret || '' === $token ) {
			return false;
		}
		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => array_filter(
					array(
						'secret'   => $secret,
						'response' => $token,
						'remoteip' => $ip,
					)
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->logger->error( 'Turnstile verification request failed', array( 'error' => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ) );
			return false;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && true === ( $body['success'] ?? false );
	}
}
