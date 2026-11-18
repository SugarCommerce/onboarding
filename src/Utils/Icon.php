<?php
/**
 * Icons
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Utils;

use SugarCommerce\Fields\Icons\Icons;

/**
 * Every icon the wizard and the checklist draw, through the field kit.
 *
 * Two forms are read, as everywhere the kit is used: `set:name`
 * (`heroicons-outline:check`) is inline SVG in `currentColor`, and a
 * Dashicons name (`yes`, or the whole class `dashicons-yes`) is the font's
 * span, as an item's icon always was. Either way the element carries the
 * `sugarcommerce-icon` class, which the stylesheets size.
 *
 * @internal
 */
final class Icon {

	/**
	 * An icon, ready to print.
	 *
	 * @param string $value   A `set:name` icon or a Dashicons name.
	 * @param string $classes Classes for it, beside `sugarcommerce-icon`.
	 *
	 * @return string Safe markup; empty for a value that is neither form, or
	 *                an icon no set has.
	 */
	public static function render( string $value, string $classes = '' ): string {
		return Icons::render( $value, [ 'class' => $classes ] );
	}
}
