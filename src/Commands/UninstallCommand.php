<?php

namespace Tusharb\EnvCrypt\Commands;

use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvCryptSecret;

/**
 * Takes the project back to where it started, in the order that keeps it
 * working throughout.
 *
 * The hazard this exists to remove: "composer remove" deletes the code that
 * decrypts, while .env still says DB_PASSWORD="enc:...". Nothing then reads
 * that value, every query fails, and the secret needed to recover it may
 * already have been cleaned up. So the passwords go back to plaintext FIRST,
 * and only then is anything removed.
 *
 * Run this BEFORE composer remove.
 */
class UninstallCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'envcrypt:uninstall
                            {--pool= : The application pool holding the secret, if not the registry}
                            {--keep-secret : Leave the stored secret in place}
                            {--keep-encrypted : Leave the "enc:" values in .env alone}
                            {--force : Remove the secret even while encrypted values depend on it}';

    protected $description = 'Decrypt .env and remove this package\'s files and secret';

    public function handle()
    {
        $this->heading('Uninstalling');

        $variable = EnvCrypt::rootKeyVar();

        if (! $this->restorePlaintext()) {
            return 1;
        }

        $this->removePublishedFiles();
        $this->removeSecret($variable);

        $this->line('');
        $this->call('config:clear');

        $this->heading('Done');

        $remaining = $this->encryptedTargetKeyNames();

        if ($remaining === []) {
            $this->line('.env holds plaintext passwords again, so the application works with or');
            $this->line('without this package. It is now safe to run:');
            $this->line('');
            $this->line('  composer remove tusharb/laravel-envcrypt');
        } else {
            $this->warn('These values in .env are STILL encrypted: ' . implode(', ', $remaining));
            $this->line('');
            $this->line('Do NOT remove the package yet - nothing would be able to decrypt them.');
            $this->line('Put the plaintext passwords back first:');
            $this->line('  php artisan db:password-decrypt        (needs the secret)');
            $this->line('  php artisan envcrypt:restore           (from a .env backup)');
        }

        $this->line('');
        $this->line('Left alone: config/database.php, and every .env value not managed here.');
        $this->line('Your database passwords were never changed on the database side.');

        return 0;
    }

    /**
     * Put the passwords back in plaintext while the code that can read them is
     * still installed. This is the step that makes removal survivable.
     */
    private function restorePlaintext()
    {
        $encrypted = $this->encryptedTargetKeyNames();

        if ($encrypted === []) {
            $this->line('  nothing  no encrypted values in .env');

            return true;
        }

        if ($this->option('keep-encrypted')) {
            $this->warn('  kept     ' . count($encrypted) . ' encrypted value(s) (--keep-encrypted)');

            return true;
        }

        $this->line('  found    ' . count($encrypted) . ' encrypted value(s): ' . implode(', ', $encrypted));
        $this->line('');
        $this->line('  They must go back to plaintext before the package is removed, or the');
        $this->line('  application will not be able to connect.');
        $this->line('');

        $arguments = $this->poolOption() ? ['--pool' => $this->poolOption()] : [];

        if ($this->call('db:password-decrypt', $arguments) !== 0) {
            $this->line('');
            $this->error('Could not decrypt .env, so nothing was removed.');
            $this->line('The package is untouched and the application still works.');
            $this->line('Restore a backup instead if the secret is gone: php artisan envcrypt:restore');

            return false;
        }

        return true;
    }

    private function removePublishedFiles()
    {
        $this->heading('Removing files');

        foreach ([config_path('envcrypt.php'), storage_path('tools/envcrypt.php')] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $this->line(@unlink($path)
                ? '  removed  ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path)
                : '  COULD NOT remove ' . $path);
        }
    }

    private function removeSecret($variable)
    {
        $this->heading('Removing the secret');

        if ($this->option('keep-secret')) {
            $this->line('  kept     ' . $variable . ' (--keep-secret)');

            return;
        }

        // Re-read: the decryption above should have emptied this, and if it
        // did not, the secret is still holding the only way back.
        $encrypted = $this->encryptedTargetKeyNames();

        if ($encrypted !== [] && ! $this->option('force')) {
            $this->line('  kept     ' . $variable);
            $this->line('           .env still holds encrypted values: ' . implode(', ', $encrypted));
            $this->line('           Removing the secret now would make them unrecoverable.');

            return;
        }

        if (! EnvCryptSecret::onWindows()) {
            $this->line('  Not on Windows - remove ' . $variable . ' from wherever it was set.');

            return;
        }

        if (! EnvCryptSecret::elevated()) {
            $this->warn('  Could not confirm this prompt is elevated - trying anyway.');
        }

        $pool = $this->poolOption();

        $result = $pool
            ? EnvCryptSecret::clearFromPool($pool)
            : EnvCryptSecret::clearFromMachine();

        if ($result === true) {
            $this->line('  removed  ' . $variable . ($pool ? ' from pool "' . $pool . '"' : ' from the machine registry'));

            if ($pool) {
                EnvCryptSecret::recyclePool($pool);
            }

            return;
        }

        $this->line('  COULD NOT remove ' . $variable . ': ' . (is_string($result) ? $result : 'unknown error'));
        $this->line('  From an elevated prompt, by hand:');
        $this->line('    reg delete "' . EnvCryptSecret::HIVE . '" /v ' . $variable . ' /f');
    }

    private function heading($text)
    {
        $this->line('');
        $this->line($text);
        $this->line(str_repeat('-', strlen($text)));
    }
}
