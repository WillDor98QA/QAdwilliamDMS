<?php
/**
 * Admin app shell (UI only): DMS sidebar, top bar and content frame around
 * every Data Management page, following dms-uiux-prototype.html.
 *
 * The sidebar lists exactly the pages AdminMenu registered for this user, so
 * visibility still comes from the existing permission checks. The WordPress
 * menu is hidden by CSS on DMS screens only (body class dms-app-screen); the
 * submenu pages stay registered, so routing and access checks are unchanged.
 *
 * @package DMS
 */

namespace DMS\Admin;

use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class AppShell {

	public const BODY_CLASS = 'dms-app-screen';

	/** Sidebar groups, in order. Pages not listed here fall into "System". */
	private const GROUPS = array(
		'workspace' => array( 'dms-dashboard', 'dms-holding', 'dms-assigned', 'dms-under-review', 'dms-approved', 'dms-bin', 'dms-assignments' ),
		'insights'  => array( 'dms-analytics', 'dms-reports' ),
		'data'      => array( 'dms-electoral', 'dms-users', 'dms-roles', 'dms-form-builder' ),
		'system'    => array( 'dms-notifications', 'dms-exports', 'dms-audit', 'dms-settings' ),
	);

	private const ICONS = array(
		'dms-dashboard'     => 'dashboard',
		'dms-holding'       => 'clipboard',
		'dms-assigned'      => 'id',
		'dms-under-review'  => 'visibility',
		'dms-approved'      => 'yes-alt',
		'dms-bin'           => 'trash',
		'dms-assignments'   => 'randomize',
		'dms-analytics'     => 'chart-bar',
		'dms-reports'       => 'media-spreadsheet',
		'dms-electoral'     => 'location-alt',
		'dms-users'         => 'groups',
		'dms-roles'         => 'shield',
		'dms-form-builder'  => 'feedback',
		'dms-notifications' => 'email-alt',
		'dms-exports'       => 'download',
		'dms-audit'         => 'backup',
		'dms-settings'      => 'admin-generic',
	);

	public function __construct( private Plugin $plugin ) {
	}

	/** Current DMS page slug, or '' when this is not a DMS screen. */
	public static function current_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return str_starts_with( $page, 'dms' ) ? $page : '';
	}

	public function body_class( string $classes ): string {
		return '' !== self::current_slug() ? $classes . ' ' . self::BODY_CLASS : $classes;
	}

	/**
	 * Wraps a page callback in the shell.
	 *
	 * @param list<array{slug:string,title:string,callback:callable,visible:bool}> $pages Visible pages.
	 */
	public function wrap( array $pages, string $slug, callable $callback ): callable {
		return function () use ( $pages, $slug, $callback ): void {
			$this->open( $pages, $slug );
			call_user_func( $callback );
			$this->close();
		};
	}

	/** @param list<array{slug:string,title:string,callback:callable,visible:bool}> $pages */
	private function open( array $pages, string $current ): void {
		$titles = array_column( $pages, 'title', 'slug' );
		$labels = array(
			'workspace' => __( 'Workspace', 'dms' ),
			'insights'  => __( 'Insights', 'dms' ),
			'data'      => __( 'Data & Configuration', 'dms' ),
			'system'    => __( 'System', 'dms' ),
		);
		// Sidebar order follows GROUPS; any page not listed there goes last in "System".
		$grouped = array();
		foreach ( self::GROUPS as $group => $slugs ) {
			$grouped[ $group ] = array_values( array_filter( $slugs, static fn( string $s ): bool => isset( $titles[ $s ] ) ) );
		}
		foreach ( array_keys( $titles ) as $slug ) {
			if ( ! in_array( $slug, array_merge( ...array_values( self::GROUPS ) ), true ) ) {
				$grouped['system'][] = $slug;
			}
		}
		?>
		<div class="dms-app" data-dms-app>
			<aside class="dms-sidebar" id="dms-sidebar" aria-label="<?php esc_attr_e( 'Data Management', 'dms' ); ?>">
				<div class="dms-brand">
					<span class="dms-brand__mark" aria-hidden="true">D</span>
					<span class="dms-brand__name"><?php esc_html_e( 'Data Management', 'dms' ); ?></span>
				</div>
				<nav class="dms-nav" aria-label="<?php esc_attr_e( 'Data Management sections', 'dms' ); ?>">
					<?php foreach ( $grouped as $group => $slugs ) : ?>
						<?php
						if ( array() === $slugs ) {
							continue;
						}
						?>
						<p class="dms-nav__label" id="dms-nav-<?php echo esc_attr( $group ); ?>"><?php echo esc_html( $labels[ $group ] ); ?></p>
						<ul class="dms-nav__list" aria-labelledby="dms-nav-<?php echo esc_attr( $group ); ?>">
							<?php foreach ( $slugs as $slug ) : ?>
								<?php $this->nav_item( $slug, $titles[ $slug ], $slug === $current ); ?>
							<?php endforeach; ?>
						</ul>
					<?php endforeach; ?>
				</nav>
				<div class="dms-sidebar__foot">
					<a class="dms-nav__link" href="<?php echo esc_url( admin_url() ); ?>">
						<span class="dms-nav__icon dashicons dashicons-wordpress" aria-hidden="true"></span>
						<span class="dms-nav__text"><?php esc_html_e( 'WordPress admin', 'dms' ); ?></span>
					</a>
					<?php $this->profile(); ?>
				</div>
			</aside>
			<div class="dms-backdrop" data-dms-nav-close hidden></div>
			<div class="dms-main">
				<header class="dms-topbar">
					<button type="button" class="dms-icon-btn dms-menu-toggle" aria-controls="dms-sidebar" aria-expanded="false" data-dms-nav-toggle>
						<span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Open navigation', 'dms' ); ?></span>
					</button>
					<nav class="dms-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'dms' ); ?>">
						<ol>
							<li><?php esc_html_e( 'Data Management', 'dms' ); ?></li>
							<?php $this->crumbs( $current, $titles[ $current ] ?? '' ); ?>
						</ol>
					</nav>
				</header>
				<div class="dms-content">
		<?php
	}

	private function close(): void {
		echo '</div></div></div>';
	}

	private function nav_item( string $slug, string $title, bool $active ): void {
		$count = 'dms-holding' === $slug ? $this->holding_count() : null;
		printf(
			'<li><a class="dms-nav__link%1$s" href="%2$s"%3$s><span class="dms-nav__icon dashicons dashicons-%4$s" aria-hidden="true"></span><span class="dms-nav__text">%5$s</span>%6$s</a></li>',
			$active ? ' is-active' : '',
			esc_url( add_query_arg( 'page', $slug, admin_url( 'admin.php' ) ) ),
			$active ? ' aria-current="page"' : '',
			esc_attr( self::ICONS[ $slug ] ?? 'admin-page' ),
			esc_html( $title ),
			null !== $count ? '<span class="dms-nav__count">' . esc_html( number_format_i18n( $count ) ) . '<span class="screen-reader-text"> ' . esc_html__( 'records', 'dms' ) . '</span></span>' : ''
		);
	}

	/** Holding Area size in this user's own scope (the same count the dashboard shows). */
	private function holding_count(): ?int {
		try {
			return (int) $this->plugin->lists()->search( get_current_user_id(), 'holding', array( 'per_page' => 1 ) )['total'];
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function crumbs( string $slug, string $title ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$record = absint( $_GET['registration'] ?? 0 );
		if ( $record > 0 ) {
			printf( '<li><a href="%1$s">%2$s</a></li><li aria-current="page">%3$s</li>', esc_url( add_query_arg( 'page', $slug, admin_url( 'admin.php' ) ) ), esc_html( $title ), esc_html__( 'Registration', 'dms' ) );
			return;
		}
		printf( '<li aria-current="page">%s</li>', esc_html( $title ) );
	}

	private function profile(): void {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return;
		}
		$names = wp_roles()->role_names;
		$roles = array_map( static fn( string $r ): string => translate_user_role( $names[ $r ] ?? $r ), $user->roles );
		$parts = preg_split( '/\s+/', trim( $user->display_name ) );
		$init  = strtoupper( mb_substr( $parts[0] ?? '', 0, 1 ) . mb_substr( count( $parts ) > 1 ? (string) end( $parts ) : '', 0, 1 ) );
		?>
		<div class="dms-profile">
			<span class="dms-avatar" aria-hidden="true"><?php echo esc_html( '' !== $init ? $init : '?' ); ?></span>
			<span class="dms-profile__text">
				<strong><?php echo esc_html( $user->display_name ); ?></strong>
				<small><?php echo esc_html( implode( ', ', $roles ) ); ?></small>
			</span>
		</div>
		<?php
	}
}
