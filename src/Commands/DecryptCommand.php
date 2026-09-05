<?php

namespace Tusharb\EnvCrypt\Commands;

use Exception;
use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvFile;

/**
 * Turns encrypted passwords back into plaintext.
 *
 * Two modes, because two different jobs were being asked of one command:
 *
 *  --show   prints ONE value and changes nothing. Recovery and administration:
 *           you need the password for a database client, or to check what is
 *           actually stored. It puts the plaintext on screen, so it has no
 *           place in a deployment script or anything that logs its output.
 *
 *  default  rewrites .env, putting every managed password back in plaintext.
 *           This is the deliberate way out: run it before removing the
 *           package, or whenever encryption has to be undone. It backs the
 *           file up first, confirms, and verifies what landed on disk.
 *
 * Neither mode ever prints a password except the one --show was asked for.
 */
class DecryptCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'db:password-decrypt
                            {value? : An "enc:..." value to print; implies --show}
                            {--show : Print one value instead of rewriting .env}
                            {--key=DB_PASSWORD : Which .env key --show should read}
                            {--pool= : Read the secret from this application pool}
                            {--yes : Skip the confirmation (for scripted rollback)}';

    protected $description = 'Convert encrypted database passwords in .env back to plaintext';

    public function handle()
    {
        if ($this->option('show') || $this->argument('value')) {
            return $this->showOne();
        }

        return $this->rewriteEnv();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Rewrite .env with plaintext passwords.
     *
     * Everything is decrypted and checked in memory before the file is
     * touched: one value that cannot be read stops the whole thing, because a
     * half-decrypted .env is worse than either state.
     */
    private function rewriteEnv()
    {
        $secret = $this->authoritativeSecret($this->poolOption());
        $contents = $this->envContents();

        $encrypted = [];

        foreach ($this->resolveTargetKeys() as $key => $value) {
            if ($this->isEncryptedValue($value)) {
                $encrypted[$key] = $value;
            }
        }

        if ($encrypted === []) {
            $this->info('No encrypted database passwords in .env. Nothing to do.');

            return 0;
        }

        $this->line('These values will be written back to .env as PLAINTEXT:');

        foreach (array_keys($encrypted) as $key) {
            $this->line('  ' . $key);
        }

        $this->line('');
        $this->line('Only names are shown. The passwords themselves are not printed, and');
        $this->line('the database passwords are not being changed.');
        $this->line('');

        if (! $this->option('yes') && ! $this->confirm('Decrypt them in .env?', false)) {
            $this->line('Cancelled. Nothing was changed.');

            return 0;
        }

        $plain = [];

        foreach ($encrypted as $key => $value) {
            try {
                $plain[$key] = EnvCrypt::decrypt($value, $secret);
            } catch (Exception $e) {
                $this->error($key . ' cannot be decrypted: ' . $e->getMessage());
                $this->line('');
                $this->line('Nothing was changed. Every value must be readable before any of');
                $this->line('them is rewritten - restore a .env backup instead:');
                $this->line('  php artisan envcrypt:restore');

                return 1;
            }
        }

        $store = $this->backups();
        $backup = $store->create('decrypt');

        if ($backup === null) {
            $this->error('Could not write a backup to ' . $store->directory() . '. Nothing was changed.');

            return 1;
        }

        $this->line('Backup written: ' . $backup);

        $updated = $contents;

        foreach ($plain as $key => $value) {
            $updated = EnvFile::withValueSet($updated, $key, $value, $count);

            if ($count < 1) {
                $this->error('Could not find the ' . $key . ' line. Nothing was changed.');

                return 1;
            }
        }

        if (! $store->writeEnv($updated)) {
            $this->error('.env could not be written. Nothing was changed.');

            return 1;
        }

        // Confirm what actually landed, without ever printing it.
        $written = file_get_contents($this->envPath());
        $bad = [];

        foreach ($plain as $key => $value) {
            if (EnvFile::value($written, $key) !== $value) {
                $bad[] = $key;
            }
        }

        if ($bad !== []) {
            $store->writeEnv($contents);
            $this->error('These values did not read back correctly: ' . implode(', ', $bad));
            $this->line('.env has been restored. No password was changed.');

            return 1;
        }

        $this->info('Decrypted ' . count($plain) . ' value(s) in .env: ' . implode(', ', array_keys($plain)));
        $this->line('');
        $this->line('.env now holds plaintext passwords again. Next:');
        $this->line('  php artisan config:clear');
        $this->line('Then restart the web server and any queue workers.');
        $this->line('');
        $this->line('Both .env and ' . basename($backup) . ' now hold plaintext - delete the');
        $this->line('backup once you are satisfied.');

        return 0;
    }

    /**
     * Print one value. Recovery only, and it asks first, because the whole
     * point of the command is to put a password on the screen.
     */
    private function showOne()
    {
        $key = $this->option('key');
        $payload = $this->argument('value') ?: $this->envValue($key);

        if (! $payload) {
            $this->error('No value given, and no ' . $key . ' found in .env.');

            return 1;
        }

        if (! $this->option('yes') && ! $this->confirm('This prints the password to the terminal. Continue?', false)) {
            return 0;
        }

        try {
            $this->newLine();
            $this->line(EnvCrypt::decrypt($payload, $this->authoritativeSecret($this->poolOption())));
            $this->newLine();
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return 1;
        }

        return 0;
    }
}
