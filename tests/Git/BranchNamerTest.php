<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Git\BranchName;
use Celyes\RalphPhp\Git\BranchNamer;
use Celyes\RalphPhp\Git\TaskBranch;
use Celyes\RalphPhp\Tasks\TaskGeneration;
use Celyes\RalphPhp\Tasks\TaskId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Naming the branch a pass owns.
 *
 * Two things are being pinned. The first is the shape of a generated name,
 * which goes into Git refs and a database that outlive the process, so it is
 * asserted literally rather than described. The second is the rule that matters
 * more than the shape: a name already recorded is returned untouched, whatever
 * the configuration says now, because that is what keeps a resumed pass on the
 * branch holding its commit.
 */
#[CoversClass(BranchNamer::class)]
#[CoversClass(TaskBranch::class)]
final class BranchNamerTest extends TestCase
{
    private const string DESCRIPTION = 'Generate valid task branch names from the configured prefix';

    #[Test]
    public function a_name_is_the_prefix_the_identity_and_the_description(): void
    {
        $name = (new BranchNamer())->nameFor($this->generation(), 'Add a --json flag to status');

        self::assertSame('ralph/' . $this->generation()->slug() . '-add-a-json-flag-to-status', $name->value());
    }

    #[Test]
    public function the_identity_comes_before_the_description_so_it_survives_shortening(): void
    {
        $generation = $this->generation();
        $name = (new BranchNamer())->nameFor($generation, str_repeat('alpha beta ', 40));

        self::assertStringStartsWith('ralph/' . $generation->slug() . '-', $name->value());
        self::assertLessThanOrEqual(BranchNamer::MAX_LENGTH, strlen($name->value()));
    }

    #[Test]
    public function the_description_is_shortened_at_a_word_boundary(): void
    {
        $name = (new BranchNamer())->nameFor($this->generation(), self::DESCRIPTION);

        // Forty-eight characters of description are allowed; the forty-ninth
        // falls inside "configured", so the name ends on the word before it.
        self::assertSame(
            'ralph/' . $this->generation()->slug() . '-generate-valid-task-branch-names-from-the',
            $name->value(),
        );
    }

    #[Test]
    public function a_description_that_exactly_fits_is_not_shortened(): void
    {
        $description = str_repeat('ab ', 16); // "ab-ab-…-ab", 47 characters.
        $name = (new BranchNamer())->nameFor($this->generation(), $description);

        self::assertSame(
            'ralph/' . $this->generation()->slug() . '-' . trim(str_replace(' ', '-', $description), '-'),
            $name->value(),
        );
    }

    #[Test]
    public function a_description_filling_the_budget_exactly_keeps_its_last_word(): void
    {
        // Seven six-letter words and six dashes: forty-eight characters, with
        // the word boundary falling on the character after the budget.
        $description = str_repeat('abcdef ', 7) . 'tail';
        $name = (new BranchNamer())->nameFor($this->generation(), $description);

        self::assertSame(
            'ralph/' . $this->generation()->slug() . '-' . implode('-', array_fill(0, 7, 'abcdef')),
            $name->value(),
        );
    }

    #[Test]
    public function a_description_with_no_word_boundary_to_cut_at_is_cut_at_the_budget(): void
    {
        $name = (new BranchNamer())->nameFor($this->generation(), str_repeat('x', 300));

        self::assertSame(
            'ralph/' . $this->generation()->slug() . '-' . str_repeat('x', BranchNamer::DESCRIPTION_LENGTH),
            $name->value(),
        );
    }

    #[Test]
    public function a_word_boundary_at_the_very_start_is_not_cut_to(): void
    {
        // "add-" followed by one long word: the only boundary available is
        // three characters in, and a name ending "-add" says less about the
        // task than the same width spent on the word itself.
        $name = (new BranchNamer())->nameFor($this->generation(), 'Add ' . str_repeat('z', 90));

        self::assertSame(
            'ralph/' . $this->generation()->slug() . '-add-' . str_repeat('z', BranchNamer::DESCRIPTION_LENGTH - 4),
            $name->value(),
        );
    }

