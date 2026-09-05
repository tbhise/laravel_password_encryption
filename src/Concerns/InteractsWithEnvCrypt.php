<?php

namespace Tusharb\EnvCrypt\Concerns;

use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvCryptSecret;
use Tusharb\EnvCrypt\TargetKeys;

/**
 * Shared plumbing for the commands: locating .env, reading its values, and
 * resolving the secret from the store that actually matters.
 */
trait InteractsWithEnvCrypt
{
    protected function envPath()
    {
        return base_path('.env');
    }

    protected function envContents()
    {
        $path = $this->envPath();

        return is_readable($path) ? file_get_contents($path) : '';
    }

    /** One key's value, quote-correct. Null when absent. */
    protected function envValue($key)
    {
        return \Tusharb\EnvCrypt\EnvFile::value($this->envContents(), $key);
    }

    protected function isEncryptedValue($value)
    {
        return EnvCrypt::isEncrypted($value);
    }

    /** The managed password keys, key => value. */
    protected function resolveTargetKeys()
    {
        return TargetKeys::resolve($this->envContents(), $this->fallbackKeys());
    }

    /** Just the names of the managed keys that are currently encrypted. */
    protected function encryptedTargetKeyNames()
    {
        return TargetKeys::encryptedNames($this->envContents(), $this->fallbackKeys());
    }

    protected function fallbackKeys()
    {
        $keys = (array) config('envcrypt.target_keys', ['DB_PASSWORD']);

        return $keys === [] ? ['DB_PASSWORD'] : $keys;
    }

    /** The pool named on the command line, else the configured one. */
    protected function poolOption()
    {
        $pool = $this->getDefinition()->hasOption('pool') ? $this->option('pool') : null;

        return $pool ?: config('envcrypt.pool');
    }

    /**
     * The secret this command should use, taken from the authoritative store
     * rather than the ambient environment. Returns null to mean "let EnvCrypt
     * resolve it", which is the development path.
     */
    protected function authoritativeSecret($pool = null)
    {
        if (! EnvCryptSecret::onWindows()) {
            return null;
        }

        $secret = EnvCryptSecret::current($pool);

        if ($secret === null) {
            return null;
        }

        $inherited = EnvCrypt::fromEnvironment(EnvCrypt::rootKeyVar());

        if ($inherited !== '' && $inherited !== $secret) {
            $this->warn('This terminal holds an out-of-date ' . EnvCrypt::rootKeyVar() . '.');
            $this->line('Using the current value from the configuration store instead.');
            $this->newLine();
        }

        return $secret;
    }

    /**
     * Only the platform is a hard requirement.
     *
     * Elevation is NOT gated here: the probes give false negatives on machines
     * where the Server service is stopped or policy blocks them, which stopped
     * genuinely elevated prompts from working. The write itself is the real
     * test, so a warning is printed and the attempt goes ahead.
     */
    protected function requireWindows()
    {
        if (! EnvCryptSecret::onWindows()) {
            $this->error('Implemented for Windows secret stores only.');
            $this->line('On Linux, set ' . EnvCrypt::rootKeyVar() . ' through systemd, the');
            $this->line('web server\'s environment, or your secret manager instead.');

            return false;
        }

        if (! EnvCryptSecret::elevated()) {
            $this->warn('Could not confirm this prompt is elevated - trying anyway.');
            $this->line('If it fails with "access denied", reopen the terminal with');
            $this->line('"Run as administrator".');
            $this->newLine();
        }

        return true;
    }

    /** Turn a store failure into advice rather than a raw Windows error. */
    protected function explainStoreFailure($message)
    {
        if (EnvCryptSecret::looksLikeDenial($message)) {
            $this->error('Access denied writing the secret.');
            $this->line('Reopen the terminal with "Run as administrator", then retry.');
            $this->newLine();
            $this->line('Windows reported: ' . $message);

            return;
        }

        $this->error('Storing the secret failed: ' . $message);
    }
}
