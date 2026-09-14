<?php
/**
 * Test bootstrap. Tests cover the pure functions (importer, size
 * normalisation, intersect) without loading WordPress, so only the few WP
 * helpers those functions call are stubbed here as they become needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../vendor/autoload.php';
