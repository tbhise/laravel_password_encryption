<?php

namespace Tusharb\EnvCrypt;

/**
 * Timestamped copies of .env, kept somewhere the web server will not serve.
 *
 * A backup taken before encryption holds every plaintext password the project
 * has, so where it lands matters as much as that it exists:
 *
 *  - under storage/app/, which sits outside the public web root;
 *  - in its own directory carrying a "deny everything" .gitignore, so a
 *    hurried "git add ." cannot commit one;
 *  - 0600 where the platform honours it;
 *  - never overwriting an existing file, because the older copy may be the
 *    only remaining record of a password.
 *
 * Framework-free, so the standalone tool writes backups to the same place as
 * the artisan commands.
 */
final class EnvBackup
{
    /** @var string */
    private $root;

    public function __construct($root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * Where backups live. Under storage/ when this looks like a Laravel
     * project; beside .env otherwise, which is still outside public/.
     */
    public function directory()
    {
        return is_dir($this->root . '/storage')
            ? $this->root . '/storage/app/envcrypt-backups'
            : $this->root . '/.envcrypt-backups';
    }

    public function envPath()
    {
        return $this->root . '/.env';
    }

    /**
     * Copy .env aside. Returns the path written, or null on failure.
     *
     * $label distinguishes why it was taken - "encrypt", "decrypt", "restore" -
     * so a directory listing reads as a history rather than a pile.
     */
    public function create($label = 'backup')
    {
        $source = $this->envPath();

        if (! is_readable($source)) {
            return null;
        }

        $contents = file_get_contents($source);

        if ($contents === false) {
            return null;
        }

        $directory = $this->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return null;
        }

        $this->protect($directory);

        $path = $this->uniquePath($directory, $label);

        if (file_put_contents($path, $contents) === false) {
            return null;
        }

        @chmod($path, 0600);

        return $path;
    }

    /**
     * A name nothing else holds. The counter matters: two runs inside one
     * second must not have the second silently replace the first.
     */
    private function uniquePath($directory, $label)
    {
        $base = $directory . '/env-' . preg_replace('/[^a-z0-9-]/i', '', (string) $label) . '-' . date('Ymd-His');
        $path = $base . '.bak';

        for ($n = 2; file_exists($path); $n++) {
            $path = $base . '-' . $n . '.bak';
        }

        return $path;
    }

    /** Newest first. */
    public function all()
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            return array();
        }

        $found = glob($directory . '/*.bak');

        if ($found === false) {
            return array();
        }

        usort($found, function ($a, $b) {
            $difference = filemtime($b) - filemtime($a);

            return $difference !== 0 ? $difference : strcmp($b, $a);
        });

        return $found;
    }

    public function latest()
    {
        $all = $this->all();

        return $all === array() ? null : $all[0];
    }

    /**
     * Put a backup back. The current .env is itself backed up first, so a
     * restore of the wrong file is not the end of the story.
     */
    public function restore($backup)
    {
        if (! is_readable($backup)) {
            return false;
        }

        $contents = file_get_contents($backup);

        if ($contents === false) {
            return false;
        }

        $this->create('pre-restore');

        return $this->writeEnv($contents);
    }

    /**
     * Replace .env in one step where the platform allows it.
     *
     * A partial write here would leave the application with a truncated .env
     * and no database credentials at all, so the new contents are staged
     * beside it and moved into place. rename() replaces the target on both
     * Windows and POSIX; if the staging write fails, .env is never touched.
     */
    public function writeEnv($contents)
    {
        $target = $this->envPath();
        $temporary = $target . '.envcrypt-tmp';

        if (file_put_contents($temporary, $contents) === false) {
            return false;
        }

        @chmod($temporary, 0600);

        if (@rename($temporary, $target)) {
            return true;
        }

        // Some filesystems and antivirus hooks refuse the replace. Falling
        // back to a direct write is worse, but better than failing with the
        // new contents stranded in a temporary file.
        $written = file_put_contents($target, $contents) !== false;

        @unlink($temporary);

        return $written;
    }

    /** Keep the directory out of git, and out of any directory listing. */
    private function protect($directory)
    {
        $gitignore = $directory . '/.gitignore';

        if (! is_file($gitignore)) {
            file_put_contents($gitignore, "*\n");
        }

        // Belt and braces: storage/ is not under the web root, but a
        // misconfigured server that serves it still finds nothing to index.
        $index = $directory . '/index.html';

        if (! is_file($index)) {
            file_put_contents($index, '');
        }
    }
}
