<?php
/**
 * The three V1 electoral levels (ARCH §35, §47). Each level has the same
 * shape (code, name, status, parent id), so one repository serves all three.
 *
 * @package DMS
 */

namespace DMS\Electoral;

use DMS\Database\Tables;

defined( 'ABSPATH' ) || exit;

enum ElectoralLevel: string {

	case REGION          = 'REGION';
	case CONSTITUENCY    = 'CONSTITUENCY';
	case POLLING_STATION = 'POLLING_STATION';

	public function table(): string {
		return Tables::name(
			match ( $this ) {
				self::REGION          => Tables::REGIONS,
				self::CONSTITUENCY    => Tables::CONSTITUENCIES,
				self::POLLING_STATION => Tables::POLLING_STATIONS,
			}
		);
	}

	/** Column pointing at the parent level, or null for Region. */
	public function parent_column(): ?string {
		return match ( $this ) {
			self::REGION          => null,
			self::CONSTITUENCY    => 'region_id',
			self::POLLING_STATION => 'constituency_id',
		};
	}

	public function parent(): ?self {
		return match ( $this ) {
			self::REGION          => null,
			self::CONSTITUENCY    => self::REGION,
			self::POLLING_STATION => self::CONSTITUENCY,
		};
	}

	public function child(): ?self {
		return match ( $this ) {
			self::REGION          => self::CONSTITUENCY,
			self::CONSTITUENCY    => self::POLLING_STATION,
			self::POLLING_STATION => null,
		};
	}

	/** Column on dms_registrations referencing this level. */
	public function registration_column(): string {
		return match ( $this ) {
			self::REGION          => 'region_id',
			self::CONSTITUENCY    => 'constituency_id',
			self::POLLING_STATION => 'polling_station_id',
		};
	}

	public function label(): string {
		return match ( $this ) {
			self::REGION          => __( 'Region', 'dms' ),
			self::CONSTITUENCY    => __( 'Constituency', 'dms' ),
			self::POLLING_STATION => __( 'Polling Station', 'dms' ),
		};
	}
}
