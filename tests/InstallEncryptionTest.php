<?php

namespace Tusharb\EnvCrypt\Tests;

use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvFile;

/**
 * The installer's migration half, end to end.
 *
 * A project whose secret this process can already use - a development machine
 * with the secret in .env - so the flow runs without an elevated prompt and
 * the review, confirmation, backup and cleanup can all be exercised.
 */
class InstallEncryptionTest extends TestCase
{
    /** @var string */
    private $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = EnvCrypt::generateRootKey();

        // Two real connection passwords, plus two decoys that no connection
        // uses. config/database.php in testbench reads DB_PASSWORD for mysql.
        $this->useDevelopmentSecret(
            $this->key,
            "DB_PASSWORD=main-pass\nDB_PASSWORD_REPORTING=reporting-pass\n"
            . "MAIL_PASSWORD=mail-pass\nREDIS_PASSWORD=redis-pass\n"
        );

        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => ['driver' => 'mysql', 'password' => 'main-pass'],
            'database.connections.reporting' => ['driver' => 'mysql', 'password' => 'reporting-pass'],
        ]);
    }

    private function install()
    {
        return $this->artisan('envcrypt:install', ['--project' => 'billing']);
    }

    public function test_it_encrypts_only_the_database_passwords()
    {
        $this->install()
            ->expectsQuestion('Choice', '')
            ->expectsConfirmation('Continue with password encryption?', 'yes')
            ->expectsConfirmation('Is the application working?', 'no')
            ->assertSuccessful();

        $env = $this->envContents();

        $this->assertTrue(EnvCrypt::isEncrypted(EnvFile::value($env, 'DB_PASSWORD')));
        $this->assertTrue(EnvCrypt::isEncrypted(EnvFile::value($env, 'DB_PASSWORD_REPORTING')));

        // The decoys are password-like by name, but no connection uses them.
        $this->assertSame('mail-pass', EnvFile::value($env, 'MAIL_PASSWORD'));
        $this->assertSame('redis-pass', EnvFile::value($env, 'REDIS_PASSWORD'));
    }

    public function test_the_encrypted_values_decrypt_back_to_the_originals()
    {
        $this->install()
            ->expectsQuestion('Choice', '')
            ->expectsConfirmation('Continue with password encryption?', 'yes')
            ->expectsConfirmation('Is the application working?', 'no')
            ->assertSuccessful();

        $env = $this->envContents();

        $this->assertSame('main-pass', EnvCrypt::decrypt(EnvFile::value($env, 'DB_PASSWORD'), $this->key));
        $this->assertSame(
            'reporting-pass',
            EnvCrypt::decrypt(EnvFile::value($env, 'DB_PASSWORD_REPORTING'), $this->key)
        );
    }

    public function test_it_lists_the_field_names_and_never_a_value()
    {
        $this->install()
            ->expectsOutputToContain('DB_PASSWORD_REPORTING')
            ->doesntExpectOutputToContain('main-pass')
            ->doesntExpectOutputToContain('reporting-pass')
            ->expectsQuestion('Choice', '')
            ->expectsConfirmation('Continue with password encryption?', 'yes')
            ->expectsConfirmation('Is the application working?', 'no')
            ->assertSuccessful();
    }

    /** Declining the final confirmation must leave every credential alone. */
    public function test_declining_the_confirmation_changes_nothing()
    {
        $before = $this->envContents();

        $this->install()
            ->expectsQuestion('Choice', '')
            ->expectsConfirmation('Continue with password encryption?', 'no')
            ->assertSuccessful();

        $this->assertSame(
            EnvFile::value($before, 'DB_PASSWORD'),
            EnvFile::value($this->envContents(), 'DB_PASSWORD')
        );
    }

    /** Cancelling at the review stage is not consent either. */
    public function test_cancelling_the_review_changes_nothing()
    {
        $this->install()
            ->expectsQuestion('Choice', 'c')
            ->assertSuccessful();

        $this->assertSame('main-pass', EnvFile::value($this->envContents(), 'DB_PASSWORD'));
    }

    /** The operator can drop a field from the proposed list. */
    public function test_a_field_can_be_removed_before_encryption()
    {
        $this->install()
            ->expectsQuestion('Choice', 'r')
            ->expectsQuestion('Enter numbers to remove (comma-separated)', '2')
            ->expectsQuestion('Choice', '')
            ->expectsConfirmation('Continue with password encryption?', 'yes')
            ->expectsConfirmation('Is the application working?', 'no')
            ->assertSuccessful();

        $env = $this->envContents();

        $this->assertTrue(EnvCrypt::isEncrypted(EnvFile::value($env, 'DB_PASSWORD')));
        $this->assertSame('reporting-pass', EnvFile::value($env, 'DB_PASSWORD_REPORTING'));
    }

    public function test_it_backs_up_env_before_encrypting()
    {
        $this->install()
            ->expectsQuestion('Choice', '')
            ->expectsConfirmation('Continue with password encryption?', 'yes')
            ->expectsConfirmation('Is the application working?', 'no')
            ->assertSuccessful();

        $backups = $this->backups()->all();

        $this->assertNotEmpty($backups);
        $this->assertStringContainsString('main-pass', file_get_contents($backups[0]));
    }

    /** A working application means the plaintext backup should not linger. */
    public function test_it_offers_to_delete_the_backup_once_the_app_is_confirmed_working()
    {
        $this->install()
            ->expectsQuestion('Choice', '')
            ->expectsConfirmation('Continue with password encryption?', 'yes')
            ->expectsConfirmation('Is the application working?', 'yes')
            ->expectsQuestion('What should happen to it?', 'delete')
            ->expectsOutputToContain('Deleted.')
            ->assertSuccessful();

        $this->assertEmpty($this->backups()->all());
    }

    /** A broken application means the rollback must survive. */
    public function test_it_keeps_the_backup_and_names_the_restore_command_when_the_app_is_broken()
    {
        $this->install()
            ->expectsQuestion('Choice', '')
            ->expectsConfirmation('Continue with password encryption?', 'yes')
            ->expectsConfirmation('Is the application working?', 'no')
            ->expectsOutputToContain('envcrypt:restore')
            ->assertSuccessful();

        $this->assertNotEmpty($this->backups()->all());
    }

    public function test_it_never_restarts_iis_itself()
    {
        $this->install()
            ->expectsQuestion('Choice', '')
            ->expectsConfirmation('Continue with password encryption?', 'yes')
            ->expectsConfirmation('Is the application working?', 'no')
            ->expectsOutputToContain('Restart it yourself')
            ->assertSuccessful();
    }
}
