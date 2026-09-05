<?php

/**
 * Standalone .env password tool - published by "php artisan envcrypt:install".
 * Do not edit by hand.
 *
 *   php storage/tools/envcrypt.php encrypt-all [--pool=NAME] [--reconfigure]
 *   php storage/tools/envcrypt.php encrypt     [--key=DB_PASSWORD] [--pool=NAME]
 *   php storage/tools/envcrypt.php decrypt     ["enc:..."] [--key=NAME] [--pool=NAME]
 *
 * A launcher, not a copy: it hands over to the package's own bin/envcrypt, so
 * there is one implementation and an update to the package updates this too.
 *
 * It lives under storage/ because the web root is public/, which puts it out
 * of reach over HTTP. Composer's own vendor/ is outside the web root as well -
 * this exists because it is where the operational guide says to look, and
 * because it survives a "composer install --no-dev" that renames nothing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// storage/tools/envcrypt.php -> two levels up is the project root.
$tool = dirname(__DIR__, 2) . '/vendor/tusharb/laravel-envcrypt/bin/envcrypt';

if (! is_file($tool)) {
    fwrite(STDERR, 'ERROR: cannot find ' . $tool . PHP_EOL
        . 'Is tusharb/laravel-envcrypt still installed? Try "composer install".' . PHP_EOL);
    exit(1);
}

require $tool;
