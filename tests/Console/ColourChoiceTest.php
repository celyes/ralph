<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\ColourChoice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * When the package may colour a stream.
 *
 * The decision has to be right in both directions and the two failures are not
 * symmetrical: colour a stream that is not a terminal and escape sequences end
 * up inside a file somebody is parsing — `ralph status --json` is the case that
 * matters — while refusing colour on a terminal only makes the output duller
 * than it could be. So the cases here are mostly about the first.
 */
#[CoversClass(ColourChoice::class)]
final class ColourChoiceTest extends TestCase
{
    /**
     * The variables these cases set, put back afterwards so one case cannot
     * decide another's answer.
     *
     * @var array<string, string|false>
     */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['NO_COLOR', 'FORCE_COLOR', 'TERM'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
    }

    #[Test]
    public function a_stream_that_is_not_a_terminal_is_not_coloured(): void
    {
        $stream = $this->memoryStream();

        self::assertFalse(ColourChoice::Auto->appliesTo($stream));
    }

    #[Test]
    public function an_explicit_choice_does_not_ask_the_stream(): void
    {
        $stream = $this->memoryStream();

        self::assertTrue(ColourChoice::Always->appliesTo($stream));
        self::assertFalse(ColourChoice::Never->appliesTo($stream));
    }

    #[Test]
    public function a_closed_or_absent_stream_is_not_coloured(): void
    {
        self::assertFalse(ColourChoice::Auto->appliesTo(null));
    }

    #[Test]
    public function force_colour_in_the_environment_colours_a_pipe(): void
    {
        putenv('FORCE_COLOR=1');

        self::assertTrue(ColourChoice::Auto->appliesTo($this->memoryStream()));
    }

    #[Test]
    public function force_colour_set_to_zero_is_not_a_request_for_colour(): void
    {
        putenv('FORCE_COLOR=0');

        self::assertFalse(ColourChoice::Auto->appliesTo($this->memoryStream()));
    }

    /**
     * The no-color.org convention, and the reason it is checked first: of the
     * two readings of a contradiction, the one that cannot make output
     * unreadable is the one that wins.
     */
    #[Test]
    public function no_colour_wins_over_force_colour(): void
    {
        putenv('NO_COLOR=1');
        putenv('FORCE_COLOR=1');

        self::assertFalse(ColourChoice::Auto->appliesTo($this->memoryStream()));
    }

    /**
     * `NO_COLOR= ralph run` is how a shell user turns the variable off for one
     * command, so an empty value is the variable being unset.
     */
    #[Test]
    public function an_empty_no_colour_is_the_variable_being_unset(): void
    {
        putenv('NO_COLOR=');
        putenv('FORCE_COLOR=1');

        self::assertTrue(ColourChoice::Auto->appliesTo($this->memoryStream()));
    }

    #[Test]
    public function a_terminal_that_says_it_cannot_do_this_is_believed(): void
    {
        putenv('TERM=dumb');

        self::assertFalse(ColourChoice::Auto->appliesTo($this->memoryStream()));
    }

    #[Test]
    public function the_command_line_can_ask_for_colour_either_way(): void
    {
        self::assertSame(ColourChoice::Always, ColourChoice::requestedIn(['run', '--color']));
        self::assertSame(ColourChoice::Always, ColourChoice::requestedIn(['run', '--colour']));
        self::assertSame(ColourChoice::Never, ColourChoice::requestedIn(['status', '--no-color']));
        self::assertSame(ColourChoice::Never, ColourChoice::requestedIn(['status', '--no-colour']));
    }

    #[Test]
    public function a_command_line_that_says_nothing_leaves_the_stream_to_answer(): void
    {
        self::assertNull(ColourChoice::requestedIn([]));
        self::assertNull(ColourChoice::requestedIn(['run', '--agent', 'codex']));
    }

    /**
     * Both at once is a mistake, and the output that would report it is the
     * output this decides — so the safe answer is to decide nothing and let the
     * usage error come out in whatever form the stream warrants.
     */
    #[Test]
    public function both_options_together_decide_nothing(): void
    {
        self::assertNull(ColourChoice::requestedIn(['run', '--color', '--no-color']));
    }

    #[Test]
    public function nothing_after_a_double_dash_is_an_option(): void
    {
        self::assertNull(ColourChoice::requestedIn(['run', '--', '--color']));
        self::assertSame(ColourChoice::Always, ColourChoice::requestedIn(['run', '--color', '--', '--no-color']));
    }

    /**
     * @return resource
     */
    private function memoryStream(): mixed
    {
        $stream = fopen('php://memory', 'rb+');

        if ($stream === false) {
            throw new RuntimeException('Unable to open an in-memory stream.');
        }

        return $stream;
    }
}
