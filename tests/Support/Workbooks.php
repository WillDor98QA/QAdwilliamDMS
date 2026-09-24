<?php
/**
 * Builds .xlsx files for import tests: normal workbooks via XlsxWriter, and
 * hand-made archives for Excel-style shared strings and malicious content.
 */

namespace DMS\Tests\Support;

use DMS\Export\XlsxWriter;

final class Workbooks {

	/** @var list<string> */
	private static array $files = array();

	/**
	 * @param array<string,list<list<string>>> $sheets sheet name => rows (first row = header)
	 */
	public static function make( array $sheets ): string {
		$path   = self::path();
		$writer = new XlsxWriter( $path );
		foreach ( $sheets as $name => $rows ) {
			$writer->add_sheet( $name, array_shift( $rows ) ?? array() );
			foreach ( $rows as $row ) {
				$writer->add_row( $row );
			}
		}
		$writer->finish();
		return $path;
	}

	/** The standard three sheets with the given data rows. */
	public static function electoral( array $regions, array $constituencies = array(), array $stations = array() ): string {
		return self::make(
			array(
				'Regions'          => array_merge( array( array( 'region_code', 'region_name' ) ), $regions ),
				'Constituencies'   => array_merge( array( array( 'constituency_code', 'constituency_name', 'region_code' ) ), $constituencies ),
				'Polling Stations' => array_merge( array( array( 'polling_station_code', 'polling_station_name', 'constituency_code' ) ), $stations ),
			)
		);
	}

	/**
	 * A raw archive with the given parts (for shared strings and attack cases).
	 *
	 * @param array<string,string> $parts zip path => content
	 */
	public static function raw( array $parts, int $method = \ZipArchive::CM_DEFLATE ): string {
		$path = self::path();
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		foreach ( $parts as $name => $content ) {
			$zip->addFromString( $name, $content );
			$zip->setCompressionName( $name, $method );
		}
		$zip->close();
		return $path;
	}

	/** Minimal workbook skeleton pointing sheet1 at the given sheet XML. */
	public static function skeleton( string $sheet_name, string $sheet_xml, ?string $shared = null ): array {
		$parts = array(
			'[Content_Types].xml'        => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
			'xl/workbook.xml'            => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $sheet_name . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
			'xl/worksheets/sheet1.xml'   => $sheet_xml,
		);
		if ( null !== $shared ) {
			$parts['xl/sharedStrings.xml'] = $shared;
		}
		return $parts;
	}

	public static function cleanup(): void {
		foreach ( self::$files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		self::$files = array();
	}

	private static function path(): string {
		$base          = tempnam( sys_get_temp_dir(), 'dms-wb' );
		$path          = $base . '.xlsx';
		self::$files[] = $base;
		self::$files[] = $path;
		return $path;
	}
}
