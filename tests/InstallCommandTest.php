<?php

namespace Tusharb\EnvCrypt\Tests;

use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvCryptConnector;
use Tusharb\EnvCrypt\EnvFile;

/**
 * The installer against a real booted application.
 *
 * These cover the step Composer cannot do for itself - turning files in
 * vendor/ into a project that has named its own secret - and, just as
 * importantly, the things it must never do on its own.
 */
class InstallCommandTest extends TestCase
{
    public function test_it_names_the_secret_after_the_project()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing', '--no-keygen' => true])
            ->assertSuccessful();

        $this->assertSame(
            'TUSHARB_BILLING_BUILD_TAG',
            EnvFile::value($this->envContents(), 'ENVCRYPT_KEY_VAR')
        );
    }

    /** The application name comes from APP_NAME first. */
    public function test_it_takes_the_identifier_from_app_name()
    {
        $this->writeEnv("APP_ENV=local\nAPP_NAME=\"Acme Billing\"\nDB_PASSWORD=plain-text\n");

        $this->artisan('envcrypt:install', ['--no-keygen' => true])->assertSuccessful();

        $this->assertSame(
            'TUSHARB_ACME_BILLING_BUILD_TAG',
            EnvFile::value($this->envContents(), 'ENVCRYPT_KEY_VAR')
        );
    }

    /** The stock APP_NAME identifies nothing, so it must not become the name. */
    public function test_it_falls_back_to_the_directory_when_app_name_is_the_default()
    {
        $this->writeEnv("APP_ENV=local\nAPP_NAME=Laravel\nDB_PASSWORD=plain-text\n");

        $this->artisan('envcrypt:install', ['--no-keygen' => true])->assertSuccessful();

        $directory = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', basename($this->app->basePath())));

        $this->assertSame(
            'TUSHARB_' . trim($directory, '_') . '_BUILD_TAG',
            EnvFile::value($this->envContents(), 'ENVCRYPT_KEY_VAR')
        );
    }

    public function test_it_publishes_the_config_and_the_standalone_tool()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing', '--no-keygen' => true])
            ->assertSuccessful();

        $this->assertFileExists(config_path('envcrypt.php'));
        $this->assertFileExists(storage_path('tools/envcrypt.php'));
    }

    public function test_it_can_skip_the_standalone_tool()
    {
        $this->artisan('envcrypt:install', [
            '--project' => 'billing',
            '--no-keygen' => true,
            '--no-tool' => true,
        ])->assertSuccessful();

        $this->assertFileDoesNotExist(storage_path('tools/envcrypt.php'));
    }

    /** Safe in a deploy script means a second run must change nothing. */
    public function test_running_it_twice_changes_nothing()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing', '--no-keygen' => true])
            ->assertSuccessful();

        $first = $this->envContents();

        $this->artisan('envcrypt:install', ['--no-keygen' => true])
            ->expectsOutputToContain('unchanged  ENVCRYPT_KEY_VAR')
            ->assertSuccessful();

        $this->assertSame($first, $this->envContents());
    }

    /**
     * The safety rule that matters most: setting the project up must never
     * rewrite a credential on its own.
     */
    public function test_it_leaves_the_password_in_plaintext()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing', '--no-keygen' => true])
            ->assertSuccessful();

        $this->assertSame('plain-text', EnvFile::value($this->envContents(), 'DB_PASSWORD'));
    }

    /** A non-interactive run may set the project up, but never touch credentials. */
    public function test_a_non_interactive_run_never_encrypts()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing', '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame('plain-text', EnvFile::value($this->envContents(), 'DB_PASSWORD'));
        $this->assertNotNull(EnvFile::value($this->envContents(), 'ENVCRYPT_KEY_VAR'));
    }

    public function test_it_prints_the_steps_the_operator_still_has_to_run()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing', '--no-keygen' => true])
            ->expectsOutputToContain('php artisan db:keygen')
            ->expectsOutputToContain('php artisan db:password-encrypt-all')
            ->expectsOutputToContain('php artisan db:secret-check')
            ->assertSuccessful();
    }

    /** Removing the package without decrypting first is the known hazard. */
    public function test_it_points_at_uninstall_before_removal()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing', '--no-keygen' => true])
            ->expectsOutputToContain('envcrypt:uninstall')
            ->assertSuccessful();
    }

    public function test_a_pool_is_recorded_and_carried_into_the_instructions()
    {
        $this->artisan('envcrypt:install', [
            '--project' => 'billing',
            '--pool' => 'BillingPool',
            '--no-keygen' => true,
        ])
            ->expectsOutputToContain('db:keygen --pool="BillingPool"')
            ->assertSuccessful();

        $this->assertSame('BillingPool', EnvFile::value($this->envContents(), 'ENVCRYPT_POOL'));
    }

    public function test_verify_fails_before_install_and_passes_after()
    {
        $this->artisan('envcrypt:verify')
            ->expectsOutputToContain('envcrypt:install')
            ->assertFailed();

        $this->artisan('envcrypt:install', ['--project' => 'billing', '--no-keygen' => true])
            ->assertSuccessful();

        $this->artisan('envcrypt:verify')->assertSuccessful();
    }

    /** The seam the whole design rests on. */
    public function test_the_connector_is_bound_for_password_bearing_drivers()
    {
        $this->assertInstanceOf(EnvCryptConnector::class, $this->app->make('db.connector.mysql'));
        $this->assertInstanceOf(EnvCryptConnector::class, $this->app->make('db.connector.pgsql'));
        $this->assertInstanceOf(EnvCryptConnector::class, $this->app->make('db.connector.sqlsrv'));
    }

    public function test_the_connector_decrypts_on_the_way_to_pdo()
    {
        $key = EnvCrypt::generateRootKey();
        $payload = EnvCrypt::encrypt('real-password', $key);

        $this->useDevelopmentSecret($key);

        $inner = new class implements \Illuminate\Database\Connectors\ConnectorInterface {
            public $seen;

            public function connect(array $config)
            {
                $this->seen = $config['password'];

                return null;
            }
        };

        (new EnvCryptConnector($inner))->connect(['password' => $payload]);

        $this->assertSame('real-password', $inner->seen);
    }
}
