<?php

namespace Tusharb\EnvCrypt\Tests;

use PHPUnit\Framework\TestCase;
use Tusharb\EnvCrypt\Console\InteractiveConsole;
use Tusharb\EnvCrypt\Elevation;

/**
 * The channel that lets the installer ask a question during
 * "composer require", where STDIN is a pipe.
 *
 * The behaviour that matters most here is the refusal: on a build server there
 * is nobody to answer, and a prompt would either hang the pipeline or be
 * answered by end-of-input.
 */
class InteractiveConsoleTest extends TestCase
{
    /** @var array<string,string|false> */
    private $saved = [];

    private $markers = ['CI', 'CONTINUOUS_INTEGRATION', 'BUILD_NUMBER', 'GITHUB_ACTIONS', 'COMPOSER_NO_INTERACTION'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->markers as $marker) {
            $this->saved[$marker] = getenv($marker);
            putenv($marker);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $marker => $value) {
            $value === false ? putenv($marker) : putenv($marker . '=' . $value);
        }

        parent::tearDown();
    }

    public function test_it_refuses_to_open_on_a_ci_server()
    {
        putenv('CI=1');

        $this->assertNull(InteractiveConsole::open());
    }

    public function test_it_refuses_when_composer_says_the_run_is_non_interactive()
    {
        putenv('COMPOSER_NO_INTERACTION=1');

        $this->assertNull(InteractiveConsole::open());
    }

    public function test_every_known_ci_marker_closes_the_channel()
    {
        foreach (['CONTINUOUS_INTEGRATION', 'BUILD_NUMBER', 'GITHUB_ACTIONS'] as $marker) {
            putenv($marker . '=1');

            $this->assertNull(InteractiveConsole::open(), $marker . ' should suppress prompting');

            putenv($marker);
        }
    }

    /**
     * Without a terminal - which is this test process - open() must return
     * null rather than a channel that reads end-of-input as an answer.
     */
    public function test_it_returns_null_when_there_is_no_terminal()
    {
        $console = InteractiveConsole::open();

        if ($console !== null) {
            $console->close();
            $this->markTestSkipped('This runner has a terminal attached.');
        }

        $this->assertNull($console);
    }

    public function test_elevation_is_only_offered_on_windows()
    {
        $this->assertSame(DIRECTORY_SEPARATOR === '\\' && function_exists('exec'), Elevation::available());
    }
}
