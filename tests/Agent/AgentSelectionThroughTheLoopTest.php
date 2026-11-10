<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AdapterRegistrationFile;
use Celyes\RalphPhp\Agent\AgentSelector;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\LifecycleState;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\Tasks\TaskId;
use Celyes\RalphPhp\Tests\Fixture\FixtureStep;
use Celyes\RalphPhp\Tests\Support\Git;
use Celyes\RalphPhp\Tests\Support\JsonDocument;
use Celyes\RalphPhp\Tests\Support\LoopProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which agent does the work, and what happens when it cannot.
 *
 * PROJECT.md section J, item 3: the project's default, the command-line
 * override, adapter registration, a missing agent, and the agent a resume
 * continues with. All five have unit suites —
 * {@see AgentSelectorTest} for the resolution and its refusals,
 * {@see AdapterRegistryTest} and {@see AdapterRegistrationFileTest} for
 * registration, {@see \Celyes\RalphPhp\Tests\Run\AgentContinuityTest} for the
 * recorded agent a resume reads — and each of them answers the question with
 * an object: *which selection was produced*.
 *
 * What none of them can answer is the question a user is actually asking,
 * which is *which program ran*. A selection is only worth something if the
 * agent it names is the one that was started, the other configured agent was
 * not, and a refusal starts neither. So every case here installs more than one
 * usable agent, each a separate executable with a journal of its own, and the
 * assertions are what the agents were asked to do — not what the loop decided
 * to ask.
 *
 * That is also what makes "no silent fallback" (section E, item 9) assertable:
 * a project here always has a working agent sitting next to the broken one, so
 * a run that quietly reached for something installed would succeed, and the
 * cases about refusals would fail.
 *
 * Real `ralph` subprocesses against real repositories, with the fixture worker
 * as every agent. No agent CLI, no credentials and no network (item 8).
 */
#[CoversClass(AgentSelector::class)]
#[CoversClass(AgentSource::class)]
#[CoversClass(AdapterRegistrationFile::class)]
final class AgentSelectionThroughTheLoopTest extends TestCase
{
    private const string FIRST = 'Add a --dry-run option to the import command';

    private const string SECOND = 'Document the import command in the README';

    /**
     * The two agents every case configures, and what each one writes.
     *
     * Different files on purpose: the commit a run leaves says which of the
     * two did the work, so the question "was the right agent run" has an
     * answer in Git as well as in the workers' journals.
     */
    private const string PREFERRED = 'preferred';

    private const string ALTERNATE = 'alternate';

    private const string PREFERRED_FILE = 'src/Preferred.php';

    private const string ALTERNATE_FILE = 'src/Alternate.php';

    /**
     * The worker's own output, which is the moment a Ctrl+C has something to
     * interrupt.
     */
    private const string WORKING = ', mode slow';

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
     * The project's `agent` setting decides which program is started.
     *
     * Two agents, both installed and both able to carry the task through; the
     * configuration names one of them. What is asserted is not the recorded
     * name — a run that started the wrong program would record the right name
     * just as happily — but that the selected agent made the attempt, the
     * other was never started, and the file in the commit is the one the
     * selected agent writes.
     *
     * The run says out loud which agent it chose and where the choice came
     * from, because that line is a user's only chance to notice a default they
     * did not mean before an agent starts editing their code.
     */
    #[Test]
    public function the_projects_default_agent_is_the_program_the_run_starts(): void
    {
        $this->givenTwoAgents()
            ->withTasks([self::FIRST])
            ->withConfiguration(['agent' => self::PREFERRED]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode, $result->describe());
        self::assertStringContainsString(
            self::PREFERRED . ' (adapter "codex", selected by the project configuration)',
            $result->standardOutput,
            $result->describe(),
        );

        self::assertCount(1, $this->project->agent(self::PREFERRED)->attempts());
        self::assertSame(
            [],
            $this->project->agent(self::ALTERNATE)->attempts(),
            'The agent the project did not choose was started anyway.',
        );

        // The work in the commit is the selected agent's, which is the same
        // statement made where a reviewer would see it.
        $branch = $this->project->taskBranches()[0] ?? '';
        self::assertSame([self::PREFERRED_FILE], $this->filesIn($branch));

        // And the row says both the agent and that it was the project's own
        // default rather than a one-off, which is what a resume reads.
        $run = $this->project->journal()->runs()->latest();
        self::assertNotNull($run);
        self::assertSame(self::PREFERRED, $run->agent()->name());
        self::assertSame(AgentSource::ProjectDefault, $run->agentSource());
    }

