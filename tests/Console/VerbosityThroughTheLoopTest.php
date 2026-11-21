<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Console;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Console\Verbosity;
use Celyes\RalphPhp\Log\TerminalSink;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How much a real run prints, with and without `--verbose`.
 *
 * PROJECT.md section K, item 2 asks for two complete accounts rather than for
 * an account and a truncation, and the two halves of that are only visible
 * from outside: the quiet run has to still say everything a user needs to act
 * on, and the verbose run has to still be the run that was there before the
 * flag existed. Neither is a property of a class — the lines come from the
 * runner, the pass, the sink and the output together — so this is the binary,
 * run twice over the same project, with the two terminals compared.
 *
 * The third assertion is the one that makes the default safe: a quiet run
 * writes everything a verbose run writes, to the attempt's log and to the
 * rows, and `ralph logs TASK-ID` prints it afterwards. A quiet run is a run
 * whose terminal said less, not a run that kept less.
 */
#[CoversClass(Verbosity::class)]
#[CoversClass(Output::class)]
#[CoversClass(TerminalSink::class)]
final class VerbosityThroughTheLoopTest extends TestCase
{
    private const string TASK = 'Add a --dry-run option to the import command';

    private const string EDITED = 'src/Import.php';

    private const string EDIT = "<?php\n\n// --dry-run\n";

    /**
     * What the agent says for itself. Repeated into the terminal by a verbose
     * run and by no other.
     */
    private const string SAID = 'Added the option and its tests.';

    /**
     * What a check prints while it runs, which is the other process whose
     * output a run echoes.
     */
    private const string CHECKED = 'the workspace is checked';

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and the project a run works in is a repository.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * The default: the run's own account of its own work, and nothing else.
     *
     * Four kinds of line are asserted present, because a quiet run that
     * dropped any of them would be a run a user could not follow: which run
     * this is and what it is doing, which task was taken on, what happened to
     * it, and why the run ended with what to do next.
     *
     * And three kinds asserted absent, which are the wall of text the item is
     * about: the agent's own output, the package's notes beside it, and the
     * commentary on the settings the run resolved.
     */
    #[Test]
    public function a_run_that_was_not_asked_to_be_verbose_prints_only_its_own_account(): void
    {
        $this->givenAProjectThatCanRun();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $task = $this->taskId();

        self::assertStringContainsString('processing pending tasks', $result->standardOutput, $result->describe());
        self::assertStringContainsString($task . ' ' . self::TASK, $result->standardOutput, $result->describe());
        self::assertStringContainsString($task . ' completed', $result->standardOutput, $result->describe());
        self::assertStringContainsString('1 of 1 tasks completed', $result->standardOutput, $result->describe());
        self::assertStringContainsString('next: ', $result->standardOutput, $result->describe());

        // The agent's own output, the notes filed beside it, and the
        // settings commentary. None of the three.
        self::assertStringNotContainsString(self::SAID, $result->standardOutput, $result->describe());
        self::assertStringNotContainsString(self::CHECKED, $result->standardOutput, $result->describe());
        self::assertStringNotContainsString('--- ', $result->standardOutput, $result->describe());
        self::assertStringNotContainsString('checks: ', $result->standardOutput, $result->describe());

        // Short enough to read: the run's own lines and the blank lines
        // between them, rather than an agent's transcript.
        self::assertLessThan(
            12,
            count($result->standardOutputLines()),
            'A quiet run printed more lines than its own account: ' . $result->describe(),
        );
    }

