<?php
/**
 * Validates a whole workbook and plans the changes, without writing anything
 * (ARCH §40, §52, §54, §55, §58; MP §16, §17).
 *
 * Order follows the hierarchy: Regions, then Constituencies against valid
 * region codes (in the file or already in the database), then Polling
 * Stations against valid constituency codes. Each row becomes CREATE, UPDATE,
 * UNCHANGED, DUPLICATE or INVALID. Codes missing from the file are left
 * untouched (the import never deletes).
 *
 * Moving an existing record to a different parent is refused when
 * registrations use it, because their Region/Constituency/Station chain would
 * no longer match (ARCH §55 "conflicts with existing data", §61).
 *
 * @package DMS
 */

namespace DMS\Imports;

use DMS\Database\Tables;
use DMS\Electoral\ElectoralLevel;
use DMS\Electoral\ElectoralRepository;

defined( 'ABSPATH' ) || exit;

class ImportValidator {

	public const MAX_REPORTED_ERRORS = 1000;

	public function __construct( private \wpdb $db, private ElectoralRepository $electoral ) {
	}

	/**
	 * @return ImportPlan
	 * @throws ImportFileException When the workbook structure is unusable (missing sheet, missing columns, too many rows).
	 */
	public function plan( XlsxReader $reader ): ImportPlan {
		$plan = new ImportPlan();

		// Structure first: every sheet and column must exist before any row is judged (ARCH §54 step 2).
		$structure = array();
		foreach ( WorkbookSpec::sheets() as $level_value => $spec ) {
			if ( ! $reader->has_sheet( $spec['sheet'] ) ) {
				$structure[] = sprintf(
					/* translators: 1: sheet name, 2: sheets found */
					__( 'The workbook is missing the "%1$s" sheet. Sheets found: %2$s. Please use the official template.', 'dms' ),
					$spec['sheet'],
					implode( ', ', $reader->sheet_names() )
				);
			}
		}
		if ( array() !== $structure ) {
			throw new ImportFileException( implode( ' ', $structure ) );
		}

		$in_file = array();
		$total   = 0;
		foreach ( WorkbookSpec::sheets() as $level_value => $spec ) {
			$level                   = ElectoralLevel::from( $level_value );
			$rows                    = $this->read_sheet( $reader, $level, $total );
			$in_file[ $level_value ] = $this->plan_level( $plan, $level, $rows, $in_file );
		}
		return $plan;
	}

	/**
	 * @return list<array{row:int,code:string,name:string,parent:string}>
	 * @throws ImportFileException
	 */
	private function read_sheet( XlsxReader $reader, ElectoralLevel $level, int &$total ): array {
		$spec    = WorkbookSpec::sheets()[ $level->value ];
		$columns = WorkbookSpec::columns( $level );
		$map     = null;
		$rows    = array();

		foreach ( $reader->rows( $spec['sheet'] ) as $number => $cells ) {
			$cells = array_map( static fn( $v ): string => trim( (string) $v ), $cells );
			if ( null === $map ) {
				if ( array() === array_filter( $cells, static fn( string $v ): bool => '' !== $v ) ) {
					continue; // Leading blank rows before the header.
				}
				$headers = array_map( static fn( string $h ): string => strtolower( str_replace( ' ', '_', $h ) ), $cells );
				$map     = array();
				$missing = array();
				foreach ( $columns as $column ) {
					$index = array_search( $column, $headers, true );
					if ( false === $index ) {
						$missing[] = $column;
					} else {
						$map[ $column ] = $index;
					}
				}
				if ( array() !== $missing ) {
					throw new ImportFileException(
						sprintf(
							/* translators: 1: sheet name, 2: missing column names */
							__( 'The "%1$s" sheet is missing the column(s): %2$s. The first row must contain the column names from the official template.', 'dms' ),
							$spec['sheet'],
							implode( ', ', $missing )
						)
					);
				}
				continue;
			}
			$value = static fn( string $column ): string => (string) ( $cells[ $map[ $column ] ] ?? '' );
			$row   = array(
				'row'    => (int) $number,
				'code'   => WorkbookSpec::normalise_code( $value( $spec['code'] ) ),
				'name'   => WorkbookSpec::normalise_name( $value( $spec['name'] ) ),
				'parent' => null !== $spec['parent'] ? WorkbookSpec::normalise_code( $value( $spec['parent'] ) ) : '',
			);
			if ( '' === $row['code'] && '' === $row['name'] && '' === $row['parent'] ) {
				continue; // Blank row.
			}
			if ( ++$total > WorkbookSpec::MAX_ROWS ) {
				/* translators: %d: maximum rows */
				throw new ImportFileException( sprintf( __( 'The workbook has more than %d data rows. Split it into smaller files.', 'dms' ), WorkbookSpec::MAX_ROWS ) );
			}
			$rows[] = $row;
		}
		if ( null === $map ) {
			/* translators: %s: sheet name */
			throw new ImportFileException( sprintf( __( 'The "%s" sheet is empty. Keep the header row from the official template.', 'dms' ), $spec['sheet'] ) );
		}
		return $rows;
	}

