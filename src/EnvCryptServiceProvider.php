<?php

namespace Tusharb\EnvCrypt;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;
use Tusharb\EnvCrypt\Commands\CheckCommand;
use Tusharb\EnvCrypt\Commands\DecryptCommand;
use Tusharb\EnvCrypt\Commands\EncryptAllCommand;
use Tusharb\EnvCrypt\Commands\EncryptCommand;
use Tusharb\EnvCrypt\Commands\InstallCommand;
use Tusharb\EnvCrypt\Commands\KeygenCommand;
use Tusharb\EnvCrypt\Commands\RestoreCommand;
use Tusharb\EnvCrypt\Commands\RotateCommand;
use Tusharb\EnvCrypt\Commands\UninstallCommand;
use Tusharb\EnvCrypt\Commands\VerifyCommand;

class EnvCryptServiceProvider extends ServiceProvider
{
    /**
     * Composer runs package:discover through post-autoload-dump, so it is the
     * first thing to execute after "composer require" and the only moment a
     * library gets to act on its own installation.
     */
    const SETUP_TRIGGER = 'package:discover';

    /** Commands where an unconfigured project is worth a one-line mention. */
    private $mentionDuring = ['list', 'about'];

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/envcrypt.php', 'envcrypt');

        // Resolved rather than reached for as $this->app['config']: the
        // container is only ArrayAccess on the concrete Application class,
        // and the contract this provider is handed does not declare it.
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app->make('config');

        // Only the NAME of the secret's variable and the derivation context
        // are configuration. The secret itself is never read through config(),
        // because config:cache would then write it beside the ciphertext it
        // unlocks.
        EnvCrypt::configure([
            'key_var' => $config->get('envcrypt.key_var'),
            'context' => $config->get('envcrypt.context'),
            'base_path' => $this->app->basePath(),
        ]);

        // ConnectionFactory::createConnector() checks the container for a
        // "db.connector.{driver}" binding before using its own. Decrypting
        // there - at connection time - is what keeps the plaintext out of
        // bootstrap/cache/config.php.
        foreach ((array) $config->get('envcrypt.connectors', []) as $driver => $connector) {
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

        // Application::storagePath() took no argument before Laravel 9, where
        // passing one silently returns the storage directory itself - and
        // publishing a file onto a directory path fails with nothing useful
        // said. configPath() has accepted one since Laravel 8, so only this
        // call needs building by hand.
        $this->publishes([
            __DIR__ . '/../stubs/envcrypt-tool.php' => rtrim($this->app->storagePath(), '/\\')
                . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'envcrypt.php',
        ], 'envcrypt-tool');

        $this->commands([
            InstallCommand::class,
            VerifyCommand::class,
            UninstallCommand::class,
            KeygenCommand::class,
            RestoreCommand::class,
            RotateCommand::class,
            EncryptCommand::class,
            EncryptAllCommand::class,
            DecryptCommand::class,
            CheckCommand::class,
        ]);

        $this->setUpIfNotInstalled();
    }

    /**
     * Set the project up as part of "composer require", rather than telling
     * somebody to do it afterwards.
     *
     * A library cannot register a Composer script - only the root project can -
     * so package:discover is the hook available, and it is enough: the whole
     * setup runs from here, and the installer reaches the operator's terminal
     * directly for the questions Composer's pipes would otherwise swallow.
     *
     * It runs once. The moment the project has named its own secret this is a
     * no-op, so "composer update" never re-enters it.
     */
    private function setUpIfNotInstalled()
    {
        if ($this->isInstalled()) {
            return;
        }

        if ($this->runningOneOf($this->mentionDuring)) {
            (new ConsoleOutput())->writeln(
                '<comment>' . PHP_EOL . '  EnvCrypt is not set up for this project yet.'
                . PHP_EOL . '  Run:  php artisan envcrypt:install' . PHP_EOL . '</comment>'
            );

            return;
        }

        if (! $this->runningOneOf([self::SETUP_TRIGGER])) {
            return;
        }

        $output = new ConsoleOutput();
        $output->writeln('<comment>' . PHP_EOL . '  Setting up EnvCrypt for this project...' . PHP_EOL . '</comment>');

        try {
            // The installer works out for itself how to ask: this process's
            // STDIN is a pipe when Composer started it, so it opens the
            // console directly. Where there is no console at all - CI, a
            // scripted deploy - it sets the project up and encrypts nothing.
            $this->app->make(Kernel::class)->call('envcrypt:install', ['--from-composer' => true]);

            $output->write($this->app->make(Kernel::class)->output());
        } catch (Throwable $e) {
            // package:discover must not fail because of this: Composer treats
            // a non-zero exit from a script as a failed installation.
            $output->writeln('<comment>  EnvCrypt setup could not run: ' . $e->getMessage() . '</comment>');
            $output->writeln('<comment>  Run "php artisan envcrypt:install" to finish it.' . PHP_EOL . '</comment>');
        }
    }

    /**
     * Installed means "this project has named its own secret". A project still
     * on the default name has never run envcrypt:install, and would share a
     * secret with every other project that had not either.
     */
    private function isInstalled()
    {
        return EnvCrypt::rootKeyVar() !== EnvCrypt::DEFAULT_ROOT_KEY_VAR;
    }

    private function runningOneOf(array $commands)
    {
        $argv = isset($_SERVER['argv']) ? $_SERVER['argv'] : [];

        // "php artisan" on its own lists the commands, so it counts as list.
        if (count($argv) < 2) {
            return true;
        }

        return in_array($argv[1], $commands, true);
    }
}
