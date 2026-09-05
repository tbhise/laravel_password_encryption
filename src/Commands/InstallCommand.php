<?php

namespace Tusharb\EnvCrypt\Commands;

use Illuminate\Console\Command;
use Tusharb\EnvCrypt\Concerns\InteractsWithEnvCrypt;
use Tusharb\EnvCrypt\Console\InteractiveConsole;
use Tusharb\EnvCrypt\Elevation;
use Tusharb\EnvCrypt\EnvCrypt;
use Tusharb\EnvCrypt\EnvCryptSecret;
use Tusharb\EnvCrypt\EnvFile;
use Tusharb\EnvCrypt\Io\ConsoleIo;
use Tusharb\EnvCrypt\Io\TerminalIo;
use Tusharb\EnvCrypt\Migrator;

/**
 * Sets the project up, end to end, asking before anything irreversible.
 *
 * Composer can only put files in vendor/. This is the step that turns them
 * into a working installation: it names this project's secret, publishes the
 * config and the standalone tool, stores a secret if the server has none, and
 * - only with an explicit confirmation - migrates the passwords in .env.
 *
 * Two things it will not do, deliberately:
 *
 *  - encrypt anything without a review and a yes/no answer, so a scripted or
 *    non-interactive run can never alter credentials;
 *  - restart IIS. That decision affects every site on the server and belongs
 *    to whoever is watching the traffic.
 *
 * Idempotent: a second run reports "unchanged" and finds nothing left to
 * encrypt.
 */
class InstallCommand extends Command
{
    use InteractsWithEnvCrypt;

