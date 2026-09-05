<?php

namespace Tusharb\EnvCrypt\Commands;

use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvFile;

/**
 * Sets the project up, and says what is left to do.
 *
 * Composer can only put files in vendor/. This is the step that turns them
 * into a working installation: it names this project's secret, publishes the
 * config and the standalone tool, and prints the remaining operator steps -
 * the ones no package can perform for itself, because they need an elevated
 * prompt and the database password.
 *
 * Idempotent, so it is safe in a deploy script: a second run reports
 * "unchanged" and changes nothing.
 */
class InstallCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'envcrypt:install
                            {--project= : Short identifier for this project, e.g. BILLING}
                            {--pool= : Record an IIS application pool as this project\'s secret store}
                            {--no-tool : Skip publishing storage/tools/envcrypt.php}
                            {--force : Overwrite an existing name, config or tool}';

    protected $description = 'Set this project up to use encrypted database passwords';

    public function handle()
    {
        $this->heading('Installing');

        $envPath = $this->envPath();

        if (! is_file($envPath)) {
            $this->error('No .env found at ' . $envPath . '.');
            $this->line('Copy .env.example to .env first - there is nothing to protect yet.');

            return 1;
        }

        if (! is_writable($envPath)) {
            $this->error('.env is not writable at ' . $envPath . '.');

            return 1;
        }

        $variable = $this->resolveSecretName();

        if ($variable === null) {
            return 1;
        }

        $this->line('  project root : ' . base_path());
        $this->line('  secret name  : ' . $variable);
        $this->line('');

        if (! $this->recordSecretName($variable)) {
            return 1;
        }

        $this->recordPool();
        $this->publishConfig();
        $this->publishTool();

        // The provider configured EnvCrypt before .env said any of this, so
        // tell it now - otherwise the verification below, and any command
        // chained after this one, would still use the old name.
        EnvCrypt::configure(['key_var' => $variable]);

        $this->line('');

        if ($this->call('envcrypt:verify') !== 0) {
            $this->line('');
            $this->error('Installed, but verification failed - see above.');

            return 1;
        }

        $this->printNextSteps($variable);

        return 0;
    }

    /**
     * The name of this project's secret.
     *
     * A project identifier goes in it so two projects on one server never
     * share a secret - an "enc:" value from one would otherwise decrypt in the
     * other, and one compromise would expose every database on the box. The
     * name also says nothing about what it holds, so it does not stand out in
     * a configuration dump. That buys time against untargeted scanning and
     * nothing more; it is not a security control.
     */
    private function resolveSecretName()
    {
        $existing = EnvFile::value($this->envContents(), 'ENVCRYPT_KEY_VAR');

        if ($existing !== null && $existing !== '' && ! $this->option('force')) {
            return $existing;
        }

        $project = $this->option('project');

        if (! $project) {
            if (! $this->input->isInteractive()) {
                $this->error('No project identifier given.');
                $this->line('Re-run with --project=NAME, e.g. --project=BILLING.');

                return null;
            }

            $project = $this->ask(
                'Short identifier for this project',
                $this->normalise(basename(base_path()))
            );
        }

        $project = $this->normalise($project);

        if ($project === '') {
            $this->error('That identifier has no letters or digits in it.');

            return null;
        }

        return 'TUSHARB_' . $project . '_BUILD_TAG';
    }

    /**
     * The NAME goes in .env; the secret itself never does. Recording it there
     * rather than only in config/envcrypt.php is what lets the standalone tool
     * - which boots no framework and reads no config - resolve the same name.
     */
    private function recordSecretName($variable)
    {
        $contents = $this->envContents();

        if (EnvFile::value($contents, 'ENVCRYPT_KEY_VAR') === $variable) {
            $this->line('  unchanged  ENVCRYPT_KEY_VAR');

            return true;
        }

        $updated = EnvFile::withValueSet($contents, 'ENVCRYPT_KEY_VAR', $variable);

        if (file_put_contents($this->envPath(), $updated) === false) {
            $this->error('Could not write .env. Add this line by hand:');
            $this->line('    ENVCRYPT_KEY_VAR=' . $variable);

            return false;
        }

        $this->line('  wrote      ENVCRYPT_KEY_VAR=' . $variable . ' to .env');

        return true;
    }

    private function recordPool()
    {
        $pool = $this->option('pool');

        if (! $pool) {
            return;
        }

        $contents = $this->envContents();

        if (EnvFile::value($contents, 'ENVCRYPT_POOL') === $pool) {
            $this->line('  unchanged  ENVCRYPT_POOL');

            return;
        }

        file_put_contents($this->envPath(), EnvFile::withValueSet($contents, 'ENVCRYPT_POOL', $pool));
        $this->line('  wrote      ENVCRYPT_POOL=' . $pool . ' to .env');
    }

    private function publishConfig()
    {
        $path = config_path('envcrypt.php');

        if (is_file($path) && ! $this->option('force')) {
            $this->line('  unchanged  config/envcrypt.php');

            return;
        }

        $this->publishTag('envcrypt-config');

        $this->line(is_file($path)
            ? '  wrote      config/envcrypt.php'
            : '  FAILED to publish config/envcrypt.php');
    }

    /**
     * The standalone tool is what works when the application will not boot -
     * which is exactly the situation a wrong DB_PASSWORD creates.
     */
    private function publishTool()
    {
        if ($this->option('no-tool')) {
            $this->line('  skipped    storage/tools/envcrypt.php (--no-tool)');

            return;
        }

        $path = storage_path('tools/envcrypt.php');

        if (is_file($path) && ! $this->option('force')) {
            $this->line('  unchanged  storage/tools/envcrypt.php');

            return;
        }

        $this->publishTag('envcrypt-tool');

        $this->line(is_file($path)
            ? '  wrote      storage/tools/envcrypt.php'
            : '  FAILED to publish storage/tools/envcrypt.php');
    }

    private function publishTag($tag)
    {
        $arguments = ['--tag' => $tag];

        if ($this->option('force')) {
            $arguments['--force'] = true;
        }

        $this->callSilent('vendor:publish', $arguments);
    }

    private function printNextSteps($variable)
    {
        $pool = $this->option('pool') ?: EnvFile::value($this->envContents(), 'ENVCRYPT_POOL');
        $poolOption = $pool ? ' --pool="' . $pool . '"' : '';

        $this->heading('Next steps');
        $this->line('These need an elevated prompt and your database password, so they are');
        $this->line('yours to run. Nothing above encrypted anything.');
        $this->line('');
        $this->line('1. Store the secret, from an ELEVATED prompt (once per server):');
        $this->line('     php artisan db:keygen' . $poolOption);
        $this->line('');

        if ($pool) {
            $this->line('   The application pool is recycled for you.');
        } else {
            $this->line('   Then "iisreset". WAS reads the machine environment when it starts,');
            $this->line('   so recycling the pool is NOT enough for a machine-wide variable.');
        }

        $this->line('');
        $this->line('   Back the secret up off this machine. It is the only copy, and losing');
        $this->line('   it makes an encrypted password unrecoverable.');
        $this->line('');
        $this->line('2. Open a NEW terminal - a process cannot see a variable set after it');
        $this->line('   started - then encrypt the existing password(s):');
        $this->line('     php artisan db:password-encrypt-all');
        $this->line('');
        $this->line('   It lists what it found, waits for your confirmation, backs up .env,');
        $this->line('   and reads every value back before reporting success.');
        $this->line('');
        $this->line('3. Check the whole thing:');
        $this->line('     php artisan config:clear');
        $this->line('     php artisan db:secret-check');
        $this->line('');
        $this->line('4. Restart whatever holds an old environment: iisreset (or the pool),');
        $this->line('   and php artisan queue:restart. Then load a page that queries the');
        $this->line('   database - the CLI and the web server have separate environments.');
        $this->line('');
        $this->line('Leave config/database.php exactly as Laravel ships it. Decryption happens');
        $this->line('at connection time, which is what keeps plaintext out of the config cache.');
        $this->line('');
        $this->line('The secret lives in ' . $variable . ', in the Windows registry or on an');
        $this->line('application pool - never in .env on a production server.');
        $this->line('');
        $this->line('When the application will not boot, the same migration runs with no');
        $this->line('framework at all:');
        $this->line('     php storage/tools/envcrypt.php encrypt-all');
    }

    /** A short, uppercase identifier, safe as part of a variable name. */
    private function normalise($value)
    {
        return trim(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $value)), '_');
    }

    private function heading($text)
    {
        $this->line('');
        $this->line($text);
        $this->line(str_repeat('-', strlen($text)));
    }
}
