<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\State;

use Celyes\RalphPhp\State\FailureEvidence;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the runner saw, held to a size and read back in one piece.
 *
 * Two things are worth testing here and the second is easy to lose. The
 * observations have to survive a round trip through a text column, including how
 * many of them a bound left out — evidence that silently shows three of seven is
 * worse than evidence that says so. And the rendering has to be safe against the
 * content, because the content is captured process output: a detail that happens
 * to contain a code fence must not end the block it is inside.
 */
#[CoversClass(FailureEvidence::class)]
final class FailureEvidenceTest extends TestCase
{
    #[Test]
    public function an_attempt_the_runner_watched_in_silence_has_no_evidence(): void
    {
        $none = FailureEvidence::none();

        self::assertTrue($none->isEmpty());
        self::assertSame(0, $none->count());
        self::assertSame(0, $none->omitted());
        self::assertSame('', $none->toMarkdown());
        self::assertSame('no evidence recorded', $none->label());
    }

    #[Test]
    public function observations_are_kept_in_the_order_they_were_seen(): void
    {
        $evidence = FailureEvidence::of('composer test failed (exit 1)', 'FAILURES!')
            ->with('composer stan was not run');

        self::assertSame(
            [
                ['label' => 'composer test failed (exit 1)', 'detail' => 'FAILURES!'],
                ['label' => 'composer stan was not run', 'detail' => ''],
            ],
            $evidence->entries(),
        );
        self::assertSame(2, $evidence->count());
        self::assertFalse($evidence->isEmpty());
    }

    #[Test]
    public function an_observation_must_say_what_was_seen(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An observation must say what was seen.');

        FailureEvidence::of("  \n ");
    }

    #[Test]
    public function a_label_is_collapsed_onto_one_line_and_shortened(): void
    {
        $evidence = FailureEvidence::of("composer test\nfailed   badly " . str_repeat('x', 200));

        $label = $evidence->entries()[0]['label'];

        self::assertStringNotContainsString("\n", $label);
        self::assertStringStartsWith('composer test failed badly', $label);
        self::assertSame(FailureEvidence::LABEL_CHARACTERS, mb_strlen($label));
    }

    #[Test]
    public function the_end_of_a_long_detail_is_what_is_kept(): void
    {
        $evidence = FailureEvidence::of('tests failed', str_repeat('a', 400) . 'FAILURES! 1 test');

        $detail = $evidence->entries()[0]['detail'];

        self::assertStringEndsWith(
            'FAILURES! 1 test',
            $detail,
            'The start of the output was kept, which is the version banner rather than the failure.',
        );
        self::assertStringStartsWith('…', $detail);
        self::assertLessThanOrEqual(FailureEvidence::DETAIL_CHARACTERS, mb_strlen($detail));
    }

    #[Test]
    public function a_detail_of_many_short_lines_is_bounded_by_its_lines(): void
    {
        $evidence = FailureEvidence::of('tests failed', implode("\n", array_fill(0, 60, 'x')));

        $detail = $evidence->entries()[0]['detail'];

        self::assertLessThanOrEqual(
            FailureEvidence::DETAIL_LINES + 1,
            count(explode("\n", $detail)),
            'A detail of many short lines is rendered indented, so a line bound is what keeps '
            . 'a written note inside one note\'s budget.',
        );
        self::assertStringStartsWith('…', $detail);
    }

    #[Test]
    public function a_detail_at_its_bounds_is_kept_whole(): void
    {
        $text = implode("\n", array_fill(0, FailureEvidence::DETAIL_LINES, 'xy'));

        self::assertSame($text, FailureEvidence::of('a check failed', $text)->entries()[0]['detail']);
    }

    #[Test]
    public function observations_past_the_bound_are_counted_rather_than_kept(): void
    {
        $evidence = FailureEvidence::none();

        for ($seen = 1; $seen <= FailureEvidence::ENTRIES + 2; ++$seen) {
            $evidence = $evidence->with('observation ' . $seen);
        }

        self::assertSame(FailureEvidence::ENTRIES, $evidence->count());
        self::assertSame(2, $evidence->omitted());
        self::assertSame(
            'observation 1',
            $evidence->entries()[0]['label'],
            'The bound dropped the first observation, which is the failure the rest are context for.',
        );
    }

    /**
     * Evidence folded out of evidence answers for what the first bound dropped.
     *
     * {@see \Celyes\RalphPhp\State\EvidenceTrail} reads stored observations
     * that were bounded on the way into the column, so the count they carry has
     * to be addable to the set they are folded into: a second bound that
     * silently reset it would hide what the first one disclosed.
     */
    #[Test]
    public function observations_something_else_left_out_can_be_counted_here(): void
    {
        $evidence = FailureEvidence::of('a check failed')->alsoOmitted(4);

        self::assertSame(1, $evidence->count());
        self::assertSame(4, $evidence->omitted());
        self::assertStringContainsString('4 further observations are not shown', $evidence->toMarkdown());
        self::assertSame('a check failed (+4 more)', $evidence->label());
    }

