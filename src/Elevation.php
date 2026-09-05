<?php

namespace Tusharb\EnvCrypt;

/**
 * Re-runs one artisan command through a UAC prompt.
 *
 * Only the secret's first write needs this: HKLM is administrator-only, and a
 * process cannot elevate itself. Rather than stopping with "open an elevated
 * prompt and run this yourself", the installer asks Windows to start the one
 * command that needs it, which raises the standard consent dialog.
 *
 * The secret never travels on that command line. The elevated process runs
 * db:keygen, which generates the value itself and writes it - so nothing
 * secret is visible in the process list of either side.
 */
final class Elevation
{
    /**
     * Run "php artisan <command>" elevated and wait for it.
     *
     * Returns true when the elevated process was started and exited; that is
     * not proof it succeeded, so callers must verify the effect (read the
     * secret back) rather than trusting this.
     */
    public static function runArtisan($command, $basePath)
    {
        if (! self::available()) {
            return false;
        }

        $php = self::php();

        if ($php === null) {
            return false;
        }

        $arguments = "'artisan','" . str_replace("'", "''", $command) . "'";

        // -Wait so the caller can check the registry immediately afterwards.
        // A hidden window keeps a console from flashing up and vanishing; the
        // verification the caller does is what actually reports success.
        $powershell = 'powershell -NoProfile -NonInteractive -Command "'
            . '$p = Start-Process -FilePath ' . self::quoteForPowerShell($php)
            . ' -ArgumentList ' . $arguments
            . ' -WorkingDirectory ' . self::quoteForPowerShell($basePath)
            . ' -Verb RunAs -WindowStyle Hidden -Wait -PassThru; '
            . 'exit $p.ExitCode"';

        $status = 1;
        $output = array();

        @exec($powershell . ' 2>&1', $output, $status);

        return $status === 0;
    }

    public static function available()
    {
        return DIRECTORY_SEPARATOR === '\\' && function_exists('exec');
    }

    /** The interpreter running now, so the elevated copy is the same one. */
    private static function php()
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_file(PHP_BINARY)) {
            return PHP_BINARY;
        }

        return null;
    }

    /** Single quotes, doubled inside - PowerShell's own escaping. */
    private static function quoteForPowerShell($value)
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
