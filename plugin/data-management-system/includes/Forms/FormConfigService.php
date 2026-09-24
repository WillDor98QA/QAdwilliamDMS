<?php
/**
 * Form Builder / Field Configuration saves (ARCH §42–§44, Decisions §15).
 *
 * Allowed: relabel, reorder within a section, required/optional, enable/disable
 * eligible fields, edit select options, add/edit/remove custom personal
 * fields. System fields (phone, region, constituency, polling station) stay
 * enabled and required; consent is not configurable. Removing a custom field
 * hides it from the form but leaves already-collected values untouched.
 * Requires settings.edit; every change is audited (FORM_CHANGED).
 *
 * @package DMS
 */

namespace DMS\Forms;

use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Errors\ValidationException;
use DMS\Support\Authorizer;

defined( 'ABSPATH' ) || exit;

class FormConfigService {

	public const MAX_CUSTOM_FIELDS = 30;
	public const MAX_OPTIONS       = 100;

	public function __construct( private AuditService $audit, private Authorizer $authorizer ) {
	}

	/** @return array{fields:array<string,array<string,mixed>>,custom:list<array<string,mixed>>} */
	public function current(): array {
		$config = get_option( FormDefinition::OPTION, array() );
		return array(
			'fields' => is_array( $config['fields'] ?? null ) ? $config['fields'] : array(),
			'custom' => is_array( $config['custom'] ?? null ) ? array_values( $config['custom'] ) : array(),
		);
	}

	/**
	 * @param array{fields?:array<string,array<string,mixed>>,custom?:list<array<string,mixed>>} $input
	 * @throws ValidationException
	 */
	public function save( array $input ): void {
		$this->authorizer->require( 'settings.edit' );

		$errors   = array();
		$defaults = array();
		foreach ( FormDefinition::defaults() as $field ) {
			$defaults[ $field['key'] ] = $field;
		}

		$fields = array();
		foreach ( (array) ( $input['fields'] ?? array() ) as $key => $o ) {
			if ( ! isset( $defaults[ $key ] ) || ! is_array( $o ) ) {
				$errors[ "fields.{$key}" ] = __( 'Unknown field.', 'dms' );
				continue;
			}
			$clean = array();
			if ( isset( $o['label'] ) ) {
				$label = trim( sanitize_text_field( (string) $o['label'] ) );
				if ( '' === $label || mb_strlen( $label ) > 100 ) {
					$errors[ "fields.{$key}.label" ] = __( 'Labels must be 1–100 characters.', 'dms' );
				}
				$clean['label'] = $label;
			}
			if ( ! $defaults[ $key ]['system'] ) {
				$clean['required'] = ! empty( $o['required'] );
				$clean['enabled']  = ! empty( $o['enabled'] );
			}
			if ( isset( $o['order'] ) ) {
				$clean['order'] = (int) $o['order'];
			}
			if ( 'select' === $defaults[ $key ]['type'] && isset( $o['options'] ) ) {
				$clean['options'] = $this->clean_options( $o['options'], "fields.{$key}.options", $errors );
			}
			$fields[ $key ] = $clean;
		}

		$custom = array();
		$keys   = array();
		$list   = array_values( (array) ( $input['custom'] ?? array() ) );
		if ( count( $list ) > self::MAX_CUSTOM_FIELDS ) {
			/* translators: %d: maximum */
			$errors['custom'] = sprintf( __( 'At most %d custom fields.', 'dms' ), self::MAX_CUSTOM_FIELDS );
		}
		foreach ( $list as $i => $c ) {
			$c     = (array) $c;
			$key   = sanitize_key( (string) ( $c['key'] ?? '' ) );
			$label = trim( sanitize_text_field( (string) ( $c['label'] ?? '' ) ) );
			$type  = (string) ( $c['type'] ?? '' );
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) || isset( $keys[ $key ] ) || isset( $defaults[ $key ] ) ) {
				$errors[ "custom.{$i}.key" ] = __( 'Keys must be unique, start with a letter, and use lowercase letters, digits or underscores.', 'dms' );
			}
			if ( '' === $label || mb_strlen( $label ) > 100 ) {
				$errors[ "custom.{$i}.label" ] = __( 'Labels must be 1–100 characters.', 'dms' );
			}
			if ( ! in_array( $type, FormDefinition::CUSTOM_TYPES, true ) ) {
				$errors[ "custom.{$i}.type" ] = __( 'Choose a valid field type.', 'dms' );
			}
			$options      = 'select' === $type ? $this->clean_options( $c['options'] ?? array(), "custom.{$i}.options", $errors ) : array();
			$keys[ $key ] = true;
			$custom[]     = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $c['required'] ),
				'enabled'  => ! array_key_exists( 'enabled', $c ) || ! empty( $c['enabled'] ),
				'options'  => $options,
				'order'    => (int) ( $c['order'] ?? 1000 + $i ),
			);
		}

		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}

		$old = $this->current();
		$new = array(
			'fields' => $fields,
			'custom' => $custom,
		);
		if ( $old == $new ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- structural comparison of arrays.
			return;
		}
		update_option( FormDefinition::OPTION, $new, false );
		$this->audit->record(
			AuditAction::FORM_CHANGED,
			array(
				'object_type' => 'form',
				'metadata'    => array(
					'old' => $old,
					'new' => $new,
				),
			)
		);
	}

	/**
	 * @param array<string,string> $errors
	 * @return list<string>
	 */
	private function clean_options( mixed $options, string $error_key, array &$errors ): array {
		$options = is_array( $options ) ? $options : preg_split( '/\r\n|\r|\n/', (string) $options );
		$clean   = array_values( array_unique( array_filter( array_map( static fn( $o ): string => trim( sanitize_text_field( (string) $o ) ), (array) $options ), static fn( string $o ): bool => '' !== $o ) ) );
		if ( array() === $clean || count( $clean ) > self::MAX_OPTIONS ) {
			/* translators: %d: maximum */
			$errors[ $error_key ] = sprintf( __( 'Enter between 1 and %d options, one per line.', 'dms' ), self::MAX_OPTIONS );
		}
		return $clean;
	}
}
