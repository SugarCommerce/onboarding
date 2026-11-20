<?php
/**
 * sugarcommerce/jobs' Sync, as far as the onboarding screen asks it.
 *
 * Onboarding only suggests jobs, so the package is not installed for its
 * suite. This stands in for it under the name an unprefixed build asks for.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Jobs;

/**
 * A registry of syncs by id, and a button that says which it is.
 */
final class Sync {

	/**
	 * Registered syncs.
	 *
	 * @var array<string, self>
	 */
	public static array $syncs = [];

	/**
	 * Register one.
	 *
	 * @param string $id Its id.
	 */
	public function __construct( public readonly string $id ) {
		self::$syncs[ $id ] = $this;
	}

	/**
	 * The sync registered under an id.
	 *
	 * @param string $id Its id.
	 *
	 * @return self|null
	 */
	public static function get( string $id ): ?self {
		return self::$syncs[ $id ] ?? null;
	}

	/**
	 * Print its button.
	 *
	 * @param array $args Ignored.
	 *
	 * @return void
	 */
	public function render_button( array $args = [] ): void {
		echo '<button class="stub-sync" data-id="' . htmlspecialchars( $this->id ) . '"></button>';
	}
}
