<?php

namespace Tusharb\EnvCrypt;

use Exception;
use Tusharb\EnvCrypt\Io\Io;

/**
 * Finds the database passwords in .env, confirms the list with the operator,
 * and rewrites the file with encrypted values.
 *
 * Framework-free on purpose. The artisan command and the standalone script
 * both drive this one class through an Io, so a project migrated from the
 * command line and one migrated on a locked-down server go through identical
 * code.
 *
 * PASSWORD VALUES ARE NEVER PRINTED. Only variable names appear in any listing
 * or confirmation - the sole exception is decryptOne(), whose whole purpose is
 * recovery and which asks first.
 */
final class Migrator
{
    /** @var \Tusharb\EnvCrypt\Io\Io */
    private $io;

    /** @var string */
    private $root;

    /** @var string|null */
    private $pool;

    /** @var string[]|null keys supplied by the caller instead of guessed */
    private $candidates = null;

    /** @var string[] password-like keys the caller ruled out, shown but never selected */
    private $rejected = array();

    /** @var string|null the backup this run took, for the caller to offer to clean up */
    private $backupPath = null;

    /** The .env backup written by the last encryptAll(), or null if none was. */
    public function backupPath()
    {
        return $this->backupPath;
    }

    public function __construct(Io $io, $root, $pool = null)
    {
        $this->io = $io;
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->pool = $pool ?: null;
    }

    /**
     * Use this list rather than the name-based sweep.
     *
     * An artisan command can ask Laravel which keys really are a connection's
     * password (see ConnectionKeys); the standalone tool has no framework to
     * ask, so it falls back to guessing from names. Both then go through the
     * same review, confirmation, backup and verification below.
     *
     * @param string[] $candidates keys proposed for encryption
     * @param string[] $rejected   other password-like keys, listed but not selected
     */
    public function useCandidates(array $candidates, array $rejected = array())
    {
        $this->candidates = array_values($candidates);
        $this->rejected = array_values($rejected);

        return $this;
    }

    /* ------------------------------------------------------------------ */
    /* encrypt-all                                                        */
    /* ------------------------------------------------------------------ */

    public function encryptAll($reconfigure = false)
    {
        $envPath = $this->envPath();

        if (! is_readable($envPath)) {
            return $this->fail('Cannot read ' . $envPath);
        }

        if (! is_writable($envPath)) {
            return $this->fail($envPath . ' is not writable.');
        }

        $contents = file_get_contents($envPath);

        if ($this->candidates !== null) {
            $database = $this->candidates;
            $other = $this->rejected;
        } else {
            list($database, $other) = TargetKeys::classify($contents);
        }

        $declared = TargetKeys::declared($contents);

        // A previously confirmed list is authoritative. Re-running only asks
        // again when a NEW database password turned up, or --reconfigure was
        // passed. Keys in the "other" bucket are deliberately not counted:
        // they are never in the declared list, so counting them would force a
        // pointless review on every single run.
        $newlyFound = array_diff($database, $declared);

        if ($declared !== array() && ! $reconfigure && $newlyFound === array()) {
            $selected = $declared;
            $this->io->write('Using the ' . count($selected) . ' variable(s) already recorded in '
                . EnvCrypt::TARGET_KEYS_VAR . '.');
            $this->io->write('Nothing new to review. Pass --reconfigure to change the list.');
            $this->io->write();
        } else {
            $proposed = $declared !== array() && ! $reconfigure
                ? array_values(array_unique(array_merge($declared, $database)))
                : $database;

            $selected = $this->review($proposed, $other, $contents);

            if ($selected === null) {
                $this->io->write('Cancelled. Nothing was changed.');

                return 0;
            }
        }

        if ($selected === array()) {
            $this->io->write('No variables selected. Nothing was changed.');

            return 0;
        }

        // Partition the confirmed list. Names only in every message below.
        $targets = array();
        $skipped = array();

        foreach ($selected as $key) {
            $value = EnvFile::value($contents, $key);

            if ($value === null) {
                $skipped[$key] = 'not present in .env';
            } elseif ($value === '') {
                $skipped[$key] = 'empty';
            } elseif (EnvCrypt::isEncrypted($value)) {
                $skipped[$key] = 'already encrypted';
            } else {
                $targets[$key] = $value;
            }
        }

        foreach ($skipped as $key => $why) {
            $this->io->write('  skip  ' . $key . ' (' . $why . ')');
        }

        if ($targets === array()) {
            $this->io->write();
            $this->io->write('Nothing left to encrypt.');

            // The list itself may still be new information worth recording.
            $this->persistIfChanged($envPath, $contents, $selected);

            return 0;
        }

        if (! $this->confirmFinal(array_keys($targets))) {
            $this->io->write('Cancelled. Nothing was changed.');

            return 0;
        }

        $secret = $this->secret();

        // Encrypt and self-check EVERYTHING before touching the file. One
        // failure aborts with nothing written - never a half-migrated .env.
        $payloads = array();

        foreach ($targets as $key => $plain) {
            try {
                $payload = EnvCrypt::encrypt($plain, $secret);

                if (EnvCrypt::decrypt($payload, $secret) !== $plain) {
                    return $this->fail('Self-check failed for ' . $key . '. Nothing was changed.');
                }

                $payloads[$key] = $payload;
            } catch (Exception $e) {
                return $this->fail($key . ': ' . $e->getMessage() . ' Nothing was changed.');
            }
        }

        // Taken only now: everything above could still abort, and a backup
        // holding every plaintext password is not a file to leave lying about
        // for a run that changed nothing.
        $store = new EnvBackup($this->root);
        $backup = $store->create('encrypt');

        if ($backup === null) {
            return $this->fail('Could not write a backup to ' . $store->directory() . '. Nothing was changed.');
        }

        $this->backupPath = $backup;

        $this->io->write();
        $this->io->write('Backup written: ' . $backup);

        $updated = $contents;

        foreach ($payloads as $key => $payload) {
            $updated = EnvFile::withValueSet($updated, $key, $payload, $count);

            if ($count < 1) {
                return $this->fail('Could not find the ' . $key . ' line. Nothing was changed.');
            }
        }

        $updated = EnvFile::withValueSet($updated, EnvCrypt::TARGET_KEYS_VAR, implode(',', $selected));

        if (! $store->writeEnv($updated)) {
            return $this->fail('.env could not be written. Nothing was changed.');
        }

        // Verify what actually landed on disk, not what we hoped we wrote.
        $written = file_get_contents($envPath);
        $bad = array();

        foreach ($payloads as $key => $payload) {
            $value = EnvFile::value($written, $key);

            try {
                if ($value === null || EnvCrypt::decrypt($value, $secret) !== $targets[$key]) {
                    $bad[] = $key;
                }
            } catch (Exception $e) {
                $bad[] = $key;
            }
        }

        if ($bad !== array()) {
            $store->writeEnv($contents);

            return $this->fail(
                'These values did not read back correctly: ' . implode(', ', $bad)
                . ' .env has been restored from the backup. No password was changed.'
            );
        }

        $this->io->write();
        $this->io->write('Encrypted ' . count($payloads) . ' database password(s):');

        foreach (array_keys($payloads) as $key) {
            $this->io->write('  ' . $key);
        }

        $this->io->write();
        $this->io->write('Recorded in ' . EnvCrypt::TARGET_KEYS_VAR . '. Edit that line, or re-run with');
        $this->io->write('--reconfigure, to change which variables are managed.');
        $this->io->write();
        $this->io->write('The database passwords themselves were NOT changed.');
        $this->io->write();
        $this->io->write('Next:');
        $this->io->write('  php artisan config:clear');
        $this->io->write('  php artisan db:secret-check');
        $this->io->write();
        $this->io->write('Delete ' . basename($backup) . ' once you have verified the application.');

        return 0;
    }

