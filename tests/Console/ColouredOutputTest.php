<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\ColourChoice;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Console\Style;
use Celyes\RalphPhp\Run\Runner;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Cli;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Colour, through the real binary.
 *
 * {@see StyleTest} and {@see ColourChoiceTest} cover the palette and the
 * decision; this is the wiring between them and an actual invocation, which is
 * where the two mistakes that matter would show up. The first is escape
 * sequences in output that is not for a person — the `--json` document above
 * all — and the second is the loop's own progress lines losing the task
 * headings and outcome markers that make a long run readable.
 *
 * Every invocation here writes to a pipe, so `auto` means no colour and the
 * cases that want it ask for it the two ways a user can: the option and the
 * environment variable.
 */
#[CoversClass(Style::class)]
#[CoversClass(ColourChoice::class)]
#[CoversClass(Output::class)]
#[CoversClass(Runner::class)]
final class ColouredOutputTest extends TestCase
{
    /**
     * A sequence introducer. Enough to tell coloured output from plain,
     * without pinning any case to a particular colour.
     */
    private const string SEQUENCE = "\033[";

    private ?LoopProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->remove();
        $this->project = null;
    }

    #[Test]
    public function a_pipe_gets_no_colour_of_its_own_accord(): void
    {
        $result = Cli::run([], null, ['FORCE_COLOR' => false, 'NO_COLOR' => false]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringNotContainsString(self::SEQUENCE, $result->standardOutput, $result->describe());
    }

    #[Test]
    public function the_option_colours_output_that_is_not_a_terminal(): void
    {
        $result = Cli::run(['--color'], null, ['FORCE_COLOR' => false, 'NO_COLOR' => false]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString(self::SEQUENCE, $result->standardOutput, $result->describe());

        // The text is still all there: colour is added around the words, never
        // instead of them.
        self::assertStringContainsString('ralph <command> [options]', $result->standardOutput, $result->describe());
        self::assertStringContainsString('Exit codes:', $result->standardOutput, $result->describe());
    }

    #[Test]
    public function the_environment_colours_output_that_is_not_a_terminal(): void
    {
        $result = Cli::run([], null, ['FORCE_COLOR' => '1', 'NO_COLOR' => false]);

        self::assertStringContainsString(self::SEQUENCE, $result->standardOutput, $result->describe());
    }

    #[Test]
    public function the_option_overrides_the_environment_in_both_directions(): void
    {
        $plain = Cli::run(['--no-color'], null, ['FORCE_COLOR' => '1', 'NO_COLOR' => false]);
        self::assertStringNotContainsString(self::SEQUENCE, $plain->standardOutput, $plain->describe());

        $coloured = Cli::run(['--color'], null, ['NO_COLOR' => '1', 'FORCE_COLOR' => false]);
        self::assertStringContainsString(self::SEQUENCE, $coloured->standardOutput, $coloured->describe());
    }

    #[Test]
    public function no_colour_in_the_environment_is_honoured(): void
    {
        $result = Cli::run([], null, ['NO_COLOR' => '1', 'FORCE_COLOR' => '1']);

        self::assertStringNotContainsString(self::SEQUENCE, $result->standardOutput, $result->describe());
    }

    /**
     * The regression this is really here for: `ralph status --json | jq` must
     * keep working for a user who has `FORCE_COLOR` exported, because a
     * document with escape sequences in its strings is not JSON any more.
     */
    #[Test]
    public function the_json_document_is_never_coloured(): void
    {
        $project = $this->project();

        $result = $project->ralph(['status', '--json', '--color'], ['FORCE_COLOR' => '1']);

        self::assertStringNotContainsString(self::SEQUENCE, $result->standardOutput, $result->describe());
        self::assertIsArray(json_decode($result->standardOutput, true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function the_status_table_is_coloured_and_stays_aligned(): void
    {
        $project = $this->project();

        $coloured = $project->ralph(['status', '--color']);
        $plain = $project->ralph(['status']);

        self::assertStringContainsString(self::SEQUENCE, $coloured->standardOutput, $coloured->describe());
        self::assertStringNotContainsString(self::SEQUENCE, $plain->standardOutput, $plain->describe());

        // The same report either way, which is what makes the plain form the
        // one the rest of this suite can assert on: taking the sequences back
        // out gives the bytes the plain invocation wrote.
        self::assertSame(
            $plain->standardOutput,
            self::withoutSequences($coloured->standardOutput),
            $coloured->describe(),
        );
    }

    /**
     * A failure is a reason the command could not do what it was asked, and it
     * goes to the other stream — so it is styled by that stream's answer, and
     * the ordinary stream stays empty either way.
     */
    #[Test]
    public function a_failure_is_marked_on_the_stream_it_is_reported_on(): void
    {
        $result = Cli::run(['stats'], null, ['FORCE_COLOR' => '1', 'NO_COLOR' => false]);

        self::assertSame(ExitCode::USAGE, $result->exitCode, $result->describe());
        self::assertSame('', $result->standardOutput, $result->describe());
        self::assertStringContainsString(self::SEQUENCE, $result->errorOutput, $result->describe());

        // The words are the same words, and the hint is still under the
        // message: this marks the report up, it does not rewrite it.
        $plain = self::withoutSequences($result->errorOutput);
        self::assertStringContainsString('ralph: Unknown command "stats".', $plain, $result->describe());
        self::assertStringContainsString('Did you mean "status"?', $plain, $result->describe());
    }

    /**
     * The run's own progress lines: a heading per task, and the outcome marked.
     *
     * The heading is asserted in the plain form, because it is not decoration —
     * a run that printed an agent's thousand lines with nothing over them left
     * a reader to work out whose hour they were looking at.
     */
    #[Test]
    public function a_run_heads_each_task_and_marks_how_it_ended(): void
    {
        $project = LoopProject::create()
            ->withWorker(
                FixtureStep::success(['src/Import.php' => "<?php\n// --dry-run\n"]),
                FixtureStep::success(['README.md' => "Documented.\n"]),
            )
            ->withTasks(['Add a --dry-run option to the import command', 'Document the import command'])
            ->withConfiguration();

        $this->project = $project;

        $plain = $project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $plain->exitCode, $plain->describe());

        // Each task, numbered within the run rather than within the checklist,
        // with its identifier and its description.
        self::assertStringContainsString('[1/2] TASK-', $plain->standardOutput, $plain->describe());
        self::assertStringContainsString(
            'Add a --dry-run option to the import command',
            $plain->standardOutput,
            $plain->describe(),
        );
        self::assertStringContainsString('[2/2] TASK-', $plain->standardOutput, $plain->describe());

        // And a blank line above each heading, so an agent's last line of
        // output and the loop's next step are not one block of text.
        self::assertStringContainsString(PHP_EOL . PHP_EOL . '[1/2] TASK-', $plain->standardOutput);
        self::assertStringContainsString(PHP_EOL . PHP_EOL . '[2/2] TASK-', $plain->standardOutput);
    }

    /**
     * The files stay plain, whatever the terminal is doing.
     *
     * The attempt log is what a reader comes back to and what `ralph logs`
     * prints, and escape sequences in a file somebody is grepping are a cost
     * with no benefit. Styling therefore happens in
     * {@see \Celyes\RalphPhp\Log\TerminalSink} and nowhere nearer the work.
     */
    #[Test]
    public function a_coloured_run_still_writes_plain_logs(): void
    {
        $project = LoopProject::create()
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php
// --dry-run
"]))
            ->withTasks(['Add a --dry-run option to the import command'])
            ->withConfiguration();

        $this->project = $project;

        $run = $project->ralph(['run'], ['FORCE_COLOR' => '1']);

        self::assertSame(ExitCode::SUCCESS, $run->exitCode, $run->describe());
        self::assertStringContainsString(self::SEQUENCE, $run->standardOutput, $run->describe());

        $logs = glob($project->path('.ralph/runtime/logs/*/*')) ?: [];

        self::assertNotSame([], $logs, 'The run was expected to leave at least one log file.');

        foreach ($logs as $log) {
            self::assertStringNotContainsString(
                self::SEQUENCE,
                (string) file_get_contents($log),
                sprintf('%s holds terminal escape sequences.', $log),
            );
        }
    }

    /**
     * The outcome lines of a run that went well and a run that did not are
     * different colours, because they are what a user looks for first.
     */
    #[Test]
    public function a_completed_task_and_a_failed_one_read_differently(): void
    {
        $passing = LoopProject::create()
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n// --dry-run\n"]))
            ->withTasks(['Add a --dry-run option to the import command'])
            ->withConfiguration();

        $this->project = $passing;

        $completed = $passing->ralph(['run'], ['FORCE_COLOR' => '1']);
        self::assertSame(ExitCode::SUCCESS, $completed->exitCode, $completed->describe());

        $style = Style::coloured();
        $outcome = self::lineMatching($completed->standardOutputLines(), '/^TASK-\S+ completed: /');

        self::assertSame($style->pass(self::withoutSequences($outcome)), $outcome);

        $passing->remove();
        $this->project = null;

        $failing = LoopProject::create()
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n// --dry-run\n"]))
            ->withTasks(['Add a --dry-run option to the import command'])
            ->withConfiguration(['checks' => [['name' => 'tests', 'command' => ['false']]]]);

        $this->project = $failing;

        $failed = $failing->ralph(['run'], ['FORCE_COLOR' => '1']);
        self::assertSame(ExitCode::EXECUTION, $failed->exitCode, $failed->describe());

        $outcome = self::lineMatching($failed->standardOutputLines(), '/^TASK-\S+ failed: /');

        self::assertSame($style->fail(self::withoutSequences($outcome)), $outcome);
    }

    /**
     * A project with one run behind it, so `status` has a run row and a task
     * row to print.
     */
    private function project(): LoopProject
    {
        $project = LoopProject::create()
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n// --dry-run\n"]))
            ->withTasks(['Add a --dry-run option to the import command'])
            ->withConfiguration();

        $this->project = $project;

        $run = $project->ralph(['run']);
        self::assertSame(ExitCode::SUCCESS, $run->exitCode, $run->describe());

        return $project;
    }

    /**
     * The one line of this shape, still carrying its sequences.
     *
     * Matched against the plain form, so a case can look for the line a user
     * reads without saying anything about how it is marked up — and matched
     * against the whole line, because a run's notes mention the same words the
     * outcome line is made of.
     *
     * @param list<string> $lines
     */
    private static function lineMatching(array $lines, string $pattern): string
    {
        foreach ($lines as $line) {
            if (preg_match($pattern, self::withoutSequences($line)) === 1) {
                return $line;
            }
        }

        self::fail(sprintf('No line of the output matches %s.', $pattern));
    }

    private static function withoutSequences(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }
}
