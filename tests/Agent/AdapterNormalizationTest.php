<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AdapterProvider;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Agent\BuiltInAdapters;
use Celyes\RalphPhp\Agent\ClaudeAdapter;
use Celyes\RalphPhp\Agent\CodexAdapter;
use Celyes\RalphPhp\Agent\ExecutionBudget;
use Celyes\RalphPhp\Agent\ExecutionRequest;
use Celyes\RalphPhp\Agent\ExecutionResult;
use Celyes\RalphPhp\Agent\ProcessAdapter;
use Celyes\RalphPhp\Agent\ResultProtocol;
use Celyes\RalphPhp\Agent\Workspace;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Limits;
use Celyes\RalphPhp\Memory\AttemptContextLoader;
use Celyes\RalphPhp\State\AttemptOutcome;
use Celyes\RalphPhp\Tasks\ChecklistParser;
use Celyes\RalphPhp\Tasks\Task;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The shipped adapters answering the same agent behaviour the same way.
 *
 * PROJECT.md section E, item 10. The two adapters before this one are each
 * tested against their own CLI's command line, and both read their output
 * through {@see ProcessAdapter::reported()}, so the normalisation holds by
 * construction rather than by two adapters agreeing. What is not established by
 * that is the property the runner depends on: that for the *same* thing an
 * agent did, every adapter comes back with a result that is identical in
 * everything verification, committing and pushing act on. Two tests asserting
 * "Codex reports a candidate" and "Claude reports a candidate" would both pass
 * while the pair disagreed about the claim, the exit status, the evidence, or
 * whether the attempt may be retried.
 *
 * So this runs one scripted agent behaviour through every adapter
 * {@see BuiltInAdapters::providers()} lists and compares the results to each
 * other, field by field. The comparison is the assertion; the declared outcome
 * is asserted too, so that a pair which agreed on the *wrong* reading — every
 * behaviour an error, say — is not recorded as normalised.
 *
 * **Driven from the catalogue, not from a list of two.** A third shipped
 * adapter is held to the same property by existing, without this file being
 * edited, which is the only form of the statement that stays true as the
 * package grows. A project's own adapter is outside what this can reach — it
 * may legitimately read its agent's native reporting (section E, item 5) — but
 * it normalises onto the same {@see ExecutionResult} cases, and
 * {@see \Celyes\RalphPhp\Tests\Agent\ExecutionAdapterTest} is where that
 * contract is held.
 *
 * **One stub, one agent name, one workspace.** The adapters are configured as
 * the same agent — the same name, the same executable, the same stub behaviour
 * — because the names in a log note and in a piece of evidence are the
 * configured agent's, and an adapter comparison that let them differ would be
 * comparing two spellings instead of two readings. Nothing of the two
 * invocations is shared beyond that: each adapter builds its own command line
 * and sends its own preamble, which the stub ignores, exactly as a CLI that
 * had done the work and printed its report would.
 *
 * No agent CLI and no credentials are involved (section J, item 8): the stub is
 * a PHP program that prints what the test says and exits with the status the
 * test says.
 */
#[CoversClass(ProcessAdapter::class)]
#[CoversClass(CodexAdapter::class)]
#[CoversClass(ClaudeAdapter::class)]
#[CoversClass(BuiltInAdapters::class)]
final class AdapterNormalizationTest extends TestCase
{
    private const string DESCRIPTION = 'Add a --dry-run option to the import command';

    /**
     * The configured agent name, which is deliberately neither CLI's.
     *
     * `agents` entries are user-chosen labels, so one name over two adapters is
     * an ordinary configuration rather than a contrivance — and it is what
     * makes the recorded evidence comparable, since its labels name the agent.
     */
    private const string AGENT = 'agent';

    /**
     * The task's own workspace: the directory every adapter must run in.
     */
    private string $workspace;

    /**
     * The controlling checkout, which nothing here may touch.
     */
    private string $project;

    /**
     * The one stub both adapters are pointed at.
     */
    private string $executable;

