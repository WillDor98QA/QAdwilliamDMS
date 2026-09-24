<?php
/**
 * Safe, streaming reader for the official .xlsx workbook (ARCH §49, §66
 * "protection against malicious spreadsheet content"). Implementation decision ID-41.
 *
 * Protections:
 * - Zip bombs: limits on entry count, total uncompressed size and per-entry
 *   compression ratio, checked before anything is decompressed.
 * - XML attacks: any DOCTYPE is rejected (no entities, no external
 *   resources). Parsing uses XMLReader with network access disabled.
 * - Formulas are never evaluated; only the cached value is read.
 *
 * Reads shared strings, inline strings, numbers and booleans. Returns rows
 * as positional string arrays with their spreadsheet row numbers.
 *
 * @package DMS
 */

namespace DMS\Imports;

defined( 'ABSPATH' ) || exit;

// XMLReader exposes camelCase properties (nodeType, localName, isEmptyElement).
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

class XlsxReader {

	public const MAX_ENTRIES           = 500;
	public const MAX_UNCOMPRESSED      = 200 * 1024 * 1024;
	public const MAX_COMPRESSION_RATIO = 250;

	private \ZipArchive $zip;

	/** @var list<string>|null */
	private ?array $shared = null;

	/** @var array<string,string>|null Lower-cased trimmed sheet name => zip path. */
	private ?array $sheets = null;

