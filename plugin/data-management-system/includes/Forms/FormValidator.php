<?php
/**
 * Server-side validation and sanitization of a public submission against the
 * form definition (MP §9: never trust client-side validation).
 *
 * @package DMS
 */

namespace DMS\Forms;

use DMS\Errors\ValidationException;
use DMS\Registrations\PhoneNormalizer;

defined( 'ABSPATH' ) || exit;

class FormValidator {

	public function __construct( private FormDefinition $definition ) {
	}

	/**
	 * @param array<string,mixed> $input Raw request data.
	 * @return array<string,mixed> Clean registration columns, plus extra_fields and consent.
	 * @throws ValidationException
	 */
	public function validate( array $input ): array {
		$errors = array();
		$data   = array( 'extra_fields' => array() );

		foreach ( $this->definition->enabled_fields() as $field ) {
			$key   = $field['key'];
			$raw   = $input[ $key ] ?? '';
			$raw   = is_scalar( $raw ) ? trim( (string) wp_unslash( (string) $raw ) ) : '';
			$empty = '' === $raw;

			if ( $empty ) {
				if ( $field['required'] ) {
					/* translators: %s: field label */
					$errors[ $key ] = sprintf( __( '%s is required.', 'dms' ), $field['label'] );
				}
				if ( ! $field['custom'] ) {
					$data[ $key ] = null;
				}
				continue;
			}

			$value = $this->clean( $field, $raw, $error );
			if ( null !== $error ) {
				$errors[ $key ] = $error;
				continue;
			}

			if ( $field['custom'] ) {
				$data['extra_fields'][ substr( $key, 7 ) ] = $value;
			} elseif ( 'phone' === $field['type'] ) {
				$data['phone']            = $raw;
				$data['phone_normalized'] = $value;
			} else {
				$data[ $key ] = $value;
			}
		}

		// Consent is mandatory and not configurable (ARCH §71.3, §78.1).
		$consent = filter_var( $input['consent'] ?? false, FILTER_VALIDATE_BOOLEAN );
		if ( ! $consent ) {
			$errors['consent'] = __( 'You must give consent to submit this registration.', 'dms' );
		}
		$data['consent_given'] = $consent ? 1 : 0;

		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}
		if ( array() === $data['extra_fields'] ) {
			$data['extra_fields'] = null;
		}
		return $data;
	}

	/**
	 * Validates a partial staff edit (ARCH §71.2): only the submitted keys, each
	 * against its field definition. Phone and consent are not editable (OD-20).
	 * Custom fields are returned under extra_fields (merged by the caller).
	 *
	 * @param array<string,mixed> $changes
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	public function validate_changes( array $changes ): array {
		$fields = array();
		foreach ( $this->definition->fields() as $field ) {
			$fields[ $field['key'] ] = $field;
		}
		$errors = array();
		$clean  = array();
		foreach ( $changes as $key => $raw ) {
			$field = $fields[ $key ] ?? null;
			if ( null === $field || 'phone' === $field['type'] ) {
				$errors[ (string) $key ] = __( 'This field cannot be edited.', 'dms' );
				continue;
			}
			$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
			if ( '' === $raw ) {
				if ( $field['required'] && $field['enabled'] ) {
					/* translators: %s: field label */
					$errors[ $key ] = sprintf( __( '%s is required.', 'dms' ), $field['label'] );
					continue;
				}
				$value = null;
			} else {
				$value = $this->clean( $field, $raw, $error );
				if ( null !== $error ) {
					$errors[ $key ] = $error;
					continue;
				}
			}
			if ( $field['custom'] ) {
				$clean['extra_fields'][ substr( $key, 7 ) ] = $value;
			} else {
				$clean[ $key ] = $value;
			}
		}
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}
		return $clean;
	}

	/** @param array<string,mixed> $field */
	private function clean( array $field, string $raw, ?string &$error ): mixed {
		$error = null;
		$label = $field['label'];
		$max   = (int) $field['max'];

		switch ( $field['type'] ) {
			case 'phone':
				$normalized = PhoneNormalizer::normalize( $raw );
				if ( null === $normalized ) {
					$error = __( 'Enter a valid Ghana mobile number, e.g. 024 123 4567.', 'dms' );
				}
				return $normalized;

			case 'email':
				$email = sanitize_email( $raw );
				if ( ! is_email( $email ) || mb_strlen( $email ) > $max ) {
					$error = __( 'Enter a valid email address.', 'dms' );
				}
				return $email;

			case 'date':
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, new \DateTimeZone( 'UTC' ) );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $raw ) {
					$error = __( 'Enter a valid date.', 'dms' );
					return null;
				}
				if ( 'date_of_birth' === $field['key'] && ( $raw > gmdate( 'Y-m-d' ) || $raw < '1900-01-01' ) ) {
					$error = __( 'Enter a valid date of birth.', 'dms' );
				}
				return $raw;

			case 'select':
				if ( ! in_array( $raw, $field['options'], true ) ) {
					/* translators: %s: field label */
					$error = sprintf( __( 'Choose a valid option for %s.', 'dms' ), $label );
				}
				return $raw;

			case 'number':
				if ( ! is_numeric( $raw ) ) {
					/* translators: %s: field label */
					$error = sprintf( __( '%s must be a number.', 'dms' ), $label );
				}
				return $raw + 0;

			case 'electoral':
				$id = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( false === $id ) {
					/* translators: %s: field label */
					$error = sprintf( __( 'Select a %s.', 'dms' ), $label );
				}
				return false === $id ? null : $id;

			case 'textarea':
				$text = sanitize_textarea_field( $raw );
				break;

			default:
				$text = sanitize_text_field( $raw );
		}

		if ( mb_strlen( $text ) > $max ) {
			/* translators: 1: field label, 2: maximum length */
			$error = sprintf( __( '%1$s must be at most %2$d characters.', 'dms' ), $label, $max );
		}
		return $text;
	}
}