    protected function setUp(): void
    {
        $this->workspace = TemporaryDirectory::create('ralph-workspace');
        $this->project = TemporaryDirectory::create('ralph-project');
        $this->executable = $this->project . '/agent';
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->workspace);
        TemporaryDirectory::remove($this->project);
    }

    /**
     * One agent behaviour, read by every shipped adapter, compared across them.
     *
     * @param string $body what the stub does: an agent session, scripted
     * @param AttemptOutcome $expected the normalised case it has to become
     */
    #[Test]
    #[DataProvider('behaviours')]
    public function every_adapter_reads_the_same_session_the_same_way(string $body, AttemptOutcome $expected): void
    {
        $this->stub($body);

        $records = $this->readByEveryAdapter(
            static function (ExecutionResult $result) use ($expected): void {
                self::assertSame($expected, $result->outcome());
            },
        );

        $this->assertAgreement($records);
    }

    /**
     * The sessions, as an agent's output and exit status.
     *
     * Every row is a case the loop behaves differently about, and the rows that
     * pair a declaration with a contradicting exit status are here because that
     * weighing — the observation outranking the declaration, except for a
     * question — is the part of the reading an adapter could plausibly have
     * done for itself and got differently.
     *
     * @return iterable<string, array{string, AttemptOutcome}>
     */
    public static function behaviours(): iterable
    {
        yield 'a candidate reported on standard output' => [
            self::printing('{"outcome": "candidate", "summary": "Added the option and a test."}'),
            AttemptOutcome::Candidate,
        ];

        yield 'a candidate reported on the error stream' => [
            self::printing('{"outcome": "candidate", "summary": "Added the option and a test."}', stream: 'STDERR'),
            AttemptOutcome::Candidate,
        ];

        yield 'a candidate reported by a session that then failed' => [
            self::printing('{"outcome": "candidate", "summary": "Added the option and a test."}', status: 1),
            AttemptOutcome::Error,
        ];

        yield 'a question' => [
            self::printing(
                '{"outcome": "blocked", "summary": "two import formats are plausible",'
                    . ' "next_action": "say which format the flag applies to"}',
            ),
            AttemptOutcome::Blocked,
        ];

        yield 'a question from a session that then failed' => [
            self::printing(
                '{"outcome": "blocked", "summary": "two import formats are plausible",'
                    . ' "next_action": "say which format the flag applies to"}',
                status: 3,
            ),
            AttemptOutcome::Blocked,
        ];

        yield 'an agent reporting that it could not do the work' => [
            self::printing('{"outcome": "error", "summary": "the import command does not exist"}'),
            AttemptOutcome::Error,
        ];

        yield 'a clean exit with nothing reported' => [
            'echo "I had a look around.";',
            AttemptOutcome::Error,
        ];

        yield 'a failure with nothing reported' => [
            'fwrite(STDERR, "stream error: unexpected status 429"); exit(1);',
            AttemptOutcome::Error,
        ];

        yield 'a session that printed nothing at all' => [
            'exit(0);',
            AttemptOutcome::Error,
        ];

        yield 'a block that cannot be read' => [
            self::printing('{"outcome": "candidate", summary: unquoted}'),
            AttemptOutcome::Error,
        ];

        yield 'a block declaring an outcome no agent may declare' => [
            self::printing('{"outcome": "completed", "summary": "done"}'),
            AttemptOutcome::Error,
        ];

        yield 'two blocks, of which the last is read' => [
            self::printing('{"outcome": "candidate", "summary": "Added the option."}')
                . self::printing(
                    '{"outcome": "blocked", "summary": "the second thought",'
                        . ' "next_action": "say which format the flag applies to"}',
                ),
            AttemptOutcome::Blocked,
        ];
    }

    /**
     * A session stopped by the attempt's own limit.
     *
     * Apart from the table because it needs a budget rather than a script, and
     * worth the pair of extra seconds it costs: a timeout is the one outcome no
     * adapter interprets — {@see ProcessAdapter} records it before
     * `interpret()` is reached — and an adapter that overrode that would be
     * reporting an ordinary failure for a limit that ran out, which is how a
     * user comes to raise the wrong one.
     */
    #[Test]
    public function every_adapter_records_an_overrun_as_the_same_timeout(): void
    {
        $this->stub('echo "thinking"; sleep(30); echo "never";');

        $records = $this->readByEveryAdapter(
            static function (ExecutionResult $result): void {
                self::assertSame(AttemptOutcome::TimedOut, $result->outcome());
                self::assertNull($result->exitCode());
            },
            new Limits(attemptsPerTask: 3, taskTimeout: 1, runTimeout: 7200),
        );

        $this->assertAgreement($records);
    }

    /**
     * The three cases an agent is allowed to declare are each in the table.
     *
     * A guard on the table rather than on the adapters: the normalisation this
     * file is about is of {@see ResultProtocol}'s declarable outcomes, so a
     * fourth one added there without a row here would leave the pair compared
     * on everything but the new case.
     */
    #[Test]
    public function every_outcome_an_agent_may_declare_is_in_the_table(): void
    {
        $exercised = [];

        foreach (self::behaviours() as [, $outcome]) {
            $exercised[$outcome->value] = true;
        }

        foreach (ResultProtocol::outcomes() as $declarable) {
            self::assertArrayHasKey(
                $declarable,
                $exercised,
                sprintf('No scripted session in this test becomes a "%s" result.', $declarable),
            );
        }
    }

    /**
     * There is a pair to compare in the first place.
     *
     * Every assertion above is about several adapters agreeing, and over a
     * single adapter all of them would pass while saying nothing. The package
     * ships two (section E, items 6 and 7), and a release that shipped one
     * should fail here rather than quietly reduce this file to a tautology.
     */
    #[Test]
    public function the_property_is_asserted_over_more_than_one_adapter(): void
    {
        $adapters = array_map(
            static fn (AdapterProvider $provider): string => $provider->adapter(),
            BuiltInAdapters::providers(),
        );

        self::assertGreaterThan(1, count($adapters));
        self::assertSame($adapters, array_unique($adapters));
        self::assertSame((new BuiltInAdapters())->names(), $adapters);
    }

    /**
     * Run the installed stub through every shipped adapter.
     *
     * @param callable(ExecutionResult): void $assert checked for each adapter
     *        before the results are compared, so that a disagreement is
     *        reported against a reading that was itself expected
     *
     * @return array<string, array<string, bool|int|string|null>>
     */
    private function readByEveryAdapter(callable $assert, ?Limits $limits = null): array
    {
        $records = [];

        foreach (BuiltInAdapters::providers() as $provider) {
            $adapter = $provider->create($this->definition());
            $result = $adapter->execute($this->request($limits));

            $assert($result);

            $records[$provider->adapter()] = self::recordOf($result);
        }

        return $records;
    }

    /**
     * Every adapter came back with the same result.
     *
     * Compared against the first rather than pairwise, which is the same thing
     * for equality and reports the mismatch once naming the adapter that
     * disagreed.
     *
     * @param array<string, array<string, bool|int|string|null>> $records
     */
    private function assertAgreement(array $records): void
    {
        self::assertSameSize(BuiltInAdapters::providers(), $records);

        $first = (string) array_key_first($records);

        foreach ($records as $adapter => $record) {
            self::assertSame(
                $records[$first],
                $record,
                sprintf(
                    'The "%s" adapter read this session differently from the "%s" adapter, so verification'
                        . ' and Git behaviour would depend on which agent ran.',
                    $adapter,
                    $first,
                ),
            );
        }
    }

    /**
     * Everything about a result the runner acts on or records.
     *
     * The outcome and the three questions asked of it, because those are what
     * decide whether verification runs, whether the run stops for a person and
     * whether the attempt is retried. The claim, the blocker and the evidence
     * in their stored form, because those are what a user reads and what the
     * next attempt is briefed with. The exit status, because it is persisted.
     *
     * Not the elapsed seconds: two invocations of the same stub take two
     * different amounts of time, and nothing downstream decides anything from
     * them.
     *
     * @return array<string, bool|int|string|null>
     */
    private static function recordOf(ExecutionResult $result): array
    {
        return [
            'outcome' => $result->outcome()->value,
            'has candidate' => $result->hasCandidate(),
            'requires human input' => $result->requiresHumanInput(),
            'is retryable' => $result->isRetryable(),
            'settles' => $result->settles()?->value,
            'claim' => $result->claim()->toStorage(),
            'blocker' => $result->blocker()?->toStorage(),
            'evidence' => $result->evidence()->toStorage(),
            'exit code' => $result->exitCode(),
        ];
    }

    /**
     * A stub body printing a result block, as a session's output holds it:
     * prose, the block, and — when the row asks for one — a failing status.
     *
     * The fence comes from {@see ResultProtocol} rather than being spelled
     * here, so a test that scripted a format nothing asks for is not possible.
     */
    private static function printing(string $report, int $status = 0, string $stream = 'STDOUT'): string
    {
        return sprintf(
            "fwrite(%s, %s);\n%s",
            $stream,
            var_export(sprintf("Reading the import command...\n```%s\n%s\n```\n", ResultProtocol::FENCE, $report), true),
            $status === 0 ? '' : sprintf("exit(%d);\n", $status),
        );
    }

    /**
     * A program standing in for whichever CLI an adapter thinks it is running.
     *
     * PHP rather than a shell script, run through its own absolute binary: the
     * body is written as code rather than composed into a command line. It
     * ignores its arguments and its prompt, which is what makes it the same
     * agent behaviour for every adapter — each adapter's own command line is
     * tested where that adapter is.
     */
    private function stub(string $body): void
    {
        $script = sprintf("#!%s\n<?php\n%s\n", PHP_BINARY, $body);

        if (file_put_contents($this->executable, $script) === false || !chmod($this->executable, 0o700)) {
            throw new RuntimeException('Unable to install the agent stub at ' . $this->executable . '.');
        }
    }

    /**
     * The same agent for every adapter: one name, one executable, nothing
     * agent-specific configured.
     */
    private function definition(): AgentDefinition
    {
        return new AgentDefinition(self::AGENT, self::AGENT, $this->executable);
    }

    private function request(?Limits $limits = null): ExecutionRequest
    {
        $task = Task::fromItem(
            (new ChecklistParser())->parse('- [ ] ' . self::DESCRIPTION . "\n", 'tasks.md')->items()[0],
        );

        return new ExecutionRequest(
            new AgentSelection($this->definition(), AgentSource::ProjectDefault),
            TaskGeneration::first($task->id()),
            1,
            $task,
            Workspace::at($this->workspace),
            (new AttemptContextLoader())->loadFor(
                (new ConfigurationLoader())->defaults($this->project),
                $task->id(),
            ),
            ExecutionBudget::of($limits ?? new Limits(attemptsPerTask: 3, taskTimeout: 60, runTimeout: 7200)),
            null,
            null,
        );
    }
}
