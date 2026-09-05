<?php

namespace Tusharb\EnvCrypt\Tests;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tusharb\EnvCrypt\Io\ConsoleIo;

/**
 * A regression test for a real bug: ConsoleIo::ask() passed a null default to
 * Symfony's question helper, so a person pressing Enter on a genuine question
 * and a run with nobody there to answer both came back as null - the two were
 * indistinguishable. That made "[Enter] confirm as-is", printed right above
 * the prompt, actually ABORT instead of confirm.
 *
 * The reason 77 other tests missed this: Laravel's artisan()/expectsQuestion()
 * test helper fakes the entire IO layer and never invokes Symfony's real
 * QuestionHelper, so it cannot reproduce a default-value bug that lives inside
 * that helper. This test drives a real Command::run() against a real,
 * streamed Symfony input instead.
 */
class ConsoleIoAskTest extends TestCase
{
    /** Run a command that does nothing but ConsoleIo::ask('> '), feeding $input as the stream. */
    private function askWithStream($input)
    {
        $command = new class extends Command {
            protected $signature = 'test:ask-probe';

            public $result = 'UNSET';

            public function handle()
            {
                $this->result = (new ConsoleIo($this))->ask('> ');
            }
        };

        $command->setLaravel($this->app);

        // A real file, not php://memory: PHP's own EOF signalling on a
        // memory stream does not behave like a genuinely closed pipe, which
        // is what a real "nobody is there to answer" run looks like.
        $path = tempnam(sys_get_temp_dir(), 'envcrypt-ask-test');
        file_put_contents($path, $input);
        $stream = fopen($path, 'r');

        $symfonyInput = new ArrayInput([]);
        $symfonyInput->setStream($stream);

        $command->run($symfonyInput, new BufferedOutput());

        fclose($stream);
        unlink($path);

        return $command->result;
    }

    /**
     * THE regression. A real terminal, a real question, and the operator
     * simply pressing Enter - this must read as "confirm as-is", never as
     * "no answer, abort".
     */
    public function test_a_blank_enter_is_a_real_answer_not_a_refusal()
    {
        $this->assertSame('', $this->askWithStream("\n"));
    }

    public function test_typed_text_is_returned_as_is()
    {
        $this->assertSame('c', $this->askWithStream("c\n"));
    }

    /**
     * A stream that runs out before an answer arrives is genuinely different
     * from a blank Enter, and must still resolve to the "no answer" sentinel
     * rather than throwing or silently confirming.
     */
    public function test_a_stream_with_nothing_in_it_returns_null()
    {
        $this->assertNull($this->askWithStream(''));
    }
}
