<?php
/**
 * PHPUnit bootstrap for the WordPress test suite, multisite.
 *
 * @package AOQuill
 */

$tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';
if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found in $tests_dir. See wordpress-plugin/README.md.\n" );
	exit( 1 );
}
if ( ! defined( 'WP_TESTS_MULTISITE' ) ) {
	define( 'WP_TESTS_MULTISITE', true );
}
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/ao-quill.php';
	}
);
tests_add_filter(
	'setup_theme',
	static function () {
		AOQ_Install::install();
	}
);

require $tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-aoq-testcase.php';
