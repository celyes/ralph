<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Event;

use Celyes\RalphPhp\Event\EventId;
use Celyes\RalphPhp\Event\EventKind;
use Celyes\RalphPhp\Tasks\TaskId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The name of a thing that happened, which a consumer deduplicates on.
 *
 * PROJECT.md section I, item 4. Two properties, and they pull in opposite
 * directions: the same occurrence must always produce the same identifier, in
 * this process and in the next one, or a consumer cannot recognise what it has
 * already acted on; and two different occurrences must never produce the same
 * one, or it would skip something that mattered. So every fact the identifier
 * is derived from is varied here one at a time.
 */
#[CoversClass(EventId::class)]
final class EventIdTest extends TestCase
{
    private const string TASK = 'TASK-1A2B3C4D';

    /**
     * The same four facts give the same name, however often they are asked
     * about: a consumer re-reading the stream has to arrive at the key it
     * stored the first time.
     */
    #[Test]
    public function the_same_occurrence_always_has_the_same_name(): void
    {
        $first = EventId::of('run-1', EventKind::TaskFinished, TaskId::fromString(self::TASK), 'generation:1');
        $again = EventId::of('run-1', EventKind::TaskFinished, TaskId::fromString(self::TASK), 'generation:1');

        self::assertSame($first->value(), $again->value());
        self::assertTrue($first->equals($again));
    }

    /**
     * One fixed-width opaque column, recognisable as this package's.
     *
     * Opaque on purpose: every part it is derived from is already its own
     * field in the envelope, so a consumer that parsed the identifier would be
     * depending on an arrangement that is not interface.
     */
    #[Test]
    public function it_reads_as_one_short_opaque_column(): void
    {
        $value = EventId::of('run-1', EventKind::RunStarted)->value();

        self::assertMatchesRegularExpression('/^ev_[0-9a-f]{32}$/', $value);
        self::assertStringStartsWith(EventId::PREFIX, $value);
    }

    /**
     * Each fact of the four changes the name, so that no two occurrences share
     * one.
     *
     * The run belongs in it because a pass that could not write its box
     * finishes one run blocked and a later run completed — the same task and the
     * same generation, two events a consumer must not collapse.
     */
    #[Test]
    public function every_fact_it_is_derived_from_tells_two_occurrences_apart(): void
    {
        $task = TaskId::fromString(self::TASK);
        $other = TaskId::fromString('TASK-99887766');

        $names = [
            'baseline' => EventId::of('run-1', EventKind::TaskFinished, $task, 'generation:1'),
            'another run' => EventId::of('run-2', EventKind::TaskFinished, $task, 'generation:1'),
            'another kind' => EventId::of('run-1', EventKind::TaskStarted, $task, 'generation:1'),
            'another task' => EventId::of('run-1', EventKind::TaskFinished, $other, 'generation:1'),
            'another pass' => EventId::of('run-1', EventKind::TaskFinished, $task, 'generation:2'),
            'no task' => EventId::of('run-1', EventKind::RunFinished, null, 'generation:1'),
            'no discriminator' => EventId::of('run-1', EventKind::TaskFinished, $task),
        ];

        $values = array_map(static fn (EventId $id): string => $id->value(), $names);

        self::assertSame(
            array_keys($names),
            array_keys(array_unique($values)),
            'two occurrences that differ must not share one identity',
        );
    }

    /**
     * The parts cannot be rearranged into each other.
     *
     * A run called `a` with the discriminator `bc` and one called `ab` with
     * `c` are different occurrences, and a name built by joining the parts
     * would give both the same one. They are length-prefixed before hashing so
     * that no value can be chosen to read as another arrangement.
     */
    #[Test]
    public function a_value_cannot_be_arranged_to_read_as_another(): void
    {
        self::assertNotSame(
            EventId::of('a', EventKind::RunFinished, null, 'bc')->value(),
            EventId::of('ab', EventKind::RunFinished, null, 'c')->value(),
        );
    }
}
