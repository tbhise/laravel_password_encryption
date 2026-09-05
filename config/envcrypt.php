<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The variable holding the secret
    |--------------------------------------------------------------------------
    |
    | The name of the machine / application-pool environment variable that
    | holds the root secret. It is NOT read from .env in production - only the
    | name is configured here; the value lives in the Windows registry or on an
    | IIS application pool.
    |
    | Give every project on a shared server its own name, so two of them can
    | never end up sharing a secret. A name that says nothing about its purpose
    | does not stand out in a configuration dump - that is obscurity, not
    | protection, but it costs nothing.
    |
    | Set ENVCRYPT_KEY_VAR in .env, e.g. BILLING_BUILD_TAG.
    |
    */

    'key_var' => env('ENVCRYPT_KEY_VAR', 'ENVCRYPT_ROOT_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Key-derivation context
    |--------------------------------------------------------------------------
    |
    | Mixed into the derived cipher key so the stored secret is never used as a
    | key directly. CHANGING THIS MAKES EVERY EXISTING ENCRYPTED VALUE
    | UNREADABLE - decrypt them back to plaintext first if you ever must.
    |
    */

    'context' => env('ENVCRYPT_CONTEXT', 'db-password-encryption-v2'),

    /*
    |--------------------------------------------------------------------------
    | IIS application pool
    |--------------------------------------------------------------------------
    |
    | When the secret lives on a pool rather than machine-wide, name it here
    | and every command uses it without --pool. Leave null for machine-wide.
    |
    */

    'pool' => env('ENVCRYPT_POOL'),

    /*
    |--------------------------------------------------------------------------
    | Fallback managed keys
    |--------------------------------------------------------------------------
    |
    | Used only when .env declares no ENVCRYPT_TARGET_KEYS and nothing is
    | encrypted yet. The confirmed list normally lives in .env, written by
    | "php artisan db:password-encrypt-all".
    |
    */

    'target_keys' => ['DB_PASSWORD'],

    /*
    |--------------------------------------------------------------------------
    | Connectors to wrap
    |--------------------------------------------------------------------------
    |
    | Driver => the connector class it would otherwise use. SQLite is absent
    | because it has no password. MariaDbConnector exists only from Laravel 11;
    | a driver whose class is missing is skipped silently.
    |
    */

    'connectors' => [
        'mysql' => \Illuminate\Database\Connectors\MySqlConnector::class,
        'mariadb' => \Illuminate\Database\Connectors\MariaDbConnector::class,
        'pgsql' => \Illuminate\Database\Connectors\PostgresConnector::class,
        'sqlsrv' => \Illuminate\Database\Connectors\SqlServerConnector::class,
    ],

];