    /**
     * `--agent` decides for one run, and leaves the project's default alone.
     *
     * The override is only an override if the next run without it goes back —
     * a tool that remembered the last agent used would make a user's one-off
     * experiment the project's new default without saying so. Two tasks, so
     * the two runs are doing real work rather than repeating one task: the
     * option sends the first to the alternate agent, and the second run with
     * no option sends the second task to the configured default.
     *
     * Both halves are read off the agents themselves: one attempt each, from
     * the agent that was asked for each time.
     */
    #[Test]
    public function the_agent_option_decides_one_run_and_leaves_the_default_alone(): void
    {
        $this->givenTwoAgents()
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['agent' => self::PREFERRED, 'limits' => ['iterations' => 1]]);

        $overridden = $this->project->ralph(['run', '--agent', self::ALTERNATE]);

        self::assertSame(ExitCode::LIMIT, $overridden->exitCode, $overridden->describe());
        self::assertStringContainsString(
            self::ALTERNATE . ' (adapter "codex", selected by the --agent option)',
            $overridden->standardOutput,
            $overridden->describe(),
        );

        self::assertCount(1, $this->project->agent(self::ALTERNATE)->attempts());
        self::assertSame([], $this->project->agent(self::PREFERRED)->attempts());
        self::assertSame([self::ALTERNATE_FILE], $this->filesIn($this->branchFor(self::FIRST)));

        $overriding = $this->project->journal()->runs()->latest();
        self::assertNotNull($overriding);
        self::assertSame(AgentSource::CommandLine, $overriding->agentSource());

        // Nothing was written back: the setting still names the agent the user
        // chose for the project, and the next run acts on it.
        self::assertSame(
            self::PREFERRED,
            JsonDocument::parse($this->project->ralph(['status', '--json'])->standardOutput)->string('project.agent'),
        );

        $ordinary = $this->project->ralph(['run']);

        self::assertSame(ExitCode::SUCCESS, $ordinary->exitCode, $ordinary->describe());
        self::assertCount(
            1,
            $this->project->agent(self::PREFERRED)->attempts(),
            'The run with no option did not go back to the configured default.',
        );
        self::assertCount(1, $this->project->agent(self::ALTERNATE)->attempts());
        self::assertSame([self::PREFERRED_FILE], $this->filesIn($this->branchFor(self::SECOND)));

