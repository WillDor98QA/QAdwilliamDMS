<?php
/**
 * Public, unauthenticated REST endpoints for the registration form.
 *
 *   GET  dms/v1/public/form                                    form definition + runtime flags
 *   GET  dms/v1/public/regions                                 cascade level 1
 *   GET  dms/v1/public/regions/{id}/constituencies             cascade level 2
 *   GET  dms/v1/public/constituencies/{id}/polling-stations    cascade level 3
 *   POST dms/v1/public/registrations                           submit (and OTP step)
 *   POST dms/v1/public/registrations/otp/resend                resend code
 *
 * These are public by design (permission_callback is explicit, not missing).
 * Abuse controls: per-IP rate limits on lookups and submissions, Turnstile +
 * honeypot on new submissions, OTP limits, and a form nonce on every POST
 * (fetched fresh from /public/form, so cached pages do not break it).
 *
 * @package DMS
 */

namespace DMS\Api;

use DMS\Electoral\ElectoralLevel;
use DMS\Electoral\ElectoralRepository;
use DMS\Errors\AuthorizationException;
use DMS\Forms\FormDefinition;
use DMS\Registrations\RegistrationSubmissionService;
use DMS\Security\Honeypot;
use DMS\Security\RateLimiter;
use DMS\Security\TurnstileVerifier;
use DMS\Support\RequestContext;
use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

class PublicRegistrationController {

	public const NAMESPACE    = 'dms/v1';
	public const NONCE_ACTION = 'dms_public_registration';

	public function __construct(
		private RegistrationSubmissionService $submissions,
		private ElectoralRepository $electoral,
		private FormDefinition $form,
		private TurnstileVerifier $turnstile,
		private RateLimiter $limiter,
		private Settings $settings,
		private RequestContext $context,
		private RestResponder $responder,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$public = '__return_true'; // Intentionally public endpoints; see class docblock.
		// WordPress calls validate_callback with (value, request, key); PHP 8 built-ins such as is_numeric() reject the extra arguments.
		$id_arg = array( 'id' => array( 'validate_callback' => static fn( $value ): bool => (bool) preg_match( '/^\d+$/', (string) $value ) ) );

		register_rest_route(
			self::NAMESPACE,
			'/public/form',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'form' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/public/regions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'regions' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/public/regions/(?P<id>\d+)/constituencies',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'constituencies' ),
				'permission_callback' => $public,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/public/constituencies/(?P<id>\d+)/polling-stations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'polling_stations' ),
				'permission_callback' => $public,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/public/registrations',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/public/registrations/otp/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resend' ),
				'permission_callback' => $public,
			)
		);
	}

	/** Public form description. Contains no secrets: only the Turnstile *site* key. */
	public function form(): \WP_REST_Response {
		return $this->responder->run(
			fn() => array(
				'fields'    => array_map(
					static fn( array $f ): array => array_intersect_key( $f, array_flip( array( 'key', 'section', 'label', 'type', 'required', 'options' ) ) ),
					$this->form->enabled_fields()
				),
				'otp'       => array( 'enabled' => $this->submissions->otp_enabled() ),
				'turnstile' => array(
					'enabled'  => $this->turnstile->is_enabled(),
					'site_key' => $this->turnstile->is_enabled() ? $this->turnstile->site_key() : null,
				),
				'honeypot'  => Honeypot::FIELD,
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	public function regions(): \WP_REST_Response {
		return $this->responder->run(
			function () {
				$this->limit_lookups();
				return $this->electoral->options( ElectoralLevel::REGION );
			}
		);
	}

	public function constituencies( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->responder->run(
			function () use ( $request ) {
				$this->limit_lookups();
				return $this->electoral->options( ElectoralLevel::CONSTITUENCY, absint( $request['id'] ) );
			}
		);
	}

	public function polling_stations( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->responder->run(
			function () use ( $request ) {
				$this->limit_lookups();
				return $this->electoral->options( ElectoralLevel::POLLING_STATION, absint( $request['id'] ) );
			}
		);
	}

	public function submit( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->responder->run(
			function () use ( $request ) {
				$this->verify_nonce( $request );
				$result = $this->submissions->submit( $this->input( $request ) );
				return new \WP_REST_Response( $result->to_public_array(), 'created' === $result->status ? 201 : 202 );
			}
		);
	}

	public function resend( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->responder->run(
			function () use ( $request ) {
				$this->verify_nonce( $request );
				$result = $this->submissions->resend_otp( (string) $request->get_param( 'otp_request_id' ), (string) $request->get_param( 'phone' ) );
				return new \WP_REST_Response( $result->to_public_array(), 202 );
			}
		);
	}

	/** @return array<string,mixed> */
	private function input( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || array() === $params ) {
			$params = $request->get_body_params();
		}
		return is_array( $params ) ? $params : array();
	}

	private function verify_nonce( \WP_REST_Request $request ): void {
		$nonce = (string) ( $request->get_header( 'x_dms_nonce' ) ?? $request->get_param( '_dms_nonce' ) ?? '' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			throw new AuthorizationException( __( 'Your session has expired. Please reload the page and try again.', 'dms' ) );
		}
	}

	private function limit_lookups(): void {
		$ip = $this->context->ip();
		if ( '' !== $ip ) {
			$this->limiter->hit( RateLimiter::bucket( 'lookup_ip_m', $ip ), $this->settings->int( 'lookup_max_per_ip_minute' ), MINUTE_IN_SECONDS );
		}
	}
}
