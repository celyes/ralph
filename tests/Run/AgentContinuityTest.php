<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Run;

use Celyes\RalphPhp\Agent\AdapterCatalog;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Exception\UsageException;
use Celyes\RalphPhp\Run\AgentContinuation;
use Celyes\RalphPhp\Run\AgentContinuity;
use Celyes\RalphPhp\State\AgentRecord;
use Celyes\RalphPhp\State\Database;
use Celyes\RalphPhp\State\RunRecord;
use Celyes\RalphPhp\State\RunStore;
use Celyes\RalphPhp\State\StateBootstrap;
use Celyes\RalphPhp\Support\FrozenClock;
use Celyes\RalphPhp\Tests\Support\Cli;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Resuming with the agent the work was started under (PROJECT.md section H,
 * item 6).
 *
 * The item is one promise and one refusal, and both are asserted against real
 * run rows written by {@see RunStore} rather than against a hand-built record:
 * the whole point is that the agent comes off the row, so a test that built the
 * row in memory would be testing the wrong half.
 *
 * The promise is that the invocation is the recorded one — executable, model,
 * argument array and authentication variable names — even when
 * `.ralph/config.json` now says something else. Each of those edits is made for
 * real, through a configuration file the loader reads, because "the
 * configuration gets no vote" is only worth asserting against a configuration
 * that is actually trying to vote.
 *
 * The refusal is an agent change, and it is asserted twice over: once where a
 * user asks for it (`--agent` naming something else) and once where the store
 * would have to write it. Both messages are asserted to name what to type
 * instead, since the item asks for the alternative to be documented and a hint
 * a user reads at the moment they need it is the documentation that reaches
 * them.
 */
#[CoversClass(AgentContinuity::class)]
#[CoversClass(AgentContinuation::class)]
#[CoversClass(AgentSource::class)]
#[CoversClass(AgentRecord::class)]
final class AgentContinuityTest extends TestCase
{
    private TemporaryProject $project;

    private Database $database;

