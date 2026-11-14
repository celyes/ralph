<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * How a run distributes its task commits across branches (PROJECT.md section
 * K, item 1).
 *
 * The two modes answer one question — *whose branch does this task's commit
 * land on?* — and they answer it differently because they serve different
 * review habits:
 *
 *  - **Per task.** Every pass owns a branch of its own, named after the task
 *    ({@see \Celyes\RalphPhp\Git\BranchNamer}), created from the commit the run
 *    resolved. Each task is reviewable, revertable and mergeable on its own,
 *    and a failed task leaves nothing on the branch another task is using. It
 *    is also the mode that cannot carry work forward: a second task starts from
 *    the base commit and so cannot see what the first one committed, which is
 *    the independence assumption PROJECT.md section 2 states.
 *  - **Shared.** Every task in the run commits to one configured branch, in
 *    order, keeping a commit per task. A reviewer reads one branch and one
 *    history instead of collecting several, and — the reason the mode exists —
 *    a later task can build on an earlier one, because the branch it continues
 *    from already holds that work.
 *
 * The strategy is a property of configuration rather than of a command-line
 * flag, and it is recorded with the run like every other non-secret setting
 * ({@see \Celyes\RalphPhp\State\RunStore}). Both halves matter on resume: a
 * resumed pass must land its commit where the interrupted one was going to put
 * it, and a setting edited between two runs must not move a branch that already
 * holds a commit.
 *
 * Which branch "one configured branch" means is {@see GitSettings::sharedBranch()},
 * not this enum: the mode is the decision, the name is the detail, and keeping
 * them apart is what lets a project switch modes without rewriting a name it
 * never set.
 */
enum BranchStrategy: string
{
    /**
     * One branch per task, created from the run's base commit.
     */
    case PerTask = 'per-task';

    /**
     * One branch for the whole run, carrying each task's commit in order.
     */
    case Shared = 'shared';

    /**
     * The strategy a configured value names, or null when it names none.
     *
     * Surrounding whitespace and casing are forgiven, as they are for every
     * other enumerated setting a person types into JSON by hand; the spellings
     * themselves are not guessed at, so a misspelling is reported by
     * {@see ConfigurationValidator} against the setting rather than silently
     * becoming a mode the project did not ask for.
     */
    public static function parse(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }

    /**
     * Every accepted spelling, in the order they are offered to a user.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $strategy): string => $strategy->value, self::cases());
    }

    /**
     * Whether all of a run's tasks commit to one branch.
     */
    public function isShared(): bool
    {
        return $this === self::Shared;
    }

    /**
     * Whether each pass owns a branch of its own.
     */
    public function isPerTask(): bool
    {
        return $this === self::PerTask;
    }

    /**
     * A short phrase naming the mode, for status output and log lines.
     */
    public function label(): string
    {
        return match ($this) {
            self::PerTask => 'one branch per task',
            self::Shared => 'one shared branch per run',
        };
    }
}
