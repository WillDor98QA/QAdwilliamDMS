<?php
/**
 * Streaming CSV writer (UTF-8 with BOM so Excel detects the encoding).
 *
 * @package DMS
 */

namespace DMS\Export;

defined( 'ABSPATH' ) || exit;

final class CsvWriter implements TableWriter {

	/** @var resource */
	private $handle;

	public function __construct( string $path ) {
		$handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming large exports.
		if ( false === $handle ) {
			throw new \RuntimeException( 'Cannot open export file for writing.' );
		}
		$this->handle = $handle;
		fwrite( $this->handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	public function add_sheet( string $name, array $headers, array $options = array() ): void {
		$this->add_row( $headers );
	}

	public function add_row( array $values ): void {
		fputcsv( $this->handle, array_map( array( CellSanitizer::class, 'csv' ), $values ), ',', '"', '' );
	}

	public function finish(): void {
		fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