    /**
     * And with the flag, the run that was there before the flag existed.
     */
    #[Test]
    public function a_verbose_run_prints_the_agents_output_the_notes_and_the_settings(): void
    {
        $this->givenAProjectThatCanRun();

        $result = $this->project->ralph(['run', '--verbose']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        $task = $this->taskId();

        // Everything the quiet run said is still said.
        self::assertStringContainsString($task . ' ' . self::TASK, $result->standardOutput, $result->describe());
        self::assertStringContainsString($task . ' completed', $result->standardOutput, $result->describe());
        self::assertStringContainsString('1 of 1 tasks completed', $result->standardOutput, $result->describe());

        // With the three kinds of line it is asked for on top.
        self::assertStringContainsString(self::SAID, $result->standardOutput, $result->describe());
        self::assertStringContainsString(self::CHECKED, $result->standardOutput, $result->describe());
        self::assertStringContainsString('--- ', $result->standardOutput, $result->describe());
        self::assertStringContainsString('checks: ', $result->standardOutput, $result->describe());
    }

    /**
     * The short form, in a bundle with another global option, because that is
     * how short options are typed.
     */
    #[Test]
    public function the_short_form_asks_for_the_same_run(): void
    {
        $this->givenAProjectThatCanRun();

        $result = $this->project->ralph(['run', '-v']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString(self::SAID, $result->standardOutput, $result->describe());
    }

    /**
     * The promise that makes the quiet default safe: nothing was lost, only
     * unprinted.
     *
     * The same project is run twice from scratch, and what the quiet run left
     * on disk is held against what the verbose run printed — the agent's
     * output, the notes, the checks' output and the evidence for the tick are
     * all in the attempt's log either way, and `ralph logs TASK-ID` is where
     * a user who left the flag off reads them.
     */
    #[Test]
    public function a_quiet_run_keeps_everything_a_verbose_run_shows(): void
    {
        $this->givenAProjectThatCanRun();
        $watched = $this->project->ralph(['run', '--verbose']);
        self::assertSame(ExitCode::SUCCESS, $watched->exitCode, $watched->describe());

        $this->project->remove();
        $this->project = LoopProject::create();
        $this->givenAProjectThatCanRun();

        $quiet = $this->project->ralph(['run']);
        self::assertSame(ExitCode::SUCCESS, $quiet->exitCode, $quiet->describe());

        $task = $this->taskId();
        $log = (string) file_get_contents(
            $this->project->path(sprintf('.ralph/runtime/logs/%s/attempt-01-agent.log', $task)),
        );

        self::assertStringContainsString(self::SAID, $log);
        self::assertStringContainsString('--- ', $log);

        // And the command that prints it back joins the log to the rows, so
        // the checks' own output is there too.
        $logs = $this->project->ralph(['logs', $task]);

        self::assertSame(ExitCode::SUCCESS, $logs->exitCode, $logs->describe());
        self::assertStringContainsString(self::SAID, $logs->standardOutput, $logs->describe());
        self::assertStringContainsString(self::CHECKED, $logs->standardOutput, $logs->describe());

        // The same tick, the same evidence, the same work in the tree: the
        // flag changed the terminal and nothing else.
        self::assertStringContainsString('- [x] ' . self::TASK, $this->project->checklist());
        self::assertSame(1, $this->project->journal()->tasks()->verified()->count());
        self::assertNotSame([], $this->project->workingTreeChanges());
    }

    /**
     * A failure says what went wrong either way, and offers the flag rather
     * than printing a trace nobody asked for.
     */
    #[Test]
    public function an_unexpected_failure_offers_the_flag_and_honours_it(): void
    {
        $quiet = $this->project->ralph(['nonsense']);

        self::assertSame(ExitCode::USAGE, $quiet->exitCode, $quiet->describe());
        self::assertStringContainsString('ralph: ', $quiet->errorOutput, $quiet->describe());
        self::assertStringNotContainsString('#0 ', $quiet->errorOutput, $quiet->describe());

        // A recognised failure has hints instead of a trace, so the trace is
        // asked for with the flag and arrives.
        $verbose = $this->project->ralph(['nonsense', '--verbose']);

        self::assertSame(ExitCode::USAGE, $verbose->exitCode, $verbose->describe());
        self::assertStringContainsString('#0 ', $verbose->errorOutput, $verbose->describe());
    }

    /**
     * A project with a worker that says something, a task, and a check that
     * prints while it runs — so that both kinds of echoed output are in play.
     */
    private function givenAProjectThatCanRun(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT])->saying(self::SAID))
            ->withTasks([self::TASK])
            ->withConfiguration(['checks' => [[
                'name' => 'tests',
                'command' => [PHP_BINARY, '-r', sprintf('echo "%s\n";', self::CHECKED)],
                'timeout' => 30,
            ]]]);
    }

    private function taskId(): string
    {
        return TaskId::fromDescription(self::TASK)->value();
    }
}
