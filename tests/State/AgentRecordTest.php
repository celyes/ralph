<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\State\AgentRecord;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The agent a pass was carried out with, as it is written down.
 *
 * Two things are under test and the second one matters more than the first.
 * The first is that the record survives a trip through its three columns, so
 * resume continues with the agent the work was started under rather than with
 * whatever the configuration file says today. The second is that nothing
 * secret can get into those columns: the settings carry the *names* of the
 * environment variables an agent authenticates with and never their values,
 * and that is what makes the row safe to keep in a file inside the user's
 * repository and to print in diagnostics.
 */
#[CoversClass(AgentRecord::class)]
final class AgentRecordTest extends TestCase
{
    #[Test]
    public function a_definition_is_recorded_with_its_name_adapter_and_settings(): void
    {
        $record = AgentRecord::ofDefinition(AgentDefinition::builtIn('codex'));

        self::assertSame('codex', $record->name());
        self::assertSame('codex', $record->adapter());
        self::assertSame('codex', $record->executable());
        self::assertArrayHasKey('arguments', $record->settings());
    }

    /**
     * The name is the user's label and the adapter is the implementation, so a
     * project can configure two agents that share an adapter — status shows the
     * name while resume finds the adapter.
     */
    #[Test]
    public function the_name_and_the_adapter_are_separate_facts(): void
    {
        $fast = AgentRecord::ofDefinition(new AgentDefinition('fast', 'claude', 'claude', 'haiku'));
        $careful = AgentRecord::ofDefinition(new AgentDefinition('careful', 'claude', 'claude', 'opus'));

        self::assertSame('fast', $fast->name());
        self::assertSame('careful', $careful->name());
        self::assertSame('claude', $fast->adapter());
        self::assertSame('claude', $careful->adapter());
        self::assertFalse(
            $fast->isSameAgent($careful),
            'Two agents sharing an adapter are still different agents.',
        );
    }

    /**
     * A run records the agent it resolved, whichever way it was chosen: the
     * record is of the definition, so where the selection came from does not
     * change what is stored.
     */
    #[Test]
    public function a_resolved_selection_is_recorded(): void
    {
        $definition = new AgentDefinition('claude', 'claude', 'claude', 'opus');

        foreach ([AgentSource::ProjectDefault, AgentSource::CommandLine] as $source) {
            $record = AgentRecord::of(new AgentSelection($definition, $source));

            self::assertSame('claude', $record->name());
            self::assertSame('claude', $record->adapter());
            self::assertSame('opus', $record->model());
        }
    }

    #[Test]
    public function the_model_is_read_when_one_was_configured(): void
    {
        self::assertSame(
            'opus',
            AgentRecord::ofDefinition(
                new AgentDefinition('claude', 'claude', 'claude', 'opus'),
            )->model(),
        );
        self::assertNull(
            AgentRecord::ofDefinition(
                new AgentDefinition('claude', 'claude', 'claude'),
            )->model(),
        );
    }

    /**
     * The whole reason this type exists rather than the stores serialising
     * whatever configuration object they are holding.
     */
    #[Test]
    public function the_stored_settings_carry_variable_names_and_never_their_values(): void
    {
        putenv('RALPH_TEST_AGENT_TOKEN=sk-do-not-store-me');

        try {
            $record = AgentRecord::ofDefinition(new AgentDefinition(
                'codex',
                'codex',
                'codex',
                null,
                [],
                ['RALPH_TEST_AGENT_TOKEN'],
            ));

            $stored = $record->toColumns()['settings'];

            self::assertStringContainsString('RALPH_TEST_AGENT_TOKEN', $stored);
            self::assertStringNotContainsString('sk-do-not-store-me', $stored);
            self::assertStringNotContainsString('sk-do-not-store-me', $record->describe());
        } finally {
            putenv('RALPH_TEST_AGENT_TOKEN');
        }
    }

    #[Test]
    public function a_record_survives_a_trip_through_its_three_columns(): void
    {
        $original = AgentRecord::ofDefinition(new AgentDefinition(
            'careful',
            'claude',
            '/usr/local/bin/claude',
            'opus',
            ['--no-color'],
            ['ANTHROPIC_API_KEY'],
        ));
        $columns = $original->toColumns();

        $read = AgentRecord::fromStorage($columns['agent'], $columns['adapter'], $columns['settings']);

        self::assertSame($original->name(), $read->name());
        self::assertSame($original->adapter(), $read->adapter());
        self::assertSame($original->settings(), $read->settings());
        self::assertSame('/usr/local/bin/claude', $read->executable());
        self::assertSame('opus', $read->model());
    }

