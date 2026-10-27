<?php

/*
 * Boots the WordPress test library (the one wordpress-develop and
 * bin/install-wp-tests.sh set up) against a test database, with the kit
 * loaded unprefixed from this repository's vendor/.
 *
 *   WP_TESTS_DIR               the library (contains includes/bootstrap.php)
 *   WP_TESTS_CONFIG_FILE_PATH  its wp-tests-config.php (database credentials, ABSPATH)
 *
 * Bit Form's checkout has both: tests/__libs and tests.config.php.
 */

$testsDir = getenv('WP_TESTS_DIR') ?: '';
$config = getenv('WP_TESTS_CONFIG_FILE_PATH') ?: '';

if ('' === $testsDir || !is_file($testsDir . '/includes/bootstrap.php') || '' === $config || !is_file($config)) {
    fwrite(STDOUT, "Integration tests skipped: set WP_TESTS_DIR and WP_TESTS_CONFIG_FILE_PATH (see phpunit.integration.xml).\n");

    exit(0);
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

define('WP_TESTS_CONFIG_FILE_PATH', $config);
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__, 2) . '/vendor/yoast/phpunit-polyfills');

require_once $testsDir . '/includes/functions.php';

require $testsDir . '/includes/bootstrap.php';
