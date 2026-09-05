<?php

namespace Tusharb\EnvCrypt;

/**
 * Which .env keys actually supply a database connection's password.
 *
 * The name-based sweep in TargetKeys has to guess, because the standalone tool
 * has no framework to ask. An artisan command does: Laravel has already
 * resolved every env() call in config/database.php, so each connection's
 * password is sitting in the config array. Matching those values back to the
 * lines in .env identifies the real keys - including ones no naming
 * convention would find, such as DB_PASSWORD_REPORTING - and excludes
 * MAIL_PASSWORD and REDIS_PASSWORD without needing a list of exceptions,
 * because those values are not any connection's password.
 *
 * Values are compared, never printed, and never returned.
 */
final class ConnectionKeys
{
    /** @var array<string,array> connection name => config array */
    private $connections;

    /** @var string */
    private $envContents;

    /** @var string[] drivers whose connector this package wraps */
    private $wrappedDrivers;

    public function __construct(array $connections, $envContents, array $wrappedDrivers = array())
    {
        $this->connections = $connections;
        $this->envContents = (string) $envContents;
        $this->wrappedDrivers = $wrappedDrivers;
    }

    /**
     * Managed keys, in the order their connections appear in config.
     *
     * @return array<string,string[]> key => connection names that use it
     */
    public function keys()
    {
        $found = array();

        foreach ($this->passwordsByConnection() as $connection => $password) {
            $key = $this->keyHolding($password);

            if ($key === null) {
                continue;
            }

            if (! isset($found[$key])) {
                $found[$key] = array();
            }

            $found[$key][] = $connection;
        }

        return $found;
    }

    /**
     * Connections whose password is set but comes from no .env line at all -
     * hardcoded in config/database.php, or supplied by something else.
     *
     * They cannot be managed by editing .env, and saying so is better than
     * leaving them silently absent from the list.
     *
     * @return string[] connection names
     */
    public function unmanageable()
    {
        $found = array();

        foreach ($this->passwordsByConnection() as $connection => $password) {
            if ($this->keyHolding($password) === null) {
                $found[] = $connection;
            }
        }

        return $found;
    }

    /**
     * Connections holding an "enc:" password on a driver this package does not
     * wrap.
     *
     * Worth its own report: nothing decrypts for them, so the literal string
     * "enc:..." reaches PDO as the password and the failure looks like a
     * wrong password rather than a missing connector.
     *
     * @return array<string,string> connection name => driver
     */
    public function encryptedOnUnwrappedDriver()
    {
        $found = array();

        foreach ($this->connections as $name => $config) {
            if (! is_array($config) || ! EnvCrypt::isEncrypted($this->passwordOf($config))) {
                continue;
            }

            $driver = isset($config['driver']) ? (string) $config['driver'] : '';

            if (! in_array($driver, $this->wrappedDrivers, true)) {
                $found[$name] = $driver;
            }
        }

        return $found;
    }

    /**
     * Connections configured through a single URL.
     *
     * Laravel expands the URL into host/username/password before the
     * connector runs, so an encrypted password inside one is not something
     * this package has been tested against.
     *
     * @return string[] connection names
     */
    public function urlConfigured()
    {
        $found = array();

        foreach ($this->connections as $name => $config) {
            if (is_array($config) && isset($config['url']) && $config['url'] !== null && $config['url'] !== '') {
                $found[] = $name;
            }
        }

        return $found;
    }

    /** @return array<string,string> connection name => password */
    private function passwordsByConnection()
    {
        $found = array();

        foreach ($this->connections as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            $password = $this->passwordOf($config);

            // An empty password has nothing to encrypt, and would otherwise
            // match every empty line in .env.
            if (is_string($password) && $password !== '') {
                $found[$name] = $password;
            }
        }

        return $found;
    }

    private function passwordOf(array $config)
    {
        return isset($config['password']) ? $config['password'] : null;
    }

    /**
     * The .env key holding this exact value.
     *
     * Two keys can hold the same string - a project may reuse one password for
     * the database and for Redis - so a DB_-prefixed key wins when the match is
     * ambiguous. That is a tie-break, not the detection itself: a key with no
     * such prefix is still returned when it is the only one that matches.
     */
    private function keyHolding($password)
    {
        $matches = array();

        foreach (EnvFile::all($this->envContents) as $key => $value) {
            if ($value === $password) {
                $matches[] = $key;
            }
        }

        if ($matches === array()) {
            return null;
        }

        foreach ($matches as $key) {
            if (preg_match('/^DB_/i', $key) === 1) {
                return $key;
            }
        }

        return $matches[0];
    }
}