    protected $signature = 'envcrypt:install
                            {--project= : Short identifier for this project, e.g. BILLING}
                            {--pool= : Record an IIS application pool as this project\'s secret store}
                            {--no-tool : Skip publishing storage/tools/envcrypt.php}
                            {--no-keygen : Do not store a secret, even if none exists}
                            {--no-encrypt : Set up only; leave the passwords in plaintext}
                            {--force : Overwrite an existing name, config or tool}
                            {--from-composer : Internal: started by Composer, so questions go to the console}';

    protected $description = 'Set this project up to use encrypted database passwords';

    /**
     * A channel to the terminal, used when this command's own STDIN is not one.
     *
     * @var \Tusharb\EnvCrypt\Console\InteractiveConsole|null
     */
    private $console = null;

    /** Whether anything can be asked at all, decided once in openConsole(). */
    private $interactive = false;

    public function handle()
    {
        $this->openConsole();

        $this->heading('Installing');

        if (! $this->preflight()) {
            return 1;
        }

        $variable = $this->resolveSecretName();

        if ($variable === null) {
            return 1;
        }

        $this->line('  project root : ' . base_path());
        $this->line('  secret name  : ' . $variable);
        $this->line('');

        if (! $this->recordSecretName($variable)) {
            return 1;
        }

        $this->recordPool();
        $this->publishConfig();
        $this->publishTool();

        // The provider configured EnvCrypt before .env said any of this, so
        // tell it now - otherwise everything below would still use the old
        // name.
        EnvCrypt::configure(['key_var' => $variable]);

        $this->line('');

        if ($this->call('envcrypt:verify') !== 0) {
            $this->line('');
            $this->error('Installed, but verification failed - see above.');

            return 1;
        }

        $secretReady = $this->ensureSecret($variable);

        if ($this->shouldEncrypt($secretReady)) {
            return $this->migrate($variable);
        }

        $this->printRemainingSteps($variable, $secretReady);

        return 0;
    }

    /* ------------------------------------------------------------------ */
    /* Asking, wherever the terminal happens to be                        */
    /* ------------------------------------------------------------------ */

    /**
     * Decide how this run will ask questions.
     *
     * Symfony's prompts read STDIN, which is a pipe when Composer invokes
     * artisan: a question would be printed and instantly answered by
     * end-of-input. Rather than guessing at that from inside, the caller says
     * so with --from-composer, and this then talks to the console directly.
     * Where there is no console either - CI, a scripted deploy - the run is
     * genuinely unattended and asks nothing at all.
     */
    private function openConsole()
    {
        if ($this->option('no-interaction')) {
            $this->interactive = false;
            $this->input->setInteractive(false);

            return;
        }

        // Started by a person, directly: Symfony's own prompts work, and the
        // input object's answer is the truthful one.
        if (! $this->option('from-composer')) {
            $this->interactive = $this->input->isInteractive();

            return;
        }

        // Started from package:discover during "composer require". STDIN is a
        // pipe, so questions have to go to the console itself. Kernel::call()
        // also hands the command an ArrayInput that claims to be interactive
        // whatever STDIN is - left alone, every question would be put to that
        // pipe and abort at end-of-input.
        $this->console = InteractiveConsole::open();
        $this->interactive = $this->console !== null;

        if (! $this->interactive) {
            $this->input->setInteractive(false);
        }
    }

    /** Can this run ask the operator anything at all? */
    private function canAsk()
    {
        return $this->interactive;
    }

    /**
     * These four route every question through the console when one is open,
     * so the same command works identically whether it was started by a person
     * or by Composer.
     */
    public function confirm($question, $default = false)
    {
        if ($this->console !== null) {
            return $this->console->confirm($question, $default);
        }

        return parent::confirm($question, $default);
    }

    public function ask($question, $default = null)
    {
        if ($this->console !== null) {
            return $this->console->ask($question, $default);
        }

        return parent::ask($question, $default);
    }

    public function choice($question, array $choices, $default = null, $attempts = null, $multiple = false)
    {
        if ($this->console !== null) {
            return $this->console->choice($question, array_values($choices), $default);
        }

        return parent::choice($question, $choices, $default, $attempts, $multiple);
    }

    /* ------------------------------------------------------------------ */
    /* Setup                                                              */
    /* ------------------------------------------------------------------ */

    private function preflight()
    {
        $envPath = $this->envPath();

        if (! is_file($envPath)) {
            $this->error('No .env found at ' . $envPath . '.');
            $this->line('Copy .env.example to .env first - there is nothing to protect yet.');

            return false;
        }

        if (! is_writable($envPath)) {
            $this->error('.env is not writable at ' . $envPath . '.');

            return false;
        }

        return true;
    }

    /**
     * The name of this project's secret.
     *
     * APP_NAME first, because it is what the project already calls itself; the
     * directory name second; a prompt last. The identifier matters because two
     * projects on one server sharing a name share a secret - an "enc:" value
     * from one would decrypt in the other, and one compromise would expose
     * every database on the box.
     *
     * The name also says nothing about what it holds, so it does not stand out
     * in a configuration dump. That buys time against untargeted scanning and
     * nothing more; it is not a security control.
     */
    private function resolveSecretName()
    {
        $existing = EnvFile::value($this->envContents(), 'ENVCRYPT_KEY_VAR');

        if ($existing !== null && $existing !== '' && ! $this->option('force')) {
            return $existing;
        }

        $project = $this->option('project') ?: $this->guessProject();

        if ($project === '' && $this->input->isInteractive()) {
            $project = (string) $this->ask('Short identifier for this project');
        }

        $project = $this->normalise($project);

        if ($project === '') {
            $this->error('Could not work out a project identifier.');
            $this->line('Re-run with --project=NAME, e.g. --project=BILLING.');

            return null;
        }

        // A leading digit is legal in a Windows environment variable but not in
        // most shells' expansion syntax, so a project called "2024Billing"
        // would produce a name that is awkward to read back by hand.
        if (preg_match('/^[0-9]/', $project) === 1) {
            $project = 'APP_' . $project;
        }

        return $project . '_BUILD_TAG';
    }

    /**
     * APP_NAME, unless it is Laravel's untouched default - which identifies
     * nothing, and would give two stock projects the same secret name.
     */
    private function guessProject()
    {
        $appName = $this->normalise(EnvFile::value($this->envContents(), 'APP_NAME'));

        if ($appName !== '' && $appName !== 'LARAVEL') {
            return $appName;
        }

        return $this->normalise(basename(base_path()));
    }

    /**
     * The NAME goes in .env; the secret itself never does. Recording it there
     * rather than only in config/envcrypt.php is what lets the standalone tool
     * - which boots no framework and reads no config - resolve the same name.
     */
    private function recordSecretName($variable)
    {
        $contents = $this->envContents();

        if (EnvFile::value($contents, 'ENVCRYPT_KEY_VAR') === $variable) {
            $this->line('  unchanged  ENVCRYPT_KEY_VAR');

            return true;
        }

        if (! $this->backups()->writeEnv(EnvFile::withValueSet($contents, 'ENVCRYPT_KEY_VAR', $variable))) {
            $this->error('Could not write .env. Add this line by hand:');
            $this->line('    ENVCRYPT_KEY_VAR=' . $variable);

            return false;
        }

        $this->line('  wrote      ENVCRYPT_KEY_VAR=' . $variable . ' to .env');

        return true;
    }

    private function recordPool()
    {
        $pool = $this->option('pool');

        if (! $pool) {
            return;
        }

        $contents = $this->envContents();

        if (EnvFile::value($contents, 'ENVCRYPT_POOL') === $pool) {
            $this->line('  unchanged  ENVCRYPT_POOL');

            return;
        }

        $this->backups()->writeEnv(EnvFile::withValueSet($contents, 'ENVCRYPT_POOL', $pool));
        $this->line('  wrote      ENVCRYPT_POOL=' . $pool . ' to .env');
    }

    private function publishConfig()
    {
        $path = config_path('envcrypt.php');

        if (is_file($path) && ! $this->option('force')) {
            $this->line('  unchanged  config/envcrypt.php');

            return;
        }

        $this->publishTag('envcrypt-config');

        $this->line(is_file($path)
            ? '  wrote      config/envcrypt.php'
            : '  FAILED to publish config/envcrypt.php');
    }

    /**
     * The standalone tool is what works when the application will not boot -
     * which is exactly the situation a wrong DB_PASSWORD creates.
     */
    private function publishTool()
    {
        if ($this->option('no-tool')) {
            $this->line('  skipped    storage/tools/envcrypt.php (--no-tool)');

            return;
        }

        $path = storage_path('tools/envcrypt.php');

        if (is_file($path) && ! $this->option('force')) {
            $this->line('  unchanged  storage/tools/envcrypt.php');

            return;
        }

        $this->publishTag('envcrypt-tool');

        $this->line(is_file($path)
            ? '  wrote      storage/tools/envcrypt.php'
            : '  FAILED to publish storage/tools/envcrypt.php');
    }

    private function publishTag($tag)
    {
        $arguments = ['--tag' => $tag];

        if ($this->option('force')) {
            $arguments['--force'] = true;
        }

        $this->callSilent('vendor:publish', $arguments);
    }

    /* ------------------------------------------------------------------ */
    /* The secret                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Make sure a secret exists, without ever replacing one that does.
     *
     * Generating over a live secret is the one unrecoverable action available
     * here: every value already encrypted under the old one becomes
     * permanently unreadable. So an existing secret is always kept, and
     * changing it is db:key-rotate's job, which re-encrypts as it goes.
     */
    private function ensureSecret($variable)
    {
        $this->heading('Secret');

        if (EnvCryptSecret::onWindows() && EnvCryptSecret::current($this->poolOption()) !== null) {
            $this->line('  unchanged  a secret is already stored - keeping it');

            return true;
        }

        // A developer machine reaches this with the secret in .env, which
        // EnvCrypt accepts only while APP_ENV is local. Asking such a machine
        // for an elevated prompt it does not need would be an invented
        // obstacle, so what actually matters is asked instead: can this
        // process encrypt?
        if ($this->secretIsUsable()) {
            $this->line('  ok         a usable secret is already available to this process');

            return true;
        }

        if (! EnvCryptSecret::onWindows()) {
            $this->line('  Not Windows - store ' . $variable . ' through systemd, the web');
            $this->line('  server\'s environment, or your secret manager, then re-run.');

            return false;
        }

        if ($this->option('no-keygen')) {
            $this->line('  skipped    no secret stored (--no-keygen)');

            return false;
        }

        if (! $this->canAsk()) {
            $this->line('  skipped    no secret stored - nothing can be created unattended,');
            $this->line('             because storing it needs administrator consent.');

            return false;
        }

        $this->line('  No secret is stored for this project yet.');
        $this->line('');

        // Try in this process first. An already-elevated terminal succeeds
        // here and never sees a UAC dialog at all.
        $arguments = $this->poolOption() ? ['--pool' => $this->poolOption()] : [];

        if ($this->callSilent('db:keygen', $arguments) === 0
            && EnvCryptSecret::current($this->poolOption()) !== null) {
            $this->line('  stored     ' . $variable . ' (this prompt was already elevated)');
            $this->warnAboutRestart();

            return true;
        }

        // It needs administrator rights, and a process cannot elevate itself.
        // Rather than stopping with instructions, ask Windows to run that one
        // command elevated - which is what raises the consent dialog.
        if (! Elevation::available()) {
            $this->line('  Could not store the secret, and cannot request elevation here.');
            $this->line('  Run "php artisan db:keygen" from an elevated prompt, then re-run this.');

            return false;
        }

        $this->line('  Storing it writes to HKLM, which needs administrator rights.');
        $this->line('  Windows will show a consent dialog for that one command only.');
        $this->line('');

        if (! $this->confirm('Request administrator access and store the secret now?', true)) {
            $this->line('  Skipped. Nothing can be encrypted until a secret exists.');

            return false;
        }

        Elevation::runArtisan('db:keygen' . ($this->poolOption() ? ' --pool=' . $this->poolOption() : ''), base_path());

        // Verified by reading the store back, never by trusting the exit code:
        // the elevated process is a different process with its own console.
        if (EnvCryptSecret::current($this->poolOption()) === null) {
            $this->line('');
            $this->warn('  No secret was stored - the dialog was declined, or the write failed.');
            $this->line('  Run "php artisan db:keygen" from an elevated prompt to see why.');

            return false;
        }

        $this->line('  stored     ' . $variable);
        $this->warnAboutRestart();

        return true;
    }

    /**
     * A machine-wide variable reaches IIS only after WAS restarts, which is
     * why this is said at the moment the secret is created rather than at the
     * end, where it would be read as advice about the encryption.
     */
    private function warnAboutRestart()
    {
        $this->line('');
        $this->line('  Back this secret up off the machine. It is the only copy, and losing');
        $this->line('  it makes an encrypted password unrecoverable.');

        if (! $this->poolOption()) {
            $this->line('');
            $this->line('  IIS will not see it until "iisreset" - a pool recycle is not enough');
            $this->line('  for a machine-wide variable. Run that yourself when it suits you.');
        }
    }

    /** Can this process encrypt at all - development fallback included? */
    private function secretIsUsable()
    {
        try {
            EnvCrypt::encrypt('probe', $this->authoritativeSecret($this->poolOption()));

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Migration                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Encryption happens only when a human is present to review it. A
     * non-interactive run - a deploy script, a Composer hook, CI - sets the
     * project up and stops, because silently rewriting credentials is not
     * something an unattended process should be able to do.
     */
    private function shouldEncrypt($secretReady)
    {
        if ($this->option('no-encrypt') || ! $secretReady) {
            return false;
        }

        return $this->canAsk();
    }

    private function migrate($variable)
    {
        $this->heading('Encrypting');

        $connections = $this->connectionKeys();
        $selected = array_keys($connections->keys());

        if ($selected === []) {
            $this->line('No database connection password was found in .env.');
            $this->line('Nothing to encrypt. If that is wrong, run:');
            $this->line('  php artisan db:password-encrypt-all --by-name');

            $this->printRemainingSteps($variable, true);

            return 0;
        }

        // The review and the one confirmation must reach the same terminal the
        // rest of the questions did - which is not this command's STDIN when
        // Composer started it.
        $io = $this->console !== null ? new TerminalIo($this->console) : new ConsoleIo($this);

        $migrator = new Migrator($io, base_path(), $this->poolOption());
        $migrator->useCandidates($selected, $this->otherPasswordKeys($selected));

        if ($migrator->encryptAll(false) !== 0) {
            return 1;
        }

        $backup = $migrator->backupPath();

        if ($backup === null) {
            // Nothing was encrypted - cancelled, or already done.
            $this->printRemainingSteps($variable, true);

            return 0;
        }

        $this->refreshAndCheck();
        $this->confirmAndCleanUp($backup);

        return 0;
    }

    /**
     * The two checks that are safe to run unattended. Neither touches .env.
     */
    private function refreshAndCheck()
    {
        $this->heading('Checking');

        $this->call('config:clear');
        $this->call('db:secret-check');
    }

    /**
     * The backup holds every plaintext password this project had, so it is not
     * a file to forget on a server. But deleting it before the application is
     * known to work would remove the rollback, so the question is asked in
     * that order.
     */
    private function confirmAndCleanUp($backup)
    {
        $this->heading('Restart, then test');
        $this->line('The web server still holds the old environment. Restart it yourself:');
        $this->line('');
        $this->line($this->poolOption()
            ? '  the application pool was recycled for you when the secret was stored'
            : '  iisreset          (restarts every site on this server - your call when)');
        $this->line('');
        $this->line('  php artisan queue:restart   for queue workers');
        $this->line('');
        $this->line('Then load a page that queries the database. Test it in a BROWSER, not');
        $this->line('only here - the CLI and the web server have separate environments, and');
        $this->line('that difference is the thing worth checking.');
        $this->line('');

        if (! $this->confirm('Is the application working?', false)) {
            $this->line('');
            $this->warn('Keeping the backup: ' . $backup);
            $this->line('');
            $this->line('To put the old .env back:');
            $this->line('  php artisan envcrypt:restore');
            $this->line('');
            $this->line('Then "php artisan config:clear". Your database passwords were never');
            $this->line('changed, so there is nothing to undo on the database side.');

            return;
        }

        $this->line('');
        $this->line('The backup contains every password in PLAINTEXT:');
        $this->line('  ' . $backup);
        $this->line('');

        $choice = $this->choice(
            'What should happen to it?',
            ['delete' => 'Delete it (recommended)', 'keep' => 'Keep it where it is', 'move' => 'Move it somewhere else'],
            'delete'
        );

        if ($choice === 'delete' || $choice === 'Delete it (recommended)') {
            $this->line(@unlink($backup)
                ? 'Deleted.'
                : 'Could not delete it - remove ' . $backup . ' by hand.');

            return;
        }

        if ($choice === 'move' || $choice === 'Move it somewhere else') {
            $destination = (string) $this->ask('Move it to (full path)');

            if ($destination !== '' && @rename($backup, $destination)) {
                $this->line('Moved to ' . $destination);

                return;
            }

            $this->warn('Could not move it. It is still at ' . $backup);

            return;
        }

        $this->warn('Kept at ' . $backup . ' - it holds plaintext passwords.');
        $this->line('Move it off this server, or delete it, once you no longer need it.');
    }

    /* ------------------------------------------------------------------ */

    private function printRemainingSteps($variable, $secretReady)
    {
        $pool = $this->poolOption();
        $poolOption = $pool ? ' --pool="' . $pool . '"' : '';

        $this->heading('Next steps');

        $step = 1;

        if (! $secretReady) {
            $this->line($step++ . '. Store the secret, from an ELEVATED prompt:');
            $this->line('     php artisan db:keygen' . $poolOption);
            $this->line('');

            if (! $pool) {
                $this->line('   Then "iisreset" - WAS reads the machine environment when it starts,');
                $this->line('   so recycling the pool is NOT enough for a machine-wide variable.');
            }

            $this->line('');
            $this->line('   Back the secret up off this machine. It is the only copy, and losing');
            $this->line('   it makes an encrypted password unrecoverable.');
            $this->line('');
        }

        $this->line($step++ . '. Encrypt the existing password(s) - reviewed, and confirmed by you:');
        $this->line('     php artisan db:password-encrypt-all');
        $this->line('');
        $this->line($step++ . '. Check it:');
        $this->line('     php artisan config:clear');
        $this->line('     php artisan db:secret-check');
        $this->line('');
        $this->line($step . '. Restart the web server yourself (iisreset, or recycle the pool),');
        $this->line('   then load a page that queries the database in a browser.');
        $this->line('');
        $this->line('Leave config/database.php exactly as Laravel ships it. Decryption happens');
        $this->line('at connection time, which is what keeps plaintext out of the config cache.');
        $this->line('');
        $this->line('The secret lives in ' . $variable . ', in the Windows registry or on an');
        $this->line('application pool - never in .env on a production server.');
        $this->line('');
        $this->line('Before removing this package, run "php artisan envcrypt:uninstall" - it');
        $this->line('puts the plaintext passwords back, so the application keeps working.');
    }

    /** A short, uppercase identifier, safe as part of a variable name. */
    private function normalise($value)
    {
        return trim(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $value)), '_');
    }

    private function heading($text)
    {
        $this->line('');
        $this->line($text);
        $this->line(str_repeat('-', strlen($text)));
    }
}
