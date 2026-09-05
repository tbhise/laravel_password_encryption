<?php

namespace Tusharb\EnvCrypt\Commands;

use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\Io\ConsoleIo;
use Tusharb\EnvCrypt\Migrator;

/**
 * Migrates a project in one reviewed pass: finds the database passwords in
 * .env, shows the list for confirmation, backs the file up and rewrites it.
 *
 * The same operation as "php vendor/bin/envcrypt encrypt-all", which is the
 * one to reach for when the application itself will not boot. The difference
 * is the detection: with Laravel booted, the keys come from the resolved
 * database config rather than from a guess at their names.
 */
class EncryptAllCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'db:password-encrypt-all
                            {--pool= : Read the secret from this application pool}
                            {--reconfigure : Review the managed list again}
                            {--by-name : Detect keys by name instead of from the database config}';

    protected $description = 'Encrypt every database password in .env, in one reviewed pass';

    public function handle()
    {
        $migrator = new Migrator(new ConsoleIo($this), base_path(), $this->poolOption());

        if (! $this->option('by-name')) {
            $connections = $this->connectionKeys();
            $selected = array_keys($connections->keys());

            // Falling back rather than proposing an empty list: a project whose
            // passwords are not in .env at all, or whose config this cannot
            // read, is better served by the old name-based sweep than by
            // "nothing found".
            if ($selected !== []) {
                $migrator->useCandidates($selected, $this->otherPasswordKeys($selected));

                $this->reportConnectionFindings($connections);
            }
        }

        return $migrator->encryptAll((bool) $this->option('reconfigure'));
    }

    /**
     * Things the operator should know before choosing - each of which would
     * otherwise surface much later as an authentication failure.
     */
    private function reportConnectionFindings($connections)
    {
        foreach ($connections->unmanageable() as $connection) {
            $this->warn('Connection "' . $connection . '" has a password that is not in .env.');
            $this->line('It cannot be managed here - set it from .env first if you want it encrypted.');
        }

        foreach ($connections->urlConfigured() as $connection) {
            $this->warn('Connection "' . $connection . '" is configured through a URL.');
            $this->line('Its password lives inside that URL. Split it into host/username/password');
            $this->line('settings before encrypting - URL-embedded passwords are not supported.');
        }

        foreach ($connections->encryptedOnUnwrappedDriver() as $connection => $driver) {
            $this->error('Connection "' . $connection . '" has an encrypted password on driver "'
                . $driver . '", which this package does not wrap.');
            $this->line('Nothing will decrypt it, and the failure will look like a wrong password.');
            $this->line('Add the driver to the "connectors" array in config/envcrypt.php.');
        }
    }
}
