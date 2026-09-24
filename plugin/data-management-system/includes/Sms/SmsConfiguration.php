<?php
/**
 * Resolves the configured gateway and whether SMS is ready to use.
 * Read on every call, so a settings change takes effect immediately.
 *
 * @package DMS
 */

namespace DMS\Sms;

use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

class SmsConfiguration {

	public function __construct( private Settings $settings, private SmsGatewayRegistry $registry ) {
	}

	public function gateway(): ?SmsGateway {
		$id = (string) $this->settings->get( 'sms_gateway' );
		return '' === $id ? null : $this->registry->get( $id );
	}

	/** @return list<string> Admin-safe problems; empty means SMS can be used. */
	public function problems(): array {
		$id = (string) $this->settings->get( 'sms_gateway' );
		if ( '' === $id ) {
			return array( __( 'No SMS provider is selected.', 'dms' ) );
		}
		$gateway = $this->registry->get( $id );
		if ( null === $gateway ) {
			return array( __( 'The selected SMS provider is no longer available.', 'dms' ) );
		}
		return $gateway->configuration_problems();
	}

	public function is_ready(): bool {
		return array() === $this->problems();
	}
}
