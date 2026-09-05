<?php

/**
 * EnvCrypt installation kit - a single portable file.
 *
 * Installs encrypted-DB_PASSWORD support into any Laravel 8-13 project:
 *
 *   php envcrypt-kit.php install [--project=NAME] [--force]
 *   php envcrypt-kit.php verify
 *   php envcrypt-kit.php uninstall [--pool=NAME] [--force]
 *
 * Boots neither Laravel nor Composer, so it runs on a locked-down server.
 * Every file it writes is embedded below - there is nothing else to copy, and
 * nothing to forget.
 *
 * It installs FOUR files:
 *
 *   app/Support/EnvCrypt.php                  crypto, secret store, .env parsing
 *   app/EnvCryptKit.php                       connector and console commands
 *   app/Providers/EnvCryptServiceProvider.php wiring
 *   storage/tools/envcrypt.php                standalone migration tool
 *
 * and adds one line to the project's provider list. It does NOT touch
 * AppServiceProvider, config/database.php, or .env.
 *
 * The split matters: app/Support/EnvCrypt.php depends on nothing at all, so
 * storage/tools/envcrypt.php can require it with no Composer autoloader and no
 * Laravel bootstrap. That is what makes the migration tool usable on a running
 * production server, and on any Laravel version.
 *
 * Migrating a project with several database passwords:
 *
 *   php artisan db:keygen                          (elevated, once per server)
 *   php storage/tools/envcrypt.php encrypt-all     (finds, confirms, rewrites)
 *
 * Running install twice changes nothing, so it is safe in a deploy script.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$kit = new EnvCryptKitInstaller(getcwd());

$command = isset($argv[1]) ? $argv[1] : '';
$options = kit_options($argv);

switch ($command) {
    case 'install':
        exit($kit->install($options));
    case 'verify':
        exit($kit->verify());
    case 'uninstall':
        exit($kit->uninstall($options));
    default:
        echo 'Usage, from the project root:' . PHP_EOL;
        echo '  php envcrypt-kit.php install [--project=NAME] [--force]' . PHP_EOL;
        echo '  php envcrypt-kit.php verify' . PHP_EOL;
        echo '  php envcrypt-kit.php uninstall [--pool=NAME] [--force]' . PHP_EOL;
        exit(1);
}

function kit_options(array $argv)
{
    $options = [];

    foreach (array_slice($argv, 2) as $argument) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $m)) {
            $options[$m[1]] = isset($m[2]) ? $m[2] : true;
        }
    }

    return $options;
}

/* ------------------------------------------------------------------ */

final class EnvCryptKitInstaller
{
    private $root;

    private $written = [];

    public function __construct($root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public function install(array $options)
    {
        $this->heading('Installing');

        if (! $this->looksLikeLaravel()) {
            return $this->fail(
                'This does not look like a Laravel project root.' . PHP_EOL
                . 'Expected artisan and composer.json in ' . $this->root
            );
        }

        $project = $this->projectIdentifier($options);
        $variable = 'NPAV_' . $project . '_BUILD_TAG';

        $this->line('project root : ' . $this->root);
        $this->line('identifier   : ' . $project);
        $this->line('secret name  : ' . $variable);
        $this->line('');

        $force = isset($options['force']);

        // The support file carries the variable name, so a project can never
        // end up sharing a secret with another one by accident.
        $support = str_replace('__ROOT_KEY_VAR__', $variable, $this->supportSource());

        if (! $this->write('app/Support/EnvCrypt.php', $support, $force)) {
            return 1;
        }

        if (! $this->write('app/EnvCryptKit.php', $this->kitSource(), $force)) {
            return 1;
        }

        if (! $this->write('app/Providers/EnvCryptServiceProvider.php', $this->providerSource(), $force)) {
            return 1;
        }

        if (! $this->write('storage/tools/envcrypt.php', $this->toolSource(), $force)) {
            return 1;
        }

        if (! $this->registerProvider()) {
            return 1;
        }

        $this->line('');

        if ($this->verify() !== 0) {
            $this->line('');
            $this->line('Installed, but verification failed - see above.');

            return 1;
        }

        $this->line('');
        $this->heading('Next steps');
        $this->line('1. Store the secret in the registry (elevated prompt):');
        $this->line('     php artisan db:keygen');
        $this->line('   IIS needs "iisreset" afterwards to see it.');
        $this->line('');
        $this->line('2. Open a NEW terminal, then encrypt the existing password(s).');
        $this->line('   For one, or for ten, in a single reviewed pass:');
        $this->line('     php storage/tools/envcrypt.php encrypt-all');
        $this->line('   It finds the database passwords, shows you the list to');
        $this->line('   confirm, backs up .env and rewrites it for you.');
        $this->line('');
        $this->line('3. Check everything:');
        $this->line('     php artisan config:clear');
        $this->line('     php artisan db:secret-check');
        $this->line('');
        $this->line('config/database.php must NOT be edited. Leave the password');
        $this->line('lines exactly as Laravel ships them.');

        return 0;
    }

    public function verify()
    {
        $this->heading('Verifying');

        $ok = true;

        foreach ($this->installedFiles() as $file) {
            $ok = $this->check(is_file($this->path($file)), $file) && $ok;
        }

        $ok = $this->check($this->providerIsRegistered(), 'provider registered') && $ok;

        // config/database.php must not decrypt - that would put the plaintext
        // into bootstrap/cache/config.php on the next config:cache.
        $database = $this->path('config/database.php');

        if (is_file($database)) {
            $contents = file_get_contents($database);

            $ok = $this->check(
                strpos($contents, 'EnvCrypt::maybeDecrypt') === false
                    && strpos($contents, 'EnvCrypt::decrypt') === false,
                'config/database.php does not decrypt'
            ) && $ok;
        }

        $cache = $this->path('bootstrap/cache/config.php');

        if (is_file($cache)) {
            $this->line('  note: a config cache exists - run "php artisan db:secret-check"');
            $this->line('        to confirm it holds no plaintext password.');
        }

        $this->line('');
        $this->line($ok
            ? 'Files and wiring are in place. Run "php artisan db:secret-check" for the runtime checks.'
            : 'Verification FAILED.');

        return $ok ? 0 : 1;
    }

    public function uninstall(array $options = [])
    {
        $this->heading('Uninstalling');

        // Read the secret's name out of the installed support file BEFORE
        // deleting it - it is the only place this project's name is recorded.
        $variable = $this->installedSecretName();

        foreach ($this->installedFiles() as $file) {
            $path = $this->path($file);

            if (is_file($path)) {
                unlink($path);
                $this->line('  removed  ' . $file);
            }
        }

        $this->unregisterProvider();

        $this->line('');

        if ($variable === null) {
            $this->line('Could not find the secret name (was app/Support/EnvCrypt.php already removed?).');
            $this->line('Nothing was cleaned up in the registry or an application pool - remove');
            $this->line('NPAV_<PROJECT>_BUILD_TAG by hand if one is no longer needed.');
        } else {
            $this->removeSecret($variable, $options);
        }

        $this->line('');
        $this->line('If DB_PASSWORD is still an "enc:" value the application will no longer');
        $this->line('start - put the plaintext password back in .env.');

        return 0;
    }

    /**
     * The secret's variable name, read from the installed support file itself -
     * the one place a rename cannot leave this out of sync.
     */
    private function installedSecretName()
    {
        $path = $this->path('app/Support/EnvCrypt.php');

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if (preg_match('/const\s+ROOT_KEY_VAR\s*=\s*\'([^\']+)\'/', $contents, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Everything install writes, in the order verify and uninstall want it. */
    private function installedFiles()
    {
        return [
            'app/Support/EnvCrypt.php',
            'app/EnvCryptKit.php',
            'app/Providers/EnvCryptServiceProvider.php',
            'storage/tools/envcrypt.php',
        ];
    }

    /**
     * Which managed password values are still encrypted.
     *
     * Checks every key the project declared in ENVCRYPT_TARGET_KEYS, falling
     * back to DB_PASSWORD - so uninstall cannot delete a secret that ten
     * encrypted values still depend on just because DB_PASSWORD looks fine.
     * This installer runs standalone, so it parses .env itself rather than
     * reusing the kit's EnvFile.
     */
    private function stillEncryptedKeys()
    {
        $path = $this->path('.env');

        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m)
                && ! array_key_exists($m[1], $values)) {
                $values[$m[1]] = $this->unquote($m[2]);
            }
        }

        $keys = ['DB_PASSWORD'];

        if (isset($values['ENVCRYPT_TARGET_KEYS']) && trim($values['ENVCRYPT_TARGET_KEYS']) !== '') {
            $keys = array_filter(array_map('trim', explode(',', $values['ENVCRYPT_TARGET_KEYS'])));
        }

        $encrypted = [];

        foreach ($keys as $key) {
            if (isset($values[$key]) && strncmp($values[$key], 'enc:', 4) === 0) {
                $encrypted[] = $key;
            }
        }

        return $encrypted;
    }

    /** Strip one matching layer of quotes, as the kit's EnvFile does. */
    private function unquote($raw)
    {
        $value = trim((string) $raw);

        if (strlen($value) < 2) {
            return $value;
        }

        $first = substr($value, 0, 1);

        if (($first === '"' || $first === "'") && substr($value, -1) === $first) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Removes the secret from wherever it lives. This installer runs without
     * Laravel or Composer, so it shells out directly rather than reusing the
     * kit's own EnvCryptSecret class.
     */
    private function removeSecret($variable, array $options)
    {
        $encrypted = $this->stillEncryptedKeys();

        if ($encrypted !== [] && empty($options['force'])) {
            $this->line('  These .env values still look encrypted: ' . implode(', ', $encrypted));
            $this->line('  Leaving ' . $variable . ' in place so they stay recoverable.');
            $this->line('  Decrypt them first, or re-run "uninstall --force" to remove the secret anyway.');

            return;
        }

        if (strncasecmp(PHP_OS_FAMILY, 'Windows', 7) !== 0) {
            $this->line('  Not on Windows - remove ' . $variable . ' from wherever it was set by hand.');

            return;
        }

        $pool = isset($options['pool']) ? $options['pool'] : null;

        if ($pool) {
            $this->removeFromPool($variable, $pool);
        } else {
            $this->removeFromMachine($variable);
        }
    }

    private function removeFromMachine($variable)
    {
        $hive = 'HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Environment';
        $command = 'reg delete "' . $hive . '" /v ' . $variable . ' /f';
        $output = trim((string) shell_exec($command . ' 2>&1'));

        if (stripos($output, 'unable to find') !== false) {
            $this->line('  unchanged  ' . $variable . ' was not in the registry');
        } elseif (stripos($output, 'denied') !== false) {
            $this->line('  COULD NOT remove ' . $variable . ' - access denied. From an elevated');
            $this->line('  prompt: ' . $command);
        } else {
            $this->line('  removed  ' . $variable . ' from the machine registry');
        }
    }

    private function removeFromPool($variable, $pool)
    {
        $appcmd = getenv('windir') . '\\system32\\inetsrv\\appcmd.exe';
        $command = '"' . $appcmd . '" set config -section:system.applicationHost/applicationPools'
            . ' "/-[name=\'' . $pool . '\'].environmentVariables.[name=\'' . $variable . '\']"'
            . ' /commit:apphost';
        $output = trim((string) shell_exec($command . ' 2>&1'));

        if ($output === '' || stripos($output, 'error') === false) {
            $this->line('  removed  ' . $variable . ' from pool "' . $pool . '"');
        } else {
            $this->line('  COULD NOT remove ' . $variable . ' from pool "' . $pool . '": ' . $output);
        }
    }

    /* -------------------------------------------------------------- */

    private function looksLikeLaravel()
    {
        return is_file($this->path('artisan')) && is_file($this->path('composer.json'));
    }

    /**
     * A short, uppercase identifier for this project, used in the secret's
     * name so two projects on one server never share a secret.
     */
    private function projectIdentifier(array $options)
    {
        if (isset($options['project']) && is_string($options['project'])) {
            return $this->normalise($options['project']);
        }

        $guess = $this->normalise(basename($this->root));

        echo 'Project identifier [' . $guess . ']: ';
        $answer = trim((string) fgets(STDIN));

        return $answer === '' ? $guess : $this->normalise($answer);
    }

    private function normalise($value)
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $value));

