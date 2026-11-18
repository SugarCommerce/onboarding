<?php
/**
 * PHPUnit bootstrap.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

require_once __DIR__ . '/stubs.php';

/*
 * The field engine's own stubs, copied from sugarcommerce/fields' suite into
 * tests/kit: a Composer install of the engine leaves its tests behind, as it
 * should. Ours are required first so that where the two overlap this file's
 * version wins, since every stub is guarded by function_exists().
 */
require_once __DIR__ . '/kit/stubs.php';

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
