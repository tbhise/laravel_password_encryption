<?php

namespace Tusharb\EnvCrypt\Commands;

use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvCryptSecret;

/**
 * Generates the secret and stores it.
 *
 * Two behaviours worth knowing: writing needs elevation, and a process cannot
 * see a variable set after it started - so the write is verified by reading the
 * store back, never with getenv().
 */
class KeygenCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'db:keygen
                            {--pool= : Store it on this IIS application pool instead}
                            {--show : Print a new secret and store nothing}
                            {--force : Replace a stored secret that nothing depends on}';

    protected $description = 'Store the secret that unlocks an encrypted DB_PASSWORD';

    public function handle()
    {
        $secret = EnvCrypt::generateRootKey();

        if ($this->option('show')) {
            $this->line($secret);

            return 0;
        }

        // Checked before elevation, so someone in the wrong situation is told
        // what is actually wrong. --force does NOT override this: there is no
        // safe way to discard a secret a value on disk still depends on, so
        // rotation is a separate command that re-encrypts as it goes.
        //
        // Covers EVERY managed password, not just DB_PASSWORD - otherwise a
        // fresh secret would silently strand DB_PASSWORD_SECOND and friends.
        $encrypted = $this->encryptedTargetKeyNames();

        if ($encrypted !== []) {
            $this->error(count($encrypted) . ' value(s) in .env are already encrypted:');
            $this->line('  ' . implode(', ', $encrypted));
            $this->newLine();
            $this->line('Replacing the secret would make them unreadable. To change the');
            $this->line('secret, rotate instead - it re-encrypts every one of them in one step:');
            $this->line('  php artisan db:key-rotate --pool="YourAppPool"');
            $this->newLine();
            $this->line('If they are genuinely unrecoverable, put the plaintext passwords');
            $this->line('back into .env first, then run this command again.');

            return 1;
        }

        // Machine-wide registry variable unless a pool is named.
        $pool = $this->poolOption();

        if (! $this->requireWindows()) {
            return 1;
        }

        $this->line('Storing ' . EnvCrypt::rootKeyVar()
            . ($pool ? ' on pool "' . $pool . '"' : ' machine-wide') . '.');
        $this->newLine();

        $existing = $pool ? EnvCryptSecret::fromPool($pool) : EnvCryptSecret::fromMachine();

        if ($existing !== null && ! $this->option('force')) {
            $this->error('A secret is already stored, and nothing appears to depend on it.');
            $this->line('If that is right, replace it with --force.');

            return 1;
        }

        $result = $pool
            ? EnvCryptSecret::writeToPool($pool, $secret)
            : EnvCryptSecret::writeToMachine($secret);

        if ($result !== true) {
            $this->explainStoreFailure((string) $result);

            return 1;
        }

        $stored = $pool ? EnvCryptSecret::fromPool($pool) : EnvCryptSecret::fromMachine();

        if ($stored !== $secret) {
            $this->error('Written, but it did not read back. Nothing changed reliably.');

            return 1;
        }

        $this->info('Secret stored.');
        $this->newLine();
        $this->line('Back it up now - this is the only copy, and losing it makes an');
        $this->line('encrypted DB_PASSWORD unrecoverable.');
        $this->newLine();

        if ($pool) {
            EnvCryptSecret::recyclePool($pool);
            $this->line('Application pool recycled.');
        } else {
            $this->warn('A machine variable needs "iisreset" before IIS sees it,');
            $this->warn('because WAS reads the machine environment when it starts.');
        }

        $this->newLine();
        $this->line('Next, in a NEW terminal: php artisan db:password-encrypt-all');

        return 0;
    }
}
