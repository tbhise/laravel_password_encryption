<?php

namespace Tusharb\EnvCrypt\Io;

use Tusharb\EnvCrypt\Console\InteractiveConsole;

/**
 * The migrator's review and confirmation, driven through a console channel
 * rather than through the command's own input.
 *
 * Needed when the installer runs inside "composer require": the command's
 * STDIN is a pipe, so Symfony's question helper would read end-of-input and
 * silently take the default. Everything the operator is asked - the field
 * list, and the one encryption confirmation - goes through here instead.
 */
final class TerminalIo implements Io
{
    /** @var \Tusharb\EnvCrypt\Console\InteractiveConsole */
    private $console;

    public function __construct(InteractiveConsole $console)
    {
        $this->console = $console;
    }

    public function write($line = '')
    {
        $this->console->write($line);
    }

    public function error($line)
    {
        $this->console->write($line);
    }

    public function ask($label)
    {
        return $this->console->ask(rtrim($label, ' :>'));
    }

    public function confirm($question, $default = false)
    {
        return $this->console->confirm($question, $default);
    }

    /**
     * Not reachable from the installer - encryption reads its plaintext from
     * .env, never from a prompt. Deliberately refuses rather than echoing a
     * password to a terminal it cannot switch the echo off on.
     */
    public function secret($label)
    {
        return '';
    }
}
