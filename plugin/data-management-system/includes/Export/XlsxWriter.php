<?php
/**
 * Minimal streaming XLSX (Office Open XML) writer — implementation decision ID-33.
 *
 * Rows are streamed to temporary XML files and zipped at the end, so memory
 * use stays flat for large exports. Supports several sheets (the Phase 7
 * import template needs three), a bold header row, and text cells written
 * as inline strings. Excel never evaluates inline strings as formulas.
 *
 * @package DMS
 */

namespace DMS\Export;

defined( 'ABSPATH' ) || exit;

final class XlsxWriter implements TableWriter {

	/** @var list<array{name:string,file:string,rows:int}> */
	private array $sheets = array();

	/** @var resource|null */
	private $current = null;

	public function __construct( private string $path ) {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new \RuntimeException( 'The PHP zip extension is required for Excel export.' );
		}
	}

	public function add_sheet( string $name, array $headers, array $options = array() ): void {
		$this->close_sheet();
		$name = mb_substr( preg_replace( '#[\\\\/?*\[\]:]#', ' ', $name ), 0, 31 );
		$file = wp_tempnam( 'dms-sheet' );
		$fh   = fopen( $file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			throw new \RuntimeException( 'Cannot create temporary sheet file.' );
		}
		$cols = '';
		if ( isset( $options['width'] ) || isset( $options['text_columns'] ) ) {
			$count = max( count( $headers ), 1 );
			$text  = array_map( 'intval', $options['text_columns'] ?? array() );
			$cols  = '<cols>';
			for ( $i = 0; $i < $count; $i++ ) {
				// Style 2 = "@" text format: typed codes like 001 keep their leading zeros.
				$cols .= sprintf( '<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"%3$s/>', $i + 1, (int) ( $options['width'] ?? 20 ), in_array( $i, $text, true ) ? ' style="2"' : '' );
			}
			$cols .= '</cols>';
		}
		fwrite( $fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $cols . '<sheetData>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$this->current  = $fh;
		$this->sheets[] = array(
			'name' => '' !== trim( $name ) ? $name : 'Sheet' . ( count( $this->sheets ) + 1 ),
			'file' => $file,
			'rows' => 0,
		);
		$this->write_row( $headers, 1 );
	}

	public function add_row( array $values ): void {
		if ( null === $this->current ) {
			$this->add_sheet( 'Sheet1', array() );
		}
		$this->write_row( $values, 0 );
	}

	public function finish(): void {
		$this->close_sheet();
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $this->path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Cannot create Excel file.' );
		}
		$overrides = '';
		$workbook  = '';
		$rels      = '';
		foreach ( $this->sheets as $i => $sheet ) {
			$n          = $i + 1;
			$overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$workbook  .= '<sheet name="' . self::esc( $sheet['name'] ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$rels      .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$zip->addFile( $sheet['file'], 'xl/worksheets/sheet' . $n . '.xml' );
		}
		$styles_id = count( $this->sheets ) + 1;
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $overrides . '</Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $workbook . '</sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '<Relationship Id="rId' . $styles_id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
		$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf fontId="0"/><xf fontId="1" applyFont="1"/><xf fontId="0" numFmtId="49" applyNumberFormat="1"/></cellXfs></styleSheet>' );
		if ( ! $zip->close() ) {
			throw new \RuntimeException( 'Cannot write Excel file.' );
		}
		foreach ( $this->sheets as $sheet ) {
			wp_delete_file( $sheet['file'] );
		}
	}

	/** @param list<mixed> $values */
	private function write_row( array $values, int $style ): void {
		$row_number = ++$this->sheets[ count( $this->sheets ) - 1 ]['rows'];
		$xml        = '<row r="' . $row_number . '">';
		foreach ( array_values( $values ) as $col => $value ) {
			$ref = self::column( $col ) . $row_number;
			$s   = $style ? ' s="' . $style . '"' : '';
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) ) {
				$xml .= '<c r="' . $ref . '"' . $s . '><v>' . $value . '</v></c>';
			} else {
				$xml .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc( (string) $value ) . '</t></is></c>';
			}
		}
		fwrite( $this->current, $xml . '</row>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	private function close_sheet(): void {
		if ( null !== $this->current ) {
			fwrite( $this->current, '</sheetData></worksheet>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fclose( $this->current ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->current = null;
		}
	}

	/** 0 → A, 25 → Z, 26 → AA. */
	public static function column( int $index ): string {
		$name = '';
		for ( $n = $index + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$name = chr( 65 + ( ( $n - 1 ) % 26 ) ) . $name;
		}
		return $name;
	}

	/** XML-escapes and removes characters that are illegal in XML 1.0. */
	private static function esc( string $value ): string {
		$value = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