        return trim($value, '_');
    }

    private function write($relative, $contents, $force)
    {
        $path = $this->path($relative);

        if (is_file($path) && ! $force) {
            if (file_get_contents($path) === $contents) {
                $this->line('  unchanged  ' . $relative);

                return true;
            }

            $this->line('  EXISTS and differs: ' . $relative);
            $this->line('  Re-run with --force to overwrite it.');

            return false;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            $this->line('  FAILED to create ' . $directory);

            return false;
        }

        if (file_put_contents($path, $contents) === false) {
            $this->line('  FAILED to write ' . $relative);

            return false;
        }

        $this->written[] = $relative;
        $this->line('  wrote      ' . $relative);

        return true;
    }

    /**
     * Laravel 11+ keeps providers in bootstrap/providers.php; earlier versions
     * in the 'providers' array of config/app.php.
     */
    private function providerListFile()
    {
        $modern = $this->path('bootstrap/providers.php');

        return is_file($modern) ? $modern : $this->path('config/app.php');
    }

    private function providerIsRegistered()
    {
        $file = $this->providerListFile();

        if (! is_file($file)) {
            return false;
        }

        return strpos(file_get_contents($file), 'EnvCryptServiceProvider') !== false;
    }

    private function registerProvider()
    {
        if ($this->providerIsRegistered()) {
            $this->line('  unchanged  provider already registered');

            return true;
        }

        $file = $this->providerListFile();

        if (! is_file($file)) {
            return $this->fail('Could not find bootstrap/providers.php or config/app.php.');
        }

        $contents = file_get_contents($file);
        $entry = '        App\Providers\EnvCryptServiceProvider::class,';

        if (basename($file) === 'providers.php') {
            // return [ ... ];
            $updated = preg_replace(
                '/return\s*\[/',
                "return [\n    App\Providers\EnvCryptServiceProvider::class,",
                $contents,
                1
            );
        } else {
            // 'providers' => [ ... ] or ServiceProvider::defaultProviders()->merge([ ... ])
            $updated = preg_replace(
                "/('providers'\s*=>\s*\[)/",
                "$1\n{$entry}",
                $contents,
                1
            );

            if ($updated === $contents) {
                $updated = preg_replace(
                    '/(->merge\(\[)/',
                    "$1\n{$entry}",
                    $contents,
                    1
                );
            }
        }

        if ($updated === null || $updated === $contents) {
            $this->line('  COULD NOT register the provider automatically.');
            $this->line('  Add this line by hand to ' . str_replace($this->root . '/', '', $file) . ':');
            $this->line('    App\Providers\EnvCryptServiceProvider::class,');

            return false;
        }

        if (file_put_contents($file, $updated) === false) {
            return $this->fail('Could not write ' . $file);
        }

        $this->line('  wrote      ' . str_replace($this->root . '/', '', $file) . ' (provider registered)');

        return true;
    }

    private function unregisterProvider()
    {
        $file = $this->providerListFile();

        if (! is_file($file)) {
            return;
        }

        $contents = file_get_contents($file);

        $updated = preg_replace(
            '/^\s*(?:\\\\)?App\\\\Providers\\\\EnvCryptServiceProvider::class,\s*\r?\n/m',
            '',
            $contents
        );

        if ($updated !== null && $updated !== $contents) {
            file_put_contents($file, $updated);
            $this->line('  removed  provider registration');
        }
    }

    private function path($relative)
    {
        return $this->root . '/' . $relative;
    }

    private function check($condition, $label)
    {
        $this->line('  [' . ($condition ? 'ok  ' : 'FAIL') . '] ' . $label);

        return (bool) $condition;
    }

    private function heading($text)
    {
        echo PHP_EOL . $text . PHP_EOL . str_repeat('-', strlen($text)) . PHP_EOL;
    }

    private function line($text)
    {
        echo $text . PHP_EOL;
    }

    private function fail($message)
    {
        echo PHP_EOL . 'ERROR: ' . $message . PHP_EOL;

        return 1;
    }

    /* -------------------------------------------------------------- */
    /* Embedded files                                                 */
    /* -------------------------------------------------------------- */

    /**
     * app/Support/EnvCrypt.php - the framework-agnostic half.
     *
     * Deliberately free of every `use` statement and of Composer's autoloader,
     * so a plain PHP script can require it on its own. That is what lets
     * storage/tools/envcrypt.php run with no Laravel bootstrap at all.
     */
    private function supportSource()
    {
        return <<<'SUPPORT'
<?php

/**
 * EnvCrypt support classes - installed by envcrypt-kit.php. Do not edit by hand.
 *
 * The crypto, the secret store, and .env parsing. Nothing here touches Laravel
 * or Composer: no `use` statements, no autoloader, no framework helpers - so
 * this file can be required by the Laravel integration AND by a standalone
 * script, on any Laravel version, with the same behaviour either way.
 */

/**
 * Encrypts and decrypts values stored in .env.
 */
final class EnvCrypt
{
    /** Marks a value as encrypted. Anything without it is treated as plaintext. */
    const PREFIX = 'enc:';

    /**
     * The secret. Deliberately NOT Laravel's APP_KEY, whose rotation is an
     * application-level operation that must stay independent of database
     * credentials.
     *
     * The name carries a project identifier so two projects on one server never
     * share a secret, and says nothing about its purpose so it does not stand
     * out in a configuration dump. That is obscurity, not protection.
     */
    const ROOT_KEY_VAR = '__ROOT_KEY_VAR__';

    /** Changing this label makes every existing value undecryptable. */
    const CONTEXT = 'db-password-encryption-v2';

    const CIPHER = 'aes-256-cbc';

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
        if (strncmp($payload, self::PREFIX, strlen(self::PREFIX)) !== 0) {
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
                . self::ROOT_KEY_VAR . ' is not the secret it was encrypted with.'
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
        if (! is_string($value) || strncmp($value, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return $value;
        }

        return self::decrypt($value);
    }

    private static function key($explicitRootKey = null)
    {
        static $derived = null;

        // Never memoise an explicit secret: rotation derives two keys in one
        // process, and a cached one would poison the second.
        if ($explicitRootKey !== null) {
            return self::derive($explicitRootKey);
        }

        if ($derived !== null) {
            return $derived;
        }

        $rootKey = self::fromEnvironment(self::ROOT_KEY_VAR);

        // DEVELOPMENT ONLY, and gated rather than taken whenever the real
        // variable is missing. A silent fallback would make "secret in .env" a
        // supported production configuration - the arrangement this design
        // exists to prevent.
        if ($rootKey === '' && self::isDevelopment()) {
            $rootKey = self::fromEnvFile(self::ROOT_KEY_VAR);
        }

        if ($rootKey === '') {
            throw new RuntimeException(
                'EnvCrypt: ' . self::ROOT_KEY_VAR . ' is not available to this process. '
                . 'Store it with "php artisan db:keygen" from an elevated prompt, then '
                . 'recycle the application pool. A process cannot see a variable set '
                . 'after it started.'
            );
        }

        return $derived = self::derive($rootKey);
    }

    /** Turn a stored secret into the 32-byte cipher key. */
    private static function derive($rootKey)
    {
        if (strncmp($rootKey, 'base64:', 7) === 0) {
            $decoded = base64_decode(substr($rootKey, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('EnvCrypt: ' . self::ROOT_KEY_VAR . ' is not valid base64.');
            }

            $rootKey = $decoded;
        }

        // The value on disk is never used directly as a cipher key.
        return hash_hmac('sha256', self::CONTEXT, $rootKey, true);
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
        // app/Support/EnvCrypt.php -> two levels up is the project root.
        $file = dirname(__DIR__, 2) . '/.env';

        if (! is_readable($file)) {
            return '';
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return '';
        }

        // A Windows editor may have saved .env with a byte-order mark, which
        // would otherwise hide whichever variable is on the first line.
        $lines = preg_split('/\r\n|\r|\n/', ltrim($contents, "\xEF\xBB\xBF"));

        foreach ($lines as $line) {
            if (preg_match('/^\s*' . preg_quote($name, '/') . '\s*=\s*"?([^"\r\n]*)"?\s*$/', $line, $m)) {
                return trim($m[1]);
            }
        }

        return '';
    }
}

/**
 * Resolves the secret from the store that actually matters, rather than from
 * whatever the terminal inherited.
 *
 * This exists because of a real failure: a window opened before the secret was
 * last changed still holds the old value, and encrypting under it produces a
 * value the web application cannot read.
 *
 * Uses shell_exec rather than Symfony's Process on purpose: Process needs
 * Composer's autoloader, which a standalone script cannot assume is loaded.
 */
final class EnvCryptSecret
{
    const HIVE = 'HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Environment';

    /** Read from an application pool's environmentVariables collection. */
    public static function fromPool($pool)
    {
        $output = self::run(
            self::quote(self::appcmd()) . ' list config'
            . ' -section:system.applicationHost/applicationPools'
        );

        if ($output === null) {
            return null;
        }

        // The section lists every pool; isolate this one, then the variable.
        $at = strpos($output, "[name='" . $pool . "']");

        if ($at === false) {
            return null;
        }

        $pattern = "/name='" . preg_quote(EnvCrypt::ROOT_KEY_VAR, '/') . "',value='([^']*)'/";

        return preg_match($pattern, substr($output, $at), $m) ? $m[1] : null;
    }

    public static function fromMachine()
    {
        $output = self::run(
            'reg query ' . self::quote(self::HIVE) . ' /v ' . EnvCrypt::ROOT_KEY_VAR
        );

        if ($output === null) {
            return null;
        }

        $pattern = '/\s' . preg_quote(EnvCrypt::ROOT_KEY_VAR, '/') . '\s+REG_\w+\s+(.*)$/m';

        if (! preg_match($pattern, $output, $m)) {
            return null;
        }

        return trim($m[1]) === '' ? null : trim($m[1]);
    }

    /** True on success, or the failure text. */
    public static function writeToPool($pool, $secret)
    {
        $output = self::run(
            self::quote(self::appcmd()) . ' set config'
            . ' -section:system.applicationHost/applicationPools'
            . ' ' . self::quote(
                "/+[name='" . $pool . "'].environmentVariables."
                . "[name='" . EnvCrypt::ROOT_KEY_VAR . "',value='" . $secret . "']"
            )
            . ' /commit:apphost',
            true
        );

        return self::succeeded($output) ? true : trim((string) $output);
    }

    public static function clearFromPool($pool)
    {
        $output = self::run(
            self::quote(self::appcmd()) . ' set config'
            . ' -section:system.applicationHost/applicationPools'
            . ' ' . self::quote(
                "/-[name='" . $pool . "'].environmentVariables."
                . "[name='" . EnvCrypt::ROOT_KEY_VAR . "']"
            )
            . ' /commit:apphost',
            true
        );

        return self::succeeded($output);
    }

    public static function recyclePool($pool)
    {
        $output = self::run(
            self::quote(self::appcmd()) . ' recycle apppool'
            . ' /apppool.name:' . self::quote($pool),
            true
        );

        return self::succeeded($output);
    }

    /** True on success, or the failure text. */
    public static function writeToMachine($secret)
    {
        $output = (string) self::run(
            'setx /M ' . EnvCrypt::ROOT_KEY_VAR . ' ' . self::quote($secret),
            true
        );

        if (self::looksLikeDenial($output) || stripos($output, 'error') !== false) {
            return trim($output);
        }

        // setx silently shortens anything over 1024 characters, which would
        // store a secret that cannot decrypt anything.
        return stripos($output, 'truncat') === false ? true : trim($output);
    }

    /**
     * Best-effort elevation check.
     *
     * Three probes, because each can fail for reasons of its own: "net session"
     * needs the Server service running, and both it and the others can be
     * restricted by policy. Any one succeeding proves elevation; all three
     * failing proves nothing, so callers must treat false as "unknown" and
     * attempt the write anyway.
     */
    public static function elevated()
    {
        $probes = array(
            'reg query HKU\S-1-5-19',              // LocalService hive, admin-only
            'net session',                          // needs the Server service
            'fsutil dirty query %systemdrive%',
        );

        foreach ($probes as $probe) {
            if (self::run($probe) !== null) {
                return true;
            }
        }

        return false;
    }

    /** Does a failure message look like a permissions problem? */
    public static function looksLikeDenial($message)
    {
        return stripos($message, 'denied') !== false
            || stripos($message, 'error 5') !== false;
    }

    public static function onWindows()
    {
        return strncasecmp(PHP_OS_FAMILY, 'Windows', 7) === 0;
    }

    /**
     * Run a command. Returns its output, or null when it failed - unless
     * $keepFailureOutput, where the output is returned either way so the
     * caller can report what Windows actually said.
     */
    private static function run($command, $keepFailureOutput = false)
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $status = 1;
        $lines = array();

        // exec() gives the exit status, which shell_exec() does not.
        @exec($command . ' 2>&1', $lines, $status);

        $output = implode(PHP_EOL, $lines);

        if ($status !== 0 && ! $keepFailureOutput) {
            return null;
        }

        return $output;
    }

    private static function succeeded($output)
    {
        return $output !== null
            && stripos($output, 'error') === false
            && ! self::looksLikeDenial((string) $output);
    }

    private static function quote($value)
    {
        // escapeshellarg() uses double quotes on Windows, which is what cmd
        // expects, and neutralises everything inside them.
        return escapeshellarg($value);
    }

    private static function appcmd()
    {
        return getenv('windir') . '\\system32\\inetsrv\\appcmd.exe';
    }
}

/**
 * Reading and writing .env values.
 *
 * Shared by the Laravel commands and the standalone tool so there is exactly
 * one implementation, never two that drift apart.
 */
final class EnvFile
{
    /**
     * Strip exactly one matching layer of quotes.
     *
     * Without this, a single-quoted value - DB_PASSWORD='S#$a%n12d', which is
     * how a password containing # or $ has to be written - comes back with its
     * quotes attached and encrypts the wrong string.
     */
    public static function unquote($raw)
    {
        $value = trim((string) $raw);

        if (strlen($value) < 2) {
            return $value;
        }

        $first = substr($value, 0, 1);

        if (($first === '"' || $first === "'") && substr($value, -1) === $first) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /** Every top-level KEY=VALUE pair, in file order, values unquoted. */
    public static function all($contents)
    {
        $found = array();

        // Tolerate a byte-order mark, which a Windows editor may have left at
        // the start of the file - otherwise the first variable is invisible.
        $contents = ltrim((string) $contents, "\xEF\xBB\xBF");

        foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m)) {
                // First occurrence wins, matching how dotenv itself behaves.
                if (! array_key_exists($m[1], $found)) {
                    $found[$m[1]] = self::unquote($m[2]);
                }
            }
        }

        return $found;
    }

    /** One key's value, or null when the key is absent. */
    public static function value($contents, $key)
    {
        $all = self::all($contents);

        return array_key_exists($key, $all) ? $all[$key] : null;
    }

    /** Keys whose NAME matches $pattern, values unquoted, in file order. */
    public static function matchingKeys($contents, $pattern)
    {
        $found = array();

        foreach (self::all($contents) as $key => $value) {
            if (preg_match($pattern, $key) === 1) {
                $found[$key] = $value;
            }
        }

        return $found;
    }

    /**
     * Set one key's value, always double-quoted.
     *
     * Safe unconditionally here because every value written through this is an
     * "enc:" payload or a comma-separated key list - base64 and bare names,
     * neither of which can contain a quote or a newline.
     *
     * A callback replacement, so the value's own characters can never be read
     * as a backreference. $count reports whether the key was found.
     */
    public static function withValueSet($contents, $key, $value, &$count = null)
    {
        $pattern = '/^(\s*' . preg_quote($key, '/') . '\s*=)[^\r\n]*$/m';

        $updated = preg_replace_callback($pattern, function ($m) use ($value) {
            return $m[1] . '"' . $value . '"';
        }, $contents, 1, $count);

        if ($count > 0) {
            return $updated;
        }

        // Absent: append, keeping the file's existing line ending.
        $eol = strpos($contents, "\r\n") !== false ? "\r\n" : PHP_EOL;
        $count = 1;

        return rtrim($contents, "\r\n") . $eol . $key . '="' . $value . '"' . $eol;
    }
}
SUPPORT;
    }

    /**
     * app/EnvCryptKit.php - the Laravel half.
     *
     * Requires the support file above for the crypto, so the two can never
     * disagree about how a value is encrypted or how .env is parsed.
     */
    private function kitSource()
    {
        return <<<'KITFILE'
<?php

/**
 * EnvCrypt kit - installed by envcrypt-kit.php. Do not edit by hand.
 *
 * The Laravel-facing half: the database connector and the console commands.
 * Deliberately in the global namespace and loaded with require_once, so
 * Composer's autoloader is not involved in finding it and the same file works
 * on Laravel 8 through 13.
 */

use Illuminate\Console\Command;
use Illuminate\Database\Connectors\ConnectorInterface;

// The crypto, the secret store and .env parsing - no Laravel, no Composer.
require_once __DIR__ . '/Support/EnvCrypt.php';

/**
 * Decrypts the password as PDO is created.
 *
 * A connector is the right seam because ConnectionFactory::make() splits
 * read/write configurations ABOVE the connector, so both halves pass through
 * here. A DB::extend hook at manager level would miss the replica password.
 */
final class EnvCryptConnector implements ConnectorInterface
{
    private $inner;

    public function __construct(ConnectorInterface $inner)
    {
        $this->inner = $inner;
    }

    public function connect(array $config)
    {
        $config['password'] = EnvCrypt::maybeDecrypt(
            isset($config['password']) ? $config['password'] : ''
        );

        return $this->inner->connect($config);
    }
}

/**
 * Shared plumbing for the commands: locating .env and reading its values.
 */
trait EnvCryptCommandHelpers
{
    protected function envPath()
    {
        return base_path('.env');
    }

    /** Where the confirmed list of managed password keys is recorded. */
    const TARGET_KEYS_VAR = 'ENVCRYPT_TARGET_KEYS';

    protected function envContents()
    {
        $path = $this->envPath();

        return is_readable($path) ? file_get_contents($path) : '';
    }

    /** One key's value, quote-correct. Null when absent. */
    protected function envValue($key)
    {
        return EnvFile::value($this->envContents(), $key);
    }

    protected function isEncryptedValue($value)
    {
        return $value !== null
            && strncmp($value, EnvCrypt::PREFIX, strlen(EnvCrypt::PREFIX)) === 0;
    }

    /** The DB_PASSWORD value in .env, or null. */
    protected function passwordValue()
    {
        return $this->envValue('DB_PASSWORD');
    }

    protected function passwordIsEncrypted()
    {
        return $this->isEncryptedValue($this->passwordValue());
    }

    /**
     * Which .env keys hold database passwords this project manages.
     *
     * Three tiers, most authoritative first:
     *
     *  1. ENVCRYPT_TARGET_KEYS - the list the operator confirmed through
     *     "php storage/tools/envcrypt.php encrypt-all". Used verbatim.
     *  2. Failing that, any DB_*PASSWORD* key that is ALREADY encrypted. A
     *     defensive read-only sweep, so a value encrypted by hand without
     *     ever running encrypt-all still gets protected by the guards below.
     *  3. Failing that, DB_PASSWORD alone - exactly the behaviour before
     *     multi-password support existed, so nothing regresses for a project
     *     with one database.
     *
     * Returns key => value, values unquoted.
     */
    protected function resolveTargetKeys()
    {
        $contents = $this->envContents();
        $declared = EnvFile::value($contents, self::TARGET_KEYS_VAR);

        if ($declared !== null && trim($declared) !== '') {
            $found = array();

            foreach (explode(',', $declared) as $key) {
                $key = trim($key);

                if ($key === '') {
                    continue;
                }

                $found[$key] = EnvFile::value($contents, $key);
            }

            if ($found !== array()) {
                return $found;
            }
        }

        $encrypted = array();

        foreach (EnvFile::matchingKeys($contents, '/^DB_.*PASSWORD/i') as $key => $value) {
            if ($this->isEncryptedValue($value)) {
                $encrypted[$key] = $value;
            }
        }

        if ($encrypted !== array()) {
            return $encrypted;
        }

        return array('DB_PASSWORD' => EnvFile::value($contents, 'DB_PASSWORD'));
    }

    /** Just the names, for a message. */
    protected function encryptedTargetKeyNames()
    {
        $names = array();

        foreach ($this->resolveTargetKeys() as $key => $value) {
            if ($this->isEncryptedValue($value)) {
                $names[] = $key;
            }
        }

        return $names;
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

        $secret = $pool ? EnvCryptSecret::fromPool($pool) : null;

        if ($secret === null) {
            $secret = EnvCryptSecret::fromMachine();
        }

        if ($secret === null) {
            return null;
        }

        $inherited = EnvCrypt::fromEnvironment(EnvCrypt::ROOT_KEY_VAR);

        if ($inherited !== '' && $inherited !== $secret) {
            $this->warn('This terminal holds an out-of-date ' . EnvCrypt::ROOT_KEY_VAR . '.');
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

/**
 * Generates the secret and stores it.
 *
 * Two behaviours worth knowing: writing needs elevation, and a process cannot
 * see a variable set after it started - so the write is verified by reading the
 * store back, never with getenv().
 */
class EnvCryptKeygenCommand extends Command
{
    use EnvCryptCommandHelpers;

    protected $signature = 'db:keygen
                            {--pool= : Store it on this IIS application pool instead}
                            {--show : Print a new secret and store nothing}
                            {--force : Replace a stored secret that nothing depends on}';

    protected $description = 'Store the secret that unlocks an encrypted DB_PASSWORD';

    public function handle()
    {
        $secret = 'base64:' . base64_encode(random_bytes(32));

        if ($this->option('show')) {
            $this->line($secret);

            return 0;
        }

        // Checked before elevation, so someone in the wrong situation is told
        // what is actually wrong. --force does NOT override this: there is no
        // safe way to discard a secret a value on disk still depends on, so
        // rotation is a separate command that re-encrypts as it goes.
        //
        // Covers EVERY managed password, not just DB_PASSWORD - otherwise a
        // fresh secret would silently strand DB_PASSWORD_SECOND and friends.
        $encrypted = $this->encryptedTargetKeyNames();

        if ($encrypted !== array()) {
            $this->error(count($encrypted) . ' value(s) in .env are already encrypted:');
            $this->line('  ' . implode(', ', $encrypted));
            $this->newLine();
            $this->line('Replacing the secret would make them unreadable. To change the');
            $this->line('secret, rotate instead - it re-encrypts every one of them in one step:');
            $this->line('  php artisan db:key-rotate --pool="YourAppPool"');
            $this->newLine();
            $this->line('If they are genuinely unrecoverable, put the plaintext passwords');
            $this->line('back into .env first, then run this command again.');

            return 1;
        }

        // Machine-wide registry variable unless a pool is named.
        $pool = $this->option('pool');

        if (! $this->requireWindows()) {
            return 1;
        }

        $existing = $pool ? EnvCryptSecret::fromPool($pool) : EnvCryptSecret::fromMachine();

        if ($existing !== null && ! $this->option('force')) {
            $this->error('A secret is already stored, and nothing appears to depend on it.');
            $this->line('If that is right, replace it with --force.');

            return 1;
        }

        $result = $pool
            ? EnvCryptSecret::writeToPool($pool, $secret)
            : EnvCryptSecret::writeToMachine($secret);

        if ($result !== true) {
            $this->explainStoreFailure((string) $result);

            return 1;
        }

        $stored = $pool ? EnvCryptSecret::fromPool($pool) : EnvCryptSecret::fromMachine();

        if ($stored !== $secret) {
            $this->error('Written, but it did not read back. Nothing changed reliably.');

            return 1;
        }

        $this->info('Secret stored.');
        $this->newLine();
        $this->line('Back it up now - this is the only copy, and losing it makes an');
        $this->line('encrypted DB_PASSWORD unrecoverable.');
        $this->newLine();

        if ($pool) {
            EnvCryptSecret::recyclePool($pool);
            $this->line('Application pool recycled.');
        } else {
            $this->warn('A machine variable needs "iisreset" before IIS sees it,');
            $this->warn('because WAS reads the machine environment when it starts.');
        }

        $this->newLine();
        $this->line('Next: php artisan db:password-encrypt');

        return 0;
    }
}

/**
 * Encrypts a password for pasting into .env.
 *
 * Prompts with the echo off and never accepts the password as an argument, so
 * the plaintext reaches neither the screen nor shell history.
 */
class EnvCryptEncryptCommand extends Command
{
    use EnvCryptCommandHelpers;

    protected $signature = 'db:password-encrypt
                            {--pool= : Read the secret from this application pool}';

    protected $description = 'Encrypt a database password for .env';

    public function handle()
    {
        $secret = $this->authoritativeSecret($this->option('pool'));

        $first = $this->secret('Current database password');
        $second = $this->secret('Repeat to confirm');

        if ($first === null || $first === '') {
            $this->error('Nothing was entered.');

            return 1;
        }

        if ($first !== $second) {
            $this->error('The two entries do not match. Nothing was encrypted.');

            return 1;
        }

        try {
            $payload = EnvCrypt::encrypt($first, $secret);

            // Read it straight back, so a broken value is never handed over.
            if (EnvCrypt::decrypt($payload, $secret) !== $first) {
                $this->error('Self-check failed. Do not use this value.');

                return 1;
            }
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->newLine();
        $this->info('Self-check passed. Copy this exact line into .env:');
        $this->newLine();
        $this->line('  DB_PASSWORD="' . $payload . '"');
        $this->newLine();
        $this->line('Then: php artisan config:clear && php artisan db:secret-check');

        return 0;
    }
}

/**
 * Prints a decrypted value.
 *
 * Recovery and administration only. It puts the plaintext password on screen,
 * so it has no place in a deployment script, a scheduled task, or anything
 * that logs its output.
 */
class EnvCryptDecryptCommand extends Command
{
    use EnvCryptCommandHelpers;

    protected $signature = 'db:password-decrypt
                            {value? : The "enc:..." value, or omit to read --key from .env}
                            {--key=DB_PASSWORD : Which .env key to read when no value is given}
                            {--pool= : Read the secret from this application pool}';

    protected $description = 'Print a decrypted database password (recovery only)';

    public function handle()
    {
        $key = $this->option('key');
        $payload = $this->argument('value') ?: $this->envValue($key);

        if (! $payload) {
            $this->error('No value given, and no ' . $key . ' found in .env.');

            return 1;
        }

        if (! $this->confirm('This prints the password to the terminal. Continue?', false)) {
            return 0;
        }

        try {
            $this->newLine();
            $this->line(EnvCrypt::decrypt($payload, $this->authoritativeSecret($this->option('pool'))));
            $this->newLine();
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return 1;
        }

        return 0;
    }
}

/**
 * Replaces the secret and re-encrypts every managed password under the new
 * one, in memory.
 *
 * Doing this by hand means the plaintext passwords spend time in .env, in any
 * backup taken meanwhile, and in terminal scrollback.
 *
 * Strictly all-or-nothing across every key: one value that cannot be
 * decrypted under the current secret stops the whole rotation before anything
 * is touched, because a partial rotation would leave some values readable and
 * others permanently lost.
 */
class EnvCryptRotateCommand extends Command
{
    use EnvCryptCommandHelpers;

    protected $signature = 'db:key-rotate
                            {--pool= : The application pool holding the secret, if not the registry}
                            {--dry-run : Check that rotation would succeed, and change nothing}
                            {--reveal-previous : Print the outgoing secret, for old .env backups}';

    protected $description = 'Replace the secret and re-encrypt every managed password';

    public function handle()
    {
        // Machine-wide registry variable unless a pool is named.
        $pool = $this->option('pool');

        if (! $this->requireWindows()) {
            return 1;
        }

        $envPath = $this->envPath();

        if (! is_writable($envPath)) {
            $this->error('.env is not writable at ' . $envPath);

            return 1;
        }

        $contents = file_get_contents($envPath);

        // Only currently-encrypted keys are rotation targets. Encrypting a
        // plaintext one is encrypt-all's job, not rotation's.
        $targets = array();

        foreach ($this->resolveTargetKeys() as $key => $value) {
            if ($this->isEncryptedValue($value)) {
                $targets[$key] = $value;
            }
        }

        if ($targets === array()) {
            $this->error('No encrypted password values found in .env, so there is nothing to rotate.');

            return 1;
        }

        $this->line('Rotating ' . count($targets) . ' encrypted value(s): '
            . implode(', ', array_keys($targets)));
        $this->newLine();

        $previous = $pool ? EnvCryptSecret::fromPool($pool) : EnvCryptSecret::fromMachine();

        // 1. Recover EVERY plaintext under the CURRENT secret before anything
        //    else happens. Locals only - never written anywhere, never echoed.
        //    One failure aborts the whole rotation: a partial rotation would
        //    strand whichever keys had not been re-encrypted yet.
        $plain = array();

        foreach ($targets as $key => $value) {
            try {
                $plain[$key] = EnvCrypt::decrypt($value, $previous);
            } catch (Exception $e) {
                $this->error($key . ' cannot be decrypted under the current secret.');
                $this->line($e->getMessage());
                $this->newLine();
                $this->line('Nothing was changed. Every managed value must be readable');
                $this->line('before the secret can be replaced - fix or plaintext that one,');
                $this->line('then rotate again.');

                return 1;
            }
        }

        // 2. Re-encrypt all of them under a NEW secret that is not installed
        //    yet, self-checking each before any of it reaches disk.
        $newSecret = 'base64:' . base64_encode(random_bytes(32));
        $reEncrypted = array();

        foreach ($plain as $key => $value) {
            $payload = EnvCrypt::encrypt($value, $newSecret);

            if (EnvCrypt::decrypt($payload, $newSecret) !== $value) {
                $this->error('Self-check failed for ' . $key . '. Nothing was changed.');

                return 1;
            }

            $reEncrypted[$key] = $payload;
        }

        if ($this->option('dry-run')) {
            $this->info('Rotation would succeed for all ' . count($reEncrypted)
                . ' value(s). Nothing was changed.');

            return 0;
        }

        $this->warn('The application will not connect until its workers are recycled.');
        $this->warn('Existing .env backups will not be readable without the outgoing secret.');

        if (! $this->confirm('Rotate now?', false)) {
            $this->line('Cancelled. Nothing was changed.');

            return 0;
        }

        // Only obtainable now, before it is overwritten, and only on request:
        // printing it by default would put a live secret into scrollback on
        // every routine rotation.
        if ($this->option('reveal-previous')
            && $this->confirm('Print the outgoing secret to this terminal?', false)) {
            $this->newLine();
            $this->line('Outgoing secret - store it with the backups it unlocks:');
            $this->line('  ' . (string) $previous);
            $this->newLine();
        }

        // Ordering is deliberate. Atomicity across two stores is impossible, so
        // every reversible step goes first and the one irreversible step -
        // overwriting the installed secret - goes last. A failure then leaves
        // the old secret beside the old ciphertext, which is a working system.
        $backup = $envPath . '.rotate-backup-' . date('Ymd-His');

        if (file_put_contents($backup, $contents) === false) {
            $this->error('Could not write a backup to ' . $backup . '. Nothing was changed.');

            return 1;
        }

        $updated = $contents;

        foreach ($reEncrypted as $key => $payload) {
            $updated = EnvFile::withValueSet($updated, $key, $payload, $count);

            if ($count < 1) {
                $this->error('Could not find the ' . $key . ' line to update. Nothing was changed.');

                return 1;
            }
        }

        if (file_put_contents($envPath, $updated) === false) {
            $this->error('.env could not be written. Nothing was changed.');

            return 1;
        }

        // Verify what actually landed on disk, rather than trusting the write.
        $unreadable = $this->keysThatDoNotReadBack($newSecret, $plain);

        if ($unreadable !== array()) {
            file_put_contents($envPath, $contents);
            $this->error('These values did not read back correctly from .env: '
                . implode(', ', $unreadable));
            $this->line('.env has been restored and the secret was never touched.');

            return 1;
        }

        $result = $pool
            ? EnvCryptSecret::writeToPool($pool, $newSecret)
            : EnvCryptSecret::writeToMachine($newSecret);

        $stored = $pool ? EnvCryptSecret::fromPool($pool) : EnvCryptSecret::fromMachine();

        if ($result !== true || $stored !== $newSecret) {
            file_put_contents($envPath, $contents);
            $this->error('The new secret could not be stored and verified.');
            $this->line('.env has been restored. The previous secret is still installed,');
            $this->line('so the application is unchanged and still working.');

            return 1;
        }

        if ($pool) {
            EnvCryptSecret::recyclePool($pool);
        }

        $this->info('Rotated ' . count($reEncrypted) . ' value(s): '
            . implode(', ', array_keys($reEncrypted)));
        $this->newLine();
        $this->line('Backup of the previous .env: ' . $backup);
        $this->line('It holds the OLD ciphertext, which the new secret cannot read.');
        $this->newLine();
        $this->line('Next: php artisan config:clear, php artisan queue:restart,');
        $this->line('then php artisan db:secret-check. Delete the backup once verified.');

        return 0;
    }

    /**
     * Which keys on disk do NOT decrypt back to the plaintext we started with.
     * Names only - the values themselves are never reported.
     */
    private function keysThatDoNotReadBack($secret, array $expected)
    {
        $contents = $this->envContents();
        $bad = array();

        foreach ($expected as $key => $plain) {
            $value = EnvFile::value($contents, $key);

            if ($value === null) {
                $bad[] = $key;

                continue;
            }

            try {
                if (EnvCrypt::decrypt($value, $secret) !== $plain) {
                    $bad[] = $key;
                }
            } catch (Exception $e) {
                $bad[] = $key;
            }
        }

        return $bad;
    }
}

/**
 * The check to run after every deploy.
 *
 * Six assertions, exit 1 on any failure. This is what turns "it silently did
 * not work" into "it told me which piece is missing".
 */
class EnvCryptCheckCommand extends Command
{
    use EnvCryptCommandHelpers;

    protected $signature = 'db:secret-check
                            {--pool= : Read the secret from this application pool}
                            {--connection= : Which database connection to test}';

    protected $description = 'Verify the encrypted DB_PASSWORD setup end to end';

    public function handle()
    {
        $ok = true;

        $ok = $this->assert(
            class_exists('EnvCrypt') && class_exists('EnvCryptConnector'),
            'kit file loaded'
        ) && $ok;

        $driver = config('database.default');
        $binding = 'db.connector.' . config('database.connections.' . $driver . '.driver', $driver);

        $ok = $this->assert(
            app()->bound($binding) && app($binding) instanceof EnvCryptConnector,
            'connector bound (' . $binding . ')'
        ) && $ok;

        $ok = $this->assert(
            $this->configDoesNotDecrypt(),
            'config/database.php does not decrypt'
        ) && $ok;

        // Resolved the same way the other commands do - reading the pool or
        // registry directly - rather than trusting this process's own ambient
        // environment, which a CLI session will not have unless it was started
        // fresh after the secret was stored.
        $secret = $this->authoritativeSecret($this->option('pool'));
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
            \Illuminate\Support\Facades\DB::connection($connection)->select('select 1');
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
KITFILE;
    }

    /**
     * storage/tools/envcrypt.php - the standalone migration tool.
     *
     * Requires only app/Support/EnvCrypt.php: no Composer autoloader, no
     * Laravel bootstrap, nothing version-specific. That is what lets it run on
     * a live server the moment the decryption code is deployed, and keeps it
     * working when a wrong DB_PASSWORD is the very reason the site will not
     * start.
     */
    private function toolSource()
    {
        return <<<'TOOLFILE'
<?php

/**
 * Standalone .env password tool - installed by envcrypt-kit.php.
 * Do not edit by hand.
 *
 * Run it from the PROJECT ROOT:
 *
 *   php storage/tools/envcrypt.php encrypt-all [--pool=NAME] [--reconfigure]
 *   php storage/tools/envcrypt.php encrypt     [--key=DB_PASSWORD] [--pool=NAME]
 *   php storage/tools/envcrypt.php decrypt     ["enc:..."] [--key=NAME] [--pool=NAME]
 *
 * Boots neither Laravel nor Composer - it needs only app/Support/EnvCrypt.php.
 * So it still works when a wrong DB_PASSWORD is the reason the site will not
 * start, and it can migrate an existing production project the moment the
 * decryption code is on the server.
 *
 * Under storage/ because the web root is public/, so it is not reachable over
 * HTTP. It also refuses to run outside the CLI.
 *
 * PASSWORD VALUES ARE NEVER PRINTED. Only variable names appear in any listing
 * or confirmation - the sole exception is the "decrypt" subcommand, whose whole
 * purpose is recovery and which asks first.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// storage/tools/envcrypt.php  ->  two levels up is the project root.
$root = dirname(__DIR__, 2);
$supportFile = $root . '/app/Support/EnvCrypt.php';

if (! is_file($supportFile)) {
    fwrite(STDERR, 'ERROR: cannot find ' . $supportFile . PHP_EOL
        . 'Run this from the project root of a project where the kit is installed.' . PHP_EOL);
    exit(1);
}

require_once $supportFile;

$tool = new EnvCryptTool($root);
$command = isset($argv[1]) ? $argv[1] : '';
$options = EnvCryptTool::parseOptions($argv);

switch ($command) {
    case 'encrypt-all':
        exit($tool->encryptAll($options));
    case 'encrypt':
        exit($tool->encryptOne($options));
    case 'decrypt':
        exit($tool->decryptOne($argv, $options));
    default:
        echo 'Usage, from the project root:' . PHP_EOL;
        echo '  php storage/tools/envcrypt.php encrypt-all [--pool=NAME] [--reconfigure]' . PHP_EOL;
        echo '  php storage/tools/envcrypt.php encrypt     [--key=DB_PASSWORD] [--pool=NAME]' . PHP_EOL;
        echo '  php storage/tools/envcrypt.php decrypt     ["enc:..."] [--key=NAME] [--pool=NAME]' . PHP_EOL;
        exit(1);
}

final class EnvCryptTool
{
    /** Where the confirmed list of managed password keys is recorded. */
    const TARGET_KEYS_VAR = 'ENVCRYPT_TARGET_KEYS';

    /** Any key whose name contains this is a candidate worth classifying. */
    const CANDIDATE_PATTERN = '/PASSWORD/i';

    /**
     * Fields that mark a DB_PASSWORD[_SUFFIX] as belonging to a real database
     * connection. Presence of any one of them, sharing the same suffix, is
     * what separates DB_PASSWORD_SECOND from MAIL_PASSWORD.
     */
    private $connectionFields = array(
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_DRIVER',
    );

    private $root;

    public function __construct($root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public static function parseOptions(array $argv)
    {
        $options = array();

        foreach (array_slice($argv, 2) as $argument) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $m)) {
                $options[$m[1]] = isset($m[2]) ? $m[2] : true;
            }
        }

        return $options;
    }

    /* ------------------------------------------------------------------ */
    /* encrypt-all                                                        */
    /* ------------------------------------------------------------------ */

    public function encryptAll(array $options)
    {
        $envPath = $this->envPath();

        if (! is_readable($envPath)) {
            return $this->fail('Cannot read ' . $envPath);
        }

        if (! is_writable($envPath)) {
            return $this->fail($envPath . ' is not writable.');
        }

        $contents = file_get_contents($envPath);
        $pool = isset($options['pool']) && is_string($options['pool']) ? $options['pool'] : null;

        list($database, $other) = $this->classify($contents);
        $declared = $this->declaredKeys($contents);
        $reconfigure = isset($options['reconfigure']);

        // A previously confirmed list is authoritative. Re-running only asks
        // again when a NEW database password turned up, or --reconfigure was
        // passed. Keys in the "other" bucket are deliberately not counted:
        // they are never in the declared list, so counting them would force a
        // pointless review on every single run.
        $newlyFound = array_diff($database, $declared);

        if ($declared !== array() && ! $reconfigure && $newlyFound === array()) {
            $selected = $declared;
            echo 'Using the ' . count($selected) . ' variable(s) already recorded in '
                . self::TARGET_KEYS_VAR . '.' . PHP_EOL;
            echo 'Nothing new to review. Pass --reconfigure to change the list.' . PHP_EOL . PHP_EOL;
        } else {
            $proposed = $declared !== array() && ! $reconfigure
                ? array_values(array_unique(array_merge($declared, $database)))
                : $database;

            $selected = $this->review($proposed, $other, $contents);

            if ($selected === null) {
                echo 'Cancelled. Nothing was changed.' . PHP_EOL;

                return 0;
            }
        }

        if ($selected === array()) {
            echo 'No variables selected. Nothing was changed.' . PHP_EOL;

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
            } elseif ($this->isEncrypted($value)) {
                $skipped[$key] = 'already encrypted';
            } else {
                $targets[$key] = $value;
            }
        }

        foreach ($skipped as $key => $why) {
            echo '  skip  ' . $key . ' (' . $why . ')' . PHP_EOL;
        }

        if ($targets === array()) {
            echo PHP_EOL . 'Nothing left to encrypt.' . PHP_EOL;

            // The list itself may still be new information worth recording.
            $this->persistIfChanged($envPath, $contents, $selected);

            return 0;
        }

        if (! $this->confirmFinal(array_keys($targets))) {
            echo 'Cancelled. Nothing was changed.' . PHP_EOL;

            return 0;
        }

        $secret = $this->secret($pool);

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
                return $this->fail($key . ': ' . $e->getMessage() . PHP_EOL . 'Nothing was changed.');
            }
        }

        $backup = $envPath . '.encrypt-backup-' . date('Ymd-His');

        if (file_put_contents($backup, $contents) === false) {
            return $this->fail('Could not write a backup to ' . $backup . '. Nothing was changed.');
        }

        echo PHP_EOL . 'Backup written: ' . $backup . PHP_EOL;

        $updated = $contents;

        foreach ($payloads as $key => $payload) {
            $updated = EnvFile::withValueSet($updated, $key, $payload, $count);

            if ($count < 1) {
                return $this->fail('Could not find the ' . $key . ' line. Nothing was changed.');
            }
        }

        $updated = EnvFile::withValueSet($updated, self::TARGET_KEYS_VAR, implode(',', $selected));

        if (file_put_contents($envPath, $updated) === false) {
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
            file_put_contents($envPath, $contents);

            return $this->fail(
                'These values did not read back correctly: ' . implode(', ', $bad) . PHP_EOL
                . '.env has been restored from the backup. No password was changed.'
            );
        }

        echo PHP_EOL . 'Encrypted ' . count($payloads) . ' database password(s):' . PHP_EOL;

        foreach (array_keys($payloads) as $key) {
            echo '  ' . $key . PHP_EOL;
        }

        echo PHP_EOL . 'Recorded in ' . self::TARGET_KEYS_VAR . '. Edit that line, or re-run with' . PHP_EOL;
        echo '--reconfigure, to change which variables are managed.' . PHP_EOL;
        echo PHP_EOL . 'The database passwords themselves were NOT changed.' . PHP_EOL;
        echo PHP_EOL . 'Next:' . PHP_EOL;
        echo '  php artisan config:clear' . PHP_EOL;
        echo '  php artisan db:secret-check' . PHP_EOL;
        echo PHP_EOL . 'Delete ' . basename($backup) . ' once you have verified the application.' . PHP_EOL;

        return 0;
    }

    /**
     * Split candidates into "belongs to a database connection" and
     * "everything else", by relationship rather than by the word PASSWORD.
     */
    private function classify($contents)
    {
        $database = array();
        $other = array();
        $all = EnvFile::all($contents);

        foreach ($all as $key => $value) {
            if ($key === self::TARGET_KEYS_VAR || preg_match(self::CANDIDATE_PATTERN, $key) !== 1) {
                continue;
            }

            if ($this->belongsToDatabase($key, $all)) {
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
    private function belongsToDatabase($key, array $all)
    {
        if (strcasecmp($key, 'DB_PASSWORD') === 0) {
            return true;
        }

        if (! preg_match('/^DB_PASSWORD(_[A-Za-z0-9]+)$/i', $key, $m)) {
            return false;
        }

        foreach ($this->connectionFields as $field) {
            if (array_key_exists($field . $m[1], $all)) {
                return true;
            }
        }

        return false;
    }

    /** The list recorded by a previous run, if any. */
    private function declaredKeys($contents)
    {
        $declared = EnvFile::value($contents, self::TARGET_KEYS_VAR);
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
     * Show the proposal and let the operator confirm, remove, add or cancel.
     * Returns the confirmed list, or null when cancelled.
     */
    private function review(array $selected, array $other, $contents)
    {
        while (true) {
            echo PHP_EOL;

            if ($selected === array()) {
                echo 'No variables are currently selected.' . PHP_EOL;
            } else {
                echo 'The following variables appear to be database passwords' . PHP_EOL;
                echo '(they sit alongside a matching database connection\'s host, name or user):' . PHP_EOL . PHP_EOL;

                foreach ($selected as $i => $key) {
                    echo '  ' . ($i + 1) . '. ' . $key . PHP_EOL;
                }
            }

            if ($other !== array()) {
                echo PHP_EOL . 'Other password-like variables found, NOT selected' . PHP_EOL;
                echo '(no matching database connection fields):' . PHP_EOL . PHP_EOL;

                foreach ($other as $key) {
                    echo '  ' . $key . PHP_EOL;
                }
            }

            echo PHP_EOL . 'Only variable names are shown - values are never displayed or logged.' . PHP_EOL;
            echo PHP_EOL . 'Review the list:' . PHP_EOL;
            echo '  [Enter]  confirm as-is' . PHP_EOL;
            echo '  r        remove one or more (by number)' . PHP_EOL;
            echo '  a        add a variable by name' . PHP_EOL;
            echo '  c        cancel - change nothing' . PHP_EOL . PHP_EOL;

            $answer = $this->prompt('> ');

            // End of input is not consent.
            if ($answer === null) {
                echo 'No answer given (end of input).' . PHP_EOL;

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

            echo 'Unrecognised choice.' . PHP_EOL;
        }
    }

    private function removeByNumber(array $selected)
    {
        if ($selected === array()) {
            return $selected;
        }

        $answer = (string) $this->prompt('Enter numbers to remove (comma-separated): ');
        $remove = array();

        foreach (explode(',', $answer) as $number) {
            $number = (int) trim($number);

            if ($number >= 1 && $number <= count($selected)) {
                $remove[] = $number - 1;
            }
        }

        if ($remove === array()) {
            echo 'Nothing matched those numbers.' . PHP_EOL;

            return $selected;
        }

        foreach ($remove as $index) {
            echo '  removed  ' . $selected[$index] . PHP_EOL;
            unset($selected[$index]);
        }

        return array_values($selected);
    }

    private function addByName(array $selected, $contents)
    {
        $name = trim((string) $this->prompt('Enter the variable name to add: '));

        if ($name === '') {
            return $selected;
        }

        if (in_array($name, $selected, true)) {
            echo '  ' . $name . ' is already selected.' . PHP_EOL;

            return $selected;
        }

        if (EnvFile::value($contents, $name) === null) {
            echo '  ' . $name . ' is not present in .env. Not added.' . PHP_EOL;

            return $selected;
        }

        $selected[] = $name;
        echo '  added  ' . $name . PHP_EOL;

        return $selected;
    }

    private function confirmFinal(array $keys)
    {
        echo PHP_EOL . 'The following ' . count($keys)
            . ' database password variable(s) will be encrypted:' . PHP_EOL . PHP_EOL;

        foreach ($keys as $key) {
            echo '  ' . $key . PHP_EOL;
        }

        echo PHP_EOL . 'No other .env password fields will be modified.' . PHP_EOL;
        echo 'The database passwords themselves are not being changed.' . PHP_EOL . PHP_EOL;

        $answer = strtolower(trim((string) $this->prompt('Continue? [y/N] ')));

        return $answer === 'y' || $answer === 'yes';
    }

    /** Record the managed list when it differs from what is already there. */
    private function persistIfChanged($envPath, $contents, array $selected)
    {
        $declared = $this->declaredKeys($contents);

        if ($declared === $selected) {
            return;
        }

        $updated = EnvFile::withValueSet($contents, self::TARGET_KEYS_VAR, implode(',', $selected));

        if (file_put_contents($envPath, $updated) !== false) {
            echo 'Recorded the managed list in ' . self::TARGET_KEYS_VAR . '.' . PHP_EOL;
        }
    }

    /* ------------------------------------------------------------------ */
    /* encrypt / decrypt, one value                                       */
    /* ------------------------------------------------------------------ */

    public function encryptOne(array $options)
    {
        $key = isset($options['key']) && is_string($options['key']) ? $options['key'] : 'DB_PASSWORD';
        $pool = isset($options['pool']) && is_string($options['pool']) ? $options['pool'] : null;

        $first = $this->promptSecret('Database password for ' . $key . ' : ');
        $second = $this->promptSecret('Repeat to confirm' . str_repeat(' ', max(1, strlen($key) - 4)) . ' : ');

        if ($first === '') {
            return $this->fail('Nothing was entered.');
        }

        if ($first !== $second) {
            return $this->fail('The two entries do not match. Nothing was encrypted.');
        }

        try {
            $secret = $this->secret($pool);
            $payload = EnvCrypt::encrypt($first, $secret);

            if (EnvCrypt::decrypt($payload, $secret) !== $first) {
                return $this->fail('Self-check failed. Do not use this value.');
            }
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }

        echo PHP_EOL . 'Self-check passed. Copy this exact line into .env:' . PHP_EOL . PHP_EOL;
        echo '  ' . $key . '="' . $payload . '"' . PHP_EOL . PHP_EOL;
        echo 'Then: php artisan config:clear' . PHP_EOL;

        return 0;
    }

    public function decryptOne(array $argv, array $options)
    {
        $key = isset($options['key']) && is_string($options['key']) ? $options['key'] : 'DB_PASSWORD';
        $pool = isset($options['pool']) && is_string($options['pool']) ? $options['pool'] : null;

        $payload = isset($argv[2]) && strncmp($argv[2], '--', 2) !== 0
            ? $argv[2]
            : EnvFile::value(file_get_contents($this->envPath()), $key);

        if (! $payload) {
            return $this->fail('No value given, and no ' . $key . ' found in .env.');
        }

        echo 'This prints a password to the terminal. It belongs in recovery work,' . PHP_EOL;
        echo 'not in a deployment script or anything that logs its output.' . PHP_EOL . PHP_EOL;

        $answer = strtolower(trim((string) $this->prompt('Continue? [y/N] ')));

        if ($answer !== 'y' && $answer !== 'yes') {
            echo 'Cancelled.' . PHP_EOL;

            return 0;
        }

        try {
            echo PHP_EOL . EnvCrypt::decrypt($payload, $this->secret($pool)) . PHP_EOL . PHP_EOL;
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

    private function isEncrypted($value)
    {
        return strncmp($value, EnvCrypt::PREFIX, strlen(EnvCrypt::PREFIX)) === 0;
    }

    /**
     * The secret, from the store rather than from whatever this terminal
     * inherited - a window opened before the secret last changed still holds
     * the old value, and encrypting under that produces values the web
     * application cannot read. Null means "let EnvCrypt resolve it", the
     * development path.
     */
    private function secret($pool)
    {
        if (! EnvCryptSecret::onWindows()) {
            return null;
        }

        $secret = $pool ? EnvCryptSecret::fromPool($pool) : null;

        if ($secret === null) {
            $secret = EnvCryptSecret::fromMachine();
        }

        if ($secret === null) {
            return null;
        }

        $inherited = EnvCrypt::fromEnvironment(EnvCrypt::ROOT_KEY_VAR);

        if ($inherited !== '' && $inherited !== $secret) {
            echo 'NOTE: this terminal holds an out-of-date ' . EnvCrypt::ROOT_KEY_VAR . '.' . PHP_EOL;
            echo '      Using the current value from the configuration store instead.' . PHP_EOL . PHP_EOL;
        }

        return $secret;
    }

    /**
     * Read a line. Returns null at end of input.
     *
     * The distinction matters: EOF must never be mistaken for "the operator
     * pressed Enter to accept", or a piped or scheduled invocation would
     * silently confirm a list nobody looked at.
     */
    private function prompt($label)
    {
        echo $label;

        $line = fgets(STDIN);

        if ($line === false) {
            echo PHP_EOL;

            return null;
        }

        // A UTF-8 BOM arrives ahead of the first line from some shells.
        return ltrim(rtrim($line, "\r\n"), "\xEF\xBB\xBF");
    }

    /**
     * Ask for a password without printing it, so it stays out of the
     * scrollback, a screen share, and shell history.
     */
    private function promptSecret($label)
    {
        echo $label;

        if (! function_exists('shell_exec')) {
            echo '(warning: input will be visible) ';

            return rtrim((string) fgets(STDIN), "\r\n");
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $ps = 'powershell -NoProfile -Command "'
                . '$s = Read-Host -AsSecureString; '
                . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
                . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))"';

            $value = rtrim((string) shell_exec($ps), "\r\n");
            echo PHP_EOL;

            return $value;
        }

        shell_exec('stty -echo');
        $value = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        echo PHP_EOL;

        return $value;
    }

    private function fail($message)
    {
        fwrite(STDERR, PHP_EOL . 'ERROR: ' . $message . PHP_EOL . PHP_EOL);

        return 1;
    }
}
TOOLFILE;
    }

    private function providerSource()
    {
        return <<<'PROVIDER'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the EnvCrypt kit into the application. Installed by envcrypt-kit.php.
 *
 * The kit file has no namespace and is not autoloaded, on purpose: it must be
 * usable without Composer. So it is required here, once, before anything else.
 */
class EnvCryptServiceProvider extends ServiceProvider
{
    /**
     * Drivers whose configuration carries a password.
     *
     * MariaDbConnector exists only from Laravel 11, and SQLite has no password.
     * A ::class reference inside a closure is a compile-time string that never
     * autoloads, so naming a missing class here is harmless until used.
     */
    private $connectors = [
        'mysql' => \Illuminate\Database\Connectors\MySqlConnector::class,
        'mariadb' => \Illuminate\Database\Connectors\MariaDbConnector::class,
        'pgsql' => \Illuminate\Database\Connectors\PostgresConnector::class,
        'sqlsrv' => \Illuminate\Database\Connectors\SqlServerConnector::class,
    ];

    public function register(): void
    {
        require_once app_path('EnvCryptKit.php');

        // ConnectionFactory::createConnector() checks the container for a
        // "db.connector.{driver}" binding before using its own. Decrypting
        // here - at connection time - is what keeps the plaintext out of
        // bootstrap/cache/config.php.
        foreach ($this->connectors as $driver => $connector) {
            $this->app->bind('db.connector.' . $driver, function () use ($connector) {
                return new \EnvCryptConnector(new $connector);
            });
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \EnvCryptKeygenCommand::class,
                \EnvCryptRotateCommand::class,
                \EnvCryptEncryptCommand::class,
                \EnvCryptDecryptCommand::class,
                \EnvCryptCheckCommand::class,
            ]);
        }
    }
}
PROVIDER;
    }
}
