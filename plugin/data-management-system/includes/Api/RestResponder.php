<?php
/**
 * Maps outcomes to structured REST responses (MP §36, §38).
 *
 * Expected errors keep their safe message and status. Anything unexpected is
 * logged and answered with a generic message plus a reference ID — never a
 * stack trace, SQL or configuration detail.
 *
 * @package DMS
 */

namespace DMS\Api;

use DMS\Errors\DmsException;
use DMS\Errors\RateLimitedException;
use DMS\Errors\ValidationException;
use DMS\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class RestResponder {

	public function __construct( private Logger $logger ) {
	}

	/**
	 * @param callable():mixed $action Returns the response data, or a WP_REST_Response.
	 */
	public function run( callable $action, int $success_status = 200 ): \WP_REST_Response {
		try {
			$result = $action();
			return $result instanceof \WP_REST_Response ? $result : new \WP_REST_Response( $result, $success_status );
		} catch ( DmsException $e ) {
			$body = array(
				'code'    => $e->error_code(),
				'message' => $e->getMessage(),
			);
			if ( $e instanceof ValidationException ) {
				$body['errors'] = $e->errors;
			}
			$response = new \WP_REST_Response( $body, $e->http_status() );
			if ( $e instanceof RateLimitedException ) {
				$response->header( 'Retry-After', (string) $e->retry_after );
				$body['retry_after'] = $e->retry_after;
				$response->set_data( $body );
			}
			return $response;
		} catch ( \Throwable $e ) {
			$reference = $this->logger->error(
				'Unhandled REST error',
				array(
					'exception' => get_class( $e ),
					'error'     => $e->getMessage(),
					'file'      => $e->getFile() . ':' . $e->getLine(),
				)
			);
			return new \WP_REST_Response(
				array(
					'code'      => 'dms_internal_error',
					/* translators: %s: error reference */
					'message'   => sprintf( __( 'Something went wrong. Please try again later. Reference: %s', 'dms' ), $reference ),
					'reference' => $reference,
				),
				500
			);
		}
	}
}
