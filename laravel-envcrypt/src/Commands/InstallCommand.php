<?php

namespace Npav\EnvCrypt\Commands;

use Illuminate\Console\Command;
use Npav\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Npav\EnvCrypt\EnvCrypt;
use Npav\EnvCrypt\EnvFile;

/**
 * One-time setup: publish the config file and name this project's secret.
 *
 * The name matters more than it looks. Two projects on one server that share a
 * variable name share a secret, so each gets its own - derived from the
 * project identifier, and recorded in .env as ENVCRYPT_KEY_VAR.
 */
class InstallCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'envcrypt:install
                            {--project= : Short identifier for this project, e.g. BILLING}
                            {--force : Overwrite an existing ENVCRYPT_KEY_VAR}';

    protected $description = 'Publish the EnvCrypt config and name this project\'s secret';

    public function handle()
    {
        $this->callSilent('vendor:publish', ['--tag' => 'envcrypt-config']);
        $this->line('  published  config/envcrypt.php');

        $envPath = $this->envPath();

        if (! is_file($envPath)) {
            $this->error('No .env found at ' . $envPath . '.');

            return 1;
        }

        $contents = file_get_contents($envPath);
        $existing = EnvFile::value($contents, 'ENVCRYPT_KEY_VAR');

        if ($existing !== null && $existing !== '' && ! $this->option('force')) {
            $this->line('  unchanged  ENVCRYPT_KEY_VAR is already ' . $existing);
            $this->printNextSteps($existing);

            return 0;
        }

        $project = $this->option('project');

        if (! $project) {
            $project = $this->ask(
                'Short identifier for this project',
                $this->normalise(basename(base_path()))
            );
        }

        $variable = 'NPAV_' . $this->normalise($project) . '_BUILD_TAG';

        if (! is_writable($envPath)) {
            $this->error('.env is not writable. Add this line by hand:');
            $this->line('  ENVCRYPT_KEY_VAR=' . $variable);

            return 1;
        }

        $updated = EnvFile::withValueSet($contents, 'ENVCRYPT_KEY_VAR', $variable);

        if (file_put_contents($envPath, $updated) === false) {
            $this->error('Could not write .env.');

            return 1;
        }

        $this->line('  wrote      ENVCRYPT_KEY_VAR=' . $variable . ' to .env');

        // The provider configured EnvCrypt before this line existed, so tell
        // it now - otherwise the very next command in this process would use
        // the old name.
        EnvCrypt::configure(['key_var' => $variable]);

        $this->printNextSteps($variable);

        return 0;
    }

    private function printNextSteps($variable)
    {
        $this->newLine();
        $this->info('Next steps');
        $this->line('1. Store the secret (ELEVATED prompt, once per server):');
        $this->line('     php artisan db:keygen');
        $this->line('   IIS needs "iisreset" afterwards to see a machine variable.');
        $this->newLine();
        $this->line('2. Open a NEW terminal, then encrypt the existing password(s):');
        $this->line('     php artisan db:password-encrypt-all');
        $this->newLine();
        $this->line('3. Check everything:');
        $this->line('     php artisan config:clear');
        $this->line('     php artisan db:secret-check');
        $this->newLine();
        $this->line('config/database.php must NOT be edited. Leave the password lines');
        $this->line('exactly as Laravel ships them - decryption happens at connection');
        $this->line('time, so no plaintext ever reaches bootstrap/cache/config.php.');
        $this->newLine();
        $this->line('The secret lives in ' . $variable . ', in the Windows registry or on');
        $this->line('an IIS application pool - never in .env on a production server.');
    }

    /** A short, uppercase identifier, safe as part of a variable name. */
    private function normalise($value)
    {
        return trim(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $value)), '_');
    }
}
