<?php

namespace Tusharb\EnvCrypt\Tests;

use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvCryptConnector;
use Tusharb\EnvCrypt\EnvCryptServiceProvider;
use Tusharb\EnvCrypt\EnvFile;
use Orchestra\Testbench\TestCase;

/**
 * The installer against a real booted application.
 *
 * These cover the step Composer cannot do for itself: turning files in
 * vendor/ into a project that has named its own secret.
 */
class InstallCommandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [EnvCryptServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A project with a plaintext password, as it looks before migration.
        file_put_contents($this->envPath(), "APP_ENV=local\nDB_CONNECTION=mysql\nDB_PASSWORD=plain-text\n");

        EnvCrypt::reset();
        EnvCrypt::configure(['base_path' => $this->app->basePath()]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->envPath(), config_path('envcrypt.php'), storage_path('tools/envcrypt.php')] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        EnvCrypt::reset();

        parent::tearDown();
    }

    private function envPath()
    {
        return $this->app->basePath('.env');
    }

    private function envContents()
    {
        return file_get_contents($this->envPath());
    }

    public function test_it_names_the_secret_after_the_project()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing'])
            ->assertSuccessful();

        $this->assertSame(
            'TUSHARB_BILLING_BUILD_TAG',
            EnvFile::value($this->envContents(), 'ENVCRYPT_KEY_VAR')
        );
    }

    public function test_it_publishes_the_config_and_the_standalone_tool()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing'])->assertSuccessful();

        $this->assertFileExists(config_path('envcrypt.php'));
        $this->assertFileExists(storage_path('tools/envcrypt.php'));
    }

    public function test_it_can_skip_the_standalone_tool()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing', '--no-tool' => true])
            ->assertSuccessful();

        $this->assertFileDoesNotExist(storage_path('tools/envcrypt.php'));
    }

    /** Safe in a deploy script means a second run must change nothing. */
    public function test_running_it_twice_changes_nothing()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing'])->assertSuccessful();
        $first = $this->envContents();

        $this->artisan('envcrypt:install')
            ->expectsOutputToContain('unchanged  ENVCRYPT_KEY_VAR')
            ->assertSuccessful();

        $this->assertSame($first, $this->envContents());
    }

    /** It must never quietly encrypt: that needs an elevated prompt first. */
    public function test_it_leaves_the_password_in_plaintext()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing'])->assertSuccessful();

        $this->assertSame('plain-text', EnvFile::value($this->envContents(), 'DB_PASSWORD'));
    }

    public function test_it_prints_the_steps_the_operator_still_has_to_run()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing'])
            ->expectsOutputToContain('php artisan db:keygen')
            ->expectsOutputToContain('php artisan db:password-encrypt-all')
            ->expectsOutputToContain('php artisan db:secret-check')
            ->assertSuccessful();
    }

    public function test_a_pool_is_recorded_and_carried_into_the_instructions()
    {
        $this->artisan('envcrypt:install', ['--project' => 'billing', '--pool' => 'BillingPool'])
            ->expectsOutputToContain('db:keygen --pool="BillingPool"')
            ->assertSuccessful();

        $this->assertSame('BillingPool', EnvFile::value($this->envContents(), 'ENVCRYPT_POOL'));
    }

    /** Without a name there is nothing to install, and no default is safe. */
    public function test_it_refuses_to_guess_a_name_when_not_interactive()
    {
        $this->artisan('envcrypt:install', ['--no-interaction' => true])->assertFailed();

        $this->assertNull(EnvFile::value($this->envContents(), 'ENVCRYPT_KEY_VAR'));
    }

    public function test_verify_fails_before_install_and_passes_after()
    {
        $this->artisan('envcrypt:verify')
            ->expectsOutputToContain('envcrypt:install')
            ->assertFailed();

        $this->artisan('envcrypt:install', ['--project' => 'billing'])->assertSuccessful();

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

        file_put_contents(
            $this->envPath(),
            "APP_ENV=local\nENVCRYPT_KEY_VAR=TUSHARB_TESTS_BUILD_TAG\nTUSHARB_TESTS_BUILD_TAG={$key}\n"
        );

        EnvCrypt::reset();
        EnvCrypt::configure(['base_path' => $this->app->basePath()]);

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
