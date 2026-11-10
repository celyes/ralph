<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\TaskRecord;
use Celyes\RalphPhp\Tasks\Checklist;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The user's Markdown file, as a run and the run after it treat it.
 *
 * PROJECT.md section J, item 1. Every facet it names already has a unit suite
 * of its own — {@see ChecklistParserTest} for the documents, {@see TaskIdTest}
 * for the identifiers, {@see TaskListTest} for selection and skipping,
 * {@see GenerationSelectorTest} for reopened work, {@see CheckboxEditorTest}
 * and {@see ChecklistWriterTest} for the writes. What none of them can say is
 * whether the facets survive the loop: a stable identifier is only worth
 * something if the *second* run agrees with the first about which task is
 * which, and a formatting-preserving write is only worth something if what
 * reaches the file on disk after a real pass is still the user's document.
 *
 * So these cases are about the seams between the checklist layer and the rest.
 * Each one is a real `ralph run` as a subprocess against a real repository
 * with the fixture worker as its agent, and the assertions are the two things
 * a user can see: the bytes of `.ralph/tasks.md`, and whether work was done
 * twice (branches and commits in Git, generations in the state store).
 *
 * No agent CLI, no credentials and no network (section J, item 8).
 */
#[CoversClass(Checklist::class)]
#[CoversClass(ChecklistParser::class)]
#[CoversClass(TaskId::class)]
final class ChecklistThroughTheLoopTest extends TestCase
{
    private const string FIRST = 'Add a --dry-run option to the import command';

    private const string SECOND = 'Document the import command in the README';

    private const string THIRD = 'Validate the import file before reading it';