    /**
     * The settings are read as whatever was stored rather than validated
     * against today's definition: the column says what the earlier run was
     * configured with, and a row written before a setting existed must still
     * be readable.
     */
    #[Test]
    public function settings_a_newer_build_does_not_recognise_are_read_as_stored(): void
    {
        $read = AgentRecord::fromStorage(
            'codex',
            'codex',
            '{"executable":"codex","retired_option":true,"model":null}',
        );

        self::assertTrue($read->setting('retired_option'));
        self::assertSame('codex', $read->executable());
        self::assertNull($read->model());
    }

    #[Test]
    public function a_setting_the_row_never_had_reads_as_null(): void
    {
        $read = AgentRecord::fromStorage('codex', 'codex', '{}');

        self::assertNull($read->setting('executable'));
        self::assertNull($read->executable());
        self::assertNull($read->model());
        self::assertSame([], $read->settings());
    }

    /**
     * A non-string in a column that must be text is reported as absent rather
     * than cast, since "the executable is `true`" is not a path.
     */
    #[Test]
    public function a_setting_of_the_wrong_type_is_not_read_as_text(): void
    {
        $read = AgentRecord::fromStorage('codex', 'codex', '{"executable":42,"model":["opus"]}');

        self::assertNull($read->executable());
        self::assertNull($read->model());
    }

    #[Test]
    public function settings_that_are_not_readable_json_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not readable JSON');

        AgentRecord::fromStorage('codex', 'codex', '{"executable":');
    }

    #[Test]
    public function settings_that_are_not_an_object_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a JSON object');

        AgentRecord::fromStorage('codex', 'codex', '"codex"');
    }

    #[Test]
    public function a_recorded_agent_must_have_a_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have a name');

        AgentRecord::fromStorage('   ', 'codex', '{}');
    }

    #[Test]
    public function a_recorded_agent_must_have_an_adapter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have a adapter');

        AgentRecord::fromStorage('codex', '', '{}');
    }

    /**
     * The question resume asks. A user who moved a binary between runs has not
     * changed agents, and PROJECT.md rejects an implicit change of *agent*.
     */
    #[Test]
    public function the_same_agent_at_a_new_path_is_still_the_same_agent(): void
    {
        $before = AgentRecord::ofDefinition(new AgentDefinition('codex', 'codex', '/usr/bin/codex'));
        $after = AgentRecord::ofDefinition(new AgentDefinition('codex', 'codex', '/opt/codex/bin/codex'));

        self::assertTrue($before->isSameAgent($after));
        self::assertNotSame($before->executable(), $after->executable());
    }

    #[Test]
    public function a_different_adapter_under_the_same_name_is_not_the_same_agent(): void
    {
        $codex = AgentRecord::ofDefinition(new AgentDefinition('agent', 'codex', 'codex'));
        $claude = AgentRecord::ofDefinition(new AgentDefinition('agent', 'claude', 'claude'));

        self::assertFalse($codex->isSameAgent($claude));
    }

    #[Test]
    public function a_record_can_be_asked_whether_it_is_the_named_agent(): void
    {
        $record = AgentRecord::ofDefinition(AgentDefinition::builtIn('claude'));

        self::assertTrue($record->is('claude'));
        self::assertFalse($record->is('codex'));
        self::assertFalse($record->is('Claude'), 'Agent names are compared exactly.');
    }

    #[Test]
    public function a_name_and_adapter_are_stored_without_surrounding_whitespace(): void
    {
        $read = AgentRecord::fromStorage("  codex\n", " codex ", '{}');

        self::assertSame('codex', $read->name());
        self::assertSame('codex', $read->adapter());
    }

    #[Test]
    public function a_record_describes_itself_with_its_adapter_and_model(): void
    {
        self::assertSame(
            'careful (adapter "claude", model "opus")',
            AgentRecord::ofDefinition(
                new AgentDefinition('careful', 'claude', 'claude', 'opus'),
            )->describe(),
        );
        self::assertSame(
            'codex (adapter "codex")',
            AgentRecord::ofDefinition(
                new AgentDefinition('codex', 'codex', 'codex'),
            )->describe(),
        );
    }

    /**
     * The settings column is JSON written for a human to read in `sqlite3`, so
     * paths keep their slashes.
     */
    #[Test]
    public function the_settings_column_is_written_unescaped(): void
    {
        $stored = AgentRecord::ofDefinition(
            new AgentDefinition('codex', 'codex', '/usr/local/bin/codex'),
        )->toColumns()['settings'];

        self::assertStringContainsString('/usr/local/bin/codex', $stored);
        self::assertStringNotContainsString('\\/', $stored);
    }
}
