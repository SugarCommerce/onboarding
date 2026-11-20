<?php
/**
 * Every field type, inside a step.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Fields\Registry;
use SugarCommerce\Onboarding\Wizard;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Anything the kit draws, a step takes — and stores what it was given.
 *
 * Driven off the kit's own registry, so a type the kit gains is covered here
 * without anybody remembering to add it. For each: the step draws it without
 * reaching for anything undefined or leaving an output buffer open, and a
 * value submitted through the wizard is stored as exactly what the type's
 * own sanitizer makes of it, and read back as the same — the wizard neither
 * double-sanitizes, nor drops, nor reshapes a value on the way through its
 * all-or-nothing draft.
 */
final class EveryFieldTypeTest extends OnboardingTestCase {

	/**
	 * What to submit for a type, where a plain string would not do.
	 *
	 * The aim is a value the type keeps, so the round trip has something in
	 * it; test_most_types_keep_a_value() holds the list to that.
	 *
	 * @var array<string, mixed>
	 */
	private const SAMPLES = [
		'email'           => 'help@example.com',
		'url'             => 'https://example.com/',
		'file_url'        => 'https://example.com/file.zip',
		'oembed'          => 'https://example.com/video',
		'number'          => '5',
		'percentage'      => '20',
		'range'           => '3',
		'date'            => '2026-01-02',
		'time'            => '08:30',
		'datetime'        => '2026-01-02T08:30',
		'color'           => '#ff0000',
		'icon'            => 'heroicons-outline:star',
		'checkbox'        => '1',
		'toggle'          => '1',
		'select_multiple' => [ 'a' ],
		'checkbox_group'  => [ 'a', 'b' ],
		'sortable'        => [ 'b', 'a' ],
		'country'         => 'GB',
		'currency'        => 'gbp',
		'image'           => '5',
		'file'            => '5',
		'gallery'         => [ '5', '6' ],
		'files'           => [
			[
				'name' => 'Manual',
				'file' => '5',
			],
		],
		'post'            => '5',
		'page'            => '5',
		'user'            => '5',
		'taxonomy'        => '5',
		'nav_menu'        => '5',
		'ajax'            => '5',
		'group'           => [ 'one' => 'grouped' ],
		'repeater'        => [ [ 'one' => 'first row' ] ],
		'flexible'        => [
			[
				'layout' => 'one',
				'values' => [ 'one' => 'first row' ],
			],
		],
		'list'            => [
			[ 'item' => 'one' ],
			[ 'item' => 'two' ],
		],
		'tags'            => 'one,two',
		'key_value'       => [
			[
				'key'   => 'colour',
				'value' => 'red',
			],
		],
		'link'            => [
			'url'   => 'https://example.com/',
			'title' => 'Example',
		],
		'dimensions'      => [
			'width'  => '10',
			'height' => '20',
		],
		'date_range'      => [
			'start' => '2026-01-01',
			'end'   => '2026-01-31',
		],
		'time_range'      => [
			'start' => '09:00',
			'end'   => '17:00',
		],
		'address'         => [
			'line1'    => '1 High St',
			'city'     => 'Leeds',
			'postcode' => 'LS1 1AA',
			'country'  => 'GB',
		],
		'dimension'       => [
			'value' => '12',
			'unit'  => 'em',
		],
		'location'        => [
			'address' => 'Leeds',
			'lat'     => '53.8008',
			'lng'     => '-1.5491',
		],
		'rating'          => '4',
		'money'           => '12.50',
		'amount_type'     => '10',
		'license'         => 'AP-1234-5678',
		'code'            => '<b>bold</b>',
		'wysiwyg'         => '<p>Para</p>',
	];

	/**
	 * Enough configuration for any type to draw something.
	 *
	 * The same generic configuration the kit's own accessibility suite gives
	 * every type: a label, two options, one sub-field, and a render callback
	 * for `custom`.
	 *
	 * @param string $type The type.
	 *
	 * @return array<string, mixed>
	 */
	private static function config( string $type ): array {
		$config = [
			'type'            => $type,
			'label'           => 'A ' . $type,
			'render_callback' => static fn( $field, $attributes ): string => sprintf( '<input%s />', $attributes->render() ),
			'action_callback' => static fn(): string => 'Done.',
			'content'         => '<p>Words</p>',
		];

		// A country's list is the country library's own, and a list a type
		// brings with it is not replaced.
		if ( 'country' !== $type ) {
			$config['options'] = [
				'a' => 'Alpha',
				'b' => 'Beta',
			];
		}

		// Codes are uppercased and menus are ids, so their lists say so.
		if ( 'currency' === $type ) {
			$config['options'] = [
				'GBP' => 'Pound sterling',
				'EUR' => 'Euro',
			];
		}

		if ( 'nav_menu' === $type ) {
			$config['options'] = [ 5 => 'Main menu' ];
		}

		// Only the types whose parts are the consumer's are given parts:
		// a key-value list, a file list and a plain list bring their own.
		if ( in_array( $type, [ 'group', 'repeater' ], true ) ) {
			$config['fields'] = [
				'one' => [
					'type'  => 'text',
					'label' => 'One',
				],
			];
		}

		if ( 'flexible' === $type ) {
			$config['layouts'] = [
				'one' => [
					'label'  => 'One',
					'fields' => [
						'one' => [
							'type'  => 'text',
							'label' => 'One',
						],
					],
				],
			];
		}

		return $config;
	}