        $second = $this->project->journal()->runs()->latest();
        self::assertNotNull($second);
        self::assertSame(self::PREFERRED, $second->agent()->name());
        self::assertSame(AgentSource::ProjectDefault, $second->agentSource());
    }

    /**
     * An agent the package has never heard of, carried to a commit by an
     * adapter the project registered itself.
     *
     * Section E, item 3's extension point as the only thing that could have
     * made this run possible: `acme` is not a built-in adapter name, nothing
     * in the package mentions it, and the only reason the loop can execute it
     * is the provider the project's own `.ralph/adapters.php` returns. The
     * adapter is an ordinary third-party one ({@see WorkerAdapterProvider}) —
     * two methods about its own invocation, everything else inherited — so
     * what is shown is the interface a project is asked to implement rather
     * than a fixture with special access.
     *
     * The second half is the other direction. With the registration file gone,
     * the same configuration is refused and says which adapter it could not
     * find and which ones it has: an adapter that has stopped being registered
     * must not leave a project's agent silently resolving to something else.
     */
    #[Test]
    public function an_adapter_the_project_registers_itself_carries_a_task_to_a_commit(): void
    {
        $this->project
            ->withAgent(
                'mine',
                [FixtureStep::success([self::PREFERRED_FILE => "<?php\n\n// --dry-run\n"])],
                'acme',
            )
            ->withAdapterRegistration(
                'return [new \\' . WorkerAdapterProvider::class . "('acme')];",
            )
            ->withTasks([self::FIRST, self::SECOND])
            ->withConfiguration(['agent' => 'mine', 'limits' => ['iterations' => 1]]);

        $result = $this->project->ralph(['run']);

        self::assertSame(ExitCode::LIMIT, $result->exitCode, $result->describe());
        self::assertStringContainsString(
            'mine (adapter "acme", selected by the project configuration)',
            $result->standardOutput,
            $result->describe(),
        );

        // The agent really ran, in the task's own workspace, with the common
        // briefing: a registered adapter is given the same request the
        // built-in ones are.
        $attempt = $this->project->agent('mine')->attempt();
        self::assertStringContainsString('.ralph/runtime/worktrees/', $attempt->directory());
        self::assertStringContainsString(self::FIRST, $attempt->briefing());

        // And the loop treated the outcome as it treats any other: verified,
        // committed, ticked.
        $pass = $this->passFor(self::FIRST);
        self::assertSame(LifecycleState::Completed, $pass->state());
        self::assertNotNull($pass->commitSha());
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());

        $run = $this->project->journal()->runs()->latest();
        self::assertNotNull($run);
        self::assertSame('acme', $run->agent()->adapter());

        // Without the file, the adapter behind that agent is nobody's: the
        // next run is refused, and told what it was looking for.
        $this->project->withoutAdapterRegistration();

        $refused = $this->project->ralph(['run']);

        self::assertSame(ExitCode::CONFIGURATION, $refused->exitCode, $refused->describe());
        self::assertStringContainsString(
            'The agent "mine" names the unsupported adapter "acme".',
            $refused->errorOutput,
            $refused->describe(),
        );
        self::assertStringContainsString('Available adapters: "codex", "claude".', $refused->errorOutput);

        // The task it would have taken on was not opened, and the finished one
        // was not touched.
        self::assertSame([], $this->project->journal()->generations()->forTask($this->identify(self::SECOND)));
        self::assertCount(1, $this->project->agent('mine')->attempts(), 'The refused run started the agent anyway.');
        self::assertStringContainsString('- [ ] ' . self::SECOND, $this->project->checklist());
    }

    /**
     * Every way of naming an agent that cannot be run, refused without
     * anything else being run in its place.
     *
     * The four mistakes a user actually makes, in one project that has a
     * perfectly good agent installed all along: a name nothing is configured
     * under, asked for on the command line; the same mistake in the `agent`
     * setting; an entry naming an adapter nobody registered; and an entry
     * whose adapter is real but whose executable is not. A run that reached
     * for the working agent, or for a built-in CLI it happened to find, would
     * pass its checks and commit — so the assertion that no agent was started
     * is the whole point, and it is read off the installed worker rather than
     * out of the message.
     *
     * The first three are configuration refusals, and they cost the project
     * nothing: no run row, no branch, no edit to the checklist. The fourth is
     * not a configuration question — only starting the agent can discover that
     * its program has gone — so it is a failed pass instead, naming the
     * agent whose executable was not there and leaving the task unimplemented.
     */
    #[Test]
    public function an_agent_that_cannot_be_run_is_refused_without_another_agent_being_started(): void
    {
        // The two entries a case has to write itself, because neither could be
        // installed: an adapter nobody provides, and a program that is not
        // there. Repeated on every rewrite below, since each rewrite is the
        // whole settings file.
        $broken = [
            'unsupported' => ['adapter' => 'gemini', 'executable' => 'gemini'],
            'uninstalled' => ['adapter' => 'codex', 'executable' => $this->project->path('tools/gone')],
        ];

        $this->givenTwoAgents()
            ->withTasks([self::FIRST])
            ->withConfiguration(['agent' => self::PREFERRED, 'agents' => $broken]);

        $checklist = $this->project->checklist();

        $refusals = [
            // Asked for on the command line, under a name nothing is
            // configured under. The message lists what is configured, because
            // the fix is either a typo or an entry the user has not written.
            'a name nobody configured' => [
                self::PREFERRED,
                ['run', '--agent', 'gemini'],
                ['No agent named "gemini" is configured.', 'Configured agents:'],
            ],
            // The same mistake in the setting, where there is no option to
            // blame it on, so the message has to name the setting.
            'an unconfigured default' => [
                'typo',
                ['run'],
                ['No agent named "typo" is configured.', '"agent"'],
            ],
            // A configured agent whose adapter nobody registered: the name
            // resolves, the invocation behind it does not exist, and this is
            // the refusal that has to say nothing else will be run instead.
            'an adapter nobody registered' => [
                self::PREFERRED,
                ['run', '--agent', 'unsupported'],
                [
                    'The agent "unsupported" names the unsupported adapter "gemini".',
                    'Available adapters: "codex", "claude".',
                ],
            ],
        ];

        foreach ($refusals as $mistake => [$default, $arguments, $expected]) {
            $this->project->editConfiguration(['agent' => $default, 'agents' => $broken]);

            $result = $this->project->ralph($arguments);

            self::assertSame(ExitCode::CONFIGURATION, $result->exitCode, $mistake . ': ' . $result->describe());

            foreach ($expected as $message) {
                self::assertStringContainsString($message, $result->errorOutput, $mistake);
            }

            self::assertSame(
                0,
                $this->runsRecorded(),
                $mistake . ': a refused agent got as far as opening a run.',
            );
        }

        $this->project->editConfiguration(['agent' => self::PREFERRED, 'agents' => $broken]);

        // The executable that is not there: the run opens, because this is not
        // something a settings file can be checked for, and the pass fails
        // naming the agent whose program is missing.
        $missing = $this->project->ralph(['run', '--agent', 'uninstalled']);

        self::assertSame(ExitCode::EXECUTION, $missing->exitCode, $missing->describe());
        self::assertStringContainsString(
            'The executable "' . $this->project->path('tools/gone')
                . '" configured for the agent "uninstalled" was not found.',
            $missing->standardOutput,
            $missing->describe(),
        );

        // A failed pass, not a pass carried out by whatever else was lying
        // around: the task is unimplemented and the agent named is the one the
        // user asked for.
        $pass = $this->passFor(self::FIRST);
        self::assertSame(LifecycleState::Failed, $pass->state());
        self::assertNull($pass->commitSha());

        // Nothing was done by anything: neither installed agent was started,
        // no commit was made and the checklist is the user's file untouched.
        self::assertSame([], $this->project->agent(self::PREFERRED)->attempts());
        self::assertSame([], $this->project->agent(self::ALTERNATE)->attempts());
        self::assertSame([], array_filter(
            $this->project->taskBranches(),
            fn (string $branch): bool => $this->filesIn($branch) !== [],
        ), 'Something committed work for a task no agent implemented.');
        self::assertSame($checklist, $this->project->checklist());
    }

    /**
     * A resume continues with the agent the interrupted run recorded, even
     * when the project now prefers another one.
     *
     * Section H, item 6 where it can be observed: a run really interrupted
     * part-way through a task, a configuration edited afterwards to name the
     * other installed agent, and a resume that goes back to the agent whose
     * half-finished workspace it is reconciling. Both agents can do the work,
     * so a resume that read today's configuration would succeed and leave a
     * task implemented half by one agent and half by another — which is why
     * the assertion is that the alternate agent was never started at all.
     *
     * Asking for the change explicitly is refused rather than ignored, and
     * costs the resume nothing: the refusal is asserted first, so the resume
     * that follows is one the user had to mean.
     */
    #[Test]
    public function a_resumed_run_continues_with_the_agent_it_was_interrupted_under(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped(
                'The pcntl extension is not installed, so a signalled run cannot record its interruption.',
            );
        }

        $this->project
            ->withAgent(self::PREFERRED, [
                FixtureStep::slow(30),
                FixtureStep::success([self::PREFERRED_FILE => "<?php\n\n// --dry-run\n"]),
            ])
            ->withAgent(self::ALTERNATE, [
                FixtureStep::success([self::ALTERNATE_FILE => "<?php\n\n// --dry-run\n"]),
            ])
            ->withTasks([self::FIRST])
            ->withConfiguration(['agent' => self::PREFERRED, 'limits' => ['attempts_per_task' => 2]]);

        $interrupted = $this->project->interrupt(['run'], self::WORKING);

        self::assertSame(ExitCode::INTERRUPTED, $interrupted->exitCode, $interrupted->describe());

        // The user changes their mind about the project's agent while a run of
        // theirs is sitting interrupted.
        $this->project->editConfiguration([
            'agent' => self::ALTERNATE,
            'limits' => ['attempts_per_task' => 2],
        ]);

        $asked = $this->project->ralph(['resume', '--agent', self::ALTERNATE]);

        self::assertSame(ExitCode::CONFIGURATION, $asked->exitCode, $asked->describe());
        self::assertStringContainsString('would change agents part-way', $asked->errorOutput, $asked->describe());
        self::assertSame(
            [],
            $this->project->agent(self::ALTERNATE)->attempts(),
            'The refused resume started the agent it refused to use.',
        );

        $resumed = $this->project->ralph(['resume']);

        self::assertSame(ExitCode::SUCCESS, $resumed->exitCode, $resumed->describe());
        self::assertStringContainsString(
            self::PREFERRED . ' (adapter "codex")',
            $resumed->standardOutput,
            $resumed->describe(),
        );

        // The interrupted agent finished its own work, and the one the
        // configuration now names was never asked.
        self::assertCount(2, $this->project->agent(self::PREFERRED)->attempts());
        self::assertSame([], $this->project->agent(self::ALTERNATE)->attempts());

        $branch = $this->branchFor(self::FIRST);
        self::assertSame([self::PREFERRED_FILE], $this->filesIn($branch));
        self::assertCount(1, $this->project->commitsOn($branch), 'A resumed task is committed once.');
        self::assertStringContainsString('- [x] ' . self::FIRST, $this->project->checklist());

        // The row says the agent was the continued run's rather than a choice
        // taken under settings that have since changed.
        $run = $this->project->journal()->runs()->latest();
        self::assertNotNull($run);
        self::assertSame(self::PREFERRED, $run->agent()->name());
        self::assertSame(AgentSource::ResumedRun, $run->agentSource());
        self::assertNotNull($run->resumedFromRunId());
    }

    /**
     * Two agents a run could sensibly choose between, each writing its own
     * file so the commit says which one was used.
     */
    private function givenTwoAgents(): LoopProject
    {
        return $this->project
            ->withAgent(self::PREFERRED, [
                FixtureStep::success([self::PREFERRED_FILE => "<?php\n\n// by the preferred agent\n"]),
            ])
            ->withAgent(self::ALTERNATE, [
                FixtureStep::success([self::ALTERNATE_FILE => "<?php\n\n// by the alternate agent\n"]),
            ]);
    }

    /**
     * How many runs this project has on record, which is none until an agent
     * has been resolved.
     *
     * Counted in rows rather than asserted of the file: the state store is
     * opened before the agent is selected, so a refusal can leave an empty
     * database behind and "nothing was recorded" is a statement about what is
     * in it.
     */
    private function runsRecorded(): int
    {
        return is_file(StateBootstrap::fileIn($this->project->root()))
            ? count($this->project->journal()->runs()->all())
            : 0;
    }

    /**
     * The identifier the loop knows a task by, which comes from its words.
     */
    private function identify(string $description): TaskId
    {
        return TaskId::fromDescription($description);
    }

    /**
     * The newest pass at a task, where its state, branch and commit live.
     */
    private function passFor(string $description): GenerationRecord
    {
        $pass = $this->project->journal()->generations()->latestFor($this->identify($description));

        if ($pass === null) {
            self::fail(sprintf('Nothing has been attempted for "%s".', $description));
        }

        return $pass;
    }

    /**
     * The branch a task's pass owns.
     */
    private function branchFor(string $description): string
    {
        return (string) $this->passFor($description)->branch();
    }

    /**
     * The files a branch adds to the project, which is the work one agent did.
     *
     * @return list<string>
     */
    private function filesIn(string $branch): array
    {
        if ($branch === '') {
            return [];
        }

        $diff = Git::tryRun($this->project->root(), 'diff', '--name-only', 'HEAD..' . $branch);

        return $diff->exitCode === 0 ? $diff->standardOutputLines() : [];
    }
}
