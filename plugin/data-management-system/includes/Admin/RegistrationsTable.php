<?php
/**
 * Registration list for one area (ARCH §5, §73, §74): server-side search,
 * filters, sorting, pagination and bulk selection. Rows come from
 * RegistrationListService, so only records in the user's scope appear.
 *
 * @package DMS
 */

namespace DMS\Admin;

use DMS\Bulk\BulkActionService;
use DMS\Electoral\ElectoralLevel;
use DMS\Export\ExportService;
use DMS\Plugin;
use DMS\Registrations\RegistrationFilter;
use DMS\Workflow\Status;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class RegistrationsTable extends \WP_List_Table {

	/** @param array<string,mixed> $query Sanitized request filters. */
	public function __construct( private Plugin $plugin, private string $area, private array $query ) {
		parent::__construct(
			array(
				'singular' => 'registration',
				'plural'   => 'registrations',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		$columns = array(
			'registration_number' => __( 'Registration', 'dms' ),
			'name'                => __( 'Applicant', 'dms' ),
			'organization'        => __( 'Organization', 'dms' ),
			'location'            => __( 'Region / Constituency', 'dms' ),
		);
		// Each area shows the fields that matter there (all already on the row).
		if ( 'approved' === $this->area ) {
			$columns['approved'] = __( 'Approved', 'dms' );
		} elseif ( 'bin' === $this->area ) {
			$columns['disapproval'] = __( 'Disapproval reason', 'dms' );
			$columns['retention']   = __( 'Retention', 'dms' );
		} else {
			// Queue areas follow the reference's work-queue columns; organization stays on the record.
			unset( $columns['organization'] );
			$columns['status']  = __( 'Status', 'dms' );
			$columns['officer'] = __( 'Assigned Officer', 'dms' );
			$columns['waiting'] = __( 'Waiting', 'dms' );
		}
		if ( array() !== $this->get_bulk_actions() ) {
			$columns = array( 'cb' => '<input type="checkbox" />' ) + $columns;
		}
		return $columns;
	}

	protected function get_sortable_columns(): array {
		return array(
			'registration_number' => array( 'registration_number', false ),
			'name'                => array( 'last_name', false ),
			'status'              => array( 'status', false ),
			'submitted_at'        => array( 'submitted_at', true ),
			'waiting'             => array( 'submitted_at', true ),
			'approved'            => array( 'approved_at', true ),
			'disapproval'         => array( 'disapproved_at', true ),
		);
	}

	/** Only actions the user may perform, and that make sense in this area (ARCH §74). */
	protected function get_bulk_actions(): array {
		$actions = array();
		$areas   = array(
			'assign'   => array( 'holding' ),
			'reassign' => array( 'holding', 'assigned', 'under_review' ),
			'restore'  => array( 'bin' ),
			'delete'   => array( 'bin' ),
		);
		$labels  = array(
			'assign'   => __( 'Assign to officer', 'dms' ),
			'reassign' => __( 'Reassign to officer', 'dms' ),
			'restore'  => __( 'Restore', 'dms' ),
			'delete'   => __( 'Delete permanently', 'dms' ),
		);
		foreach ( BulkActionService::ACTIONS as $action => $permission ) {
			if ( in_array( $this->area, $areas[ $action ], true ) && current_user_can( $permission ) ) {
				$actions[ $action ] = $labels[ $action ];
			}
		}
		if ( current_user_can( ExportService::permission_for( $this->area ) ) ) {
			$actions['export_csv']  = __( 'Export selected (CSV)', 'dms' );
			$actions['export_xlsx'] = __( 'Export selected (Excel)', 'dms' );
		}
		return $actions;
	}

	public function prepare_items(): void {
		$result                = $this->plugin->lists()->search( get_current_user_id(), $this->area, $this->query );
		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'registration_number' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $result['per_page'],
				'total_pages' => (int) ceil( $result['total'] / max( 1, $result['per_page'] ) ),
			)
		);
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" aria-label="%s" />', (int) $item->id, esc_attr( $item->registration_number ) );
	}

	protected function column_registration_number( $item ): string {
		return sprintf( '<strong><a href="%1$s">%2$s</a></strong>', esc_url( AdminMenu::registration_url( (int) $item->id, $this->area ) ), esc_html( $item->registration_number ) );
	}

	protected function column_name( $item ): string {
		$name  = trim( implode( ' ', array_filter( array( $item->first_name, $item->middle_name, $item->last_name ) ) ) );
		$badge = (int) $item->phone_verified ? '' : ' <span class="dms-badge dms-badge--muted">' . esc_html__( 'unverified', 'dms' ) . '</span>';
		return '<span class="dms-person">' . View::avatar( $name ) . '<span><strong>' . esc_html( $name ) . '</strong><br><span class="description">' . esc_html( (string) $item->phone_normalized ) . '</span>' . $badge . '</span></span>';
	}

	protected function column_waiting( $item ): string {
		return '<strong>' . esc_html( View::age( $item->submitted_at ) ) . '</strong><br><span class="description">' . esc_html( View::short_date( $item->submitted_at ) ) . '</span>';
	}

	protected function column_approved( $item ): string {
		$by = $item->approved_by ? get_user_by( 'id', (int) $item->approved_by ) : null;
		return esc_html( View::short_date( $item->approved_at ) ) . ( $by ? '<br><span class="description">' . esc_html( $by->display_name ) . '</span>' : '' );
	}

	protected function column_disapproval( $item ): string {
		return '<span class="dms-reason-cell">' . esc_html( (string) $item->disapproval_reason ) . '</span><br><span class="description">' . esc_html( View::short_date( $item->disapproved_at ) ) . '</span>';
	}

	/** Days until automatic deletion (display of the existing retention rule). */
	protected function column_retention( $item ): string {
		if ( ! $item->disapproved_at ) {
			return '';
		}
		$days    = $this->plugin->page_registrations()->retention_days();
		$elapsed = intdiv( max( 0, time() - (int) strtotime( $item->disapproved_at . ' UTC' ) ), DAY_IN_SECONDS );
		$left    = max( 0, $days - $elapsed );
		/* translators: %d: days */
		$label = 0 === $left ? __( 'Due for deletion', 'dms' ) : sprintf( _n( '%d day left', '%d days left', $left, 'dms' ), $left );
		return sprintf( '<span class="dms-pill dms-pill--%1$s">%2$s</span>', $left <= 7 ? 'red' : 'amber', esc_html( $label ) );
	}

	protected function column_organization( $item ): string {
		return esc_html( (string) $item->organization );
	}

	protected function column_location( $item ): string {
		return esc_html( $item->region_name ) . '<br><span class="description">' . esc_html( $item->constituency_name ) . '</span>';
	}

	protected function column_status( $item ): string {
		$status = Status::from( $item->status );
		return sprintf( '<span class="dms-status dms-status--%1$s">%2$s</span>', esc_attr( strtolower( $status->value ) ), esc_html( $status->label() ) );
	}

	protected function column_officer( $item ): string {
		return $item->officer_name ? esc_html( $item->officer_name ) : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Unassigned', 'dms' ) . '</span>';
	}

	public function no_items(): void {
		esc_html_e( 'No registrations match. Try clearing the search or filters.', 'dms' );
	}

	/** Filters shown above the table. */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$q       = $this->query;
		$regions = $this->plugin->electoral()->options( ElectoralLevel::REGION );
		echo '<div class="alignleft actions dms-filters">';

		printf( '<label class="screen-reader-text" for="dms-filter-region">%s</label>', esc_html__( 'Region', 'dms' ) );
		echo '<select name="region_id" id="dms-filter-region" data-dms-cascade="region"><option value="">' . esc_html__( 'All regions', 'dms' ) . '</option>';
		foreach ( $regions as $r ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $r['id'], selected( (int) ( $q['region_id'] ?? 0 ), $r['id'], false ), esc_html( $r['name'] ) );
		}
		echo '</select>';

		$constituencies = ! empty( $q['region_id'] ) ? $this->plugin->electoral()->options( ElectoralLevel::CONSTITUENCY, (int) $q['region_id'] ) : array();
		printf( '<label class="screen-reader-text" for="dms-filter-constituency">%s</label>', esc_html__( 'Constituency', 'dms' ) );
		echo '<select name="constituency_id" id="dms-filter-constituency" data-dms-cascade="constituency"' . ( array() === $constituencies ? ' disabled' : '' ) . '><option value="">' . esc_html__( 'All constituencies', 'dms' ) . '</option>';
		foreach ( $constituencies as $c ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $c['id'], selected( (int) ( $q['constituency_id'] ?? 0 ), $c['id'], false ), esc_html( $c['name'] ) );
		}
		echo '</select>';

		$stations = ! empty( $q['constituency_id'] ) ? $this->plugin->electoral()->options( ElectoralLevel::POLLING_STATION, (int) $q['constituency_id'] ) : array();
		printf( '<label class="screen-reader-text" for="dms-filter-station">%s</label>', esc_html__( 'Polling Station', 'dms' ) );
		echo '<select name="polling_station_id" id="dms-filter-station" data-dms-cascade="polling_station"' . ( array() === $stations ? ' disabled' : '' ) . '><option value="">' . esc_html__( 'All polling stations', 'dms' ) . '</option>';
		foreach ( $stations as $s ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $s['id'], selected( (int) ( $q['polling_station_id'] ?? 0 ), $s['id'], false ), esc_html( $s['name'] ) );
		}
		echo '</select>';

		if ( 'holding' === $this->area ) {
			// The Holding Area's status tabs set this filter; keep it when other filters are applied.
			if ( ! empty( $q['status'] ) ) {
				printf( '<input type="hidden" name="status" value="%s">', esc_attr( (string) $q['status'] ) );
			}
		} elseif ( count( RegistrationFilter::AREAS[ $this->area ] ) > 1 ) {
			printf( '<label class="screen-reader-text" for="dms-filter-status">%s</label>', esc_html__( 'Status', 'dms' ) );
			echo '<select name="status" id="dms-filter-status"><option value="">' . esc_html__( 'All statuses', 'dms' ) . '</option>';
			foreach ( RegistrationFilter::AREAS[ $this->area ] as $s ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $s ), selected( (string) ( $q['status'] ?? '' ), $s, false ), esc_html( Status::from( $s )->label() ) );
			}
			echo '</select>';
		}

		$officers = $this->plugin->page_registrations()->officer_options();
		if ( array() !== $officers && ( current_user_can( 'registrations.view' ) || current_user_can( 'assignment.view' ) ) ) {
			printf( '<label class="screen-reader-text" for="dms-filter-officer">%s</label>', esc_html__( 'Officer', 'dms' ) );
			echo '<select name="officer_id" id="dms-filter-officer"><option value="">' . esc_html__( 'All officers', 'dms' ) . '</option>';
			foreach ( $officers as $id => $name ) {
				printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $id, selected( (int) ( $q['officer_id'] ?? 0 ), $id, false ), esc_html( $name ) );
			}
			echo '</select>';
		}

		printf(
			'<label for="dms-filter-from">%1$s</label> <input type="date" id="dms-filter-from" name="submitted_from" value="%2$s"> <label for="dms-filter-to">%3$s</label> <input type="date" id="dms-filter-to" name="submitted_to" value="%4$s">',
			esc_html__( 'Submitted from', 'dms' ),
			esc_attr( (string) ( $q['submitted_from'] ?? '' ) ),
			esc_html__( 'to', 'dms' ),
			esc_attr( (string) ( $q['submitted_to'] ?? '' ) )
		);
		if ( 'holding' === $this->area ) {
			printf( ' <label><input type="checkbox" name="unassigned" value="1"%1$s> %2$s</label>', checked( ! empty( $q['unassigned'] ), true, false ), esc_html__( 'Unassigned only', 'dms' ) );
		}
		submit_button( __( 'Filter', 'dms' ), '', 'filter_action', false );
		echo '</div>';
	}

	/** Bulk-action bar extras: officer for (re)assign; the modal lives in admin.js. */
	protected function bulk_actions( $which = '' ): void {
		parent::bulk_actions( $which );
		if ( 'top' !== $which ) {
			return;
		}
		$bulk = $this->get_bulk_actions();
		if ( isset( $bulk['assign'] ) || isset( $bulk['reassign'] ) ) {
			printf( '<label class="screen-reader-text" for="dms-bulk-officer">%s</label>', esc_html__( 'Officer', 'dms' ) );
			echo '<select name="officer_id" id="dms-bulk-officer" data-dms-bulk-officer><option value="">' . esc_html__( 'Choose officer…', 'dms' ) . '</option>';
			foreach ( $this->plugin->page_registrations()->officer_options() as $id => $name ) {
				printf( '<option value="%1$d">%2$s</option>', (int) $id, esc_html( $name ) );
			}
			echo '</select>';
		}
	}
}
