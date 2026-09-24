<?php
/**
 * Available SMS gateways. Providers are added via the `dms_sms_gateways` filter:
 *
 *     add_filter( 'dms_sms_gateways', fn( $g ) => $g + [ 'acme' => new AcmeGateway( ... ) ] );
 *
 * @package DMS
 */

namespace DMS\Sms;

use DMS\Support\SecretStore;
use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

class SmsGatewayRegistry {

	public function __construct( private ?SecretStore $secrets = null, private ?Settings $settings = null ) {
	}

	/** @return array<string,SmsGateway> */
	public function all(): array {
		$gateways = array( DevelopmentEmailGateway::ID => new DevelopmentEmailGateway() );
		if ( null !== $this->secrets && null !== $this->settings ) {
			$gateways[ DeywuroGateway::ID ] = new DeywuroGateway( $this->secrets, $this->settings );
		}
		/**
		 * @param array<string,SmsGateway> $gateways
		 */
		$gateways = apply_filters( 'dms_sms_gateways', $gateways );
		return array_filter( $gateways, static fn( $g ): bool => $g instanceof SmsGateway );
	}

	public function get( string $id ): ?SmsGateway {
		return $this->all()[ $id ] ?? null;
	}
}
