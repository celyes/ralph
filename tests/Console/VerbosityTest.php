<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\Verbosity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How much of what the package is doing reaches the terminal.
 *
 * The reading is off the raw command line rather than off parsed input, for
 * the reason {@see \Celyes\RalphPhp\Console\ColourChoice} is: parsing can
 * itself fail, and the report of that failure is output this decides the shape
 * of. So the cases below are about tokens, including the ones that are not an
 * option at all.
 */
#[CoversClass(Verbosity::class)]
final class VerbosityTest extends TestCase
{
    /**
     * The default, and the whole point of PROJECT.md section K, item 2: a user
     * who asked for nothing gets the run's own account and not the agent's.
     */
    #[Test]
    public function a_command_line_that_does_not_ask_for_it_is_quiet(): void
    {
        self::assertSame(Verbosity::Quiet, Verbosity::requestedIn([]));
        self::assertSame(Verbosity::Quiet, Verbosity::requestedIn(['run']));
        self::assertSame(Verbosity::Quiet, Verbosity::requestedIn(['logs', 'TASK-8CA4A94A']));
    }

    #[Test]
    public function the_long_form_asks_for_everything(): void
    {
        self::assertSame(Verbosity::Verbose, Verbosity::requestedIn(['run', '--verbose']));
    }

    #[Test]
    public function the_short_form_asks_for_everything(): void
    {
        self::assertSame(Verbosity::Verbose, Verbosity::requestedIn(['run', '-v']));
    }

    /**
     * A bundle is how short options are usually typed, and the position of the
     * letter inside one is not a decision the user is making.
     */
    #[Test]
    public function the_short_form_is_found_inside_a_bundle(): void
    {
        self::assertSame(Verbosity::Verbose, Verbosity::requestedIn(['run', '-nv']));
        self::assertSame(Verbosity::Verbose, Verbosity::requestedIn(['run', '-vn']));
    }

    /**
     * Everything after `--` belongs to no option, so a task's own text cannot
     * turn the terminal on by containing the word.
     */
    #[Test]
    public function what_follows_a_double_dash_is_not_an_option(): void
    {
        self::assertSame(Verbosity::Quiet, Verbosity::requestedIn(['run', '--', '--verbose']));
        self::assertSame(Verbosity::Quiet, Verbosity::requestedIn(['run', '--', '-v']));
    }

    /**
     * And a flag before the separator is still a flag: `--` ends the reading,
     * it does not undo it.
     */
    #[Test]
    public function a_flag_before_a_double_dash_still_counts(): void
    {
        self::assertSame(Verbosity::Verbose, Verbosity::requestedIn(['run', '--verbose', '--', '-v']));
    }

    /**
     * A long option that merely starts with the letters is a different option,
     * and a value that happens to read like one is a value.
     */
    #[Test]
    public function a_token_that_only_looks_like_the_flag_is_not_it(): void
    {
        self::assertSame(Verbosity::Quiet, Verbosity::requestedIn(['run', '--verbosity=2']));
        self::assertSame(Verbosity::Quiet, Verbosity::requestedIn(['retry', 'TASK-V']));
        self::assertSame(Verbosity::Quiet, Verbosity::requestedIn(['run', '--agent', 'very-verbose']));
    }

    #[Test]
    public function only_the_verbose_answer_is_verbose(): void
    {
        self::assertTrue(Verbosity::Verbose->isVerbose());
        self::assertFalse(Verbosity::Quiet->isVerbose());
    }
}
