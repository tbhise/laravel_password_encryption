<?php

namespace Npav\EnvCrypt;

/**
 * Resolves the secret from the store that actually matters, rather than from
 * whatever the terminal inherited.
 *
 * This exists because of a real failure: a window opened before the secret was
 * last changed still holds the old value, and encrypting under it produces a
 * value the web application cannot read.
 *
 * Uses exec() rather than Symfony's Process on purpose: Process needs
 * Composer's autoloader, which the standalone script cannot assume is loaded.
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

        $pattern = "/name='" . preg_quote(EnvCrypt::rootKeyVar(), '/') . "',value='([^']*)'/";

        return preg_match($pattern, substr($output, $at), $m) ? $m[1] : null;
    }

    public static function fromMachine()
    {
        $output = self::run(
            'reg query ' . self::quote(self::HIVE) . ' /v ' . EnvCrypt::rootKeyVar()
        );

        if ($output === null) {
            return null;
        }

        $pattern = '/\s' . preg_quote(EnvCrypt::rootKeyVar(), '/') . '\s+REG_\w+\s+(.*)$/m';

        if (! preg_match($pattern, $output, $m)) {
            return null;
        }

        return trim($m[1]) === '' ? null : trim($m[1]);
    }

    /** Whichever store holds it: the named pool first, then the machine. */
    public static function current($pool = null)
    {
        $secret = $pool ? self::fromPool($pool) : null;

        return $secret === null ? self::fromMachine() : $secret;
    }

    /** True on success, or the failure text. */
    public static function writeToPool($pool, $secret)
    {
        $output = self::run(
            self::quote(self::appcmd()) . ' set config'
            . ' -section:system.applicationHost/applicationPools'
            . ' ' . self::quote(
                "/+[name='" . $pool . "'].environmentVariables."
                . "[name='" . EnvCrypt::rootKeyVar() . "',value='" . $secret . "']"
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
                . "[name='" . EnvCrypt::rootKeyVar() . "']"
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
            'setx /M ' . EnvCrypt::rootKeyVar() . ' ' . self::quote($secret),
            true
        );

        if (self::looksLikeDenial($output) || stripos($output, 'error') !== false) {
            return trim($output);
        }

        // setx silently shortens anything over 1024 characters, which would
        // store a secret that cannot decrypt anything.
        return stripos($output, 'truncat') === false ? true : trim($output);
    }

    public static function clearFromMachine()
    {
        $output = self::run(
            'reg delete ' . self::quote(self::HIVE) . ' /v ' . EnvCrypt::rootKeyVar() . ' /f',
            true
        );

        if (stripos((string) $output, 'unable to find') !== false) {
            return true;
        }

        return self::succeeded($output) ? true : trim((string) $output);
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
        if (! function_exists('exec')) {
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