    /**
     * Show the proposal and let the operator confirm, remove, add or cancel.
     * Returns the confirmed list, or null when cancelled.
     */
    private function review(array $selected, array $other, $contents)
    {
        while (true) {
            $this->io->write();

            if ($selected === array()) {
                $this->io->write('No variables are currently selected.');
            } else {
                $this->io->write('The following variables appear to be database passwords');
                $this->io->write('(they sit alongside a matching database connection\'s host, name or user):');
                $this->io->write();

                foreach ($selected as $i => $key) {
                    $this->io->write('  ' . ($i + 1) . '. ' . $key);
                }
            }

            if ($other !== array()) {
                $this->io->write();
                $this->io->write('Other password-like variables found, NOT selected');
                $this->io->write('(no matching database connection fields):');
                $this->io->write();

                foreach ($other as $key) {
                    $this->io->write('  ' . $key);
                }
            }

            $this->io->write();
            $this->io->write('Only variable names are shown - values are never displayed or logged.');
            $this->io->write();
            $this->io->write('Review the list:');
            $this->io->write('  [Enter]  confirm as-is');
            $this->io->write('  r        remove one or more (by number)');
            $this->io->write('  a        add a variable by name');
            $this->io->write('  c        cancel - change nothing');
            $this->io->write();

            $answer = $this->io->ask('> ');

            // End of input is not consent.
            if ($answer === null) {
                $this->io->write('No answer given (end of input).');

                return null;
            }

            $answer = strtolower(trim($answer));

            if ($answer === '' || $answer === 'y' || $answer === 'yes') {
                return array_values($selected);
            }

            if ($answer === 'c' || $answer === 'cancel') {
                return null;
            }

            if ($answer === 'r' || $answer === 'remove') {
                $selected = $this->removeByNumber($selected);

                continue;
            }

            if ($answer === 'a' || $answer === 'add') {
                $selected = $this->addByName($selected, $contents);

                continue;
            }

            $this->io->write('Unrecognised choice.');
        }
    }

