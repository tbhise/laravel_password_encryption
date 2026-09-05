<?php

namespace Tusharb\EnvCrypt\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tusharb\EnvCrypt\EnvBackup;
use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvCryptServiceProvider;

/**
 * A booted Laravel application with a .env of our own.
 *
 * The commands read and write the real .env of the application they run in,
 * so the tests give testbench's skeleton one and clean it up afterwards -
 * including any backups written, which would otherwise accumulate plaintext
 * from every run.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [EnvCryptServiceProvider::class];
    }

    /** @var callable|null */
    private $errorHandler;

    /** @var callable|null */
    private $exceptionHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->errorHandler = $this->currentHandler('error');
        $this->exceptionHandler = $this->currentHandler('exception');

        // A project with a plaintext password, as it looks before migration.
        $this->writeEnv("APP_ENV=local\nDB_CONNECTION=mysql\nDB_PASSWORD=plain-text\n");
    }

    /** Read the installed handler without disturbing it. */
    private function currentHandler($kind)
    {
        if ($kind === 'error') {
            $handler = set_error_handler(null);
            restore_error_handler();

            return $handler;
        }

        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    /**
     * Pop only the handlers Laravel installed, identified by their own class,
     * so PHPUnit's handler underneath is never touched - removing that is what
     * it reports as "removed error handlers other than its own".
     */
    private function unwindTo($original, $kind)
    {
        for ($depth = 0; $depth < 32; $depth++) {
            $handler = $this->currentHandler($kind);

            if ($handler === $original || ! $this->isFrameworkHandler($handler)) {
                return;
            }

            $kind === 'error' ? restore_error_handler() : restore_exception_handler();
        }
    }

    private function isFrameworkHandler($handler)
    {
        if (! is_array($handler) || ! isset($handler[0]) || ! is_object($handler[0])) {
            return false;
        }

        return strpos(get_class($handler[0]), 'Illuminate\\') === 0;
    }

    protected function tearDown(): void
    {
        foreach ([$this->envPath(), config_path('envcrypt.php'), storage_path('tools/envcrypt.php')] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->clearBackups();

        EnvCrypt::reset();

        parent::tearDown();

        // A command that calls another command bootstraps Laravel's exception
        // handling again, leaving a handler behind that PHPUnit reports as a
        // risky test. Unwind exactly as far as the handler this test started
        // with - no further, or a genuine leak elsewhere would be hidden.
        $this->unwindTo($this->errorHandler, 'error');
        $this->unwindTo($this->exceptionHandler, 'exception');
    }

    protected function envPath()
    {
        return $this->app->basePath('.env');
    }

    protected function envContents()
    {
        return is_file($this->envPath()) ? file_get_contents($this->envPath()) : '';
    }

    protected function writeEnv($contents)
    {
        file_put_contents($this->envPath(), $contents);

        EnvCrypt::reset();
        EnvCrypt::configure(['base_path' => $this->app->basePath()]);
    }

    /**
     * Put a usable secret in .env, which EnvCrypt accepts only because
     * APP_ENV is local - the development fallback, exercised deliberately.
     */
    protected function useDevelopmentSecret($key, $extra = '')
    {
        $this->writeEnv(
            "APP_ENV=local\nENVCRYPT_KEY_VAR=TESTS_BUILD_TAG\n"
            . "TESTS_BUILD_TAG={$key}\nDB_CONNECTION=mysql\n" . $extra
        );

        EnvCrypt::configure(['key_var' => 'TESTS_BUILD_TAG']);
    }

    protected function backups()
    {
        return new EnvBackup($this->app->basePath());
    }

    protected function clearBackups()
    {
        $directory = $this->backups()->directory();

        if (! is_dir($directory)) {
            return;
        }

        foreach ((array) glob($directory . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($directory);
    }
}
