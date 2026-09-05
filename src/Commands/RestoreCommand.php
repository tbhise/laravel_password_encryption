<?php

namespace Tusharb\EnvCrypt\Commands;

use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;

/**
 * Puts a backed-up .env back.
 *
 * This is the rollback the whole design leans on: the database passwords were
 * never changed, so restoring the file that held them in plaintext returns the
 * application to exactly where it started. The installed package needs no
 * revert - a value without the "enc:" prefix passes through the connector
 * untouched.
 */
class RestoreCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'envcrypt:restore
                            {backup? : Which backup to restore; defaults to the most recent}
                            {--list : List the available backups and stop}';

    protected $description = 'Restore a .env backup taken before encryption';

    public function handle()
    {
        $store = $this->backups();
        $available = $store->all();

        if ($available === []) {
            $this->error('No backups found in ' . $store->directory());
            $this->line('Nothing to restore. If you kept one elsewhere, copy it over .env by hand,');
            $this->line('then run "php artisan config:clear".');

            return 1;
        }

        if ($this->option('list')) {
            $this->line('Backups in ' . $store->directory() . ', newest first:');

            foreach ($available as $path) {
                $this->line('  ' . basename($path) . '   ' . date('Y-m-d H:i:s', filemtime($path)));
            }

            return 0;
        }

        $backup = $this->chosenBackup($available);

        if ($backup === null) {
            return 1;
        }

        $this->line('Restoring ' . basename($backup) . ' over .env.');
        $this->line('It holds the passwords as they were BEFORE encryption - in plaintext.');
        $this->line('The current .env is backed up first, so this is reversible either way.');
        $this->line('');

        if (! $this->confirm('Restore it?', false)) {
            $this->line('Cancelled. Nothing was changed.');

            return 0;
        }

        if (! $store->restore($backup)) {
            $this->error('Could not write .env. Nothing was changed.');

            return 1;
        }

        $this->info('.env restored.');
        $this->line('');

        $this->call('config:clear');

        $this->line('');
        $this->line('Restart the web server (iisreset, or recycle the pool) and any queue');
        $this->line('workers, then test the application in a browser.');
        $this->line('');
        $this->line('That restored file holds plaintext passwords and is still on this server:');
        $this->line('  ' . $backup);
        $this->line('Delete it once you are done.');

        return 0;
    }

    private function chosenBackup(array $available)
    {
        $named = $this->argument('backup');

        if (! $named) {
            return $available[0];
        }

        foreach ($available as $path) {
            if ($path === $named || basename($path) === basename($named)) {
                return $path;
            }
        }

        // An explicit path outside the backup directory is still legitimate -
        // a copy kept elsewhere, handed back on the command line.
        if (is_readable($named)) {
            return $named;
        }

        $this->error('No backup called "' . $named . '".');
        $this->line('Run "php artisan envcrypt:restore --list" to see what there is.');

        return null;
    }
}
