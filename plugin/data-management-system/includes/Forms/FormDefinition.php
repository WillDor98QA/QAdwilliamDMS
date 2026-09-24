<?php
/**
 * The single V1 registration form (ARCH §32, §33, §42; Decisions §15, §16).
 *
 * Three fixed sections in a fixed order: Personal Information → Organization
 * → Electoral Information, followed by Consent. Administrators may relabel,
 * reorder within a section, require/optional, enable/disable eligible fields,
 * edit select options, and add custom personal fields (stored in
 * extra_fields). System fields — phone, region, constituency, polling
 * station, consent — are always enabled and required.
 *
 * Overrides live in the `dms_form_config` option; the Form Builder screen
 * (Phase 6) edits that option. No form versioning (Decisions §16).
 *
 * @package DMS
 */

namespace DMS\Forms;

defined( 'ABSPATH' ) || exit;

class FormDefinition {

	public const OPTION = 'dms_form_config';

	public const SECTIONS = array( 'personal', 'organization', 'electoral' );

	public const CUSTOM_TYPES = array( 'text', 'textarea', 'select', 'date', 'email', 'number' );

	/**
	 * Default field set. Required flags are defaults (implementation decision ID-21),
	 * editable in the Form Builder except for system fields.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function defaults(): array {
		$f = static fn( string $key, string $section, string $label, string $type, bool $required, bool $system = false, array $options = array(), int $max = 100 ): array => array(
			'key'      => $key,
			'section'  => $section,
			'label'    => $label,
			'type'     => $type,
			'required' => $required,
			'enabled'  => true,
			'system'   => $system,
			'custom'   => false,
			'options'  => $options,
			'max'      => $max,
		);
		return array(
			$f( 'first_name', 'personal', 'First Name', 'text', true ),
			$f( 'middle_name', 'personal', 'Middle Name', 'text', false ),
			$f( 'last_name', 'personal', 'Last Name', 'text', true ),
			$f( 'date_of_birth', 'personal', 'Date of Birth', 'date', true ),
			$f( 'gender', 'personal', 'Gender', 'select', true, false, array( 'Male', 'Female' ), 30 ),
			$f( 'phone', 'personal', 'Phone Number', 'phone', true, true, array(), 30 ),
			$f( 'email', 'personal', 'Email', 'email', true, false, array(), 191 ),
			$f( 'address', 'personal', 'Residential Address', 'textarea', false, false, array(), 1000 ),
			$f( 'organization', 'organization', 'Organization', 'text', true, false, array(), 191 ),
			$f( 'region_id', 'electoral', 'Region', 'electoral', true, true ),
			$f( 'constituency_id', 'electoral', 'Constituency', 'electoral', true, true ),
			$f( 'polling_station_id', 'electoral', 'Polling Station', 'electoral', true, true ),
		);
	}

	/**
	 * Effective fields in display order: defaults + stored overrides + custom fields.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function fields(): array {
		$config    = get_option( self::OPTION, array() );
		$config    = is_array( $config ) ? $config : array();
		$overrides = is_array( $config['fields'] ?? null ) ? $config['fields'] : array();
		$custom    = is_array( $config['custom'] ?? null ) ? $config['custom'] : array();

		$fields = array();
		foreach ( self::defaults() as $index => $field ) {
			$o = is_array( $overrides[ $field['key'] ] ?? null ) ? $overrides[ $field['key'] ] : array();
			if ( isset( $o['label'] ) && '' !== trim( (string) $o['label'] ) ) {
				$field['label'] = (string) $o['label'];
			}
			if ( ! $field['system'] ) {
				$field['required'] = (bool) ( $o['required'] ?? $field['required'] );
				$field['enabled']  = (bool) ( $o['enabled'] ?? $field['enabled'] );
			}
			if ( 'select' === $field['type'] && isset( $o['options'] ) && is_array( $o['options'] ) && array() !== $o['options'] ) {
				$field['options'] = array_values( array_map( 'strval', $o['options'] ) );
			}
			$field['order'] = (int) ( $o['order'] ?? $index * 10 );
			$fields[]       = $field;
		}

		foreach ( $custom as $index => $c ) {
			if ( ! is_array( $c ) || ! isset( $c['key'], $c['label'], $c['type'] ) || ! in_array( $c['type'], self::CUSTOM_TYPES, true ) ) {
				continue;
			}
			$fields[] = array(
				'key'      => 'custom_' . sanitize_key( (string) $c['key'] ),
				'section'  => 'personal',
				'label'    => (string) $c['label'],
				'type'     => (string) $c['type'],
				'required' => (bool) ( $c['required'] ?? false ),
				'enabled'  => (bool) ( $c['enabled'] ?? true ),
				'system'   => false,
				'custom'   => true,
				'options'  => array_values( array_map( 'strval', (array) ( $c['options'] ?? array() ) ) ),
				'max'      => 'textarea' === $c['type'] ? 2000 : 255,
				'order'    => (int) ( $c['order'] ?? 1000 + $index ),
			);
		}

		// Sections keep their fixed order; fields are ordered within a section.
		usort(
			$fields,
			static fn( array $a, array $b ): int => array( array_search( $a['section'], self::SECTIONS, true ), $a['order'] ) <=> array( array_search( $b['section'], self::SECTIONS, true ), $b['order'] )
		);
		return $fields;
	}

	/** @return list<array<string,mixed>> Enabled fields only. */
	public function enabled_fields(): array {
		return array_values( array_filter( $this->fields(), static fn( array $f ): bool => $f['enabled'] ) );
	}
}
