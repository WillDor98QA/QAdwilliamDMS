<?php
/**
 * Common interface for streaming tabular output (CSV, XLSX).
 *
 * @package DMS
 */

namespace DMS\Export;

defined( 'ABSPATH' ) || exit;

interface TableWriter {

	/**
	 * @param list<string>                                 $headers
	 * @param array{text_columns?:list<int>,width?:int}    $options Column formatting hints (XLSX only).
	 */
	public function add_sheet( string $name, array $headers, array $options = array() ): void;

	/** @param list<mixed> $values */
	public function add_row( array $values ): void;

	public function finish(): void;
}
