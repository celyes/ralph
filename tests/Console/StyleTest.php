<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\ColourChoice;
use Celyes\RalphPhp\Console\Style;
use Celyes\RalphPhp\Log\Emphasis;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\StopKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The palette the package writes its own lines in.
 *
 * Two properties carry most of the risk and are asserted hardest. **Plain
 * styling must change nothing**, because that is what makes it safe to style
 * where a line is composed: a redirected report, a `--json` document and the
 * lines the rest of this suite reads back all go through these methods with
 * colour off. And **a column must be as wide as it looks**, because padding a
 * string that already carries escape sequences is the one mistake in here that
 * produces a visibly broken table rather than a wrong colour.
 */
#[CoversClass(Style::class)]
final class StyleTest extends TestCase
{
    /**
     * Every styling method, by the name it is called by.
     *
     * @return array<string, array{string}>
     */
    public static function styles(): array
    {
        return [
            'heading' => ['heading'],
            'label' => ['label'],
            'muted' => ['muted'],
            'identifier' => ['identifier'],
            'reasoning' => ['reasoning'],
            'result' => ['result'],
            'code' => ['code'],
            'pass' => ['pass'],
            'warn' => ['warn'],
            'fail' => ['fail'],
            'alert' => ['alert'],
        ];
    }

    #[Test]
    #[DataProvider('styles')]
    public function plain_styling_returns_the_text_unchanged(string $method): void
    {
        $style = Style::plain();

        self::assertFalse($style->isEnabled());
        self::assertSame('committed b4e9105', $style->{$method}('committed b4e9105'));
    }

    #[Test]
    #[DataProvider('styles')]
    public function coloured_styling_wraps_the_text_and_closes_the_sequence(string $method): void
    {
        $styled = Style::coloured()->{$method}('committed');

        self::assertStringStartsWith("\033[", $styled);
        self::assertStringEndsWith("\033[0m", $styled);

        // The text itself is untouched in the middle, which is what lets a
        // caller style a value a user will copy out of the output.
        self::assertStringContainsString('committed', $styled);
        self::assertSame('committed', self::withoutSequences($styled));
    }

    /**
     * A sequence around nothing is invisible and still ends up in whatever the
     * output was redirected to.
     */
    #[Test]
    #[DataProvider('styles')]
    public function an_empty_string_is_left_alone(string $method): void
    {
        self::assertSame('', Style::coloured()->{$method}(''));
    }

    #[Test]
    public function a_column_is_as_wide_as_it_looks(): void
    {
        $style = Style::coloured();

        $column = $style->column('pass', 11, $style->pass(...));

        self::assertNotSame($column, self::withoutSequences($column));
        self::assertSame(11, strlen(self::withoutSequences($column)));
        self::assertSame('pass       ', self::withoutSequences($column));
    }

    /**
     * Padding is not truncation: a state that is wider than its column pushes
     * the rest of the row along rather than losing characters, which is what
     * the plain table already did.
     */
    #[Test]
    public function a_column_narrower_than_its_text_keeps_all_of_it(): void
    {
        $style = Style::plain();

        self::assertSame('done (verified)', $style->column('done (verified)', 11, $style->pass(...)));
    }

    #[Test]
    public function a_states_colour_is_the_same_wherever_it_is_printed(): void
    {
        $style = Style::coloured();

        $completed = $style->state(LifecycleState::Completed, 'completed');
        $failed = $style->state(LifecycleState::Failed, 'failed');
        $blocked = $style->state(LifecycleState::Blocked, 'blocked');
        $interrupted = $style->state(LifecycleState::Interrupted, 'interrupted');
        $pending = $style->state(LifecycleState::Pending, 'pending');

        self::assertSame($style->pass('completed'), $completed);
        self::assertSame($style->fail('failed'), $failed);
        self::assertSame($style->warn('blocked'), $blocked);
        self::assertSame($style->warn('interrupted'), $interrupted);
        self::assertSame($style->muted('pending'), $pending);

        // The four readings a user acts on differently are four different
        // colours, which is the only reason to colour them at all.
        self::assertCount(4, array_unique([$completed, $failed, $blocked, $pending]));
    }

