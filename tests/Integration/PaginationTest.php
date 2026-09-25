<?php
/**
 * Pagination on admin tables that used to load everything or stop at a fixed
 * number of rows: Users, Assignments (exceptions and workload), Reports,
 * Import History and import changes all share View::pagination().
 */

namespace DMS\Tests\Integration;

use DMS\Admin\View;
use DMS\Plugin;

final class PaginationTest extends \WP_UnitTestCase {

	use Fixtures;

	private ?string $uri = null;

	public function set_up(): void {
		parent::set_up();
		$this->uri = $_SERVER['REQUEST_URI'] ?? null;
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
	}

	public function tear_down(): void {
		$_GET = array();
		if ( null === $this->uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->uri;
		}
		parent::tear_down();
	}

	private function render( callable $callback, array $get, string $uri ): string {
		$_GET                   = $get;
		$_SERVER['REQUEST_URI'] = $uri;
		ob_start();
		try {
			$callback();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/** Rows in the first table body of $html. */
	private function body_rows( string $html, int $nth = 0 ): int {
		preg_match_all( '#<tbody>(.*?)</tbody>#s', $html, $m );
		return substr_count( $m[1][ $nth ] ?? '', '<tr' );
	}

	public function test_pagination_component_counts_and_keeps_other_arguments(): void {
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=dms-reports&report=decisions_by_month&region_id=4&paged=2';
		ob_start();
		View::pagination( 120, 50, 2 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '120 items', $html );
		$this->assertStringContainsString( '2 of 3', $html );
		$this->assertMatchesRegularExpression( '#href="[^"]*report=decisions_by_month[^"]*region_id=4[^"]*paged=3"#', $html, 'Next keeps the report and filter' );
		$this->assertMatchesRegularExpression( '#href="[^"]*paged=1"#', $html, 'First page link' );

		ob_start();
		View::pagination( 7, 20, 1 );
		$single = (string) ob_get_clean();
		$this->assertStringContainsString( '7 items', $single );
		$this->assertStringNotContainsString( 'pagination-links', $single, 'No page links for a single page' );
	}

	public function test_users_list_is_paged_sorted_and_searchable(): void {
		$this->act_as_admin();
		$region = $this->make_hierarchy()['region'];
		for ( $i = 1; $i <= 23; $i++ ) {
			$id = $this->make_officer( array( $region ) );
			wp_update_user(
				array(
					'ID'           => $id,
					'display_name' => sprintf( 'Officer %02d', $i ),
				)
			);
		}
		$special = $this->make_officer( array( $region ) );
		wp_update_user(
			array(
				'ID'           => $special,
				'display_name' => 'Zebulon Quaye',
				'user_email'   => 'zebulon@example.org',
			)
		);
		$page = array( Plugin::instance()->page_users(), 'render' );
		$uri  = '/wp-admin/admin.php?page=dms-users';

		$first = $this->render( $page, array( 'page' => 'dms-users' ), $uri );
		$this->assertSame( 20, $this->body_rows( $first ) );
		$this->assertStringContainsString( '25 items', $first, '24 officers plus the administrator' );
		$this->assertStringContainsString( '1 of 2', $first );

		$second = $this->render(
			$page,
			array(
				'page'  => 'dms-users',
				'paged' => '2',
			),
			$uri . '&paged=2'
		);
		$this->assertSame( 5, $this->body_rows( $second ) );
		$this->assertStringContainsString( 'Zebulon Quaye', $second, 'Sorted by name: Zebulon is on the last page' );
		$this->assertStringNotContainsString( 'Zebulon Quaye', $first );

		$found = $this->render(
			$page,
			array(
				'page' => 'dms-users',
				's'    => 'zebulon@',
			),
			$uri . '&s=zebulon%40'
		);
		$this->assertSame( 1, $this->body_rows( $found ) );
		$this->assertStringContainsString( 'Zebulon Quaye', $found );
		$this->assertStringContainsString( '1 item', $found );

		$none = $this->render(
			$page,
			array(
				'page' => 'dms-users',
				's'    => 'nobody-here',
			),
			$uri . '&s=nobody-here'
		);
		$this->assertStringContainsString( 'No users match this search.', $none );
	}

	public function test_assignment_exceptions_are_paged_without_a_silent_cap(): void {
		$this->act_as_admin();
		$h    = $this->make_hierarchy();
		$repo = Plugin::instance()->assignment_exceptions();
		for ( $i = 0; $i < 205; $i++ ) {
			$reg = $this->make_registration( $h );
			$repo->open( $reg, $h['region'], 'NO_ELIGIBLE_OFFICER', '' );
		}
		$this->assertSame( 205, $repo->count_open() );
		$this->assertCount( 5, $repo->open_list( 20, 200 ), 'Rows past the old 200 cap are reachable' );
		$first_ids = array_column( $repo->open_list( 20, 0 ), 'id' );
		$next_ids  = array_column( $repo->open_list( 20, 20 ), 'id' );
		$this->assertSame( array(), array_intersect( $first_ids, $next_ids ), 'Pages never repeat a row' );

		$page = array( Plugin::instance()->page_assignments(), 'render' );
		$uri  = '/wp-admin/admin.php?page=dms-assignments';
		$html = $this->render( $page, array( 'page' => 'dms-assignments' ), $uri );
		$this->assertSame( 20, $this->body_rows( $html, 0 ) );
		$this->assertStringContainsString( '205 items', $html );
		$this->assertStringContainsString( '1 of 11', $html );

		$last = $this->render(
			$page,
			array(
				'page'  => 'dms-assignments',
				'epage' => '11',
			),
			$uri . '&epage=11'
		);
		$this->assertSame( 5, $this->body_rows( $last, 0 ) );

		$beyond = $this->render(
			$page,
			array(
				'page'  => 'dms-assignments',
				'epage' => '99',
			),
			$uri . '&epage=99'
		);
		$this->assertSame( 5, $this->body_rows( $beyond, 0 ), 'A page number past the end shows the last page' );
	}
}