	/**
	 * @param list<array{row:int,code:string,name:string,parent:string}> $rows
	 * @param array<string,array<string,array{name:string,parent:string,valid:bool}>> $in_file Codes planned for earlier levels.
	 * @return array<string,array{name:string,parent:string,valid:bool}> This level's codes in the file.
	 */
	private function plan_level( ImportPlan $plan, ElectoralLevel $level, array $rows, array $in_file ): array {
		$spec     = WorkbookSpec::sheets()[ $level->value ];
		$parent   = $level->parent();
		$existing = $this->electoral->find_by_codes( $level, array_column( $rows, 'code' ) );
		$parents  = array();
		if ( null !== $parent ) {
			$parents = $this->electoral->find_by_codes( $parent, array_values( array_filter( array_unique( array_column( $rows, 'parent' ) ) ) ) );
		}

		$seen = array();
		$mine = array();
		foreach ( $rows as $row ) {
			$errors = array();
			if ( '' === $row['code'] ) {
				$errors[] = __( 'Code is required.', 'dms' );
			} elseif ( ! WorkbookSpec::is_valid_code( $row['code'] ) ) {
				/* translators: %d: maximum length */
				$errors[] = sprintf( __( 'Code may use only letters, digits and . _ - / (up to %d characters).', 'dms' ), WorkbookSpec::MAX_CODE_LENGTH );
			}
			if ( '' === $row['name'] ) {
				$errors[] = __( 'Name is required.', 'dms' );
			} elseif ( mb_strlen( $row['name'] ) > WorkbookSpec::MAX_NAME_LENGTH ) {
				/* translators: %d: maximum length */
				$errors[] = sprintf( __( 'Name is longer than %d characters.', 'dms' ), WorkbookSpec::MAX_NAME_LENGTH );
			}

			if ( '' !== $row['code'] && isset( $seen[ $row['code'] ] ) ) {
				/* translators: %d: row number of the first occurrence */
				$plan->add_error( $level, $row['row'], $row['code'], sprintf( __( 'Duplicate code: already used on row %d of this sheet.', 'dms' ), $seen[ $row['code'] ] ), ImportStatus::ACTION_DUPLICATE );
				continue;
			}
			if ( '' !== $row['code'] ) {
				$seen[ $row['code'] ] = $row['row'];
			}

			// Parent must be valid in this file, or already exist in the database.
			$parent_id = null;
			if ( null !== $parent ) {
				if ( '' === $row['parent'] ) {
					/* translators: %s: parent column name */
					$errors[] = sprintf( __( '%s is required.', 'dms' ), $spec['parent'] );
				} elseif ( isset( $in_file[ $parent->value ][ $row['parent'] ] ) && ! $in_file[ $parent->value ][ $row['parent'] ]['valid'] ) {
					/* translators: 1: parent label, 2: parent code */
					$errors[] = sprintf( __( '%1$s code %2$s has errors in this file.', 'dms' ), $parent->label(), $row['parent'] );
				} elseif ( ! isset( $in_file[ $parent->value ][ $row['parent'] ] ) && ! isset( $parents[ $row['parent'] ] ) ) {
					/* translators: 1: parent label, 2: parent code */
					$errors[] = sprintf( __( '%1$s code %2$s does not exist.', 'dms' ), $parent->label(), $row['parent'] );
				} else {
					$parent_id = isset( $parents[ $row['parent'] ] ) ? (int) $parents[ $row['parent'] ]->id : null;
				}
			}

			$current = $existing[ $row['code'] ] ?? null;
			if ( array() === $errors && null !== $current && null !== $parent ) {
				$parent_col = $level->parent_column();
				$old_parent = (int) $current->{$parent_col};
				$moves      = null === $parent_id || $parent_id !== $old_parent;
				if ( $moves && $this->registrations_using( $level, (int) $current->id ) > 0 ) {
					/* translators: %s: parent label */
					$errors[] = sprintf( __( 'Conflicts with existing data: this record is used by registrations, so it cannot be moved to a different %s.', 'dms' ), strtolower( $parent->label() ) );
				}
			}

			if ( array() !== $errors ) {
				foreach ( $errors as $message ) {
					$plan->add_error( $level, $row['row'], $row['code'], $message, ImportStatus::ACTION_INVALID );
				}
				$mine[ $row['code'] ] = array(
					'name'   => $row['name'],
					'parent' => $row['parent'],
					'valid'  => false,
				);
				continue;
			}

			$mine[ $row['code'] ] = array(
				'name'   => $row['name'],
				'parent' => $row['parent'],
				'valid'  => true,
			);
			if ( null === $current ) {
				$plan->add_change( $level, ImportStatus::ACTION_CREATE, $row['row'], $row['code'], $row['name'], $row['parent'], null );
				continue;
			}
			$same_parent = null === $parent || ( null !== $parent_id && $parent_id === (int) $current->{$level->parent_column()} );
			$same        = $same_parent && $row['name'] === $current->name && 'ACTIVE' === $current->status;
			$plan->add_change( $level, $same ? ImportStatus::ACTION_UNCHANGED : ImportStatus::ACTION_UPDATE, $row['row'], $row['code'], $row['name'], $row['parent'], $current );
		}
		return $mine;
	}

	private function registrations_using( ElectoralLevel $level, int $id ): int {
		$table = Tables::name( Tables::REGISTRATIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column from Tables/ElectoralLevel.
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$level->registration_column()} = %d", $id ) );
	}
}
