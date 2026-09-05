<?php

namespace Tusharb\EnvCrypt;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\ConfirmationQuestion;
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
     * Commands during which an unconfigured install should announce itself.
     *
     * "package:discover" is the important one: Composer runs it through
     * post-autoload-dump, so it is the first thing to execute after
     * "composer require" and the only moment the package can speak for itself.
     */
    private $announceDuring = ['package:discover', 'list', 'about'];

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

        $this->publishes([
            __DIR__ . '/../stubs/envcrypt-tool.php' => $this->app->storagePath('tools/envcrypt.php'),
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

        $this->announceIfNotInstalled();
    }

    /**
     * Composer drops the files into vendor/ and says nothing, which leaves an
     * operator with an installed package and no idea that any further step
     * exists.
     *
     * A library cannot register a Composer script - only the root project can -
     * so the closest available hook is package:discover, which Composer runs
     * through post-autoload-dump immediately after the install. When a person
     * is watching, the installer is offered there and then; otherwise the
     * package prints what to run and stops.
     */
    private function announceIfNotInstalled()
    {
        if ($this->isInstalled() || ! $this->runningOneOf($this->announceDuring)) {
            return;
        }

        $output = new ConsoleOutput();

        foreach ([
            '',
            '  EnvCrypt is in vendor/, but this project is not set up yet.',
            '',
        ] as $line) {
            $output->writeln('<comment>' . $line . '</comment>');
        }

        if ($this->offerInstall($output)) {
            return;
        }

        foreach ([
            '  Run:  php artisan envcrypt:install',
            '',
            '  It names this project\'s secret, publishes the config and prints',
            '  the remaining steps. Nothing is encrypted until you confirm it.',
            '',
        ] as $line) {
            $output->writeln('<comment>' . $line . '</comment>');
        }
    }

    /**
     * Run the installer straight away, but only when a person is actually
     * there to answer it.
     *
     * The guard is the point. An unattended Composer run - CI, a deploy
     * script, "composer install --no-interaction" - must never be able to
     * modify a project's credentials, so anything without a real terminal
     * attached gets the printed instruction instead. Even when it does run,
     * the installer encrypts nothing without a separate confirmation.
     */
    private function offerInstall(ConsoleOutput $output)
    {
        if (! $this->canPrompt()) {
            return false;
        }

        $question = new QuestionHelper();
        $input = new ArgvInput();
        $input->setInteractive(true);

        $answer = $question->ask(
            $input,
            $output,
            new ConfirmationQuestion('  Set it up now? [Y/n] ', true)
        );

        if (! $answer) {
            return false;
        }

        try {
            $this->app->make(Kernel::class)->call('envcrypt:install');
            $output->write($this->app->make(Kernel::class)->output());
        } catch (Throwable $e) {
            // package:discover must not fail because of this - Composer treats
            // a non-zero exit as a failed install.
            $output->writeln('<comment>  Could not run it: ' . $e->getMessage() . '</comment>');

            return false;
        }

        return true;
    }

    /**
     * Is there a human on the other end? A terminal on STDIN, no CI marker,
     * and no --no-interaction anywhere on the command line.
     */
    private function canPrompt()
    {
        $argv = isset($_SERVER['argv']) ? $_SERVER['argv'] : [];

        if (in_array('--no-interaction', $argv, true) || in_array('-n', $argv, true)) {
            return false;
        }

        foreach (['CI', 'CONTINUOUS_INTEGRATION', 'BUILD_NUMBER', 'GITHUB_ACTIONS'] as $marker) {
            if (getenv($marker)) {
                return false;
            }
        }

        if (! defined('STDIN') || ! function_exists('stream_isatty')) {
            return false;
        }

        return @stream_isatty(STDIN);
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
