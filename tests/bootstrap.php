<?php // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols

/**
 * PHPUnit bootstrap file
 */

declare( strict_types = 1 );

// Run tests as multisite to match production.
define( 'WP_TESTS_MULTISITE', true );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once '/wp-phpunit/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () : void {
	require_once dirname( __DIR__ ) . '/plugin.php';
} );

require_once '/wp-phpunit/includes/bootstrap.php';