    #[Test]
    public function counting_no_further_observations_leaves_the_evidence_alone(): void
    {
        $evidence = FailureEvidence::of('a check failed');

        self::assertTrue($evidence->equals($evidence->alsoOmitted(0)));
        self::assertTrue($evidence->equals($evidence->alsoOmitted(-3)));
    }

    #[Test]
    public function evidence_does_not_change_when_more_is_added_to_it(): void
    {
        $first = FailureEvidence::of('one');
        $second = $first->with('two');

        self::assertSame(1, $first->count());
        self::assertSame(2, $second->count());
        self::assertFalse($first->equals($second));
    }

    #[Test]
    public function what_is_stored_is_what_is_read_back(): void
    {
        $evidence = FailureEvidence::of('composer test failed (exit 1)', "FAILURES!\n1 test, 1 failure")
            ->with('composer stan was not run')
            ->with('third')
            ->with('fourth')
            ->with('fifth');

        $read = FailureEvidence::fromStorage($evidence->toStorage());

        self::assertTrue($read->equals($evidence));
        self::assertSame(2, $read->omitted(), 'The count of what was left out did not survive storage.');
    }

    #[Test]
    public function a_column_holding_nothing_reads_back_as_no_evidence(): void
    {
        self::assertNull(FailureEvidence::recorded(null));
        self::assertNull(FailureEvidence::recorded('  '));
        self::assertNotNull(FailureEvidence::recorded('something happened'));
    }

    #[Test]
    public function stored_prose_is_shown_under_a_generic_label(): void
    {
        $evidence = FailureEvidence::fromStorage('the worktree had unrelated changes');

        self::assertSame(
            [['label' => FailureEvidence::UNLABELLED, 'detail' => 'the worktree had unrelated changes']],
            $evidence->entries(),
        );
    }

    #[Test]
    public function a_stored_list_is_read_as_observations(): void
    {
        $evidence = FailureEvidence::fromStorage(
            '[{"label":"a check failed","detail":"output"},{"label":"another"}]',
        );

        self::assertSame(2, $evidence->count());
        self::assertSame('a check failed', $evidence->entries()[0]['label']);
        self::assertSame('', $evidence->entries()[1]['detail']);
        self::assertSame(0, $evidence->omitted());
    }

    #[Test]
    public function a_stored_list_of_plain_strings_is_read_as_observations_too(): void
    {
        $evidence = FailureEvidence::fromStorage('["the branch moved"]');

        self::assertSame('the branch moved', $evidence->entries()[0]['label']);
    }

    #[Test]
    public function an_observation_with_no_label_of_its_own_is_named(): void
    {
        $evidence = FailureEvidence::fromStorage('[{"detail":"output nobody labelled"}]');

        self::assertSame(FailureEvidence::UNLABELLED, $evidence->entries()[0]['label']);
        self::assertSame('output nobody labelled', $evidence->entries()[0]['detail']);
    }

    #[Test]
    public function stored_json_that_holds_no_observation_is_shown_as_it_stands(): void
    {
        foreach (['{"entries":{"a":1}}', '[{}]', '[[]]', '42'] as $stored) {
            $evidence = FailureEvidence::fromStorage($stored);

            self::assertSame(
                1,
                $evidence->count(),
                sprintf('Evidence stored as %s was shown as nothing at all.', $stored),
            );
            self::assertStringContainsString(trim($stored, '[]'), $evidence->toMarkdown());
        }
    }

    #[Test]
    public function the_status_line_names_the_first_observation_and_counts_the_rest(): void
    {
        self::assertSame(
            'composer test failed',
            FailureEvidence::of('composer test failed')->label(),
        );
        self::assertSame(
            'composer test failed (+1 more)',
            FailureEvidence::of('composer test failed')->with('stan was not run')->label(),
        );
    }

    #[Test]
    public function omitted_observations_are_counted_in_the_status_line(): void
    {
        $evidence = FailureEvidence::none();

        for ($seen = 1; $seen <= FailureEvidence::ENTRIES + 1; ++$seen) {
            $evidence = $evidence->with('observation ' . $seen);
        }

        self::assertSame(
            sprintf('observation 1 (+%d more)', FailureEvidence::ENTRIES),
            $evidence->label(),
        );
    }

    #[Test]
    public function a_detail_is_indented_rather_than_fenced(): void
    {
        $markdown = FailureEvidence::of('tests failed', "FAILURES!\n```not a fence```")->toMarkdown();

        self::assertStringContainsString('- **tests failed**', $markdown);
        self::assertStringContainsString('      FAILURES!', $markdown);
        self::assertStringContainsString('      ```not a fence```', $markdown);
        self::assertStringNotContainsString(
            "\n```",
            $markdown,
            'Output containing a code fence was rendered as a fence, so it ends the block early.',
        );
    }

    #[Test]
    public function what_the_bound_left_out_is_said_in_the_rendering(): void
    {
        $evidence = FailureEvidence::none();

        for ($seen = 1; $seen <= FailureEvidence::ENTRIES + 1; ++$seen) {
            $evidence = $evidence->with('observation ' . $seen);
        }

        self::assertStringContainsString('1 further observation is not shown', $evidence->toMarkdown());
        self::assertStringContainsString(
            '2 further observations are not shown',
            $evidence->with('one more')->toMarkdown(),
        );
    }
}
