<?php
/**
 * Who performed an audited action.
 *
 * @package DMS
 */

namespace DMS\Audit;

defined( 'ABSPATH' ) || exit;

final class ActorType {

	/** Logged-in WordPress user. */
	public const USER = 'USER';

	/** Anonymous public registrant. */
	public const PUBLIC_USER = 'PUBLIC';

	/** The plugin itself: automatic assignment, retention job, migrations. */
	public const SYSTEM = 'SYSTEM';
}
