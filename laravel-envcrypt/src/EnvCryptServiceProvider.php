<?php

namespace Npav\EnvCrypt;

use Illuminate\Support\ServiceProvider;
use Npav\EnvCrypt\Commands\CheckCommand;
use Npav\EnvCrypt\Commands\DecryptCommand;
use Npav\EnvCrypt\Commands\EncryptAllCommand;
use Npav\EnvCrypt\Commands\EncryptCommand;
use Npav\EnvCrypt\Commands\InstallCommand;
use Npav\EnvCrypt\Commands\KeygenCommand;
use Npav\EnvCrypt\Commands\RotateCommand;

class EnvCryptServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/envcrypt.php', 'envcrypt');

        // Only the NAME of the secret's variable and the derivation context
        // are configuration. The secret itself is never read through config(),
        // because config:cache would then write it beside the ciphertext it
        // unlocks.
        EnvCrypt::configure([
            'key_var' => $this->app['config']->get('envcrypt.key_var'),
            'context' => $this->app['config']->get('envcrypt.context'),
            'base_path' => $this->app->basePath(),
        ]);

        // ConnectionFactory::createConnector() checks the container for a
        // "db.connector.{driver}" binding before using its own. Decrypting
        // there - at connection time - is what keeps the plaintext out of
        // bootstrap/cache/config.php.
        foreach ((array) $this->app['config']->get('envcrypt.connectors', []) as $driver => $connector) {
            // MariaDbConnector exists only from Laravel 11. A driver whose
            // connector this version does not ship is simply not wrapped.
            if (! class_exists($connector)) {
                continue;
            }

            $this->app->bind('db.connector.' . $driver, function () use ($connector) {
                return new EnvCryptConnector(new $connector);
            });
        }
    }

    public function boot()
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/envcrypt.php' => $this->app->configPath('envcrypt.php'),
        ], 'envcrypt-config');

        $this->commands([
            InstallCommand::class,
            KeygenCommand::class,
            RotateCommand::class,
            EncryptCommand::class,
            EncryptAllCommand::class,
            DecryptCommand::class,
            CheckCommand::class,
        ]);
    }
}
