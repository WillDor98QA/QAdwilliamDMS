<?php
/**
 * Registration lists for staff: request filters + area + access scope, in one
 * place so list screens, exports and bulk selections can't disagree
 * (ARCH §73, §75; Decisions §17, §23; §78.14, §78.19).
 *
 * @package DMS
 */

namespace DMS\Registrations;

use DMS\Errors\NotFoundException;

defined( 'ABSPATH' ) || exit;

class RegistrationListService {

	public function __construct( private RegistrationRepository $registrations, private AccessPolicy $access ) {
	}

	/**
	 * Areas the user can open. An area is available when the user's scope can
	 * contain any of its statuses (or they can see their own assignments).
	 *
	 * @return list<string>
	 */
	public function visible_areas( int $user_id ): array {
		$scope = $this->access->scope( $user_id );
		$areas = array();
		foreach ( RegistrationFilter::AREAS as $area => $statuses ) {
			$granted = array_map( static fn( $s ): string => $s->value, $scope['statuses'] );
			if ( $scope['all'] || $scope['own_assigned'] || array() !== array_intersect( $statuses, $granted ) ) {
				$areas[] = $area;
			}
		}
		return $areas;
	}

	/**
	 * Builds the filter for this user and area from raw request input.
	 *
	 * @param array<string,mixed> $input
	 * @throws NotFoundException When the area is unknown or not visible to the user.
	 */
	public function filter_for( int $user_id, string $area, array $input ): RegistrationFilter {
		if ( ! in_array( $area, $this->visible_areas( $user_id ), true ) ) {
			throw new NotFoundException();
		}
		$filter = RegistrationFilter::from_array( $input );
		$filter->restrict_to_area( $area );
		$filter->apply_scope( $this->access->scope( $user_id ), $user_id );
		return $filter;
	}

	/** @return array{items:list<object>,total:int,page:int,per_page:int} */
	public function search( int $user_id, string $area, array $input ): array {
		return $this->registrations->search( $this->filter_for( $user_id, $area, $input ) );
	}
}
