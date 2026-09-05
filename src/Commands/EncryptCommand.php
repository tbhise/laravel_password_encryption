<?php

namespace Tusharb\EnvCrypt\Commands;

use Exception;
use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\EnvCrypt;

/**
 * Encrypts a password for pasting into .env.
 *
 * Prompts with the echo off and never accepts the password as an argument, so
 * the plaintext reaches neither the screen nor shell history.
 */
class EncryptCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'db:password-encrypt
                            {--key=DB_PASSWORD : Which .env key the value is for}
                            {--pool= : Read the secret from this application pool}';

    protected $description = 'Encrypt a database password for .env';

    public function handle()
    {
        $key = $this->option('key');
        $secret = $this->authoritativeSecret($this->poolOption());

        $first = $this->secret('Current database password');
        $second = $this->secret('Repeat to confirm');

        if ($first === null || $first === '') {
            $this->error('Nothing was entered.');

            return 1;
        }

        if ($first !== $second) {
            $this->error('The two entries do not match. Nothing was encrypted.');

            return 1;
        }

        try {
            $payload = EnvCrypt::encrypt($first, $secret);

            // Read it straight back, so a broken value is never handed over.
            if (EnvCrypt::decrypt($payload, $secret) !== $first) {
                $this->error('Self-check failed. Do not use this value.');

                return 1;
            }
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->newLine();
        $this->info('Self-check passed. Copy this exact line into .env:');
        $this->newLine();
        $this->line('  ' . $key . '="' . $payload . '"');
        $this->newLine();
        $this->line('Then: php artisan config:clear && php artisan db:secret-check');

        return 0;
    }
}
