<?php

namespace Npav\EnvCrypt\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Npav\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Npav\EnvCrypt\EnvCrypt;
use Npav\EnvCrypt\EnvCryptConnector;

/**
 * The check to run after every deploy.
 *
 * Exit 1 on any failure. This is what turns "it silently did not work" into
 * "it told me which piece is missing".
 */
class CheckCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'db:secret-check
                            {--pool= : Read the secret from this application pool}
                            {--connection= : Which database connection to test}';

    protected $description = 'Verify the encrypted DB_PASSWORD setup end to end';

    public function handle()
    {
        $ok = true;

        $this->line('  secret variable: ' . EnvCrypt::rootKeyVar());
        $this->newLine();

        $driver = config('database.default');
        $binding = 'db.connector.' . config('database.connections.' . $driver . '.driver', $driver);

        $ok = $this->assert(
            app()->bound($binding) && app($binding) instanceof EnvCryptConnector,
            'connector bound (' . $binding . ')'
        ) && $ok;

        // config/database.php must not decrypt - that would put the plaintext
        // into bootstrap/cache/config.php on the next config:cache.
        $ok = $this->assert(
            $this->configDoesNotDecrypt(),
            'config/database.php does not decrypt'
        ) && $ok;

        // Resolved the same way the other commands do - reading the pool or
        // registry directly - rather than trusting this process's own ambient
        // environment, which a CLI session will not have unless it was started
        // fresh after the secret was stored.
        $secret = $this->authoritativeSecret($this->poolOption());
        $secretOk = false;

        try {
            EnvCrypt::encrypt('probe', $secret);
            $secretOk = true;
        } catch (Exception $e) {
            $this->error('  reason: ' . $e->getMessage());
        }

        $ok = $this->assert($secretOk, 'secret visible to this process') && $ok;

        // Every managed password, not just DB_PASSWORD - a project with ten
        // connections gets ten lines, and one broken key cannot hide behind a
        // healthy one.
        foreach ($this->resolveTargetKeys() as $key => $value) {
            if ($value === null || $value === '') {
                $this->line('  [    ] ' . $key . ' is empty or absent');

                continue;
            }

            if (! $this->isEncryptedValue($value)) {
                $this->line('  [    ] ' . $key . ' is plaintext - nothing encrypted yet');

                continue;
            }

            $readable = false;

            try {
                EnvCrypt::decrypt($value, $secret);
                $readable = true;
            } catch (Exception $e) {
                $this->error('  reason: ' . $e->getMessage());
            }

            $ok = $this->assert($readable, $key . ' decrypts') && $ok;
        }

        $ok = $this->assert($this->cacheHasNoPlaintext($secret), 'config cache holds no plaintext') && $ok;

        // Deliberately NOT passed an explicit secret: the connector resolves it
        // from this process's own ambient environment, exactly as the real
        // application will. That is also why this specific check can fail even
        // when the secret is confirmed present above - see the hint below.
        $connection = $this->option('connection') ?: $driver;
        $connected = false;

        try {
            DB::connection($connection)->select('select 1');
            $connected = true;
        } catch (Exception $e) {
            $this->error('  reason: ' . $e->getMessage());

            if ($secretOk && strpos($e->getMessage(), 'not available to this process') !== false) {
                $this->newLine();
                $this->warn('The secret above was read directly from storage and is fine.');
                $this->warn('This process itself does not have it in its own environment -');
                $this->warn('normal for a CLI window that was already open when the secret');
                $this->warn('was stored. Close this window, open a brand new one, and retry.');
            }
        }

        $ok = $this->assert($connected, 'connection "' . $connection . '" works') && $ok;

        $this->newLine();

        if ($ok) {
            $this->info('All checks passed.');

            return 0;
        }

        $this->error('One or more checks FAILED.');

        return 1;
    }

    private function configDoesNotDecrypt()
    {
        $path = config_path('database.php');

        if (! is_readable($path)) {
            return true;
        }

        $contents = file_get_contents($path);

        return strpos($contents, 'EnvCrypt::maybeDecrypt') === false
            && strpos($contents, 'EnvCrypt::decrypt') === false;
    }

    /**
     * No managed plaintext password may appear in the generated config cache.
     * Every value is compared without ever being printed.
     */
    private function cacheHasNoPlaintext($secret)
    {
        $cache = base_path('bootstrap/cache/config.php');

        if (! is_file($cache)) {
            return true;
        }

        $cached = file_get_contents($cache);

        foreach ($this->resolveTargetKeys() as $key => $value) {
            if (! $this->isEncryptedValue($value)) {
                continue;
            }

            try {
                $plain = EnvCrypt::decrypt($value, $secret);
            } catch (Exception $e) {
                continue;
            }

            if ($plain !== '' && strpos($cached, $plain) !== false) {
                return false;
            }
        }

        return true;
    }

    private function assert($condition, $label)
    {
        $this->line('  [' . ($condition ? ' ok ' : 'FAIL') . '] ' . $label);

        return (bool) $condition;
    }
}
