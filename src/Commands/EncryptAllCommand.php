<?php

namespace Npav\EnvCrypt\Commands;

use Illuminate\Console\Command;
use Npav\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Npav\EnvCrypt\Io\ConsoleIo;
use Npav\EnvCrypt\Migrator;

/**
 * Migrates a project in one reviewed pass: finds the database passwords in
 * .env, shows the list for confirmation, backs the file up and rewrites it.
 *
 * The same operation as "php vendor/bin/envcrypt encrypt-all", which is the
 * one to reach for when the application itself will not boot.
 */
class EncryptAllCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'db:password-encrypt-all
                            {--pool= : Read the secret from this application pool}
                            {--reconfigure : Review the managed list again}';

    protected $description = 'Encrypt every database password in .env, in one reviewed pass';

    public function handle()
    {
        $migrator = new Migrator(new ConsoleIo($this), base_path(), $this->poolOption());

        return $migrator->encryptAll((bool) $this->option('reconfigure'));
    }
}
