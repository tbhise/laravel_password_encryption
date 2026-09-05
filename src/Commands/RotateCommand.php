<?php

namespace Tusharb\EnvCrypt\Commands;

use Exception;
use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvCryptSecret;
use Tusharb\EnvCrypt\EnvFile;

/**
 * Replaces the secret and re-encrypts every managed password under the new
 * one, in memory.
 *
 * Doing this by hand means the plaintext passwords spend time in .env, in any
 * backup taken meanwhile, and in terminal scrollback.
 *
 * Strictly all-or-nothing across every key: one value that cannot be decrypted
 * under the current secret stops the whole rotation before anything is
 * touched, because a partial rotation would leave some values readable and
 * others permanently lost.
 */
class RotateCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'db:key-rotate
                            {--pool= : The application pool holding the secret, if not the registry}
                            {--dry-run : Check that rotation would succeed, and change nothing}
                            {--reveal-previous : Print the outgoing secret, for old .env backups}';

    protected $description = 'Replace the secret and re-encrypt every managed password';

    public function handle()
    {
        // Machine-wide registry variable unless a pool is named.
        $pool = $this->poolOption();

        if (! $this->requireWindows()) {
            return 1;
        }

        $envPath = $this->envPath();

        if (! is_writable($envPath)) {
            $this->error('.env is not writable at ' . $envPath);

            return 1;
        }

        $contents = file_get_contents($envPath);

        // Only currently-encrypted keys are rotation targets. Encrypting a
        // plaintext one is encrypt-all's job, not rotation's.
        $targets = [];

        foreach ($this->resolveTargetKeys() as $key => $value) {
            if ($this->isEncryptedValue($value)) {
                $targets[$key] = $value;
            }
        }

        if ($targets === []) {
            $this->error('No encrypted password values found in .env, so there is nothing to rotate.');

            return 1;
        }

        $this->line('Rotating ' . count($targets) . ' encrypted value(s): '
            . implode(', ', array_keys($targets)));
        $this->newLine();

        $previous = EnvCryptSecret::current($pool);

        // 1. Recover EVERY plaintext under the CURRENT secret before anything
        //    else happens. Locals only - never written anywhere, never echoed.
        //    One failure aborts the whole rotation: a partial rotation would
        //    strand whichever keys had not been re-encrypted yet.
        $plain = [];

        foreach ($targets as $key => $value) {
            try {
                $plain[$key] = EnvCrypt::decrypt($value, $previous);
            } catch (Exception $e) {
                $this->error($key . ' cannot be decrypted under the current secret.');
                $this->line($e->getMessage());
                $this->newLine();
                $this->line('Nothing was changed. Every managed value must be readable');
                $this->line('before the secret can be replaced - fix or plaintext that one,');
                $this->line('then rotate again.');

                return 1;
            }
        }

        // 2. Re-encrypt all of them under a NEW secret that is not installed
        //    yet, self-checking each before any of it reaches disk.
        $newSecret = EnvCrypt::generateRootKey();
        $reEncrypted = [];

        foreach ($plain as $key => $value) {
            $payload = EnvCrypt::encrypt($value, $newSecret);

            if (EnvCrypt::decrypt($payload, $newSecret) !== $value) {
                $this->error('Self-check failed for ' . $key . '. Nothing was changed.');

                return 1;
            }

            $reEncrypted[$key] = $payload;
        }

        if ($this->option('dry-run')) {
            $this->info('Rotation would succeed for all ' . count($reEncrypted)
                . ' value(s). Nothing was changed.');

            return 0;
        }

        $this->warn('The application will not connect until its workers are recycled.');
        $this->warn('Existing .env backups will not be readable without the outgoing secret.');

        if (! $this->confirm('Rotate now?', false)) {
            $this->line('Cancelled. Nothing was changed.');

            return 0;
        }

        // Only obtainable now, before it is overwritten, and only on request:
        // printing it by default would put a live secret into scrollback on
        // every routine rotation.
        if ($this->option('reveal-previous')
            && $this->confirm('Print the outgoing secret to this terminal?', false)) {
            $this->newLine();
            $this->line('Outgoing secret - store it with the backups it unlocks:');
            $this->line('  ' . (string) $previous);
            $this->newLine();
        }

        // Ordering is deliberate. Atomicity across two stores is impossible, so
        // every reversible step goes first and the one irreversible step -
        // overwriting the installed secret - goes last. A failure then leaves
        // the old secret beside the old ciphertext, which is a working system.
        $backup = $envPath . '.rotate-backup-' . date('Ymd-His');

        if (file_put_contents($backup, $contents) === false) {
            $this->error('Could not write a backup to ' . $backup . '. Nothing was changed.');

            return 1;
        }

        $updated = $contents;

        foreach ($reEncrypted as $key => $payload) {
            $updated = EnvFile::withValueSet($updated, $key, $payload, $count);

            if ($count < 1) {
                $this->error('Could not find the ' . $key . ' line to update. Nothing was changed.');

                return 1;
            }
        }

        if (file_put_contents($envPath, $updated) === false) {
            $this->error('.env could not be written. Nothing was changed.');

            return 1;
        }

        // Verify what actually landed on disk, rather than trusting the write.
        $unreadable = $this->keysThatDoNotReadBack($newSecret, $plain);

        if ($unreadable !== []) {
            file_put_contents($envPath, $contents);
            $this->error('These values did not read back correctly from .env: '
                . implode(', ', $unreadable));
            $this->line('.env has been restored and the secret was never touched.');

            return 1;
        }

        $result = $pool
            ? EnvCryptSecret::writeToPool($pool, $newSecret)
            : EnvCryptSecret::writeToMachine($newSecret);

        $stored = $pool ? EnvCryptSecret::fromPool($pool) : EnvCryptSecret::fromMachine();

        if ($result !== true || $stored !== $newSecret) {
            file_put_contents($envPath, $contents);
            $this->error('The new secret could not be stored and verified.');
            $this->line('.env has been restored. The previous secret is still installed,');
            $this->line('so the application is unchanged and still working.');

            return 1;
        }

        if ($pool) {
            EnvCryptSecret::recyclePool($pool);
        }

        $this->info('Rotated ' . count($reEncrypted) . ' value(s): '
            . implode(', ', array_keys($reEncrypted)));
        $this->newLine();
        $this->line('Backup of the previous .env: ' . $backup);
        $this->line('It holds the OLD ciphertext, which the new secret cannot read.');
        $this->newLine();
        $this->line('Next: php artisan config:clear, php artisan queue:restart,');
        $this->line('then php artisan db:secret-check. Delete the backup once verified.');

        return 0;
    }

    /**
     * Which keys on disk do NOT decrypt back to the plaintext we started with.
     * Names only - the values themselves are never reported.
     */
    private function keysThatDoNotReadBack($secret, array $expected)
    {
        $contents = $this->envContents();
        $bad = [];

        foreach ($expected as $key => $plain) {
            $value = EnvFile::value($contents, $key);

            if ($value === null) {
                $bad[] = $key;

                continue;
            }

            try {
                if (EnvCrypt::decrypt($value, $secret) !== $plain) {
                    $bad[] = $key;
                }
            } catch (Exception $e) {
                $bad[] = $key;
            }
        }

        return $bad;
    }
}
