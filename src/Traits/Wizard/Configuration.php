<?php
/**
 * Wizard: Configuration
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Traits\Wizard;

use SugarCommerce\Fields\Field;
use SugarCommerce\Settings\Page;

/**
 * Reading a wizard's configuration into steps, with their defaults and settings filled in.
 *
 * Part of Wizard: only the class uses it.
 */
trait Configuration {

	/**
	 * Fill in everything the configuration did not say.
	 *
	 * @param string               $id     The wizard's identifier.
	 * @param array<string, mixed> $config Its configuration.
	 *
	 * @return array<string, mixed>
	 */
	private static function normalize( string $id, array $config ): array {
		$config = array_merge(
			[
				// Who may run it, and where it lives. index.php rather than
				// nothing: a page with no parent used to be registered against
				// null, which core hands to plugin_basename() and PHP 8.1
				// deprecates. Hidden is the same page with its menu item taken
				// back out, which is how core's own hidden screens do it.
				'capability'         => 'manage_options',
				'parent_slug'        => 'index.php',
				'menu_slug'          => '',
				'hidden'             => true,

				// What it looks like.
				'layout'             => 'focus',
				'progress'           => 'top',
				'page_title'         => '',
				'menu_title'         => '',
				'title'              => '',
				'logo'               => '',
				'accent'             => '',
				'body_class'         => '',
				'notices'            => false,
				'labels'             => [],

				// When it runs, and where it ends.
				'redirect'           => false,
				'exit_url'           => '',
				'completed_redirect' => '',
				'finish'             => [],

				// Where answers go: one option holding every field, a pair of
				// callbacks, a field context, a settings page -- or, saying
				// nothing, an option per field.
				'option'             => '',
				'get_callback'       => null,
				'update_callback'    => null,
				'context'            => null,
				'settings'           => '',

				// Where progress goes.
				'state_option'       => '',

				'steps'              => [],
			],
			$config
		);

		$config['menu_slug'] = sanitize_key( '' === (string) $config['menu_slug'] ? $id : (string) $config['menu_slug'] );
		$config['layout']    = 'admin' === $config['layout'] ? 'admin' : 'focus';
		$config['progress']  = 'side' === $config['progress'] ? 'side' : 'top';

		if ( '' === (string) $config['page_title'] ) {
			$config['page_title'] = __( 'Setup', 'sugarcommerce' );
		}

		foreach ( [ 'menu_title', 'title' ] as $key ) {
			if ( '' === (string) $config[ $key ] ) {
				$config[ $key ] = $config['page_title'];
			}
		}

		if ( '' === (string) $config['state_option'] ) {
			$config['state_option'] = str_replace( '-', '_', sanitize_key( $id ) ) . '_onboarding';
		}

		$config['labels'] = array_merge(
			[
				'next'   => __( 'Continue', 'sugarcommerce' ),
				'back'   => __( 'Back', 'sugarcommerce' ),
				'skip'   => __( 'Skip', 'sugarcommerce' ),
				'finish' => __( 'Finish Setup', 'sugarcommerce' ),
				'later'  => __( 'Save and Finish Later', 'sugarcommerce' ),
				'exit'   => __( 'Exit Setup', 'sugarcommerce' ),
			],
			(array) $config['labels']
		);

		// Hex or nothing. It lands in a style attribute, and esc_attr() stops
		// a value closing the attribute but not one appending a second
		// declaration after a semicolon.
		$config['accent'] = (string) ( sanitize_hex_color( (string) $config['accent'] ) ?? '' );

		// A finish screen unless the wizard says false: the moment somebody
		// finishes is the one moment they are guaranteed to read what to do
		// next, and sending them straight back to the dashboard wastes it.
		$config['finish'] = false === $config['finish'] ? false : array_merge(
			[
				'title'       => __( 'You’re all set', 'sugarcommerce' ),
				'description' => '',
				'content'     => '',
				'image'       => '',
				'items'       => [],
				'links'       => [],
				'checklist'   => '',
				'render'      => null,
				'confetti'    => true,
			],
			(array) $config['finish']
		);

		return $config;
	}

	/**
	 * Fill in every step's defaults.
	 *
	 * @param array<string, array<string, mixed>> $steps The steps.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize_steps( array $steps ): array {
		$normalized = [];

		foreach ( $steps as $key => $step ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || ! is_array( $step ) ) {
				continue;
			}

			$normalized[ $key ] = array_merge(
				[
					'title'       => '',
					'short_title' => '',
					'description' => '',
					'type'        => '',
					'show_if'     => null,
					'skippable'   => false,
					'skip_label'  => '',
					'button'      => '',

					// What the step shows, top to bottom.
					'image'       => '',
					'content'     => '',
					'items'       => [],
					'fields'      => [],
					'render'      => null,
					'links'       => [],

					// sugarcommerce/jobs, for a step that imports.
					'sync_id'     => '',

					'validate'    => null,
					'save'        => null,
				],
				$step
			);
		}

		return $normalized;
	}

	/**
	 * Fill in each field that is a setting from the settings page.
	 *
	 * With `settings` naming a sugarcommerce/settings page, a step's field
	 * with no `type` is that page's field of the same key: the same control,
	 * the same choices, the same rules and stored the same way -- encrypted
	 * where the page encrypts -- with the step's own keys laid over it.
	 * Where it sits on the page (`tab`, `section`, `width`) means nothing in
	 * a wizard, which is a column a step at a time, and is dropped. A field
	 * the page does not have is dropped too, rather than drawn as a text box
	 * nobody configured.
	 *
	 * @param array<string, array<string, mixed>> $steps The steps.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function with_settings( array $steps ): array {
		$id = (string) $this->config['settings'];

		if ( '' === $id ) {
			return $steps;
		}

		$page = Page::get( $id );

		foreach ( $steps as $key => $step ) {
			$fields = [];

			foreach ( (array) $step['fields'] as $field_key => $field ) {
				if ( ! is_array( $field ) || isset( $field['type'] ) ) {
					$fields[ $field_key ] = $field;

					continue;
				}

				$setting = $page?->get_field( (string) $field_key );

				if ( ! is_array( $setting ) ) {
					continue;
				}

				unset( $setting['tab'], $setting['section'], $setting['width'] );

				$fields[ $field_key ] = array_merge( $setting, $field );
			}

			$steps[ $key ]['fields'] = $fields;
		}

		return $steps;
	}

	/**
	 * Declare the configuration this library reads on top of the kit's.
	 *
	 * Without this the kit warns, under WP_DEBUG, that it does not know what
	 * `option` is for — which is the point of the warning, and would be
	 * wrong here.
	 *
	 * @return void
	 */
	public static function declare_config_keys(): void {
		Field::allow_config_keys( [ 'option' ] );
	}
}
