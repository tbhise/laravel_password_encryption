<?php

namespace Npav\EnvCrypt;

/**
 * Which .env keys hold database passwords this project manages.
 *
 * Framework-free, so the artisan commands and the standalone tool answer this
 * question identically - a disagreement here would let one of them encrypt a
 * value the other never checks.
 */
final class TargetKeys
{
    /** Any key whose name contains this is a candidate worth classifying. */
    const CANDIDATE_PATTERN = '/PASSWORD/i';

    /**
     * Fields that mark a DB_PASSWORD[_SUFFIX] as belonging to a real database
     * connection. Presence of any one of them, sharing the same suffix, is
     * what separates DB_PASSWORD_SECOND from MAIL_PASSWORD.
     */
    private static $connectionFields = array(
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_DRIVER',
    );

    /**
     * The managed keys, in three tiers, most authoritative first:
     *
     *  1. ENVCRYPT_TARGET_KEYS - the list the operator confirmed through
     *     "php artisan db:password-encrypt-all". Used verbatim.
     *  2. Failing that, any DB_*PASSWORD* key that is ALREADY encrypted. A
     *     defensive read-only sweep, so a value encrypted by hand without ever
     *     running encrypt-all still gets protected by the guards elsewhere.
     *  3. Failing that, DB_PASSWORD alone - exactly the behaviour before
     *     multi-password support existed, so nothing regresses for a project
     *     with one database.
     *
     * $fallback lets a project override tier 3 from config. Returns key =>
     * value, values unquoted, value null when the key is absent from .env.
     */
    public static function resolve($contents, array $fallback = array('DB_PASSWORD'))
    {
        $declared = self::declared($contents);

        if ($declared !== array()) {
            $found = array();

            foreach ($declared as $key) {
                $found[$key] = EnvFile::value($contents, $key);
            }

            return $found;
        }

        $encrypted = array();

        foreach (EnvFile::matchingKeys($contents, '/^DB_.*PASSWORD/i') as $key => $value) {
            if (EnvCrypt::isEncrypted($value)) {
                $encrypted[$key] = $value;
            }
        }

        if ($encrypted !== array()) {
            return $encrypted;
        }

        $found = array();

        foreach ($fallback as $key) {
            $found[$key] = EnvFile::value($contents, $key);
        }

        return $found;
    }

    /** Just the names of the managed keys whose value is currently encrypted. */
    public static function encryptedNames($contents, array $fallback = array('DB_PASSWORD'))
    {
        $names = array();

        foreach (self::resolve($contents, $fallback) as $key => $value) {
            if (EnvCrypt::isEncrypted($value)) {
                $names[] = $key;
            }
        }

        return $names;
    }

    /** The list recorded by a previous run, if any. */
    public static function declared($contents)
    {
        $declared = EnvFile::value($contents, EnvCrypt::TARGET_KEYS_VAR);
        $keys = array();

        if ($declared === null) {
            return $keys;
        }

        foreach (explode(',', $declared) as $key) {
            $key = trim($key);

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Split candidates into "belongs to a database connection" and
     * "everything else", by relationship rather than by the word PASSWORD.
     *
     * @return array{0: string[], 1: string[]}
     */
    public static function classify($contents)
    {
        $database = array();
        $other = array();
        $all = EnvFile::all($contents);

        foreach ($all as $key => $value) {
            if ($key === EnvCrypt::TARGET_KEYS_VAR
                || preg_match(self::CANDIDATE_PATTERN, $key) !== 1) {
                continue;
            }

            if (self::belongsToDatabase($key, $all)) {
                $database[] = $key;
            } else {
                $other[] = $key;
            }
        }

        return array($database, $other);
    }

    /**
     * DB_PASSWORD is the default connection's password by Laravel convention.
     * DB_PASSWORD_<SUFFIX> counts only when a sibling connection field shares
     * the same suffix - which is what tells DB_PASSWORD_SECOND (real) apart
     * from MAIL_PASSWORD (not a database) without hardcoding suffix names.
     */
    public static function belongsToDatabase($key, array $all)
    {
        if (strcasecmp($key, 'DB_PASSWORD') === 0) {
            return true;
        }

        if (! preg_match('/^DB_PASSWORD(_[A-Za-z0-9]+)$/i', $key, $m)) {
            return false;
        }

        foreach (self::$connectionFields as $field) {
            if (array_key_exists($field . $m[1], $all)) {
                return true;
            }
        }

        return false;
    }
}
