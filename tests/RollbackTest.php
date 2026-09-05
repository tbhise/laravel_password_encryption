<?php

namespace Tusharb\EnvCrypt\Tests;

use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvFile;

/**
 * The ways back: decrypt in place, restore a backup, and uninstall.
 *
 * All three exist because of one hazard - removing the package while .env
 * still says DB_PASSWORD="enc:...", after which nothing can read it.
 */
class RollbackTest extends TestCase
{
    /** @var string */
    private $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = EnvCrypt::generateRootKey();
    }

    /** A project mid-flight: encrypted values, and a secret that can read them. */
    private function encryptedProject()
    {
        $payload = EnvCrypt::encrypt('real-password', $this->key);
        $second = EnvCrypt::encrypt('second-password', $this->key);

        $this->useDevelopmentSecret(
            $this->key,
            "ENVCRYPT_TARGET_KEYS=\"DB_PASSWORD,DB_PASSWORD_SECOND\"\n"
            . "DB_PASSWORD=\"{$payload}\"\n"
            . "DB_PASSWORD_SECOND=\"{$second}\"\n"
        );
    }

    public function test_decrypt_writes_the_plaintext_back_into_env()
    {
        $this->encryptedProject();

        $this->artisan('db:password-decrypt')
            ->expectsConfirmation('Decrypt them in .env?', 'yes')
            ->assertSuccessful();

        $this->assertSame('real-password', EnvFile::value($this->envContents(), 'DB_PASSWORD'));
        $this->assertSame('second-password', EnvFile::value($this->envContents(), 'DB_PASSWORD_SECOND'));
    }

    public function test_decrypt_takes_a_backup_first()
    {
        $this->encryptedProject();

        $this->artisan('db:password-decrypt')
            ->expectsConfirmation('Decrypt them in .env?', 'yes')
            ->assertSuccessful();

        $backups = $this->backups()->all();

        $this->assertNotEmpty($backups);
        $this->assertStringContainsString('enc:', file_get_contents($backups[0]));
    }

    public function test_decrypt_changes_nothing_without_confirmation()
    {
        $this->encryptedProject();
        $before = $this->envContents();

        $this->artisan('db:password-decrypt')
            ->expectsConfirmation('Decrypt them in .env?', 'no')
            ->assertSuccessful();

        $this->assertSame($before, $this->envContents());
    }

    /** Names only: a decrypted password must never reach the terminal. */
    public function test_decrypt_never_prints_a_password()
    {
        $this->encryptedProject();

        $this->artisan('db:password-decrypt')
            ->expectsConfirmation('Decrypt them in .env?', 'yes')
            ->doesntExpectOutputToContain('real-password')
            ->assertSuccessful();
    }

    public function test_restore_puts_the_backed_up_env_back()
    {
        $this->backups()->create('encrypt');
        $original = $this->envContents();

        $this->writeEnv("APP_ENV=local\nDB_PASSWORD=\"enc:broken\"\n");

        $this->artisan('envcrypt:restore')
            ->expectsConfirmation('Restore it?', 'yes')
            ->assertSuccessful();

        $this->assertSame($original, $this->envContents());
    }

    public function test_restore_reports_when_there_is_nothing_to_restore()
    {
        $this->artisan('envcrypt:restore')->assertFailed();
    }

    public function test_restore_lists_what_is_available()
    {
        $backup = $this->backups()->create('encrypt');

        $this->artisan('envcrypt:restore', ['--list' => true])
            ->expectsOutputToContain(basename($backup))
            ->assertSuccessful();
    }

    /**
     * The whole reason uninstall exists: it must put the passwords back before
     * anything that can read them is removed.
     */
    public function test_uninstall_decrypts_before_reporting_it_is_safe_to_remove()
    {
        $this->encryptedProject();

        $this->artisan('envcrypt:uninstall', ['--keep-secret' => true])
            ->expectsConfirmation('Decrypt them in .env?', 'yes')
            ->expectsOutputToContain('composer remove tusharb/laravel-envcrypt')
            ->assertSuccessful();

        $this->assertSame('real-password', EnvFile::value($this->envContents(), 'DB_PASSWORD'));
    }

    /** Declining the decryption must leave the package - and the app - intact. */
    public function test_uninstall_stops_when_the_values_are_not_decrypted()
    {
        $this->encryptedProject();
        $before = $this->envContents();

        $this->artisan('envcrypt:uninstall', ['--keep-secret' => true])
            ->expectsConfirmation('Decrypt them in .env?', 'no')
            ->expectsOutputToContain('STILL encrypted')
            ->assertSuccessful();

        $this->assertSame($before, $this->envContents());
    }

    public function test_uninstall_removes_the_published_files_once_plaintext_is_back()
    {
        $this->encryptedProject();
        @mkdir(dirname(storage_path('tools/envcrypt.php')), 0777, true);
        file_put_contents(storage_path('tools/envcrypt.php'), '<?php');
        file_put_contents(config_path('envcrypt.php'), '<?php return [];');

        $this->artisan('envcrypt:uninstall', ['--keep-secret' => true])
            ->expectsConfirmation('Decrypt them in .env?', 'yes')
            ->assertSuccessful();

        $this->assertFileDoesNotExist(config_path('envcrypt.php'));
        $this->assertFileDoesNotExist(storage_path('tools/envcrypt.php'));
    }

    /** Nothing encrypted is a legitimate state, not an error. */
    public function test_uninstall_on_a_plaintext_project_is_a_no_op_for_env()
    {
        $before = $this->envContents();

        $this->artisan('envcrypt:uninstall', ['--keep-secret' => true])->assertSuccessful();

        $this->assertSame($before, $this->envContents());
    }

    /** Already-encrypted values must never be encrypted a second time. */
    public function test_encrypting_twice_leaves_the_value_alone()
    {
        $this->encryptedProject();
        $before = EnvFile::value($this->envContents(), 'DB_PASSWORD');

        $this->artisan('db:password-encrypt-all')
            ->expectsOutputToContain('already encrypted')
            ->assertSuccessful();

        $this->assertSame($before, EnvFile::value($this->envContents(), 'DB_PASSWORD'));
    }
}
