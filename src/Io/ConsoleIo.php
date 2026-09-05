<?php

namespace Tusharb\EnvCrypt\Io;

use Illuminate\Console\Command;

/**
 * The same interaction through an artisan command, so the migration reads and
 * writes exactly as the rest of the command suite does.
 */
final class ConsoleIo implements Io
{
    /** @var \Illuminate\Console\Command */
    private $command;

    public function __construct(Command $command)
    {
        $this->command = $command;
    }

    public function write($line = '')
    {
        $this->command->getOutput()->writeln($line);
    }

    public function error($line)
    {
        $this->command->error($line);
    }

    public function ask($label)
    {
        // Symfony returns null at end of input when the question is not
        // required, which is exactly the distinction this interface keeps.
        $answer = $this->command->ask(rtrim($label, ' :>'), null);

        return $answer === null ? null : (string) $answer;
    }

    public function confirm($question, $default = false)
    {
        return (bool) $this->command->confirm($question, $default);
    }

    public function secret($label)
    {
        return (string) $this->command->secret(rtrim($label, ' :'));
    }
}
