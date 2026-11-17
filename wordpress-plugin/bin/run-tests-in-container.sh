#!/usr/bin/env bash
# Runs inside the phpunit container. Fetches the WordPress test suite for
# WP_VERSION, writes its config, installs dev dependencies, runs PHPUnit.
set -euo pipefail
if [ ! -d /wp/tests/phpunit ]; then
  git clone --quiet --depth 1 --branch "${WP_VERSION}" https://github.com/WordPress/wordpress-develop.git /wp
fi
cd /wp
if [ ! -f wp-tests-config.php ]; then
  sed -e "s/youremptytestdbnamehere/wp_tests/" -e "s/yourusernamehere/root/" \
      -e "s/yourpasswordhere/root/" -e "s|localhost|db|" wp-tests-config-sample.php > wp-tests-config.php
  sed -i "s|dirname( __FILE__ ) . '/src/'|'/wp/src/'|" wp-tests-config.php
fi
cd /repo/wordpress-plugin/ao-quill
composer install --quiet --no-interaction --no-progress
WP_TESTS_PHPUNIT_POLYFILLS_PATH=$PWD/vendor/yoast/phpunit-polyfills vendor/bin/phpunit "$@"