    private const string DONE = 'Create the import command';

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
     * A checklist as a person actually writes one, run to the end: the two
     * markers the run earned are the only bytes in the file that change.
     *
     * Everything in this document is something the parser has a unit case for
     * and the writer has a unit case for, assembled into one file so that the
     * run has to get all of it right at once — a byte-order mark, Windows line
     * endings, prose above and below the tasks, acceptance criteria indented
     * under a task, a worked example in a fence whose own `- [ ]` lines are
     * not work, an item the user ticked by hand, and no final newline.
     *
     * The assertion is an exact string: the file it started as, with two
     * markers flipped. Anything else the run touched — a rewritten line
     * ending, a normalised fence, a lost byte-order mark, a newline appended at
     * the end, the example mistaken for a third task — fails it.
     */
    #[Test]
    public function a_hand_written_checklist_is_returned_with_only_its_markers_changed(): void
    {
        $document = $this->handWrittenChecklist();

        $this->project
            ->withWorker(
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]),
                FixtureStep::success(['README.md' => "An example project.\n\n## Import\n"]),
            )
            ->withChecklist($document)
            ->withConfiguration();

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());

        // Two tasks, in file order. The fenced example is not a third one, and
        // the hand-ticked item is not a fourth.
        self::assertStringContainsString('2 of 2 tasks completed', $result->standardOutput, $result->describe());

        self::assertSame(
            str_replace(
                ['- [ ] ' . self::FIRST, '- [ ] ' . self::SECOND],
                ['- [x] ' . self::FIRST, '- [x] ' . self::SECOND],
                $document,
            ),
            $this->project->checklist(),
            'A run rewrites the markers it earned and nothing else in the file.',
        );
    }

    /**
     * The file reordered between two runs, which is the test of whether an
     * identifier is stable in the only way that matters.
     *
     * The second run meets a document in which the finished task has moved to
     * the bottom, a task it never saw has been inserted at the top, and the
     * prose has been rewritten. Nothing about the first task's position, its
     * neighbours or its marker's spacing is what it was. If identity came from
     * anywhere but the words of the task itself, the run redoes work that is
     * already committed — so what is asserted is that the finished task keeps
     * the one identifier, the one generation, the one branch and the one commit
     * it had, while the two genuinely pending tasks are the ones worked on.
     */
    #[Test]
    public function a_checklist_reordered_between_runs_does_not_redo_finished_work(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]),
                FixtureStep::success(['README.md' => "An example project.\n\n## Import\n"]),
                FixtureStep::success(['src/Import.php' => "<?php\n\n// validated\n"]),
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration();

        $first = $this->project->ralph(['run']);
        self::assertSame(ExitCode::SUCCESS, $first->exitCode, $first->describe());
        self::assertStringContainsString('2 of 2 tasks completed', $first->standardOutput, $first->describe());

        $done = $this->latestPass(self::FIRST);
        $branch = (string) $done->branch();
        $commit = (string) $done->commitSha();
        self::assertNotSame('', $branch);
        self::assertNotSame('', $commit);

        // The user rearranges the file: a task that was never there at the
        // top, the two finished ones below it in the other order, different
        // prose, and one marker rewritten in upper case with the bullet and
        // the spacing a different editor leaves.
        $this->project->editChecklist(implode("\n", [
            '# Work in progress',
            '',
            'Reordered while the loop was not running.',
            '',
            '- [ ] ' . self::THIRD,
            '- [x] ' . self::SECOND,
            '* [X]  ' . self::FIRST,
            '',
        ]));

        $second = $this->project->ralph(['run']);
        self::assertSame(ExitCode::SUCCESS, $second->exitCode, $second->describe());
        self::assertStringContainsString('1 of 1 tasks completed', $second->standardOutput, $second->describe());

        // The finished task is the same task, still finished, still owning
        // exactly what it owned.
        $after = $this->latestPass(self::FIRST);
        self::assertTrue($after->task()->equals(TaskId::fromDescription(self::FIRST)));
        self::assertSame(1, $after->number());
        self::assertSame(LifecycleState::Completed, $after->state());
        self::assertSame($branch, $after->branch());
        self::assertSame($commit, $after->commitSha());
        self::assertSame($commit, $this->project->headOf($branch));
        self::assertCount(
            1,
            $this->project->journal()->generations()->forTask($after->task()),
            'A task that only moved is not a task to do again.',
        );
        self::assertTrue(
            $this->recorded(self::FIRST)->isVerified(),
            'The run that verified it is still the one credited with it.',
        );

        // And the one genuinely new task really was done: three tasks, three
        // branches, each with one commit, none of them a second commit for
        // work that was already finished.
        $branches = $this->project->taskBranches();
        self::assertCount(3, $branches);

        foreach ($branches as $name) {
            self::assertCount(1, $this->project->commitsOn($name), $name . ' holds one commit.');
        }

        // The ticked lines the user wrote are left as the user wrote them, and
        // the new one is ticked in the style of the line it was written on.
        $checklist = $this->project->checklist();
        self::assertStringContainsString('* [X]  ' . self::FIRST, $checklist);
        self::assertStringContainsString('- [x] ' . self::SECOND, $checklist);
        self::assertStringContainsString('- [x] ' . self::THIRD, $checklist);
    }

    /**
     * A finished task the user unticks, which is how reopening is asked for.
     *
     * Section C, item 8 as the user performs it: there is no command for
     * reopening work, there is only the box. The run that meets the unticked
     * item has to tell the difference between work it has never done and work
     * it is being asked to do again — and it must not do it on the branch that
     * already holds the first pass, because that commit is what somebody is
     * reviewing.
     */
    #[Test]
    public function unticking_a_finished_task_runs_it_again_without_disturbing_the_first_pass(): void
    {
        $this->project
            ->withWorker(
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]),
                FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run, again\n"]),
            )
            ->withTasks([self::FIRST])
            ->withConfiguration();

        self::assertSame(ExitCode::SUCCESS, $this->project->ralph(['run'])->exitCode);

        $first = $this->project->journal()->generations()->latestFor(TaskId::fromDescription(self::FIRST));
        self::assertNotNull($first);
        self::assertSame(1, $first->number());
        $firstBranch = (string) $first->branch();
        $firstCommit = (string) $first->commitSha();

        // The user reopens it by hand, in the file, as the only way there is.
        $this->project->editChecklist("# Tasks\n\n- [ ] " . self::FIRST . "\n");

        $again = $this->project->ralph(['run']);
        self::assertSame(ExitCode::SUCCESS, $again->exitCode, $again->describe());
        self::assertStringContainsString('1 of 1 tasks completed', $again->standardOutput, $again->describe());

        // A second pass at the same task, with its own branch and commit.
        $generations = $this->project->journal()->generations()->forTask(TaskId::fromDescription(self::FIRST));
        self::assertCount(2, $generations);

        $second = $generations[1];
        self::assertSame(2, $second->number());
        self::assertSame(LifecycleState::Completed, $second->state());
        self::assertNotSame($firstBranch, $second->branch());
        self::assertNotSame($firstCommit, $second->commitSha());
        self::assertSame(1, $second->attempts(), 'A reopening does not inherit the first pass\'s attempts.');

        // The first pass is left exactly where a reviewer left it.
        self::assertSame(LifecycleState::Completed, $generations[0]->state());
        self::assertSame($firstCommit, $generations[0]->commitSha());
        self::assertSame($firstCommit, $this->project->headOf($firstBranch));
        self::assertSame(
            [$firstBranch, (string) $second->branch()],
            $this->project->taskBranches(),
            'Both passes keep their own branch.',
        );

        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());
    }

    /**
     * One task written twice, which the run refuses before it does anything.
     *
     * A duplicate is not a task that fails: two identical descriptions have one
     * identifier, so the run cannot tell which line to tick and cannot tell a
     * second pass from a repeat. The refusal has to arrive before a worker is
     * started, because an agent's changes against an ambiguous checklist are
     * work nobody can file.
     */
    #[Test]
    public function a_checklist_stating_one_task_twice_is_refused_before_any_work_starts(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// should never run\n"]))
            ->withTasks([self::FIRST, self::SECOND, self::FIRST])
            ->withConfiguration();

        $before = $this->project->checklist();
        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $result->describe());
        self::assertStringContainsString(self::FIRST, $result->allOutput(), $result->describe());

        // Nothing was started: no branch, and the file is as the user left it.
        self::assertSame([], $this->project->taskBranches());
        self::assertSame($before, $this->project->checklist());
    }

    /**
     * Tasks the user finished themselves, which the next run leaves alone.
     *
     * Between the two runs the user ticks the remaining task off by hand, which
     * is the ordinary way a checklist stops having work in it. The second run
     * has nothing to do, and the file it was pointed at is one it must not
     * touch: no branch for an item it did not implement, and no rewriting of a
     * marker somebody else wrote.
     */
    #[Test]
    public function a_task_the_user_ticked_between_runs_is_left_untouched(): void
    {
        $this->project
            ->withWorker(FixtureStep::success(['src/Import.php' => "<?php\n\n// --dry-run\n"]))
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['limits' => ['iterations' => 1]]);

        self::assertSame(ExitCode::LIMIT, $this->project->ralph(['run'])->exitCode);
        $branchesAfterTheFirstRun = $this->project->taskBranches();
        self::assertCount(1, $branchesAfterTheFirstRun);

        $this->project->editChecklist(
            "# Tasks\n\n- [x] " . self::FIRST . "\n- [x] " . self::SECOND . "  # done by hand\n",
        );
        $before = $this->project->checklist();

        $second = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $second->exitCode, $second->describe());
        self::assertStringContainsString('nothing pending', $second->standardOutput, $second->describe());
        self::assertSame($before, $this->project->checklist(), 'A run with nothing to do writes nothing.');
        self::assertSame($branchesAfterTheFirstRun, $this->project->taskBranches());
    }

    /**
     * The document of the first case: everything a hand-written checklist has
     * in it that a run could damage.
     */
    private function handWrittenChecklist(): string
    {
        $lines = [
            "\u{FEFF}# Tasks",
            '',
            'Work the loop may pick up. This paragraph is prose, not a task.',
            '',
            '- [x] ' . self::DONE,
            '- [ ] ' . self::FIRST,
            '  Acceptance: the command reports what it would do and writes nothing.',
            '',
            '  ```markdown',
            '  - [ ] the syntax, as an example, and not work',
            '  ```',
            '- [ ] ' . self::SECOND,
            '  - mention `--dry-run`',
            '',
            '## Notes',
            '',
            'Prose below the tasks, with no newline after it.',
        ];

        return implode("\r\n", $lines);
    }

    /**
     * The row the state store holds for a task, found as the loop finds it:
     * by the identifier its description generates.
     */
    private function recorded(string $description): TaskRecord
    {
        $task = $this->project->journal()->tasks()->find(TaskId::fromDescription($description));

        if ($task === null) {
            self::fail(sprintf('The state store holds no task described as "%s".', $description));
        }

        return $task;
    }

    /**
     * The newest pass at a task, which is where its branch and commit live.
     */
    private function latestPass(string $description): GenerationRecord
    {
        $pass = $this->project->journal()->generations()->latestFor(TaskId::fromDescription($description));

        if ($pass === null) {
            self::fail(sprintf('Nothing has been attempted for "%s".', $description));
        }

        return $pass;
    }
}
