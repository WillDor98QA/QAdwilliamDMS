<?php
/**
 * Base for expected application errors.
 *
 * getMessage() is always safe to show to the user; internal detail goes to
 * the Logger instead (MP §38). http_status() lets REST/AJAX controllers map
 * errors to status codes consistently (MP §36).
 *
 * @package DMS
 */

namespace DMS\Errors;

defined( 'ABSPATH' ) || exit;

abstract class DmsException extends \RuntimeException {

	abstract public function http_status(): int;

	abstract public function error_code(): string;
}
