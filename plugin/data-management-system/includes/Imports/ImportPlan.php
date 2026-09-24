<?php
/**
 * Result of validating a workbook: planned changes, counts and errors (ARCH §55).
 *
 * @package DMS
 */

namespace DMS\Imports;

use DMS\Electoral\ElectoralLevel;

defined( 'ABSPATH' ) || exit;

final class ImportPlan {

	/** @var list<array{level:string,action:string,row:int,code:string,name:string,parent:string,current:?object}> */
	public array $changes = array();

	/** @var list<array{level:string,row:int,code:string,message:string,action:string}> */
	public array $errors = array();

	/** @var array<string,array<string,int>> level => action => count */
	public array $counts = array();

	public function add_change( ElectoralLevel $level, string $action, int $row, string $code, string $name, string $parent_code, ?object $current ): void {
		$this->changes[] = array(
			'level'   => $level->value,
			'action'  => $action,
			'row'     => $row,
			'code'    => $code,
			'name'    => $name,
			'parent'  => $parent_code,
			'current' => $current,
		);
		$this->count( $level, $action );
	}

	public function add_error( ElectoralLevel $level, int $row, string $code, string $message, string $action ): void {
		if ( count( $this->errors ) < ImportValidator::MAX_REPORTED_ERRORS ) {
			$this->errors[] = array(
				'level'   => $level->value,
				'row'     => $row,
				'code'    => $code,
				'message' => $message,
				'action'  => $action,
			);
		}
		$this->count( $level, $action );
	}

	public function has_errors(): bool {
		return array() !== $this->errors;
	}

	public function total( string $action ): int {
		return (int) array_sum( array_map( static fn( array $a ): int => $a[ $action ] ?? 0, $this->counts ) );
	}

	public function total_rows(): int {
		return (int) array_sum( array_map( 'array_sum', $this->counts ) );
	}

	public function error_count(): int {
		return $this->total( ImportStatus::ACTION_INVALID ) + $this->total( ImportStatus::ACTION_DUPLICATE );
	}

	/** @return array<string,mixed> Stored as the batch summary (no objects). */
	public function summary(): array {
		return array(
			'counts'           => $this->counts,
			'totals'           => array(
				'rows'      => $this->total_rows(),
				'create'    => $this->total( ImportStatus::ACTION_CREATE ),
				'update'    => $this->total( ImportStatus::ACTION_UPDATE ),
				'unchanged' => $this->total( ImportStatus::ACTION_UNCHANGED ),
				'errors'    => $this->error_count(),
			),
			'errors'           => $this->errors,
			'errors_truncated' => $this->error_count() > count( $this->errors ),
		);
	}

	private function count( ElectoralLevel $level, string $action ): void {
		$this->counts[ $level->value ][ $action ] = ( $this->counts[ $level->value ][ $action ] ?? 0 ) + 1;
	}
}
