<?php

namespace Npav\EnvCrypt;

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
