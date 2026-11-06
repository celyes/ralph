<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Config;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\VerificationCommand;
use Celyes\RalphPhp\Config\VerificationDrift;
use Celyes\RalphPhp\Config\VerificationSnapshot;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Freezing a run's checks, and saying what the configuration did afterwards.
 *
 * PROJECT.md section G, item 3. The checks decide whether a task's work may be
 * committed and its box ticked, and the worker that produced the work can write
 * to `.ralph/config.json`; a set read afresh each time it was needed would let
 * the worker choose what it is held to. So the two things asserted here are
 * that a frozen set survives the trip through a run's row unchanged, and that
 * an edit to the file is *reported* rather than either obeyed or swallowed.
 *
 * The comparison is deliberately made between two snapshots: a drift is a
 * statement about two readings, and keeping the file I/O outside this makes
 * "the file now says something else" and "the file can no longer be read" the
 * same kind of answer.
 */
#[CoversClass(VerificationSnapshot::class)]
#[CoversClass(VerificationDrift::class)]
#[CoversClass(VerificationCommand::class)]
final class VerificationSnapshotTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function it_freezes_a_projects_checks_in_their_configured_order(): void
    {
        $snapshot = VerificationSnapshot::of($this->configuration([
            ['name' => 'lint', 'command' => ['vendor/bin/phpstan', 'analyse'], 'timeout' => 120],
            ['name' => 'tests', 'command' => ['vendor/bin/phpunit'], 'timeout' => 900],
        ]));

        self::assertSame(['lint', 'tests'], $snapshot->names());
        self::assertSame(2, $snapshot->count());
        self::assertSame(['vendor/bin/phpstan', 'analyse'], $snapshot->commands()[0]->command());
        self::assertSame(900, (int) $snapshot->find('tests')?->timeout());
        self::assertNull($snapshot->find('coverage'));
    }

    /**
     * The whole point of the row: what comes back out is what went in.
     */
    #[Test]
    public function a_frozen_set_survives_storage_unchanged(): void
    {
        $frozen = VerificationSnapshot::of($this->configuration([
            ['name' => 'tests', 'command' => ['vendor/bin/phpunit', '--testsuite', 'unit tests'], 'timeout' => 42],
        ]));

        $restored = VerificationSnapshot::fromStorage($frozen->toStorage());

        self::assertSame($frozen->toStorage(), $restored->toStorage());
        self::assertSame($frozen->digest(), $restored->digest());
        self::assertFalse($frozen->compareTo($restored)->hasChanged());

        // The argument boundaries the user wrote, through the round trip: an
        // argument with a space in it is one argument.
        self::assertSame(
            ['vendor/bin/phpunit', '--testsuite', 'unit tests'],
            $restored->commands()[0]->command(),
        );
        self::assertSame(42, $restored->commands()[0]->timeout());
    }

    #[Test]
    public function the_digest_covers_every_part_of_every_check(): void
    {
        $base = ['name' => 'tests', 'command' => ['vendor/bin/phpunit'], 'timeout' => 600];

        $original = VerificationSnapshot::fromStorage([$base])->digest();

        self::assertNotSame(
            $original,
            VerificationSnapshot::fromStorage([['name' => 'suite'] + $base])->digest(),
            'A renamed check is a different set.',
        );
        self::assertNotSame(
            $original,
            VerificationSnapshot::fromStorage([['command' => ['true']] + $base])->digest(),
            'A different command is a different set.',
        );
        self::assertNotSame(
            $original,
            VerificationSnapshot::fromStorage([['timeout' => 1] + $base])->digest(),
            'A different timeout is a different set.',
        );
        self::assertSame($original, VerificationSnapshot::fromStorage([$base])->digest());
    }

    /**
     * A run with nothing to verify against could tick a box for anything, so
     * there is no such run.
     */
    #[Test]
    public function an_empty_set_cannot_be_frozen(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('at least one verification command');

        VerificationSnapshot::ofCommands([], 'the test');
    }

    #[Test]
    public function two_checks_with_one_name_cannot_be_frozen(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('both named "tests"');

        VerificationSnapshot::ofCommands(
            [
                new VerificationCommand('tests', ['vendor/bin/phpunit']),
                new VerificationCommand('tests', ['true']),
            ],
            'the test',
        );
    }

    /**
     * A recorded check that cannot be read back is refused: carrying on would
     * mean verifying against something other than what the run froze, which is
     * exactly what freezing it was for.
     */
    #[Test]
    public function a_stored_check_that_does_not_describe_a_command_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('run-19700101-000000-abcdef');

        VerificationSnapshot::fromStorage(
            [['name' => 'tests', 'timeout' => 600]],
            'the checks run run-19700101-000000-abcdef froze',
        );
    }

    #[Test]
    public function a_stored_check_with_an_unknown_setting_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);

        VerificationSnapshot::fromStorage([[
            'name' => 'tests',
            'command' => ['vendor/bin/phpunit'],
            'retries' => 3,
        ]]);
    }

    #[Test]
    public function an_unchanged_configuration_is_not_drift(): void
    {
        $checks = [['name' => 'tests', 'command' => ['vendor/bin/phpunit'], 'timeout' => 600]];
        $drift = VerificationSnapshot::of($this->configuration($checks))
            ->compareTo(VerificationSnapshot::of($this->configuration($checks)));

        self::assertFalse($drift->hasChanged());
        self::assertSame([], $drift->lines());
        self::assertSame('the verification configuration is unchanged', $drift->describe());
        self::assertFalse((bool) $drift->toArray()['changed']);
    }

    /**
     * The edit a worker would make to pass without passing: the suite goes,
     * something harmless arrives.
     */
    #[Test]
    public function a_replaced_check_is_reported_as_a_removal_and_an_addition(): void
    {
        $drift = $this->drift(
            [['name' => 'tests', 'command' => ['vendor/bin/phpunit']]],
            [['name' => 'quick', 'command' => ['true']]],
        );

        self::assertTrue($drift->hasChanged());
        self::assertSame(['quick'], $drift->added());
        self::assertSame(['tests'], $drift->removed());
        self::assertSame([], $drift->changed());
        self::assertFalse($drift->isReordered());

        self::assertSame(
            ['no longer configured: tests', 'newly configured: quick'],
            $drift->lines(),
        );
        self::assertStringContainsString('held to the checks it froze', $drift->describe());
    }

    #[Test]
    public function a_check_edited_under_the_same_name_is_reported_as_edited(): void
    {
        $drift = $this->drift(
            [['name' => 'tests', 'command' => ['vendor/bin/phpunit'], 'timeout' => 600]],
            [['name' => 'tests', 'command' => ['true'], 'timeout' => 600]],
        );

        self::assertTrue($drift->hasChanged());
        self::assertSame(['tests'], $drift->changed());
        self::assertSame([], $drift->added());
        self::assertSame([], $drift->removed());
        self::assertSame(['edited: tests'], $drift->lines());
    }

    #[Test]
    public function a_shortened_timeout_is_an_edit_too(): void
    {
        $drift = $this->drift(
            [['name' => 'tests', 'command' => ['vendor/bin/phpunit'], 'timeout' => 600]],
            [['name' => 'tests', 'command' => ['vendor/bin/phpunit'], 'timeout' => 1]],
        );

        self::assertSame(['tests'], $drift->changed());
    }

    /**
     * Order is behaviour: the first failure stops the sequence, so reordering
     * changes which failure a person is shown.
     */
    #[Test]
    public function the_same_checks_in_a_different_order_are_reported_as_reordered(): void
    {
        $lint = ['name' => 'lint', 'command' => ['vendor/bin/phpstan']];
        $tests = ['name' => 'tests', 'command' => ['vendor/bin/phpunit']];

        $drift = $this->drift([$lint, $tests], [$tests, $lint]);

        self::assertTrue($drift->hasChanged());
        self::assertTrue($drift->isReordered());
        self::assertSame([], $drift->added());
        self::assertSame([], $drift->removed());
        self::assertSame(
            ['reordered, which changes which failure is reported first'],
            $drift->lines(),
        );
    }

    /**
     * A removal moves everything behind it; reporting that as a reordering as
     * well would describe one edit as two.
     */
    #[Test]
    public function a_removal_is_not_also_reported_as_a_reordering(): void
    {
        $drift = $this->drift(
            [
                ['name' => 'lint', 'command' => ['vendor/bin/phpstan']],
                ['name' => 'tests', 'command' => ['vendor/bin/phpunit']],
            ],
            [['name' => 'tests', 'command' => ['vendor/bin/phpunit']]],
        );

        self::assertSame(['lint'], $drift->removed());
        self::assertFalse($drift->isReordered());
        self::assertSame(['no longer configured: lint'], $drift->lines());
    }

    /**
     * A configuration that no longer parses has still changed what the next run
     * will read, and reporting that as "no change" would be the silence this
     * item exists to break.
     */
    #[Test]
    public function a_configuration_that_cannot_be_read_is_drift(): void
    {
        $drift = VerificationDrift::unreadable('.ralph/config.json is not valid JSON');

        self::assertTrue($drift->hasChanged());
        self::assertSame(
            ['unreadable: .ralph/config.json is not valid JSON'],
            $drift->lines(),
        );
        self::assertSame(
            '.ralph/config.json is not valid JSON',
            $drift->unreadableReason(),
        );
        self::assertSame([], $drift->added());
        self::assertSame([], $drift->removed());
    }

    #[Test]
    public function a_drift_reports_itself_as_data_as_well_as_prose(): void
    {
        $drift = $this->drift(
            [['name' => 'tests', 'command' => ['vendor/bin/phpunit']]],
            [['name' => 'quick', 'command' => ['true']]],
        );

        self::assertSame(
            [
                'changed' => true,
                'added' => ['quick'],
                'removed' => ['tests'],
                'edited' => [],
                'reordered' => false,
                'unreadable' => null,
            ],
            $drift->toArray(),
        );
    }

    /**
     * The set names itself the way `ralph run` announces it.
     */
    #[Test]
    public function it_describes_itself_by_name_with_a_fingerprint(): void
    {
        $snapshot = VerificationSnapshot::of($this->configuration([
            ['name' => 'lint', 'command' => ['vendor/bin/phpstan']],
            ['name' => 'tests', 'command' => ['vendor/bin/phpunit']],
        ]));

        self::assertSame(
            sprintf('lint, tests (frozen %s)', $snapshot->digest()),
            $snapshot->describe(),
        );
        self::assertSame(VerificationSnapshot::DIGEST_LENGTH, strlen($snapshot->digest()));
    }

    #[Test]
    public function it_names_the_configuration_it_was_read_from(): void
    {
        $this->project->writeConfigArray([
            'checks' => [['name' => 'tests', 'command' => ['vendor/bin/phpunit']]],
        ]);

        $snapshot = VerificationSnapshot::of(
            (new ConfigurationLoader())->load($this->project->root()),
        );

        self::assertStringContainsString('config.json', $snapshot->source());
    }

    /**
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     */
    private function drift(array $before, array $after): VerificationDrift
    {
        return VerificationSnapshot::of($this->configuration($before))
            ->compareTo(VerificationSnapshot::of($this->configuration($after)));
    }

    /**
     * A configuration stating these checks and nothing else unusual.
     *
     * Built through the loader, so the checks are the ones a real project's
     * file would produce rather than values assembled by hand.
     *
     * @param list<array<string, mixed>> $checks
     */
    private function configuration(array $checks): Configuration
    {
        return (new ConfigurationLoader())->fromArray(['checks' => $checks], $this->project->root());
    }
}
