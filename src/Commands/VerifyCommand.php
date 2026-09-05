<?php

namespace Tusharb\EnvCrypt\Commands;

use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvCryptConnector;

/**
 * Are the files and the wiring in place?
 *
 * Deliberately separate from db:secret-check, which is the runtime check and
 * needs the secret, the database and a warm environment. This one only asks
 * whether the installation itself is sound, so it answers usefully on a
 * machine where the secret has not been stored yet - immediately after
 * envcrypt:install, for instance.
 */
class VerifyCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'envcrypt:verify';

    protected $description = 'Check that the EnvCrypt installation and wiring are in place';

    public function handle()
    {
        $this->line('Verifying');
        $this->line('---------');

        $ok = true;

        $ok = $this->check(
            class_exists(EnvCrypt::class) && class_exists(EnvCryptConnector::class),
            'package autoloaded'
        ) && $ok;

        // A project still on the default name has never been through
        // envcrypt:install, and would share a secret with every other project
        // in the same position.
        $named = EnvCrypt::rootKeyVar() !== EnvCrypt::DEFAULT_ROOT_KEY_VAR;

        $ok = $this->check($named, 'secret named for this project (' . EnvCrypt::rootKeyVar() . ')') && $ok;

        if (! $named) {
            $this->line('         run "php artisan envcrypt:install" to name it');
        }

        $default = config('database.default');
        $driver = config('database.connections.' . $default . '.driver', $default);
        $wrapped = array_keys((array) config('envcrypt.connectors', []));

        // SQLite has no password, so there is no connector to wrap and nothing
        // to check - a project on it is correctly installed with no binding.
        if (! in_array($driver, $wrapped, true)) {
            $this->line('  [ -- ] driver "' . $driver . '" carries no password - no connector needed');
        } else {
            $binding = 'db.connector.' . $driver;

            $ok = $this->check(
                app()->bound($binding) && app($binding) instanceof EnvCryptConnector,
                'connector bound (' . $binding . ')'
            ) && $ok;
        }

        $ok = $this->check($this->configDoesNotDecrypt(), 'config/database.php does not decrypt') && $ok;

        // Neither is required: the provider merges the package's own config,
        // and the standalone tool runs from vendor/ either way. Reported, not
        // asserted - so their absence is never a surprise, and never a failure.
        $this->note(is_file(config_path('envcrypt.php')), 'config/envcrypt.php published');
        $this->note(is_file(storage_path('tools/envcrypt.php')), 'storage/tools/envcrypt.php published');

        if (is_file(base_path('bootstrap/cache/config.php'))) {
            $this->line('  note: a config cache exists - run "php artisan db:secret-check"');
            $this->line('        to confirm it holds no plaintext password.');
        }

        $this->line('');
        $this->line($ok
            ? 'Files and wiring are in place. Run "php artisan db:secret-check" for the runtime checks.'
            : 'Verification FAILED.');

        return $ok ? 0 : 1;
    }

    /**
     * Decrypting in config/database.php would put the plaintext into
     * bootstrap/cache/config.php on the next config:cache - the one mistake
     * that silently undoes the whole arrangement.
     */
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

    private function check($condition, $label)
    {
        $this->line('  [' . ($condition ? ' ok ' : 'FAIL') . '] ' . $label);

        return (bool) $condition;
    }

    /** Reported, never asserted - an optional file's absence is not a failure. */
    private function note($condition, $label)
    {
        $this->line('  [' . ($condition ? ' ok ' : ' -- ') . '] ' . $label);
    }
}
