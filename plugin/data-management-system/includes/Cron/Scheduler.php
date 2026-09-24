<?php
/**
 * WP-Cron jobs (MP §29 "reliable scheduled WordPress mechanism").
 *
 *   dms_daily_maintenance  daily   retention purge + housekeeping
 *   dms_assign_pending     hourly  retry automatic assignment of PENDING records
 *   dms_retry_notifications every 15 minutes  resend failed / stuck emails
 *
 * Jobs are (re)scheduled on every boot if missing, and unscheduled on
 * deactivation. For guaranteed timing, production should run WP-Cron from a
 * system cron (DISABLE_WP_CRON + `wp cron event run --due-now`), see docs.
 *
 * @package DMS
 */

namespace DMS\Cron;

use DMS\Assignments\AssignmentService;
use DMS\Retention\RetentionService;
use DMS\Support\Logger;

defined( 'ABSPATH' ) || exit;

class Scheduler {

	public const DAILY        = 'dms_daily_maintenance';
	public const HOURLY       = 'dms_assign_pending';
	public const RETRY        = 'dms_retry_notifications';
	public const QUARTER_HOUR = 'dms_quarter_hour';

	public function __construct( private RetentionService $retention, private AssignmentService $assignments, private Logger $logger ) {
	}

	public function register(): void {
		add_action( self::DAILY, array( $this, 'run_daily' ) );
		add_action( self::HOURLY, array( $this, 'run_hourly' ) );
		add_action( self::RETRY, array( $this, 'run_retry' ) );
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::RETRY ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::QUARTER_HOUR, self::RETRY );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY );
		wp_clear_scheduled_hook( self::HOURLY );
		wp_clear_scheduled_hook( self::RETRY );
	}

	public function run_daily(): void {
		$result = $this->retention->run();
		\DMS\Plugin::instance()->exports()->purge_expired();
		$result['import_files_removed'] = \DMS\Plugin::instance()->imports()->purge_old_files();
		$this->logger->info( 'Daily maintenance', $result );
	}

	/** @param array<string,array{interval:int,display:string}> $schedules */
	public static function schedules( array $schedules ): array {
		$schedules[ self::QUARTER_HOUR ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'dms' ),
		);
		return $schedules;
	}

	public function run_retry(): void {
		\DMS\Plugin::instance()->notifications()->process_due();
	}

	public function run_hourly(): void {
		$this->assignments->assign_pending();
	}
}
