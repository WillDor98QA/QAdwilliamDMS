<?php
/**
 * Maintainable, configurable email templates (MP §37).
 *
 * Plain-text emails. Each event has a default subject and body; administrators
 * may override them (settings.edit). Only the placeholders listed for an
 * event are replaced; anything else is left as typed. Line breaks are stripped
 * from subjects so a value can never inject extra mail headers.
 *
 * @package DMS
 */

namespace DMS\Notifications;

use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Errors\ValidationException;
use DMS\Support\Authorizer;

defined( 'ABSPATH' ) || exit;

class EmailTemplates {

	public const OPTION = 'dms_email_templates';

	public const MAX_SUBJECT = 200;
	public const MAX_BODY    = 5000;

	public function __construct( private AuditService $audit, private Authorizer $authorizer ) {
	}

	/**
	 * Defaults and allowed placeholders per event.
	 *
	 * @return array<string,array{label:string,subject:string,body:string,placeholders:list<string>}>
	 */
	public static function defaults(): array {
		return array(
			NotificationService::EVENT_REGISTRATION_RECEIVED => array(
				'label'        => __( 'Applicant: registration received', 'dms' ),
				'subject'      => __( 'We received your registration — {site_name}', 'dms' ),
				'body'         => __( "Dear {first_name},\n\nWe received your registration. Your reference number is {registration_number}.\n\nPlease keep this number for your records. You will receive another email when your registration has been approved.\n\nThank you.", 'dms' ),
				'placeholders' => array( 'site_name', 'first_name', 'last_name', 'registration_number' ),
			),
			NotificationService::EVENT_ADMIN_NEW_REGISTRATION => array(
				'label'        => __( 'Administrator: new registration', 'dms' ),
				'subject'      => __( 'New registration received: {registration_number}', 'dms' ),
				'body'         => __( "A new registration ({registration_number}) has been received and placed in the Holding Area.\n\nSign in to the Data Management dashboard to view it: {admin_url}", 'dms' ),
				'placeholders' => array( 'site_name', 'registration_number', 'admin_url' ),
			),
			NotificationService::EVENT_OFFICER_ASSIGNED => array(
				'label'        => __( 'Officer: registration assigned', 'dms' ),
				'subject'      => __( 'Registration pending approval: {registration_number}', 'dms' ),
				'body'         => __( "Hello {officer_name},\n\nRegistration {registration_number} has been assigned to you and is pending your review.\n\nSign in to the Data Management dashboard to review it: {admin_url}", 'dms' ),
				'placeholders' => array( 'site_name', 'officer_name', 'registration_number', 'admin_url' ),
			),
			NotificationService::EVENT_REGISTRATION_APPROVED => array(
				'label'        => __( 'Applicant: registration approved', 'dms' ),
				'subject'      => __( 'Your registration has been approved — {site_name}', 'dms' ),
				'body'         => __( "Dear {first_name},\n\nYour registration {registration_number} has been approved.\n\nThank you.", 'dms' ),
				'placeholders' => array( 'site_name', 'first_name', 'last_name', 'registration_number' ),
			),
			NotificationService::EVENT_ASSIGNMENT_EXCEPTION => array(
				'label'        => __( 'Administrator: no officer available', 'dms' ),
				'subject'      => __( 'No active officer available: {registration_number}', 'dms' ),
				'body'         => __( "Registration {registration_number} could not be assigned because no active officer is configured for {region_name}.\n\nIt remains PENDING. Configure an officer for this region or assign it manually: {admin_url}", 'dms' ),
				'placeholders' => array( 'site_name', 'registration_number', 'region_name', 'admin_url' ),
			),
		);
	}

	/** @return array{subject:string,body:string} The effective (possibly customised) template. */
	public function get( string $event ): array {
		$default = self::defaults()[ $event ] ?? null;
		if ( null === $default ) {
			throw new \InvalidArgumentException( "Unknown email event {$event}" );
		}
		$stored = get_option( self::OPTION, array() );
		$custom = is_array( $stored[ $event ] ?? null ) ? $stored[ $event ] : array();
		return array(
			'subject' => '' !== trim( (string) ( $custom['subject'] ?? '' ) ) ? (string) $custom['subject'] : $default['subject'],
			'body'    => '' !== trim( (string) ( $custom['body'] ?? '' ) ) ? (string) $custom['body'] : $default['body'],
		);
	}

	public function is_customised( string $event ): bool {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored[ $event ] ?? null );
	}

	/**
	 * @param array<string,string> $values placeholder => value
	 * @return array{subject:string,body:string}
	 */
	public function render( string $event, array $values ): array {
		$template = $this->get( $event );
		$allowed  = self::defaults()[ $event ]['placeholders'];
		$replace  = array();
		foreach ( $allowed as $name ) {
			$replace[ '{' . $name . '}' ] = (string) ( $values[ $name ] ?? '' );
		}
		$subject = strtr( $template['subject'], $replace );
		return array(
			// Header-injection guard: a subject is always one line.
			'subject' => trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $subject ) ),
			'body'    => strtr( $template['body'], $replace ),
		);
	}

	/**
	 * Saves customised templates. An empty subject and body resets that event to the default.
	 *
	 * @param array<string,array{subject?:string,body?:string}> $input
	 * @throws ValidationException
	 */
	public function save( array $input ): void {
		$this->authorizer->require( 'settings.edit' );
		$errors = array();
		$clean  = array();
		foreach ( $input as $event => $values ) {
			$default = self::defaults()[ $event ] ?? null;
			if ( null === $default ) {
				$errors[ (string) $event ] = __( 'Unknown email.', 'dms' );
				continue;
			}
			$subject = trim( (string) preg_replace( '/[\r\n\t]+/', ' ', sanitize_text_field( (string) ( $values['subject'] ?? '' ) ) ) );
			$body    = trim( sanitize_textarea_field( (string) ( $values['body'] ?? '' ) ) );
			if ( '' === $subject && '' === $body ) {
				continue; // Back to the default.
			}
			if ( '' === $subject || '' === $body ) {
				$errors[ "{$event}.subject" ] = __( 'Enter both a subject and a message, or leave both empty to use the default.', 'dms' );
				continue;
			}
			if ( mb_strlen( $subject ) > self::MAX_SUBJECT || mb_strlen( $body ) > self::MAX_BODY ) {
				/* translators: 1: max subject length, 2: max body length */
				$errors[ "{$event}.body" ] = sprintf( __( 'Subject up to %1$d characters, message up to %2$d.', 'dms' ), self::MAX_SUBJECT, self::MAX_BODY );
				continue;
			}
			preg_match_all( '/\{([a-z_]+)\}/', $subject . ' ' . $body, $found );
			$unknown = array_diff( array_unique( $found[1] ), $default['placeholders'] );
			if ( array() !== $unknown ) {
				/* translators: 1: unknown placeholders, 2: allowed placeholders */
				$errors[ "{$event}.body" ] = sprintf( __( 'Unknown placeholder(s): %1$s. Available: %2$s.', 'dms' ), '{' . implode( '}, {', $unknown ) . '}', '{' . implode( '}, {', $default['placeholders'] ) . '}' );
				continue;
			}
			$clean[ $event ] = array(
				'subject' => $subject,
				'body'    => $body,
			);
		}
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}
		$old = get_option( self::OPTION, array() );
		if ( $old === $clean ) {
			return;
		}
		update_option( self::OPTION, $clean, false );
		$this->audit->record(
			AuditAction::SETTINGS_CHANGED,
			array(
				'object_type' => 'email_templates',
				'metadata'    => array( 'customised_events' => array_keys( $clean ) ),
			)
		);
	}
}
