<?php
/**
 * The uploaded workbook itself is unusable (wrong type, corrupt, unsafe, missing sheet).
 * The message is safe to show to the administrator.
 *
 * @package DMS
 */

namespace DMS\Imports;

use DMS\Errors\DmsException;

defined( 'ABSPATH' ) || exit;

final class ImportFileException extends DmsException {

	public function http_status(): int {
		return 422;
	}

	public function error_code(): string {
		return 'dms_import_file_invalid';
	}
}
