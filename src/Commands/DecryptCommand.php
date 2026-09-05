<?php

namespace Tusharb\EnvCrypt\Commands;

use Exception;
use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\EnvCrypt;

/**
 * Prints a decrypted value.
 *
 * Recovery and administration only. It puts the plaintext password on screen,
 * so it has no place in a deployment script, a scheduled task, or anything
 * that logs its output.
 */
class DecryptCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'db:password-decrypt
                            {value? : The "enc:..." value, or omit to read --key from .env}
                            {--key=DB_PASSWORD : Which .env key to read when no value is given}
                            {--pool= : Read the secret from this application pool}';

    protected $description = 'Print a decrypted database password (recovery only)';

    public function handle()
    {
        $key = $this->option('key');
        $payload = $this->argument('value') ?: $this->envValue($key);

        if (! $payload) {
            $this->error('No value given, and no ' . $key . ' found in .env.');

            return 1;
        }

        if (! $this->confirm('This prints the password to the terminal. Continue?', false)) {
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
