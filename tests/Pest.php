<?php

declare(strict_types=1);

/*
 * Pest auto-includes this file for the package's own `vendor/bin/pest`. The shared
 * test helpers (golden(), patternBytes()) live in tests/helpers.php so they load
 * regardless of the test binary (also registered via composer autoload-dev.files
 * and the phpunit bootstrap). See r2-consolidated finding #6.
 */

require_once __DIR__.'/helpers.php';
