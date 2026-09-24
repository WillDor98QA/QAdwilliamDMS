<?php
/**
 * Adds the minimal WordPress role given to users created by the plugin.
 *
 * It only grants `read` (enough to sign in and reach wp-admin). Every
 * application permission comes from DMS roles via CapabilityManager.
 * add_role() is a no-op if the role already exists.
 *
 * @package DMS
 */

namespace DMS\Database\Migrations;

use DMS\Database\Migration;

defined( 'ABSPATH' ) || exit;

final class M004AddStaffWordPressRole implements Migration {

	public const WP_ROLE = 'dms_user';

	public function version(): int {
		return 4;
	}

	public function description(): string {
		return 'Add dms_user WordPress role';
	}

	public function up( \wpdb $db ): void {
		if ( null === get_role( self::WP_ROLE ) ) {
			add_role( self::WP_ROLE, 'DMS User', array( 'read' => true ) );
		}
	}
}
