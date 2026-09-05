<?php

namespace Tusharb\EnvCrypt\Console;

/**
 * A way to ask a question when STDIN is not the terminal.
 *
 * Composer runs "php artisan package:discover" with the child's standard
 * handles wired to pipes, so STDIN carries nothing. The console is still
 * attached to that process, and POSIX exposes it as /dev/tty - opening that
 * reaches the real terminal whatever STDIN was pointed at.
 *
 * WINDOWS DOES NOT WORK THIS WAY, and it was measured rather than assumed. In
 * a process with a console but a redirected STDIN - Composer's exact shape:
 *
 *   fopen('CONIN$', 'r')   fails
 *   fopen('CONIN$', 'r+')  fails
 *   fopen('CONIN$', 'w+')  opens, but fread() returns '' immediately and
 *                          never blocks, so no keystroke can be read
 *
 * The same probe in a console WITHOUT a redirected STDIN blocks correctly on
 * the read. So PHP can write to the Windows console while STDIN is redirected,
 * but cannot read from it, and open() returns null there.
 *
 * That is not a fallback dressed up as a feature: null means the caller must do
 * the unattended, non-destructive thing - set the project up and encrypt
 * nothing. Reaching a Windows terminal from inside Composer needs a Composer
 * plugin, which runs in Composer's own process and uses its IO.
 */
final class InteractiveConsole
{
    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    /** @var bool whether the handles are ours to close */
    private $owned;

    private function __construct($in, $out, $owned)
    {
        $this->in = $in;
        $this->out = $out;
        $this->owned = $owned;
    }

    /**
     * A channel to the terminal, or null when there is no terminal to reach.
     *
     * @return self|null
     */
    public static function open()
    {
        // A build server must never be prompted: it would either hang or,
        // worse, answer for itself.
        if (self::looksAutomated()) {
            return null;
        }

        // The ordinary case - somebody ran artisan directly.
        if (defined('STDIN') && defined('STDOUT')
            && function_exists('stream_isatty') && @stream_isatty(STDIN)) {
            return new self(STDIN, STDOUT, false);
        }

        // Windows is excluded deliberately, not for want of trying: with STDIN
        // redirected the console input buffer cannot be read (see the class
        // comment), so there is nothing to gain. Attempting it also risks
        // real harm - fopen('CONOUT$', 'w') on a machine with no console
        // CREATES A FILE of that name in the working directory, which is how
        // this was found.
        if (DIRECTORY_SEPARATOR === '\\') {
            return null;
        }

        $in = @fopen('/dev/tty', 'r');

        if (! is_resource($in)) {
            return null;
        }

        $out = @fopen('/dev/tty', 'w');

        if (! is_resource($out)) {
            fclose($in);

            return null;
        }

        return new self($in, $out, true);
    }

    /**
     * Markers set by every mainstream CI system, plus Composer's own
     * non-interactive flag - which lives on the parent's command line, not on
     * the artisan child's, so it has to be read from the environment Composer
     * exports rather than from $argv.
     */
    private static function looksAutomated()
    {
        foreach (array('CI', 'CONTINUOUS_INTEGRATION', 'BUILD_NUMBER', 'GITHUB_ACTIONS', 'GITLAB_CI', 'JENKINS_URL', 'TEAMCITY_VERSION') as $marker) {
            if (getenv($marker)) {
                return true;
            }
        }

        // Composer sets this for "composer install --no-interaction" and for
        // any run it considers non-interactive.
        return getenv('COMPOSER_NO_INTERACTION') === '1';
    }

    public function write($line = '')
    {
        @fwrite($this->out, $line . PHP_EOL);
    }

    /**
     * Ask, and return the answer. Null at end of input - which must never be
     * read as agreement.
     */
    public function ask($question, $default = null)
    {
        @fwrite($this->out, $question . ($default === null ? '' : ' [' . $default . ']') . ': ');

        $line = fgets($this->in);

        if ($line === false) {
            @fwrite($this->out, PHP_EOL);

            return null;
        }

        $answer = trim(ltrim($line, "\xEF\xBB\xBF"));

        return $answer === '' ? $default : $answer;
    }

    public function confirm($question, $default = false)
    {
        while (true) {
            @fwrite($this->out, $question . ($default ? ' [Y/n] ' : ' [y/N] '));

            $line = fgets($this->in);

            // End of input is not consent, whatever the default says.
            if ($line === false) {
                @fwrite($this->out, PHP_EOL);

                return false;
            }

            $answer = strtolower(trim($line));

            if ($answer === '') {
                return (bool) $default;
            }

            if ($answer === 'y' || $answer === 'yes') {
                return true;
            }

            if ($answer === 'n' || $answer === 'no') {
                return false;
            }

            $this->write('Please answer y or n.');
        }
    }

    /** @param string[] $choices */
    public function choice($question, array $choices, $default = null)
    {
        $this->write($question);

        foreach ($choices as $choice) {
            $this->write('  ' . $choice);
        }

        while (true) {
            $answer = $this->ask('>', $default);

            if ($answer === null) {
                return $default;
            }

            foreach ($choices as $choice) {
                if (strcasecmp($answer, $choice) === 0) {
                    return $choice;
                }
            }

            $this->write('Please answer with one of: ' . implode(', ', $choices));
        }
    }

    public function close()
    {
        if (! $this->owned) {
            return;
        }

        if (is_resource($this->in)) {
            fclose($this->in);
        }

        if (is_resource($this->out)) {
            fclose($this->out);
        }
    }
}
