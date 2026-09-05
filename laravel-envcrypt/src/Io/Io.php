<?php

namespace Npav\EnvCrypt\Io;

/**
 * The small slice of terminal interaction the migrator needs.
 *
 * It exists so one implementation of the migration can serve both the artisan
 * command and the standalone script - the latter running with no Laravel and
 * no Composer autoloader, where Illuminate's console helpers do not exist.
 */
interface Io
{
    public function write($line = '');

    public function error($line);

    /** A line of input, or null at end of input. EOF is never consent. */
    public function ask($label);

    public function confirm($question, $default = false);

    /** A password, read without echoing it. */
    public function secret($label);
}