    #[Test]
    public function punctuation_and_casing_reduce_to_single_dashes(): void
    {
        $namer = new BranchNamer();
        $generation = $this->generation();

        self::assertSame(
            'ralph/' . $generation->slug() . '-don-t-break-c-parsing',
            $namer->nameFor($generation, "  Don't break C++ parsing!  ")->value(),
        );
    }

    #[Test]
    public function a_description_with_nothing_usable_in_it_leaves_the_name_complete(): void
    {
        $namer = new BranchNamer();
        $generation = $this->generation();
        $bare = 'ralph/' . $generation->slug();

        self::assertSame($bare, $namer->nameFor($generation)->value());
        self::assertSame($bare, $namer->nameFor($generation, '...')->value());
        self::assertSame($bare, $namer->nameFor($generation, '   ')->value());

        // A short description is a whole description, and is kept: the
        // minimum applies to what shortening may leave behind, not to what the
        // user wrote.
        self::assertSame($bare . '-fix-ci', $namer->nameFor($generation, 'Fix CI')->value());

        // Not valid UTF-8: the checklist reader refuses it with a readable
        // message long before here, and a name is still produced.
        self::assertSame($bare, $namer->nameFor($generation, "\xC3")->value());
    }

    #[Test]
    public function a_non_latin_description_produces_a_name_without_a_readable_half(): void
    {
        $generation = $this->generation();

        self::assertSame(
            'ralph/' . $generation->slug(),
            (new BranchNamer())->nameFor($generation, 'إضافة وسيط')->value(),
        );
    }

    #[Test]
    public function reopening_a_task_cannot_name_the_branch_the_first_pass_owns(): void
    {
        $namer = new BranchNamer();
        $first = $this->generation();
        $second = $first->next();

        $before = $namer->nameFor($first, self::DESCRIPTION);
        $after = $namer->nameFor($second, self::DESCRIPTION);

        self::assertNotSame($before->value(), $after->value());
        self::assertStringContainsString('-g2-', $after->value());
    }

    #[Test]
    public function the_configured_prefix_is_used_as_written(): void
    {
        $generation = $this->generation();

        self::assertSame(
            'wip/agents/' . $generation->slug() . '-add-a-flag',
            (new BranchNamer('wip/agents/'))->nameFor($generation, 'Add a flag')->value(),
        );

        // A prefix with no separator in it is the user's choice, not an error.
        self::assertSame(
            'ralph-' . $generation->slug() . '-add-a-flag',
            (new BranchNamer('ralph-'))->nameFor($generation, 'Add a flag')->value(),
        );

        // Surrounding whitespace is not: no Git reference can hold it, and
        // configuration validation has already refused a prefix containing a
        // space, so trimming cannot discard anything a user meant.
        self::assertSame('ralph/', (new BranchNamer(" ralph/\n"))->prefix());
        self::assertSame(
            'ralph/' . $generation->slug(),
            (new BranchNamer(" ralph/\n"))->nameFor($generation)->value(),
        );
    }

    #[Test]
    public function a_prefix_is_taken_from_the_project_configuration(): void
    {
        $namer = BranchNamer::forProject($this->configuration('feature/ralph-'));

        self::assertSame('feature/ralph-', $namer->prefix());
        self::assertStringStartsWith('feature/ralph-TASK-', $namer->nameFor($this->generation())->value());
    }

    #[Test]
    public function a_prefix_that_names_a_branch_git_would_refuse_is_reported_against_the_setting(): void
    {
        $namer = new BranchNamer('-ralph/');

        try {
            $namer->nameFor($this->generation(), 'Add a flag');
            self::fail('A prefix Git would refuse should not produce a branch name.');
        } catch (ConfigurationException $failure) {
            self::assertStringContainsString('git.branch_prefix is "-ralph/"', $failure->getMessage());
            self::assertStringContainsString('starts with "-"', $failure->getMessage());
            self::assertStringContainsString(
                'Correct git.branch_prefix in .ralph/config.json, then run the command again.',
                implode("\n", $failure->hints()),
            );
        }
    }

