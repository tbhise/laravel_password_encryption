<?php

namespace Tusharb\EnvCrypt\Commands;

use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvCryptSecret;

/**
 * Removes what envcrypt:install put in place, and the secret itself.
 *
 * It does NOT touch the "enc:" values in .env: removing the secret while a
 * value still depends on it is the one irreversible mistake available here, so
 * that case stops the command instead. Put the plaintext passwords back first
 * - "php artisan db:password-decrypt --key=NAME" prints each one - or pass
 * --force if they are genuinely disposable.
 */
class UninstallCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'envcrypt:uninstall
                            {--pool= : The application pool holding the secret, if not the registry}
                            {--keep-secret : Leave the stored secret alone}
                            {--force : Remove the secret even while encrypted values depend on it}';

    protected $description = 'Remove the published files and the stored secret';

    public function handle()
    {
        $this->line('Uninstalling');
        $this->line('------------');

        $variable = EnvCrypt::rootKeyVar();

        // Read before anything is deleted: .env is the only place this
        // project's secret name is recorded.
        $encrypted = $this->encryptedTargetKeyNames();

        foreach ([config_path('envcrypt.php'), storage_path('tools/envcrypt.php')] as $path) {
            if (! is_file($path)) {
                continue;
            }

            if (unlink($path)) {
                $this->line('  removed  ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path));
            } else {
                $this->line('  COULD NOT remove ' . $path);
            }
        }

        $this->removeSecret($variable, $encrypted);

        $this->line('');
        $this->line('Left alone: .env, config/database.php, and every "enc:" value.');
        $this->line('');
        $this->line('If a managed value is still an "enc:" value, the application will not');
        $this->line('connect once the package is removed - put the plaintext password back');
        $this->line('in .env first. Then: composer remove tusharb/laravel-envcrypt');

        return 0;
    }

    private function removeSecret($variable, array $encrypted)
    {
        if ($this->option('keep-secret')) {
            $this->line('  kept     ' . $variable . ' (--keep-secret)');

            return;
        }

        if ($encrypted !== [] && ! $this->option('force')) {
            $this->line('  kept     ' . $variable);
            $this->line('           These .env values still look encrypted: ' . implode(', ', $encrypted));
            $this->line('           Leaving the secret in place so they stay recoverable.');
            $this->line('           Decrypt them first, or re-run with --force.');

            return;
        }

        if (! EnvCryptSecret::onWindows()) {
            $this->line('  Not on Windows - remove ' . $variable . ' from wherever it was set.');

            return;
        }

        if (! $this->requireWindows()) {
            return;
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
}
