<?php
/**
 * Structured operational logger (Master Prompt §38).
 *
 * Every entry carries a reference ID that can be shown to users in place of
 * internal details. Context is redacted before writing.
 *
 * @package DMS
 */

namespace DMS\Support;

defined( 'ABSPATH' ) || exit;

class Logger {

	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const INFO    = 'info';

	/** @var callable(string):void */
	private $writer;

	public function __construct( ?callable $writer = null ) {
		$this->writer = $writer ?? static function ( string $line ): void {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intended sink.
			error_log( $line );
		};
	}

	/**
	 * @param array<mixed> $context
	 * @return string Reference ID for correlating user-facing errors with log entries.
	 */
	public function log( string $level, string $message, array $context = array() ): string {
		$reference = self::new_reference();
		if ( self::INFO === $level && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return $reference;
		}
		$line = sprintf(
			'[DMS][%s][%s] %s %s',
			strtoupper( $level ),
			$reference,
			$message,
			$context ? (string) wp_json_encode( Redactor::redact( $context ) ) : ''
		);
		( $this->writer )( rtrim( $line ) );
		return $reference;
	}

	/** @param array<mixed> $context */
	public function error( string $message, array $context = array() ): string {
		return $this->log( self::ERROR, $message, $context );
	}

	/** @param array<mixed> $context */
	public function warning( string $message, array $context = array() ): string {
		return $this->log( self::WARNING, $message, $context );
	}

	/** @param array<mixed> $context */
	public function info( string $message, array $context = array() ): string {
		return $this->log( self::INFO, $message, $context );
	}

	public static function new_reference(): string {
		return 'REF-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
	}
}
