<?php
/**
 * Test bootstrap. Tests cover the pure classes (size chart, importer)
 * without loading WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/class-pmh-size-chart.php';
require_once __DIR__ . '/../includes/class-pmh-importer.php';

function pmh_fixture( string $name ): string {
	return (string) file_get_contents( __DIR__ . '/fixtures/' . $name );
}
