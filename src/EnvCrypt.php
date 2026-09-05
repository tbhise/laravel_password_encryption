<?php

namespace Tusharb\EnvCrypt;

use RuntimeException;

/**
 * Encrypts and decrypts values stored in .env.
 *
 * Nothing here touches Laravel or the container: no facades, no helpers, no
 * config() - so this class can be used by the Laravel integration AND by the
 * standalone bin/envcrypt script, which boots neither Laravel nor Composer.
 * Both then agree, by construction, on how a value is encrypted.
 */
final class EnvCrypt
{
    /** Marks a value as encrypted. Anything without it is treated as plaintext. */
    const PREFIX = 'enc:';

    /** Used when nothing else names the secret. */
    const DEFAULT_ROOT_KEY_VAR = 'ENVCRYPT_ROOT_KEY';

    /** Changing this label makes every existing value undecryptable. */
    const DEFAULT_CONTEXT = 'db-password-encryption-v2';

    const CIPHER = 'aes-256-cbc';

    /** Where the confirmed list of managed password keys is recorded. */
    const TARGET_KEYS_VAR = 'ENVCRYPT_TARGET_KEYS';

    /**
     * The environment variable holding the secret.
     *
     * Deliberately NOT Laravel's APP_KEY, whose rotation is an application-level
     * operation that must stay independent of database credentials.
     *
     * Configurable so two projects on one server never share a secret, and so
     * the name can say nothing about its purpose and not stand out in a
     * configuration dump. That is obscurity, not protection.
     *
     * @var string|null
     */
    private static $rootKeyVar = null;

    /** @var string|null */
    private static $context = null;

    /** @var string|null */
    private static $basePath = null;

    /** @var string|null */
    private static $derived = null;

    /**
     * Point the class at this project. Called by the service provider from
     * config/envcrypt.php, and by the standalone script from its own bootstrap.
     */
    public static function configure(array $settings)
    {
        if (isset($settings['key_var']) && $settings['key_var'] !== '') {
            self::$rootKeyVar = (string) $settings['key_var'];
        }

        if (isset($settings['context']) && $settings['context'] !== '') {
            self::$context = (string) $settings['context'];
        }

        if (isset($settings['base_path']) && $settings['base_path'] !== '') {
            self::$basePath = rtrim(str_replace('\\', '/', $settings['base_path']), '/');
        }

        // A changed name or context means a different key, so nothing derived
        // under the old settings may survive.
        self::$derived = null;
    }

    /** Forget everything configured - for tests. */
    public static function reset()
    {
        self::$rootKeyVar = self::$context = self::$basePath = self::$derived = null;
    }

    /**
     * The name of the variable holding the secret.
     *
     * Resolution order: an explicit configure() call, then ENVCRYPT_KEY_VAR in
     * the real environment, then ENVCRYPT_KEY_VAR in .env, then the default.
     * The middle two exist so the standalone script names the same variable as
     * the application without being handed the config array.
     */
    public static function rootKeyVar()
    {
        if (self::$rootKeyVar !== null) {
            return self::$rootKeyVar;
        }

        $name = self::fromEnvironment('ENVCRYPT_KEY_VAR');

        if ($name === '') {
            $name = self::fromEnvFile('ENVCRYPT_KEY_VAR');
        }

        return self::$rootKeyVar = ($name !== '' ? $name : self::DEFAULT_ROOT_KEY_VAR);
    }

    public static function context()
    {
        if (self::$context !== null) {
            return self::$context;
        }

        $context = self::fromEnvironment('ENVCRYPT_CONTEXT');

        if ($context === '') {
            $context = self::fromEnvFile('ENVCRYPT_CONTEXT');
        }

        return self::$context = ($context !== '' ? $context : self::DEFAULT_CONTEXT);
    }

    /**
     * The project root.
     *
     * Installed under vendor/, so the depth from this file is not fixed: walk
     * up looking for the pair of files only a project root has.
     */
    public static function basePath()
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        $directory = str_replace('\\', '/', __DIR__);

