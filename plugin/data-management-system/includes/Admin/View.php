<?php
/**
 * Presentation helpers for the admin UI (dms-uiux-prototype.html components).
 *
 * Output only. The data comes from the existing services; nothing here
 * decides access or changes records.
 *
 * @package DMS
 */

namespace DMS\Admin;

use DMS\Electoral\ElectoralLevel;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class View {

	/** Page header: eyebrow, title, description and optional actions (already-escaped HTML). */
	public static function page_head( string $eyebrow, string $title, string $description = '', string $actions = '' ): void {
		?>
		<div class="dms-page-head">
			<div>
				<?php if ( '' !== $eyebrow ) : ?>
					<p class="dms-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
				<?php endif; ?>
				<h1><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== $description ) : ?>
					<p><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $actions ) : ?>
				<div class="dms-page-head__actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?></div>
			<?php endif; ?>
		</div>
		<hr class="wp-header-end">
		<?php
	}

	/** KPI card. A link when $href is given. */
	public static function kpi( string $label, int $value, string $icon, string $note = '', string $href = '', bool $alert = false ): void {
		$tag = '' !== $href ? 'a' : 'div';
		printf(
			'<%1$s class="dms-kpi%2$s"%3$s><span class="dms-kpi__label">%4$s</span><span class="dms-kpi__value">%5$s</span>%6$s<span class="dms-kpi__icon" aria-hidden="true"><span class="dashicons dashicons-%7$s"></span></span></%1$s>',
			$tag, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 'a' or 'div'.
			$alert ? ' dms-kpi--alert' : '',
			'' !== $href ? ' href="' . esc_url( $href ) . '"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $value ) ),
			'' !== $note ? '<span class="dms-kpi__note">' . esc_html( $note ) . '</span>' : '',
			esc_attr( $icon )
		);
	}

	/** Initials avatar (decorative; the name is always printed next to it). */
	public static function avatar( string $name, bool $small = true ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		$parts = is_array( $parts ) ? $parts : array();
		$init  = mb_substr( (string) ( $parts[0] ?? '' ), 0, 1 ) . ( count( $parts ) > 1 ? mb_substr( (string) end( $parts ), 0, 1 ) : '' );
		return sprintf( '<span class="dms-avatar%1$s" aria-hidden="true">%2$s</span>', $small ? ' dms-avatar--sm' : '', esc_html( '' !== $init ? mb_strtoupper( $init ) : '?' ) );
	}

	/**
	 * Active officers and their current workload, highest first. Reads the
	 * existing ranked candidates per region; an officer covering several regions
	 * appears there once per region with the same workload, so only duplicates
	 * are removed here.
	 *
	 * @return list<array{name:string,workload:int}>
	 */
	public static function officer_workload( Plugin $plugin ): array {
		$by_user = array();
		foreach ( $plugin->electoral()->options( ElectoralLevel::REGION ) as $region ) {
			foreach ( $plugin->assignments()->ranked_candidates( (int) $region['id'] ) as $c ) {
				$by_user[ (int) $c['user_id'] ] = (int) $c['workload'];
			}
		}
		$rows = array();
		foreach ( $by_user as $id => $load ) {
			$user   = get_user_by( 'id', $id );
			$rows[] = array(
				'name'     => $user ? $user->display_name : '#' . $id,
				'workload' => $load,
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => array( $b['workload'], $a['name'] ) <=> array( $a['workload'], $b['name'] ) );
		return $rows;
	}

	/** Workload list with bars scaled to the busiest officer. */
	public static function workload_list( array $rows ): void {
		if ( array() === $rows ) {
			echo '<p class="dms-empty-inline">' . esc_html__( 'No active officers are configured yet.', 'dms' ) . '</p>';
			return;
		}
		$max = max( 1, max( array_column( $rows, 'workload' ) ) );
		echo '<ul class="dms-workload">';
		foreach ( $rows as $r ) {
			printf(
				'<li>%1$s<span class="dms-workload__body"><strong>%2$s</strong><span class="dms-progress" aria-hidden="true"><i style="width:%3$d%%"></i></span></span><span class="dms-workload__value">%4$s<span class="screen-reader-text"> %5$s</span></span></li>',
				self::avatar( $r['name'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in avatar().
				esc_html( $r['name'] ),
				(int) round( 100 * $r['workload'] / $max ),
				esc_html( number_format_i18n( $r['workload'] ) ),
				esc_html__( 'active registrations', 'dms' )
			);
		}
		echo '</ul>';
	}

	/** "3h", "1d 8h" since a UTC datetime (display only). */
	public static function age( ?string $utc ): string {
		if ( ! $utc ) {
			return '';
		}
		$seconds = max( 0, time() - (int) strtotime( $utc . ' UTC' ) );
		$days    = intdiv( $seconds, DAY_IN_SECONDS );
		$hours   = intdiv( $seconds % DAY_IN_SECONDS, HOUR_IN_SECONDS );
		if ( $days > 0 ) {
			/* translators: 1: days, 2: hours */
			return sprintf( __( '%1$dd %2$dh', 'dms' ), $days, $hours );
		}
		if ( $hours > 0 ) {
			/* translators: %d: hours */
			return sprintf( __( '%dh', 'dms' ), $hours );
		}
		/* translators: %d: minutes */
		return sprintf( __( '%dm', 'dms' ), max( 1, intdiv( $seconds, MINUTE_IN_SECONDS ) ) );
	}

	/** Compact, localised date for table cells, e.g. "24 Sep 2026, 06:36". */
	public static function short_date( ?string $utc ): string {
		return $utc ? (string) wp_date( 'j M Y, H:i', (int) strtotime( $utc . ' UTC' ) ) : '';
	}

	public static function date( ?string $utc, bool $time = true ): string {
		return $utc ? get_date_from_gmt( $utc, get_option( 'date_format' ) . ( $time ? ' H:i' : '' ) ) : '';
	}
}
