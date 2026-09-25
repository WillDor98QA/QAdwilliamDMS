<?php
/**
 * Data Management admin menu (ARCH §15, §24, §30).
 *
 * A page is registered only for users allowed to open it, so WordPress also
 * refuses direct URLs to pages outside the user's permissions. That is a
 * second layer only: every page and every action checks permissions itself
 * (ARCH §25 "Menu hidden ≠ Permission denied").
 *
 * @package DMS
 */

namespace DMS\Admin;

use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class AdminMenu {

	public const PARENT = 'dms';

	/** Registration areas as submenus (ARCH §24). */
	public const AREA_PAGES = array(
		'holding'      => 'dms-holding',
		'assigned'     => 'dms-assigned',
		'under_review' => 'dms-under-review',
		'approved'     => 'dms-approved',
		'bin'          => 'dms-bin',
	);

	private ?AppShell $shell = null;

	public function __construct( private Plugin $plugin ) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( Notices::class, 'render' ) );
		add_filter( 'admin_body_class', array( $this->shell(), 'body_class' ) );
	}

	public function shell(): AppShell {
		$this->shell ??= new AppShell( $this->plugin );
		return $this->shell;
	}

	/** @return list<array{slug:string,title:string,callback:callable,visible:bool}> In menu order. */
	public function pages(): array {
		$user   = get_current_user_id();
		$areas  = $this->plugin->lists()->visible_areas( $user );
		$p      = $this->plugin;
		$pages  = array(
			array(
				'slug'     => 'dms-dashboard',
				'title'    => __( 'Dashboard', 'dms' ),
				'callback' => array( $p->page_dashboard(), 'render' ),
				'visible'  => current_user_can( 'dashboard.view' ),
			),
		);
		$titles = array(
			'holding'      => __( 'Holding Area', 'dms' ),
			'assigned'     => __( 'Assigned', 'dms' ),
			'under_review' => __( 'Under Review', 'dms' ),
			'approved'     => __( 'Approved', 'dms' ),
			'bin'          => __( 'Bin', 'dms' ),
		);
		foreach ( self::AREA_PAGES as $area => $slug ) {
			$pages[] = array(
				'slug'     => $slug,
				'title'    => $titles[ $area ],
				'callback' => array( $p->page_registrations(), 'render' ),
				'visible'  => in_array( $area, $areas, true ),
			);
		}
		$can_export = current_user_can( 'registrations.export' ) || current_user_can( 'approved.export' );
		return array_merge(
			$pages,
			array(
				array(
					'slug'     => \DMS\Admin\Pages\AnalyticsPage::SLUG,
					'title'    => __( 'Analytics', 'dms' ),
					'callback' => array( $p->page_analytics(), 'render' ),
					'visible'  => current_user_can( 'analytics.view' ),
				),
				array(
					'slug'     => \DMS\Admin\Pages\ReportsPage::SLUG,
					'title'    => __( 'Reports', 'dms' ),
					'callback' => array( $p->page_reports(), 'render' ),
					'visible'  => current_user_can( 'reports.view' ),
				),
				array(
					'slug'     => 'dms-assignments',
					'title'    => __( 'Assignments', 'dms' ),
					'callback' => array( $p->page_assignments(), 'render' ),
					'visible'  => current_user_can( 'assignment.view' ),
				),
				array(
					'slug'     => 'dms-exports',
					'title'    => __( 'My Exports', 'dms' ),
					'callback' => array( $p->page_exports(), 'render' ),
					'visible'  => $can_export,
				),
				array(
					'slug'     => 'dms-users',
					'title'    => __( 'Users', 'dms' ),
					'callback' => array( $p->page_users(), 'render' ),
					'visible'  => current_user_can( 'users.view' ),
				),
				array(
					'slug'     => 'dms-roles',
					'title'    => __( 'Roles & Permissions', 'dms' ),
					'callback' => array( $p->page_roles(), 'render' ),
					'visible'  => current_user_can( 'roles.view' ),
				),
				array(
					'slug'     => 'dms-audit',
					'title'    => __( 'Audit Logs', 'dms' ),
					'callback' => array( $p->page_audit(), 'render' ),
					'visible'  => current_user_can( 'audit.view' ),
				),
				array(
					'slug'     => \DMS\Admin\Pages\ElectoralDataPage::SLUG,
					'title'    => __( 'Electoral Data', 'dms' ),
					'callback' => array( $p->page_electoral(), 'render' ),
					'visible'  => current_user_can( 'imports.view' ) || current_user_can( 'imports.view_history' ),
				),
				array(
					'slug'     => \DMS\Admin\Pages\NotificationsPage::SLUG,
					'title'    => __( 'Notifications', 'dms' ),
					'callback' => array( $p->page_notifications(), 'render' ),
					'visible'  => \DMS\Admin\Pages\NotificationsPage::visible(),
				),
				array(
					'slug'     => 'dms-form-builder',
					'title'    => __( 'Form Builder', 'dms' ),
					'callback' => array( $p->page_form_builder(), 'render' ),
					'visible'  => current_user_can( 'settings.view' ),
				),
				array(
					'slug'     => SettingsPage::SLUG,
					'title'    => __( 'Settings', 'dms' ),
					'callback' => array( $p->settings_page(), 'render' ),
					'visible'  => current_user_can( 'settings.view' ),
				),
			)
		);
	}

	public function menu(): void {
		$visible = array_values( array_filter( $this->pages(), static fn( array $page ): bool => $page['visible'] ) );
		if ( array() === $visible ) {
			return;
		}
		// Each page renders inside the DMS app shell, whose sidebar lists these same pages.
		foreach ( $visible as $i => $page ) {
			$visible[ $i ]['callback'] = $this->shell()->wrap( $visible, $page['slug'], $page['callback'] );
		}
		// The top-level item opens the first page this user may see.
		add_menu_page( __( 'Data Management', 'dms' ), __( 'Data Management', 'dms' ), 'read', $visible[0]['slug'], $visible[0]['callback'], 'dashicons-clipboard', 26 );
		foreach ( $visible as $page ) {
			add_submenu_page( $visible[0]['slug'], $page['title'], $page['title'], 'read', $page['slug'], $page['callback'] );
		}
	}

	public function assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! str_starts_with( $page, 'dms' ) ) {
			return;
		}
		$base = plugin_dir_url( DMS_PLUGIN_FILE ) . 'admin/assets/';
		wp_enqueue_style( 'dms-admin', $base . 'admin.css', array(), DMS_VERSION );
		wp_enqueue_script( 'dms-admin', $base . 'admin.js', array(), DMS_VERSION, true );
		wp_localize_script(
			'dms-admin',
			'dmsAdmin',
			array(
				'restBase' => esc_url_raw( rest_url( 'dms/v1/public/' ) ),
				'i18n'     => array(
					'confirmTitle'       => __( 'Please confirm', 'dms' ),
					'confirmBulk'        => __( 'Are you sure you want to perform this action?', 'dms' ),
					/* translators: %d: number of selected records */
					'selected'           => __( '%d registration(s) selected.', 'dms' ),
					'confirmDestructive' => __( 'This action permanently deletes the selected records and cannot be undone.', 'dms' ),
					'confirmDelete'      => __( 'This action permanently deletes this record and cannot be undone.', 'dms' ),
					'cancel'             => __( 'Cancel', 'dms' ),
					'confirm'            => __( 'Confirm', 'dms' ),
					'deleteButton'       => __( 'Permanently Delete', 'dms' ),
					'nothingSelected'    => __( 'Select at least one registration first.', 'dms' ),
					'chooseAction'       => __( 'Choose a bulk action first.', 'dms' ),
					'all'                => __( 'All', 'dms' ),
				),
			)
		);
		unset( $hook );
	}

	public static function area_url( string $area, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::AREA_PAGES[ $area ] ), $args ), admin_url( 'admin.php' ) );
	}

	public static function registration_url( int $id, string $area = 'holding' ): string {
		return self::area_url( $area, array( 'registration' => $id ) );
	}

	/** Area slug for a status, used when linking to a record. */
	public static function area_for_status( string $status ): string {
		return match ( $status ) {
			'APPROVED'     => 'approved',
			'DISAPPROVED'  => 'bin',
			'UNDER_REVIEW' => 'under_review',
			'ASSIGNED'     => 'assigned',
			default        => 'holding',
		};
	}
}
