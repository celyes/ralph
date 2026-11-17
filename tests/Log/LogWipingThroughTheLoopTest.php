<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Log;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Log\LogWiper;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `logs.wipe_on_success` driven through a real run (PROJECT.md section K,
 * items 11 and 12).
 *
 * {@see LogWiperTest} covers what the wiper deletes and refuses; what only a
 * run can answer is whether it is ever **asked**, and asked in the right place.
 * A wiper nothing calls would pass every unit assertion made against it and
 * leave a user's runtime directory exactly as full as before; one called too
 * early would delete the log of a task whose box had not been ticked.
 *
 * So each case here starts a real `ralph` subprocess against a real repository
 * and reads the answer off the filesystem afterwards: the log files, the
 * completion record beside them, the commit, and what the next command says.
 *
 * Item 12's half is the same question asked of the outcomes that are not a
 * success. A failed, blocked or interrupted task keeps every byte it wrote
 * whatever the setting says, and each of those three is produced here by
 * really producing it — a failing check, an agent that asks for a decision, and
 * a run stopped with a signal — rather than by writing the state into a row.
 */
#[CoversClass(LogWiper::class)]
final class LogWipingThroughTheLoopTest extends TestCase
{
    private const string FIRST = 'Add a --dry-run option to the import command';

    private const string SECOND = 'Document the import command in the README';

    private const string EDITED = 'src/Import.php';

    private const string EDIT = "<?php\n\n// --dry-run\n";

    private LoopProject $project;

    protected function setUp(): void
    {
        if (!Git::isAvailable()) {
            self::markTestSkipped('Git is not installed, and a run commits in real worktrees.');
        }

        $this->project = LoopProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * The task succeeded, so its output goes and everything the completion
     * rests on stays.
     */
    #[Test]
    public function a_successful_tasks_logs_are_wiped_and_its_evidence_is_not(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withConfiguration(['logs' => ['wipe_on_success' => true]]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());

        self::assertSame([], $this->logsOf(self::FIRST), 'A successful task keeps no log files.');
        self::assertNotSame(
            [],
            $this->filesOf(self::FIRST, '*.md'),
            'The completion evidence is not output, and outlives the logs.',
        );

        // And the account of the task is untouched: the commit it produced, the
        // ticked box, and the row every later command reads.
        $passes = $this->project->journal()->generations()->forTask(TaskId::fromDescription(self::FIRST));

        self::assertCount(1, $passes);
        self::assertTrue($passes[0]->hasCommit());
        self::assertTrue($passes[0]->logsWereWiped());

        $status = $this->project->ralph(['status']);

        self::assertStringContainsString('1 of 1', $status->standardOutput);
    }

    /**
     * Section K, item 12's promise, and the reason the switch is safe to turn
     * on: the run that wipes a successful task's logs preserves the failing
     * task's in the same pass over the checklist.
     */
    #[Test]
    public function a_failing_tasks_logs_survive_a_run_that_wipes_a_successful_ones(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success([self::EDITED => self::EDIT]),
                FixtureStep::failure('the checks did not pass'),
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['logs' => ['wipe_on_success' => true]]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::EXECUTION, $result->exitCode, $result->describe());

        self::assertSame([], $this->logsOf(self::FIRST));
        self::assertNotSame([], $this->logsOf(self::SECOND), 'A failed task keeps every byte it wrote.');

        // The failing task's log is still readable through the command that
        // points people at it.
        $logs = $this->project->ralph(['logs', TaskId::fromDescription(self::SECOND)->value()]);

        self::assertSame(ExitCode::SUCCESS, $logs->exitCode, $logs->describe());
        self::assertStringContainsString('worker log', $logs->standardOutput);
    }

    /**
     * A task waiting on a person keeps its output too.
     *
     * The blocked case of section K, item 12, and the one where the logs are
     * most obviously the point: the run stopped to ask something, and what it
     * had got to before it asked is in the file.
     */
    #[Test]
    public function a_blocked_tasks_logs_survive_a_run_that_wipes(): void
    {
        $this->project
            ->withWorker(FixtureStep::blocking('the import format is ambiguous', 'say which format to read'))
            ->withTasks([self::FIRST])
            ->withConfiguration(['logs' => ['wipe_on_success' => true]]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::BLOCKED, $result->exitCode, $result->describe());
        self::assertNotSame([], $this->logsOf(self::FIRST), 'A blocked task keeps every byte it wrote.');

        $logs = $this->project->ralph(['logs', TaskId::fromDescription(self::FIRST)->value()]);

        self::assertStringContainsString('worker log', $logs->standardOutput, $logs->describe());
        self::assertStringNotContainsString('wiped', $logs->standardOutput, $logs->describe());
    }

    /**
     * And a run somebody stopped with Ctrl+C.
     *
     * The third of the three states item 12 names, and the only one where the
     * log is still being written when the decision not to delete it is made:
     * the pass is left `interrupted`, the file is closed where the signal
     * found it, and the next thing anyone does with this task is read it.
     */
    #[Test]
    public function an_interrupted_tasks_logs_survive_a_run_that_wipes(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('The pcntl extension is not installed, so a signalled run records nothing.');
        }

        $this->project
            ->withWorker(FixtureStep::slow(30)->editing([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withConfiguration(['logs' => ['wipe_on_success' => true]]);

        $result = $this->project->interrupt(['run'], ', mode slow');

        self::assertSame(ExitCode::INTERRUPTED, $result->exitCode, $result->describe());
        self::assertNotSame([], $this->logsOf(self::FIRST), 'An interrupted task keeps what it had written.');

        $logs = $this->project->ralph(['logs', TaskId::fromDescription(self::FIRST)->value()]);

        self::assertStringContainsString('worker log', $logs->standardOutput, $logs->describe());
        self::assertStringNotContainsString('wiped', $logs->standardOutput, $logs->describe());
    }

    /**
     * The default, which is what a project gets unless it says otherwise.
     */
    #[Test]
    public function a_project_that_has_not_asked_keeps_the_logs_of_its_successful_tasks(): void
    {
        $this->project
            ->withWorker(FixtureStep::success([self::EDITED => self::EDIT]))
            ->withTasks([self::FIRST])
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertNotSame([], $this->logsOf(self::FIRST));
    }

    /**
     * The log files a task has left, by the names the package writes them under.
     *
     * @return list<string>
     */
    private function logsOf(string $description): array
    {
        return $this->filesOf($description, '*.log');
    }

    /**
     * @return list<string>
     */
    private function filesOf(string $description, string $pattern): array
    {
        $directory = sprintf(
            '%s/%s/%s',
            $this->project->root(),
            Defaults::LOGS_DIRECTORY,
            TaskId::fromDescription($description)->value(),
        );

        $found = glob($directory . '/' . $pattern);

        return $found === false ? [] : $found;
    }
}