	/** @throws ImportFileException */
	public function __construct( private string $path ) {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new ImportFileException( __( 'The server cannot read Excel files (PHP zip extension missing).', 'dms' ) );
		}
		$this->zip = new \ZipArchive();
		if ( true !== $this->zip->open( $path, \ZipArchive::RDONLY ) ) {
			throw new ImportFileException( __( 'The file is not a valid Excel (.xlsx) workbook.', 'dms' ) );
		}
		$this->check_archive();
	}

	public function __destruct() {
		try {
			$this->zip->close();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- already closed.
			unset( $e );
		}
	}

	/** @return list<string> Sheet names as written in the workbook. */
	public function sheet_names(): array {
		return array_keys( $this->sheet_map( true ) );
	}

	public function has_sheet( string $name ): bool {
		return isset( $this->sheet_map()[ self::key( $name ) ] );
	}

	/**
	 * Streams the rows of one sheet.
	 *
	 * @return \Generator<int,list<string>> Spreadsheet row number => cell values by column position.
	 * @throws ImportFileException
	 */
	public function rows( string $sheet_name ): \Generator {
		$entry = $this->sheet_map()[ self::key( $sheet_name ) ] ?? null;
		if ( null === $entry ) {
			/* translators: %s: sheet name */
			throw new ImportFileException( sprintf( __( 'The workbook has no "%s" sheet.', 'dms' ), $sheet_name ) );
		}
		$shared = $this->shared_strings();
		$reader = $this->open_xml( $entry );

		$row_number = 0;
		$cells      = array();
		$in_row     = false;
		$col        = 0;
		$type       = '';
		$value      = null;
		$in_value   = false;
		$in_inline  = false;

		while ( $reader->read() ) {
			if ( \XMLReader::DOC_TYPE === $reader->nodeType ) {
				throw new ImportFileException( __( 'The workbook contains unsupported content and was rejected.', 'dms' ) );
			}
			$name = $reader->localName;
			if ( \XMLReader::ELEMENT === $reader->nodeType ) {
				if ( 'row' === $name ) {
					$r          = (int) $reader->getAttribute( 'r' );
					$row_number = $r > 0 ? $r : $row_number + 1;
					$cells      = array();
					$in_row     = true;
					$col        = 0;
					if ( $reader->isEmptyElement ) {
						$in_row = false;
						yield $row_number => array();
					}
				} elseif ( 'c' === $name && $in_row ) {
					$ref   = (string) $reader->getAttribute( 'r' );
					$col   = '' !== $ref ? self::column_index( $ref ) : count( $cells );
					$type  = (string) $reader->getAttribute( 't' );
					$value = '';
					if ( $reader->isEmptyElement ) {
						$cells[ $col ] = '';
					}
				} elseif ( 'v' === $name ) {
					$in_value = true;
				} elseif ( 'is' === $name ) {
					$in_inline = true;
				} elseif ( 't' === $name && $in_inline ) {
					$value .= $reader->readString();
				}
			} elseif ( \XMLReader::TEXT === $reader->nodeType || \XMLReader::CDATA === $reader->nodeType ) {
				if ( $in_value ) {
					$value .= $reader->value;
				}
			} elseif ( \XMLReader::END_ELEMENT === $reader->nodeType ) {
				if ( 'v' === $name ) {
					$in_value = false;
				} elseif ( 'is' === $name ) {
					$in_inline = false;
				} elseif ( 'c' === $name && $in_row ) {
					$cells[ $col ] = $this->cell_value( $type, (string) $value, $shared );
				} elseif ( 'row' === $name && $in_row ) {
					$in_row = false;
					yield $row_number => self::dense( $cells );
				}
			}
		}
		$reader->close();
	}

	/** Converts "BC12" to a zero-based column index (BC → 54). */
	public static function column_index( string $ref ): int {
		$letters = strtoupper( (string) preg_replace( '/\d+/', '', $ref ) );
		$index   = 0;
		foreach ( str_split( $letters ) as $ch ) {
			$index = $index * 26 + ( ord( $ch ) - 64 );
		}
		return max( 0, $index - 1 );
	}

	/** @param list<string> $shared */
	private function cell_value( string $type, string $raw, array $shared ): string {
		switch ( $type ) {
			case 's':
				return (string) ( $shared[ (int) $raw ] ?? '' );
			case 'b':
				return '1' === $raw ? 'TRUE' : 'FALSE';
			case 'inlineStr':
			case 'str':
			case 'e':
				return $raw;
			default:
				// Numbers: show integers without ".0" or exponent, e.g. 12.0 → "12".
				if ( is_numeric( $raw ) && floor( (float) $raw ) === (float) $raw && abs( (float) $raw ) < 1e15 ) {
					return (string) (int) round( (float) $raw );
				}
				return $raw;
		}
	}

	/**
	 * @param array<int,string> $cells
	 * @return list<string>
	 */
	private static function dense( array $cells ): array {
		if ( array() === $cells ) {
			return array();
		}
		$out = array_fill( 0, max( array_keys( $cells ) ) + 1, '' );
		foreach ( $cells as $i => $v ) {
			$out[ $i ] = $v;
		}
		return $out;
	}

	/** @return list<string> */
	private function shared_strings(): array {
		if ( null !== $this->shared ) {
			return $this->shared;
		}
		$this->shared = array();
		if ( false === $this->zip->locateName( 'xl/sharedStrings.xml' ) ) {
			return $this->shared;
		}
		$reader  = $this->open_xml( 'xl/sharedStrings.xml' );
		$current = null;
		while ( $reader->read() ) {
			if ( \XMLReader::DOC_TYPE === $reader->nodeType ) {
				throw new ImportFileException( __( 'The workbook contains unsupported content and was rejected.', 'dms' ) );
			}
			if ( \XMLReader::ELEMENT === $reader->nodeType ) {
				if ( 'si' === $reader->localName ) {
					$current = '';
					if ( $reader->isEmptyElement ) {
						$this->shared[] = '';
						$current        = null;
					}
				} elseif ( 't' === $reader->localName && null !== $current ) {
					$current .= $reader->readString();
				} elseif ( 'rPh' === $reader->localName ) {
					$reader->next(); // Skip phonetic hints.
				}
			} elseif ( \XMLReader::END_ELEMENT === $reader->nodeType && 'si' === $reader->localName && null !== $current ) {
				$this->shared[] = $current;
				$current        = null;
			}
		}
		$reader->close();
		return $this->shared;
	}

	/** @return array<string,string> */
	private function sheet_map( bool $original_names = false ): array {
		if ( null === $this->sheets ) {
			$workbook = $this->xml_string( 'xl/workbook.xml' );
			$rels     = $this->xml_string( 'xl/_rels/workbook.xml.rels' );

			$targets = array();
			foreach ( $rels->children() as $rel ) {
				$target                         = (string) $rel['Target'];
				$target                         = str_starts_with( $target, '/' ) ? ltrim( $target, '/' ) : 'xl/' . $target;
				$targets[ (string) $rel['Id'] ] = $target;
			}
			$this->sheets = array();
			$namespaces   = $workbook->getNamespaces( true );
			$r_ns         = $namespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
			foreach ( $workbook->sheets->sheet ?? array() as $sheet ) {
				$id = (string) $sheet->attributes( $r_ns )['id'];
				if ( isset( $targets[ $id ] ) ) {
					$this->sheets[ (string) $sheet['name'] ] = $targets[ $id ];
				}
			}
		}
		if ( $original_names ) {
			return $this->sheets;
		}
		$keyed = array();
		foreach ( $this->sheets as $name => $path ) {
			$keyed[ self::key( $name ) ] = $path;
		}
		return $keyed;
	}

	private static function key( string $name ): string {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ) );
	}

	/** @throws ImportFileException */
	private function check_archive(): void {
		$entries = $this->zip->numFiles;
		if ( $entries < 1 || $entries > self::MAX_ENTRIES ) {
			throw new ImportFileException( __( 'The file is not a valid Excel (.xlsx) workbook.', 'dms' ) );
		}
		$total = 0;
		for ( $i = 0; $i < $entries; $i++ ) {
			$stat = $this->zip->statIndex( $i );
			if ( false === $stat ) {
				throw new ImportFileException( __( 'The file is not a valid Excel (.xlsx) workbook.', 'dms' ) );
			}
			$total += (int) $stat['size'];
			$ratio  = (int) $stat['comp_size'] > 0 ? (int) $stat['size'] / (int) $stat['comp_size'] : 0;
			if ( $total > self::MAX_UNCOMPRESSED || $ratio > self::MAX_COMPRESSION_RATIO || str_contains( (string) $stat['name'], '..' ) ) {
				throw new ImportFileException( __( 'The workbook is too large or malformed and was rejected.', 'dms' ) );
			}
		}
		foreach ( array( 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels' ) as $required ) {
			if ( false === $this->zip->locateName( $required ) ) {
				throw new ImportFileException( __( 'The file is not a valid Excel (.xlsx) workbook.', 'dms' ) );
			}
		}
	}

	/** Small XML parts (workbook, relationships) parsed with SimpleXML after a DOCTYPE check. */
	private function xml_string( string $entry ): \SimpleXMLElement {
		$xml = $this->zip->getFromName( $entry );
		if ( false === $xml || preg_match( '/<!DOCTYPE/i', $xml ) ) {
			throw new ImportFileException( __( 'The workbook contains unsupported content and was rejected.', 'dms' ) );
		}
		$previous = libxml_use_internal_errors( true );
		$parsed   = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NONET );
		libxml_use_internal_errors( $previous );
		if ( false === $parsed ) {
			throw new ImportFileException( __( 'The file is not a valid Excel (.xlsx) workbook.', 'dms' ) );
		}
		return $parsed;
	}

	private function open_xml( string $entry ): \XMLReader {
		$reader = new \XMLReader();
		if ( ! $reader->open( 'zip://' . $this->path . '#' . $entry, null, LIBXML_NONET ) ) {
			throw new ImportFileException( __( 'The workbook could not be read.', 'dms' ) );
		}
		$reader->setParserProperty( \XMLReader::SUBST_ENTITIES, false );
		return $reader;
	}
}