    private RunStore $runs;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $this->database = (new StateBootstrap())->openInMemory()->database();
        $this->runs = new RunStore($this->database, FrozenClock::at('2026-11-08T09:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * The item, in one case: the row decides, and it says where it came from.
     */
    #[Test]
    public function a_resume_continues_with_the_agent_the_run_recorded(): void
    {
        $agreeing = [
            'agent' => 'claude',
            'agents' => ['claude' => ['executable' => '/opt/claude/bin/claude', 'model' => 'claude-opus-5']],
        ];
        $interrupted = $this->interrupted(
            $this->definition('claude', 'claude', '/opt/claude/bin/claude', 'claude-opus-5'),
            $agreeing,
        );

        $continuation = (new AgentContinuity())->continuation($interrupted, $this->configuration($agreeing));

        self::assertSame('claude', $continuation->name());
        self::assertSame('claude', $continuation->adapter());
        self::assertSame(AgentSource::ResumedRun, $continuation->selection()->source());
        self::assertFalse($continuation->selection()->isOverride());
        self::assertSame('/opt/claude/bin/claude', $continuation->selection()->definition()->executable());
        self::assertSame('claude-opus-5', $continuation->selection()->definition()->model());
        self::assertFalse($continuation->hasDrift());
        self::assertFalse($continuation->wasConfirmed());
        self::assertSame([], $continuation->lines());
        self::assertSame($interrupted->runId(), $continuation->previous()->runId());
    }

    /**
     * The other half of the item: the non-secret settings are the recorded
     * ones, not today's.
     */
    #[Test]
    public function the_recorded_settings_are_used_when_the_configuration_has_moved_on(): void
    {
        $interrupted = $this->interrupted(
            $this->definition('codex', 'codex', '/opt/codex/bin/codex', 'gpt-5-codex', ['--full-auto'], ['CODEX_TOKEN']),
        );

        $continuation = (new AgentContinuity())->continuation($interrupted, $this->configuration([
            'agents' => [
                'codex' => [
                    'executable' => '/usr/local/bin/codex',
                    'model' => 'gpt-5-codex-mini',
                    'arguments' => ['--ask-for-approval'],
                    'environment' => ['CODEX_API_KEY'],
                ],
            ],
        ]));

        $definition = $continuation->selection()->definition();

        self::assertSame('/opt/codex/bin/codex', $definition->executable());
        self::assertSame('gpt-5-codex', $definition->model());
        self::assertSame(['--full-auto'], $definition->arguments());
        self::assertSame(['CODEX_TOKEN'], $definition->environment());
    }

    /**
     * Using the recorded settings silently would leave a user reading the file
     * and watching something else happen.
     */
    #[Test]
    public function every_setting_the_configuration_now_disagrees_with_is_reported(): void
    {
        $interrupted = $this->interrupted(
            $this->definition('codex', 'codex', '/opt/codex/bin/codex', null, ['--full-auto']),
        );

        $continuation = (new AgentContinuity())->continuation($interrupted, $this->configuration([
            'agents' => [
                'codex' => [
                    'executable' => '/usr/local/bin/codex',
                    'model' => 'gpt-5-codex',
                    'arguments' => [],
                ],
            ],
        ]));

        self::assertTrue($continuation->hasDrift());
        self::assertSame($continuation->drift(), $continuation->lines());
        self::assertContains(
            sprintf(
                '"executable" is now "/usr/local/bin/codex" for agent "codex"; run %s recorded "/opt/codex/bin/codex", which is what this resume uses',
                $interrupted->runId(),
            ),
            $continuation->drift(),
        );
        self::assertContains(
            sprintf(
                '"model" is now "gpt-5-codex" for agent "codex"; run %s recorded none, which is what this resume uses',
                $interrupted->runId(),
            ),
            $continuation->drift(),
        );
        self::assertContains(
            sprintf(
                '"arguments" is now empty for agent "codex"; run %s recorded ["--full-auto"], which is what this resume uses',
                $interrupted->runId(),
            ),
            $continuation->drift(),
        );
        self::assertStringContainsString('3 settings since changed', $continuation->describe());
    }

    #[Test]
    public function a_project_default_that_has_moved_on_does_not_change_the_resume(): void
    {
        $interrupted = $this->interrupted($this->definition('codex', 'codex', 'codex'));

        $continuation = (new AgentContinuity())->continuation(
            $interrupted,
            $this->configuration(['agent' => 'claude']),
        );

        self::assertSame('codex', $continuation->name());
        self::assertContains(
            sprintf(
                'the project\'s default agent is now "claude"; run %s recorded "codex", which is what this resume uses',
                $interrupted->runId(),
            ),
            $continuation->drift(),
        );
    }

    #[Test]
    public function an_agent_no_longer_configured_at_all_is_resumed_from_its_record(): void
    {
        $interrupted = $this->interrupted(
            $this->definition('codex-fast', 'codex', '/opt/codex/bin/codex'),
        );

        $continuation = (new AgentContinuity())->continuation($interrupted, $this->configuration([
            'agent' => 'codex',
        ]));

        self::assertSame('codex-fast', $continuation->name());
        self::assertSame('/opt/codex/bin/codex', $continuation->selection()->definition()->executable());
        self::assertContains(
            sprintf(
                'no agent named "codex-fast" is configured any more; the settings recorded with run %s are used',
                $interrupted->runId(),
            ),
            $continuation->drift(),
        );
    }

    /**
     * An adapter name the configuration changed under the same agent is drift
     * like any other setting: the resume uses the one that produced the work.
     */
    #[Test]
    public function an_adapter_the_configuration_now_states_differently_does_not_change_the_resume(): void
    {
        $interrupted = $this->interrupted($this->definition('house', 'codex', './bin/worker'));

        $continuation = (new AgentContinuity())->continuation($interrupted, $this->configuration([
            'agent' => 'house',
            'agents' => ['house' => ['adapter' => 'claude', 'executable' => './bin/worker']],
        ]));

        self::assertSame('codex', $continuation->adapter());
        self::assertContains(
            sprintf(
                '"adapter" is now "claude" for agent "house"; run %s recorded "codex", which is what this resume uses',
                $interrupted->runId(),
            ),
            $continuation->drift(),
        );
    }

    #[Test]
    public function naming_the_recorded_agent_on_the_command_line_is_a_confirmation(): void
    {
        $interrupted = $this->interrupted($this->definition('claude', 'claude', 'claude'));

        $continuation = (new AgentContinuity())->continuation(
            $interrupted,
            $this->configuration(['agent' => 'claude']),
            'claude',
        );

        self::assertTrue($continuation->wasConfirmed());
        self::assertSame([sprintf(
            '"--agent claude" names the agent run %s recorded, so the resume continues with it',
            $interrupted->runId(),
        )], $continuation->lines());
    }

    /**
     * The refusal, and the documented alternative that has to come with it.
     */
    #[Test]
    public function asking_a_resume_for_a_different_agent_is_refused_with_the_way_to_get_one(): void
    {
        $interrupted = $this->interrupted($this->definition('codex', 'codex', 'codex'));

        $exception = $this->refusal($interrupted, $this->configuration(['agent' => 'codex']), 'claude');

        self::assertSame(
            sprintf(
                'Run %s was carried out with the agent "codex"; resuming it with "claude" would change agents part-way through its work.',
                $interrupted->runId(),
            ),
            $exception->getMessage(),
        );
        self::assertSame(ExitCode::CONFIGURATION, $exception->exitCode());
        self::assertContains(
            sprintf('Omit "--agent" to continue run %s with "codex".', $interrupted->runId()),
            $exception->hints(),
        );
        self::assertContains(
            'To use "claude", reconcile this run\'s interrupted work first — `ralph status` shows what is outstanding — '
                . 'and then start a new run: `ralph run --agent claude`.',
            $exception->hints(),
        );
        self::assertContains(
            'A new run begins with the configured limits whole; tasks earlier runs completed stay completed, '
                . 'and their commits and attempt history stay attributed to the agent that earned them.',
            $exception->hints(),
        );
    }

    #[Test]
    public function an_empty_agent_option_is_a_usage_error_naming_the_recorded_agent(): void
    {
        $interrupted = $this->interrupted($this->definition('codex', 'codex', 'codex'));

        try {
            (new AgentContinuity())->continuation($interrupted, $this->configuration([]), '  ');
            self::fail('Expected the continuation to be refused.');
        } catch (UsageException $exception) {
            self::assertSame('Option "--agent" requires an agent name.', $exception->getMessage());
            self::assertSame(ExitCode::USAGE, $exception->exitCode());
            self::assertContains(
                sprintf('Run %s was carried out with "codex".', $interrupted->runId()),
                $exception->hints(),
            );
        }
    }

    #[Test]
    public function a_recorded_adapter_this_build_cannot_execute_is_refused_without_a_fallback(): void
    {
        $interrupted = $this->interrupted($this->definition('house', 'house-agent', './bin/worker'));

        $continuity = new AgentContinuity($this->catalog(['codex', 'claude']));
        $exception = $this->refusal($interrupted, $this->configuration(['agent' => 'codex']), null, $continuity);

        self::assertSame(
            sprintf(
                'Run %s was carried out by the agent "house" through the adapter "house-agent", which is not available.',
                $interrupted->runId(),
            ),
            $exception->getMessage(),
        );
        self::assertContains('Available adapters: "codex", "claude".', $exception->hints());
        self::assertContains(
            sprintf(
                'Register the adapter under that name again to resume this run, or start a new run with an available agent: '
                    . '`ralph run --agent NAME`, with the agents configured in %s.',
                $this->project->path('.ralph/config.json'),
            ),
            $exception->hints(),
        );
        self::assertContains(
            'Nothing is resumed with a different adapter: the work this run left behind was produced by that one.',
            $exception->hints(),
        );
    }

    #[Test]
    public function an_unavailable_adapter_is_reported_with_a_suggestion_when_the_name_is_close(): void
    {
        $interrupted = $this->interrupted($this->definition('codex', 'codexx', 'codex'));

        $exception = $this->refusal($interrupted, $this->configuration([]));

        self::assertContains('Did you mean "codex"?', $exception->hints());
    }

    /**
     * An adapter registered by the project is as resumable as a built-in one:
     * the catalog is the only authority on what can be executed.
     */
    #[Test]
    public function a_recorded_adapter_the_project_registers_is_resumed(): void
    {
        $interrupted = $this->interrupted($this->definition('house', 'house-agent', './bin/worker'));

        $continuation = (new AgentContinuity($this->catalog(['house-agent'])))
            ->continuation($interrupted, $this->configuration(['agent' => 'codex']));

        self::assertSame('house-agent', $continuation->adapter());
        self::assertSame('./bin/worker', $continuation->selection()->definition()->executable());
    }

    #[Test]
    public function the_continuation_is_reportable_without_a_credential_in_it(): void
    {
        $variable = 'RALPH_TEST_RESUME_TOKEN';
        putenv($variable . '=super-secret-value');

        try {
            $interrupted = $this->interrupted(
                $this->definition('codex', 'codex', 'codex', 'gpt-5-codex', [], [$variable]),
            );

            $continuation = (new AgentContinuity())->continuation($interrupted, $this->configuration([
                'agent' => 'codex',
                'agents' => ['codex' => ['model' => 'gpt-5-codex', 'environment' => [$variable]]],
            ]));
            $reported = json_encode($continuation->toArray(), JSON_THROW_ON_ERROR);

            self::assertStringContainsString($variable, $reported);
            self::assertStringNotContainsString('super-secret-value', $reported);
            self::assertSame([
                'resuming' => $interrupted->runId(),
                'agent' => 'codex',
                'adapter' => 'codex',
                'source' => 'resumed_run',
                'settings' => [
                    'adapter' => 'codex',
                    'executable' => 'codex',
                    'model' => 'gpt-5-codex',
                    'arguments' => [],
                    'environment' => [$variable],
                ],
                'confirmed_on_command_line' => false,
                'drift' => [],
            ], $continuation->toArray());
            self::assertSame(
                sprintf(
                    'continuing run %s with codex (adapter "codex", model "gpt-5-codex"), as recorded',
                    $interrupted->runId(),
                ),
                $continuation->describe(),
            );
        } finally {
            putenv($variable);
        }
    }

    /**
     * A row that does not say how to invoke the agent is not guessed at.
     */
    #[Test]
    public function a_record_without_an_executable_cannot_be_resumed(): void
    {
        $interrupted = $this->interrupted($this->definition('codex', 'codex', 'codex'));
        $this->database->execute(
            'UPDATE runs SET agent_settings = ? WHERE run_id = ?',
            ['{"adapter":"codex","model":null}', $interrupted->runId()],
        );

        try {
            (new AgentContinuity())->continuation($this->reread($interrupted), $this->configuration([]));
            self::fail('Expected the continuation to be refused.');
        } catch (StateException $exception) {
            self::assertSame(
                'The recorded settings for agent "codex" do not name an executable.',
                $exception->getMessage(),
            );
            self::assertContains(
                'Start a new run instead: `ralph run --agent codex` reads the settings from the configuration file.',
                $exception->hints(),
            );
        }
    }

    /**
     * An argument array that cannot be read back is refused rather than
     * partly used: an invocation assembled from half a recorded array is a
     * different invocation.
     */
    #[Test]
    public function a_record_whose_arguments_are_unreadable_cannot_be_resumed(): void
    {
        $interrupted = $this->interrupted($this->definition('codex', 'codex', 'codex'));
        $this->database->execute(
            'UPDATE runs SET agent_settings = ? WHERE run_id = ?',
            ['{"adapter":"codex","executable":"codex","arguments":["--full-auto",7]}', $interrupted->runId()],
        );

        try {
            (new AgentContinuity())->continuation($this->reread($interrupted), $this->configuration([]));
            self::fail('Expected the continuation to be refused.');
        } catch (StateException $exception) {
            self::assertSame(
                'The recorded "arguments" setting for agent "codex" is not a list of strings.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * The storage half of the same promise: whatever a caller believes, the
     * continuation row cannot name another agent.
     */
    #[Test]
    public function the_store_refuses_a_continuation_recording_another_agent(): void
    {
        $interrupted = $this->interrupted($this->definition('codex', 'codex', 'codex'));
        $configuration = $this->configuration([]);

        try {
            $this->runs->resume(
                $interrupted,
                new AgentSelection($this->definition('claude', 'claude', 'claude'), AgentSource::ResumedRun),
                $configuration,
            );
            self::fail('Expected the resume to be refused.');
        } catch (StateException $exception) {
            self::assertSame(
                sprintf(
                    'Run %s was carried out with the agent "codex"; a run continuing it cannot record "claude".',
                    $interrupted->runId(),
                ),
                $exception->getMessage(),
            );
            self::assertContains(
                'To use "claude", start a new run instead: `ralph run --agent claude`, which begins with the configured limits whole.',
                $exception->hints(),
            );
        }

        self::assertNull($this->runs->latest()?->resumedFrom());
    }

    /**
     * What `ralph resume` does with the continuation, end to end: the row it
     * opens says the recorded agent, the recorded settings, and that the agent
     * came from the run being continued.
     */
    #[Test]
    public function the_resumed_run_row_records_the_recorded_agent_and_its_source(): void
    {
        $interrupted = $this->interrupted(
            $this->definition('codex', 'codex', '/opt/codex/bin/codex', 'gpt-5-codex'),
        );
        $configuration = $this->configuration(['agents' => ['codex' => ['executable' => '/usr/local/bin/codex']]]);

        $continuation = (new AgentContinuity())->continuation($interrupted, $configuration);
        $resumed = $this->runs->resume($interrupted, $continuation->selection(), $configuration);

        self::assertTrue($resumed->isResume());
        self::assertSame($interrupted->runId(), $resumed->resumedFromRunId());
        self::assertSame('codex', $resumed->agent()->name());
        self::assertSame(AgentSource::ResumedRun, $resumed->agentSource());
        self::assertSame('/opt/codex/bin/codex', $resumed->agent()->executable());
        self::assertSame('gpt-5-codex', $resumed->agent()->model());
        self::assertTrue($resumed->agent()->isSameAgent($interrupted->agent()));
    }

    /**
     * The item's documentation half, where a user would look for it rather
     * than in a message they have to provoke first.
     */
    #[Test]
    public function the_resume_help_documents_beginning_a_new_run_with_another_agent(): void
    {
        $help = Cli::run(['help', 'resume'], $this->project->root());

        self::assertSame(ExitCode::SUCCESS, $help->exitCode, $help->describe());
        self::assertStringContainsString(
            'continues with the agent and settings recorded for the original run',
            $help->standardOutput,
            $help->describe(),
        );
        self::assertStringContainsString('A resume cannot', $help->standardOutput, $help->describe());
        self::assertStringContainsString('ralph run --agent NAME', $help->standardOutput, $help->describe());
        self::assertStringContainsString(
            'configured limits whole',
            $help->standardOutput,
            $help->describe(),
        );
    }

    /**
     * An interrupted run, opened with the given agent and then stopped — the
     * state a resume is for.
     *
     * @param array<string, mixed> $settings
     */
    private function interrupted(AgentDefinition $definition, array $settings = []): RunRecord
    {
        $run = $this->runs->open(
            new AgentSelection($definition, AgentSource::ProjectDefault),
            $this->configuration($settings),
        );

        return $this->runs->interrupt($run, 'Ctrl+C');
    }

    /**
     * The row as it now stands, for a test that edited a column by hand.
     */
    private function reread(RunRecord $run): RunRecord
    {
        $record = $this->runs->find($run->runId());

        self::assertNotNull($record);

        return $record;
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $environment
     */
    private function definition(
        string $name,
        string $adapter,
        string $executable,
        ?string $model = null,
        array $arguments = [],
        array $environment = [],
    ): AgentDefinition {
        return new AgentDefinition($name, $adapter, $executable, $model, $arguments, $environment);
    }

    /**
     * A configuration loaded from a real file, so that the paths quoted in the
     * hints are the ones a user would have to edit.
     *
     * @param array<string, mixed> $settings
     */
    private function configuration(array $settings): Configuration
    {
        $this->project->writeConfigArray($settings === [] ? ['agent' => 'codex'] : $settings);

        return (new ConfigurationLoader())->load($this->project->root());
    }

    /**
     * @param list<string> $adapters
     */
    private function catalog(array $adapters): AdapterCatalog
    {
        return new class ($adapters) implements AdapterCatalog {
            /**
             * @param list<string> $adapters
             */
            public function __construct(private readonly array $adapters)
            {
            }

            public function has(string $adapter): bool
            {
                return in_array($adapter, $this->adapters, true);
            }

            /**
             * @return list<string>
             */
            public function names(): array
            {
                return $this->adapters;
            }
        };
    }

    private function refusal(
        RunRecord $previous,
        Configuration $configuration,
        ?string $requested = null,
        ?AgentContinuity $continuity = null,
    ): ConfigurationException {
        try {
            ($continuity ?? new AgentContinuity())->continuation($previous, $configuration, $requested);
        } catch (ConfigurationException $exception) {
            return $exception;
        }

        self::fail('Expected the continuation to be refused.');
    }
}
