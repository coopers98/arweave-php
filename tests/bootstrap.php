<?php

declare(strict_types=1);

/*
 * Bootstrap (configured in phpunit.xml, honoured by Pest): load the autoloader, then
 * the shared parity-suite helpers so they are present even when the suite is driven by
 * a binary that does NOT honour Pest's tests/Pest.php convention (e.g. an outer
 * `vendor/bin/pest` run from the repo root against this package's phpunit.xml). The
 * suite is always run via `pest`, never a bare `phpunit` invocation. The helpers are
 * function_exists-guarded, so loading them here and via composer autoload-dev.files is
 * safe. See r2-consolidated finding #6.
 */

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