        while (true) {
            if (is_file($directory . '/artisan') && is_file($directory . '/composer.json')) {
                return self::$basePath = $directory;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return self::$basePath = rtrim(str_replace('\\', '/', (string) getcwd()), '/');
    }

    public static function envPath()
    {
        return self::basePath() . '/.env';
    }

    /**
     * $rootKey overrides the stored secret. Rotation needs it: re-encrypting
     * under a new secret must not require that secret to be installed first.
     */
    public static function encrypt($plain, $rootKey = null)
    {
        $key = self::key($rootKey);

        // A fresh IV each time, so the same password never encrypts alike.
        $iv = random_bytes(16);

        $value = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($value === false) {
            throw new RuntimeException('EnvCrypt: encryption failed.');
        }

        // Encrypt-then-MAC over IV and ciphertext, so a tampered value is
        // rejected before decryption is attempted.
        $mac = hash_hmac('sha256', $iv . $value, $key, true);

        return self::PREFIX . base64_encode($iv . $mac . $value);
    }

    public static function decrypt($payload, $rootKey = null)
    {
        if (! self::isEncrypted($payload)) {
            throw new RuntimeException('EnvCrypt: value does not start with "' . self::PREFIX . '".');
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);

        // 16 IV + 32 MAC + at least one 16-byte block.
        if ($raw === false || strlen($raw) <= 48) {
            throw new RuntimeException('EnvCrypt: the encrypted value is malformed.');
        }

        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $value = substr($raw, 48);

        $key = self::key($rootKey);

        // Constant time, so the correct signature cannot be learned by timing.
        if (! hash_equals(hash_hmac('sha256', $iv . $value, $key, true), $mac)) {
            throw new RuntimeException(
                'EnvCrypt: signature check failed. The value was altered, or '
                . self::rootKeyVar() . ' is not the secret it was encrypted with.'
            );
        }

        $plain = openssl_decrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            throw new RuntimeException('EnvCrypt: decryption failed.');
        }

        return $plain;
    }

    /**
     * Decrypt only when the value is actually encrypted.
     *
     * A plaintext password passes through untouched, which is what makes
     * rollback a one-line .env edit. Untyped on purpose: env() turns "null" and
     * "false" into real null/bool, and those must not crash.
     */
    public static function maybeDecrypt($value)
    {
        return self::isEncrypted($value) ? self::decrypt($value) : $value;
    }

    public static function isEncrypted($value)
    {
        return is_string($value)
            && strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /** A fresh secret, in the form the stores expect. */
    public static function generateRootKey()
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    private static function key($explicitRootKey = null)
    {
        // Never memoise an explicit secret: rotation derives two keys in one
        // process, and a cached one would poison the second.
        if ($explicitRootKey !== null) {
            return self::derive($explicitRootKey);
        }

        if (self::$derived !== null) {
            return self::$derived;
        }

        $rootKey = self::fromEnvironment(self::rootKeyVar());

        // DEVELOPMENT ONLY, and gated rather than taken whenever the real
        // variable is missing. A silent fallback would make "secret in .env" a
        // supported production configuration - the arrangement this design
        // exists to prevent.
        if ($rootKey === '' && self::isDevelopment()) {
            $rootKey = self::fromEnvFile(self::rootKeyVar());
        }

        if ($rootKey === '') {
            throw new RuntimeException(
                'EnvCrypt: ' . self::rootKeyVar() . ' is not available to this process. '
                . 'Store it with "php artisan db:keygen" from an elevated prompt, then '
                . 'recycle the application pool. A process cannot see a variable set '
                . 'after it started.'
            );
        }

        return self::$derived = self::derive($rootKey);
    }

    /** Turn a stored secret into the 32-byte cipher key. */
    private static function derive($rootKey)
    {
        if (strncmp($rootKey, 'base64:', 7) === 0) {
            $decoded = base64_decode(substr($rootKey, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('EnvCrypt: ' . self::rootKeyVar() . ' is not valid base64.');
            }

            $rootKey = $decoded;
        }

        // The value on disk is never used directly as a cipher key.
        return hash_hmac('sha256', self::context(), $rootKey, true);
    }

    /**
     * Fails closed: a missing, unreadable or unrecognised APP_ENV counts as
     * production. An attacker who can rewrite .env could set APP_ENV=local and
     * gain nothing - they would still need the secret the ciphertext was
     * encrypted with.
     */
    private static function isDevelopment()
    {
        $env = self::fromEnvironment('APP_ENV');

        if ($env === '') {
            $env = self::fromEnvFile('APP_ENV');
        }

        return in_array(strtolower($env), array('local', 'development'), true);
    }

    public static function fromEnvironment($name)
    {
        if (isset($_ENV[$name]) && is_string($_ENV[$name])) {
            return $_ENV[$name];
        }

        if (isset($_SERVER[$name]) && is_string($_SERVER[$name])) {
            return $_SERVER[$name];
        }

        if (getenv($name) !== false) {
            return (string) getenv($name);
        }

        return '';
    }

    /**
     * Read one line straight out of .env. Deliberately not via config(): a
     * config value is baked into bootstrap/cache/config.php by config:cache,
     * which would put the secret beside the ciphertext it unlocks. Reading the
     * file also works once the cache is warm, when Laravel stops loading .env.
     */
    public static function fromEnvFile($name)
    {
        $file = self::envPath();

        if (! is_readable($file)) {
            return '';
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return '';
        }

        $value = EnvFile::value($contents, $name);

        return $value === null ? '' : trim($value);
    }
}