    private function removeByNumber(array $selected)
    {
        if ($selected === array()) {
            return $selected;
        }

        $answer = (string) $this->io->ask('Enter numbers to remove (comma-separated): ');
        $remove = array();

        foreach (explode(',', $answer) as $number) {
            $number = (int) trim($number);

            if ($number >= 1 && $number <= count($selected)) {
                $remove[] = $number - 1;
            }
        }

        if ($remove === array()) {
            $this->io->write('Nothing matched those numbers.');

            return $selected;
        }

        foreach ($remove as $index) {
            $this->io->write('  removed  ' . $selected[$index]);
            unset($selected[$index]);
        }

        return array_values($selected);
    }

    private function addByName(array $selected, $contents)
    {
        $name = trim((string) $this->io->ask('Enter the variable name to add: '));

        if ($name === '') {
            return $selected;
        }

        if (in_array($name, $selected, true)) {
            $this->io->write('  ' . $name . ' is already selected.');

            return $selected;
        }

        if (EnvFile::value($contents, $name) === null) {
            $this->io->write('  ' . $name . ' is not present in .env. Not added.');

            return $selected;
        }

        $selected[] = $name;
        $this->io->write('  added  ' . $name);

        return $selected;
    }

    private function confirmFinal(array $keys)
    {
        $this->io->write();
        $this->io->write('The following ' . count($keys)
            . ' database password variable(s) will be encrypted:');
        $this->io->write();

        foreach ($keys as $key) {
            $this->io->write('  ' . $key);
        }

        $this->io->write();
        $this->io->write('No other .env password fields will be modified.');
        $this->io->write('The database passwords themselves are not being changed.');
        $this->io->write();

        return $this->io->confirm('Continue?', false);
    }

    /** Record the managed list when it differs from what is already there. */
    private function persistIfChanged($envPath, $contents, array $selected)
    {
        $declared = TargetKeys::declared($contents);

        if ($declared === $selected) {
            return;
        }

        $updated = EnvFile::withValueSet($contents, EnvCrypt::TARGET_KEYS_VAR, implode(',', $selected));

        if (file_put_contents($envPath, $updated) !== false) {
            $this->io->write('Recorded the managed list in ' . EnvCrypt::TARGET_KEYS_VAR . '.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* encrypt / decrypt, one value                                       */
    /* ------------------------------------------------------------------ */

    public function encryptOne($key = 'DB_PASSWORD')
    {
        $first = $this->io->secret('Database password for ' . $key . ' : ');
        $second = $this->io->secret('Repeat to confirm : ');

        if ($first === '' || $first === null) {
            return $this->fail('Nothing was entered.');
        }

        if ($first !== $second) {
            return $this->fail('The two entries do not match. Nothing was encrypted.');
        }

        try {
            $secret = $this->secret();
            $payload = EnvCrypt::encrypt($first, $secret);

            if (EnvCrypt::decrypt($payload, $secret) !== $first) {
                return $this->fail('Self-check failed. Do not use this value.');
            }
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }

        $this->io->write();
        $this->io->write('Self-check passed. Copy this exact line into .env:');
        $this->io->write();
        $this->io->write('  ' . $key . '="' . $payload . '"');
        $this->io->write();
        $this->io->write('Then: php artisan config:clear');

        return 0;
    }

    public function decryptOne($payload = null, $key = 'DB_PASSWORD')
    {
        if ($payload === null || $payload === '') {
            $contents = is_readable($this->envPath()) ? file_get_contents($this->envPath()) : '';
            $payload = EnvFile::value($contents, $key);
        }

        if (! $payload) {
            return $this->fail('No value given, and no ' . $key . ' found in .env.');
        }

        $this->io->write('This prints a password to the terminal. It belongs in recovery work,');
        $this->io->write('not in a deployment script or anything that logs its output.');
        $this->io->write();

        if (! $this->io->confirm('Continue?', false)) {
            $this->io->write('Cancelled.');

            return 0;
        }

        try {
            $this->io->write();
            $this->io->write(EnvCrypt::decrypt($payload, $this->secret()));
            $this->io->write();
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }

        return 0;
    }

    /* ------------------------------------------------------------------ */

    private function envPath()
    {
        return $this->root . '/.env';
    }

    /**
     * The secret, from the store rather than from whatever this terminal
     * inherited - a window opened before the secret last changed still holds
     * the old value, and encrypting under that produces values the web
     * application cannot read. Null means "let EnvCrypt resolve it", the
     * development path.
     */
    private function secret()
    {
        if (! EnvCryptSecret::onWindows()) {
            return null;
        }

        $secret = EnvCryptSecret::current($this->pool);

        if ($secret === null) {
            return null;
        }

        $inherited = EnvCrypt::fromEnvironment(EnvCrypt::rootKeyVar());

        if ($inherited !== '' && $inherited !== $secret) {
            $this->io->write('NOTE: this terminal holds an out-of-date ' . EnvCrypt::rootKeyVar() . '.');
            $this->io->write('      Using the current value from the configuration store instead.');
            $this->io->write();
        }

        return $secret;
    }

    private function fail($message)
    {
        $this->io->error('ERROR: ' . $message);

        return 1;
    }
}
