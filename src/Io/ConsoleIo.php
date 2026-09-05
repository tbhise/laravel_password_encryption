<?php

namespace Tusharb\EnvCrypt\Io;

use Illuminate\Console\Command;
use Symfony\Component\Console\Exception\MissingInputException;

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
        $question = $question === '' ? 'Choice' : $question;

        // Checked explicitly, rather than asking Symfony with a null default:
        // with a null default, Symfony returns null BOTH when a real person
        // presses Enter on a genuine question (a legitimate "confirm as-is")
        // AND when there is nobody to ask at all - the two are indistinguishable
        // once collapsed onto the same null. That collapse was the bug: it
        // read a plain Enter as "no answer, abort" instead of "accept the
        // list", exactly backwards from what the printed menu promises.
        if ($this->command->hasOption('no-interaction') && $this->command->option('no-interaction')) {
            return null;
        }

        try {
            // '' rather than null: a blank Enter then reads as a genuine,
            // distinct answer - "confirm as-is" - rather than as the sentinel
            // this interface uses for "there is nothing to answer with".
            $answer = $this->command->ask($question, '');
        } catch (MissingInputException $e) {
            // The stream ran out before an answer arrived - a piped script
            // shorter than expected, say. The same "no answer" sentinel
            // applies: this must not be read as consent either.
            return null;
        }

        return (string) $answer;
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
