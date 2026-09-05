<?php

namespace Tusharb\EnvCrypt\Io;

/**
 * STDIN and STDOUT directly, for the standalone script.
 */
final class PlainIo implements Io
{
    public function write($line = '')
    {
        echo $line . PHP_EOL;
    }

    public function error($line)
    {
        fwrite(STDERR, $line . PHP_EOL);
    }

    /**
     * Read a line. Returns null at end of input.
     *
     * The distinction matters: EOF must never be mistaken for "the operator
     * pressed Enter to accept", or a piped or scheduled invocation would
     * silently confirm a list nobody looked at.
     */
    public function ask($label)
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

    public function confirm($question, $default = false)
    {
        $answer = $this->ask($question . ($default ? ' [Y/n] ' : ' [y/N] '));

        if ($answer === null) {
            return false;
        }

        $answer = strtolower(trim($answer));

        if ($answer === '') {
            return (bool) $default;
        }

        return $answer === 'y' || $answer === 'yes';
    }

    /**
     * Ask for a password without printing it, so it stays out of the
     * scrollback, a screen share, and shell history.
     */
    public function secret($label)
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
}
