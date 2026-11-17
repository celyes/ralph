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
 * item 11).
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
