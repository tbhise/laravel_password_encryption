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
        // The plain tool writes its own prompt characters ("> ", "... : ");
        // artisan draws those itself, so they are stripped. A label that was
        // nothing but punctuation still needs a word, or the question renders
        // blank.
        $question = rtrim($label, ' :>');

        // Symfony returns null at end of input when the question is not
        // required, which is exactly the distinction this interface keeps.
        $answer = $this->command->ask($question === '' ? 'Choice' : $question, null);

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