	/**
	 * A wizard whose second step is one field of a type.
	 *
	 * @param string $type The type.
	 *
	 * @return Wizard
	 */
	private function holding( string $type ): Wizard {
		return $this->wizard(
			[
				'option' => 'every_type',
				'steps'  => [
					'welcome' => [ 'title' => 'Welcome' ],
					'field'   => [
						'title'  => 'One field',
						'fields' => [ 'subject' => self::config( $type ) ],
					],
					'done'    => [ 'title' => 'Done' ],
				],
			]
		);
	}

	/**
	 * Every registered type id.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function typeProvider(): array {
		$cases = [];

		foreach ( ( new Registry() )->ids() as $id ) {
			$cases[ $id ] = [ $id ];
		}

		return $cases;
	}

	/**
	 * The step draws the field.
	 *
	 * @param string $type The type.
	 */
	#[DataProvider( 'typeProvider' )]
	public function test_a_step_draws_it( string $type ): void {
		$wizard = $this->holding( $type );

		$this->submit( $wizard, 'welcome' );

		$depth = ob_get_level();
		$html  = $this->render( $wizard, [ 'step' => 'field' ] );

		$this->assertSame( $depth, ob_get_level(), "{$type} left an output buffer open." );
		$this->assertStringContainsString( 'onboarding__fields', $html, "{$type}: no fields were drawn." );
		$this->assertStringContainsString( 'onboarding__next', $html, "{$type}: the step was not finished drawing." );
	}

	/**
	 * What is submitted is stored as the type makes it, and read back the same.
	 *
	 * @param string $type The type.
	 */
	#[DataProvider( 'typeProvider' )]
	public function test_a_step_stores_it( string $type ): void {
		$wizard = $this->holding( $type );
		$field  = $wizard->fields( $wizard->all_steps()['field'] )->field( 'subject' );
		$raw    = self::SAMPLES[ $type ] ?? 'a';

		$this->submit( $wizard, 'welcome' );
		$url = $this->submit( $wizard, 'field', [ 'subject' => $raw ] );

		$this->assertNotNull( $url, "{$type}: the step was refused: " . implode( ' ', $wizard->errors() ) );

		if ( ! $field->type()->stores_value() ) {
			$this->assertArrayNotHasKey( 'subject', (array) ( $GLOBALS['ob_options']['every_type'] ?? [] ), "{$type} stores nothing, and nothing was stored." );

			return;
		}

		$expected = $field->type()->sanitize( $raw, $field );
		$stored   = $GLOBALS['ob_options']['every_type']['subject'] ?? null;

		if ( null === $expected || '' === $expected || [] === $expected ) {
			$this->assertNull( $stored, "{$type}: an empty value is cleared, not stored." );

			return;
		}

		$this->assertSame( $expected, $stored, "{$type}: stored as something other than its own sanitizer made it." );
		$this->assertSame( $expected, $wizard->value( 'subject' ), "{$type}: read back as something other than what was stored." );
	}

	/**
	 * The round trip carries something for nearly every type.
	 *
	 * Without this the test above passes vacuously for a type whose sample
	 * sanitizes to nothing. The few listed here genuinely keep nothing of a
	 * value that arrives with no object behind it in a unit test.
	 */
	public function test_most_types_keep_a_value(): void {
		$registry = new Registry();
		$empty    = [];

		foreach ( $registry->ids() as $type ) {
			$wizard = $this->holding( $type );
			$field  = $wizard->fields( $wizard->all_steps()['field'] )->field( 'subject' );

			if ( null === $field || ! $field->type()->stores_value() ) {
				continue;
			}

			$value = $field->type()->sanitize( self::SAMPLES[ $type ] ?? 'a', $field );

			if ( null === $value || '' === $value || [] === $value ) {
				$empty[] = $type;
			}

			ob_reset_globals();
		}

		$this->assertSame( [], $empty, 'These types kept nothing of their sample: ' . implode( ', ', $empty ) );
	}
}
