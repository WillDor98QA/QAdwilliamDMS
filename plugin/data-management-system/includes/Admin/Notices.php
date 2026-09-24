<?php
/**
 * Per-user flash notices shown after a redirect (post/redirect/get).
 *
 * @package DMS
 */

namespace DMS\Admin;

use DMS\Errors\DmsException;
use DMS\Errors\ValidationException;

defined( 'ABSPATH' ) || exit;

final class Notices {

	private const KEY = 'dms_admin_notices_';

	public static function add( string $type, string $message, array $details = array() ): void {
		$key       = self::KEY . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
			'details' => array_values( array_map( 'strval', $details ) ),
		);
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	public static function success( string $message ): void {
		self::add( 'success', $message );
	}

	/** Records a safe, user-facing description of an expected error. */
	public static function from_exception( DmsException $e ): void {
		self::add( 'error', $e->getMessage(), $e instanceof ValidationException ? array_values( $e->errors ) : array() );
	}

	public static function render(): void {
		$key     = self::KEY . get_current_user_id();
		$notices = get_transient( $key );
		delete_transient( $key );
		foreach ( is_array( $notices ) ? $notices : array() as $n ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p>', esc_attr( 'error' === $n['type'] ? 'error' : 'success' ), esc_html( $n['message'] ) );
			if ( array() !== $n['details'] ) {
				echo '<ul class="dms-notice-list">';
				foreach ( $n['details'] as $d ) {
					printf( '<li>%s</li>', esc_html( $d ) );
				}
				echo '</ul>';
			}
			echo '</div>';
		}
	}
}