    /**
     * The weights an agent's session is read in.
     *
     * What matters is the hierarchy rather than the particular codes: the
     * agent's answer has to be the heaviest thing on the screen, its reasoning
     * the lightest thing that is still text, and the two must not be the same
     * colour — an hour of a session scrolls past, and the half-sentence that
     * says what was decided has to be findable in it.
     */
    #[Test]
    public function an_agents_reasoning_and_an_agents_answer_do_not_read_alike(): void
    {
        $style = Style::coloured();

        self::assertSame($style->reasoning('x'), $style->emphasis(Emphasis::Thinking, 'x'));
        self::assertSame($style->result('x'), $style->emphasis(Emphasis::Result, 'x'));
        self::assertSame($style->code('x'), $style->emphasis(Emphasis::Code, 'x'));
        self::assertSame($style->muted('x'), $style->emphasis(Emphasis::Frame, 'x'));
        self::assertSame($style->identifier('x'), $style->emphasis(Emphasis::Activity, 'x'));
        self::assertSame($style->pass('x'), $style->emphasis(Emphasis::Success, 'x'));
        self::assertSame($style->warn('x'), $style->emphasis(Emphasis::Warning, 'x'));
        self::assertSame($style->fail('x'), $style->emphasis(Emphasis::Failure, 'x'));

        self::assertNotSame(
            $style->emphasis(Emphasis::Thinking, 'x'),
            $style->emphasis(Emphasis::Result, 'x'),
        );

        // A worker's own bytes are a copy of what another program printed,
        // escapes and all, and are not this package's to repaint — so the one
        // weight that means "not ours" is the identity even with colour on.
        self::assertSame('x', $style->emphasis(Emphasis::Plain, 'x'));
        self::assertSame("\033[1malready bold\033[0m", $style->emphasis(Emphasis::Plain, "\033[1malready bold\033[0m"));
    }

    /**
     * A run's ending and the status of the process that reported it must not
     * disagree: the groups here are the groups {@see \Celyes\RalphPhp\Console\ExitCode::forStop()}
     * makes.
     */
    #[Test]
    public function a_runs_ending_reads_as_the_kind_of_ending_it_was(): void
    {
        $style = Style::coloured();

        self::assertSame($style->pass('done'), $style->stop(StopKind::Finished, 'done'));
        self::assertSame($style->fail('done'), $style->stop(StopKind::Attempts, 'done'));
        self::assertSame($style->fail('done'), $style->stop(StopKind::Failed, 'done'));
        self::assertSame($style->warn('done'), $style->stop(StopKind::Decision, 'done'));
        self::assertSame($style->warn('done'), $style->stop(StopKind::Limit, 'done'));
        self::assertSame($style->warn('done'), $style->stop(StopKind::Interrupted, 'done'));
    }

    #[Test]
    public function help_text_is_untouched_when_colour_is_off(): void
    {
        $lines = [
            'Usage: ralph run [--agent NAME]',
            '',
            'Options:',
            '  --agent NAME  Use this agent for the run.',
        ];

        self::assertSame($lines, Style::plain()->helpText($lines));
    }

    #[Test]
    public function help_text_has_its_headings_and_the_names_it_documents_marked(): void
    {
        $styled = Style::coloured()->helpText([
            'Usage: ralph run [--agent NAME]',
            'Options:',
            '  --agent NAME  Use this agent for the run.',
            '  -h, --help    Display this help.',
            '  run        Process pending tasks within the configured limits.',
            '  124  a configured limit stopped the run',
        ]);

        $style = Style::coloured();

        self::assertSame($style->heading('Usage:') . ' ralph run [--agent NAME]', $styled[0]);
        self::assertSame($style->heading('Options:'), $styled[1]);
        self::assertSame('  ' . $style->identifier('--agent NAME') . '  Use this agent for the run.', $styled[2]);
        self::assertSame('  ' . $style->identifier('-h, --help') . '    Display this help.', $styled[3]);
        self::assertSame(
            '  ' . $style->identifier('run') . '        Process pending tasks within the configured limits.',
            $styled[4],
        );
        self::assertSame('  ' . $style->identifier('124') . '  a configured limit stopped the run', $styled[5]);
    }

    /**
     * The pass is shape-based, so what it must not do is reach into the prose
     * that makes up most of a command's help.
     */
    #[Test]
    public function help_text_leaves_prose_alone(): void
    {
        $prose = [
            'Takes the checklist\'s pending tasks in file order, one at a time.',
            '  a task whose checks fail is attempted again while its limit holds',
            '    Worker output is written to .ralph/runtime/logs/, one file per attempt.',
            'The exit status says how the run ended:',
            '',
        ];

        self::assertSame($prose, Style::coloured()->helpText($prose));
    }

    #[Test]
    public function styling_follows_the_choice_the_stream_was_given(): void
    {
        $stream = self::memoryStream();

        // An in-memory stream is not a terminal, which is what every test in
        // this suite relies on: driving a command gives plain text.
        self::assertFalse(Style::on($stream, ColourChoice::Auto)->isEnabled());
        self::assertTrue(Style::on($stream, ColourChoice::Always)->isEnabled());
        self::assertFalse(Style::on($stream, ColourChoice::Never)->isEnabled());

        fclose($stream);
    }

    /**
     * Everything but the escape sequences.
     */
    private static function withoutSequences(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    /**
     * @return resource
     */
    private static function memoryStream(): mixed
    {
        $stream = fopen('php://memory', 'rb+');

        if ($stream === false) {
            throw new RuntimeException('Unable to open an in-memory stream.');
        }

        return $stream;
    }
}
