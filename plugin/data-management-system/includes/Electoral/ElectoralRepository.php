<?php
/**
 * Data access for Regions, Constituencies and Polling Stations (ARCH §37, §51).
 *
 * - Relationships use internal IDs; codes are the stable external identity.
 * - options() serves the public cascading dropdowns (ARCH §36): active
 *   children of one parent only, never the whole polling-station table, and
 *   cached until electoral data changes (Decisions §39).
 * - delete() refuses to orphan children or registrations (ARCH §61). The
 *   foreign keys added by M002 enforce the same rule in the database.
 *
 * @package DMS
 */

namespace DMS\Electoral;

use DMS\Database\Tables;
use DMS\Errors\ConflictException;
use DMS\Support\Clock;

defined( 'ABSPATH' ) || exit;

class ElectoralRepository {

	public const STATUS_ACTIVE   = 'ACTIVE';
	public const STATUS_INACTIVE = 'INACTIVE';

	private const CACHE_VERSION_OPTION = 'dms_electoral_cache_version';
	private const CACHE_TTL            = HOUR_IN_SECONDS;
	private const CODE_CHUNK           = 500;

	public function __construct( private \wpdb $db, private Clock $clock ) {
	}

	public function find( ElectoralLevel $level, int $id ): ?object {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from ElectoralLevel.
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$level->table()} WHERE id = %d", $id ) );
	}

	public function find_by_code( ElectoralLevel $level, string $code ): ?object {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$level->table()} WHERE code = %s", $code ) );
	}

	/**
	 * Bulk lookup for the importer, in chunks to keep queries bounded.
	 *
	 * @param list<string> $codes
	 * @return array<string,object> code => row
	 */
	public function find_by_codes( ElectoralLevel $level, array $codes ): array {
		$found = array();
		foreach ( array_chunk( array_values( array_unique( $codes ) ), self::CODE_CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$level->table()} WHERE code IN ({$placeholders})", ...$chunk ) );
			foreach ( $rows as $row ) {
				$found[ $row->code ] = $row;
			}
		}
		return $found;
	}

	/**
	 * Active records for a dropdown: all Regions, or the children of one parent.
	 *
	 * @return list<array{id:int,code:string,name:string}>
	 */
	public function options( ElectoralLevel $level, ?int $parent_id = null ): array {
		$parent_column = $level->parent_column();
		if ( null !== $parent_column && ( null === $parent_id || $parent_id <= 0 ) ) {
			return array();
		}

		$cache_key = 'dms_opts_' . $this->cache_version() . '_' . $level->value . '_' . (int) $parent_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = null === $parent_column
			? $this->db->get_results( $this->db->prepare( "SELECT id, code, name FROM {$level->table()} WHERE status = %s ORDER BY name ASC", self::STATUS_ACTIVE ) )
			: $this->db->get_results( $this->db->prepare( "SELECT id, code, name FROM {$level->table()} WHERE {$parent_column} = %d AND status = %s ORDER BY name ASC", $parent_id, self::STATUS_ACTIVE ) );
		// phpcs:enable

		$options = array_map(
			static fn( object $row ): array => array(
				'id'   => (int) $row->id,
				'code' => (string) $row->code,
				'name' => (string) $row->name,
			),
			$rows
		);
		// Empty results are not cached: otherwise requests for made-up parent IDs
		// (a public endpoint) would each add a stored transient (D-10).
		if ( array() !== $options ) {
			set_transient( $cache_key, $options, self::CACHE_TTL );
		}
		return $options;
	}

	/**
	 * True only if all three records exist, are active and form one chain (ARCH §36, §38).
	 */
	public function is_valid_chain( int $region_id, int $constituency_id, int $polling_station_id ): bool {
		$r = ElectoralLevel::REGION->table();
		$c = ElectoralLevel::CONSTITUENCY->table();
		$p = ElectoralLevel::POLLING_STATION->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$p} p
				INNER JOIN {$c} c ON c.id = p.constituency_id
				INNER JOIN {$r} r ON r.id = c.region_id
				WHERE p.id = %d AND c.id = %d AND r.id = %d
				AND p.status = %s AND c.status = %s AND r.status = %s",
				$polling_station_id,
				$constituency_id,
				$region_id,
				self::STATUS_ACTIVE,
				self::STATUS_ACTIVE,
				self::STATUS_ACTIVE
			)
		);
	}

	/** @return array<string,int> Level value => total rows. */
	public function counts(): array {
		$counts = array();
		foreach ( ElectoralLevel::cases() as $level ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts[ $level->value ] = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$level->table()}" );
		}
		return $counts;
	}

	/** @throws \RuntimeException */
	public function insert( ElectoralLevel $level, string $code, string $name, ?int $parent_id = null, bool $invalidate_cache = true ): int {
		$now           = $this->clock->now_mysql();
		$data          = array(
			'code'       => $code,
			'name'       => $name,
			'status'     => self::STATUS_ACTIVE,
			'created_at' => $now,
			'updated_at' => $now,
		);
		$parent_column = $level->parent_column();
		if ( null !== $parent_column ) {
			$data[ $parent_column ] = (int) $parent_id;
		}
		if ( false === $this->db->insert( $level->table(), $data ) ) {
			throw new \RuntimeException( "Could not insert {$level->value} {$code}: " . $this->db->last_error );
		}
		$id = (int) $this->db->insert_id; // Read before any other query overwrites it.
		if ( $invalidate_cache ) {
			$this->invalidate_cache();
		}
		return $id;
	}

	/**
	 * @param array<string,mixed> $fields Subset of name, status and the parent column.
	 * @throws \RuntimeException
	 */
	public function update( ElectoralLevel $level, int $id, array $fields, bool $invalidate_cache = true ): void {
		$allowed = array_filter( array( 'name', 'status', $level->parent_column() ) );
		$data    = array_intersect_key( $fields, array_flip( $allowed ) );
		if ( array() === $data ) {
			return;
		}
		$data['updated_at'] = $this->clock->now_mysql();
		if ( false === $this->db->update( $level->table(), $data, array( 'id' => $id ) ) ) {
			throw new \RuntimeException( "Could not update {$level->value} #{$id}: " . $this->db->last_error );
		}
		if ( $invalidate_cache ) {
			$this->invalidate_cache();
		}
	}

	/**
	 * Records that would be orphaned if this one were removed.
	 *
	 * @return array{children:int,registrations:int,officers:int}
	 */
	public function dependencies( ElectoralLevel $level, int $id ): array {
		$children = 0;
		$child    = $level->child();
		if ( null !== $child ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$children = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$child->table()} WHERE {$child->parent_column()} = %d", $id ) );
		}

		$registrations = Tables::name( Tables::REGISTRATIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$registration_count = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$registrations} WHERE {$level->registration_column()} = %d", $id ) );

		$officers = 0;
		if ( ElectoralLevel::REGION === $level ) {
			$links = Tables::name( Tables::OFFICER_REGIONS );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$officers = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$links} WHERE region_id = %d", $id ) );
		}

		return array(
			'children'      => $children,
			'registrations' => $registration_count,
			'officers'      => $officers,
		);
	}

	/** @throws ConflictException When anything still depends on the record. */
	public function delete( ElectoralLevel $level, int $id ): void {
		$deps = $this->dependencies( $level, $id );
		if ( $deps['children'] > 0 || $deps['registrations'] > 0 || $deps['officers'] > 0 ) {
			throw new ConflictException(
				sprintf(
					/* translators: 1: level label, 2: child records, 3: registrations, 4: officer links */
					__( 'This %1$s cannot be removed: %2$d child records, %3$d registrations and %4$d officer links depend on it.', 'dms' ),
					$level->label(),
					$deps['children'],
					$deps['registrations'],
					$deps['officers']
				),
				'dependency_exists'
			);
		}
		if ( false === $this->db->delete( $level->table(), array( 'id' => $id ) ) ) {
			throw new \RuntimeException( "Could not delete {$level->value} #{$id}: " . $this->db->last_error );
		}
		$this->invalidate_cache();
	}

	private function cache_version(): int {
		return (int) get_option( self::CACHE_VERSION_OPTION, 1 );
	}

	/**
	 * Invalidates every cached dropdown at once; stale transients expire on their own.
	 * Bulk writers (the importer) pass $invalidate_cache = false per row and call this once.
	 */
	public function invalidate_cache(): void {
		update_option( self::CACHE_VERSION_OPTION, $this->cache_version() + 1, true );
	}
}