    #[Test]
    public function a_prefix_long_enough_to_fill_the_name_still_leaves_the_identity_intact(): void
    {
        $generation = $this->generation();
        $prefix = str_repeat('long/', 22); // 110 characters, leaving no room.

        $name = (new BranchNamer($prefix))->nameFor($generation, self::DESCRIPTION);

        self::assertSame($prefix . $generation->slug(), $name->value());
    }

    #[Test]
    public function a_pass_with_no_recorded_branch_is_given_a_generated_one(): void
    {
        $generation = $this->generation();
        $branch = (new BranchNamer())->resolve($generation, 'Add a flag');

        self::assertTrue($branch->wasGenerated());
        self::assertFalse($branch->wasRetained());
        self::assertTrue($generation->equals($branch->generation()));
        self::assertSame('ralph/' . $generation->slug() . '-add-a-flag', $branch->value());
        self::assertSame('ralph/' . $generation->slug() . '-add-a-flag', (string) $branch);
        self::assertStringContainsString('named by this run', $branch->describe());
    }

    #[Test]
    public function a_recorded_branch_is_retained_whatever_the_configuration_now_says(): void
    {
        $generation = $this->generation();

        // The run that started this pass used a different prefix, and the
        // branch it created holds the commit. Renaming would orphan it.
        $branch = (new BranchNamer('ralph/'))->resolve($generation, 'Add a flag', 'wip/' . $generation->slug());

        self::assertTrue($branch->wasRetained());
        self::assertFalse($branch->wasGenerated());
        self::assertSame('wip/' . $generation->slug(), $branch->value());
        self::assertStringContainsString('owned from an earlier run', $branch->describe());
    }

    #[Test]
    public function a_recorded_branch_is_retained_even_when_the_description_has_been_reworded(): void
    {
        $generation = $this->generation();
        $recorded = 'ralph/' . $generation->slug() . '-the-original-wording';

        $branch = (new BranchNamer())->resolve($generation, 'An entirely different sentence', $recorded);

        self::assertSame($recorded, $branch->value());
    }

    #[Test]
    public function a_column_holding_no_branch_is_the_same_as_none(): void
    {
        $namer = new BranchNamer();

        self::assertTrue($namer->resolve($this->generation(), 'Add a flag', null)->wasGenerated());
        self::assertTrue($namer->resolve($this->generation(), 'Add a flag', '  ')->wasGenerated());
    }

    #[Test]
    public function a_recorded_branch_git_could_not_hold_is_reported_rather_than_replaced(): void
    {
        $generation = $this->generation();

        try {
            (new BranchNamer())->resolve($generation, 'Add a flag', 'ralph/broken.lock');
            self::fail('A stored branch name Git would refuse should not be read back.');
        } catch (StateException $failure) {
            self::assertStringContainsString($generation->label(), $failure->getMessage());
            self::assertStringContainsString('ralph/broken.lock', $failure->getMessage());
            self::assertStringContainsString('ending with ".lock"', $failure->getMessage());
            self::assertStringContainsString(
                'will not rename the pass onto a generated branch',
                implode("\n", $failure->hints()),
            );
        }
    }

    #[Test]
    public function a_generated_name_is_one_git_accepts(): void
    {
        $namer = new BranchNamer();

        foreach ([
            'Add a --json flag',
            'Fix the ~/.config path',
            'Refuse a ^commit that is not a commit',
            'Handle a..b ranges',
            'Keep .lock out of it',
            'Trailing dot.',
            '.hidden start',
            'HEAD',
            '-leading dash',
            'Tabs	and  spaces',
            str_repeat('x', 300),
        ] as $description) {
            $name = $namer->nameFor($this->generation(), $description);

            self::assertNull(
                BranchName::problemWith($name->value()),
                sprintf('"%s" produced the unusable name "%s".', $description, $name->value()),
            );
        }
    }

    private function generation(): TaskGeneration
    {
        return TaskGeneration::first(TaskId::fromDescription(self::DESCRIPTION));
    }

    private function configuration(string $prefix): Configuration
    {
        return (new ConfigurationLoader())->fromArray(
            ['git' => ['branch_prefix' => $prefix]],
            sys_get_temp_dir(),
        );
    }
}
