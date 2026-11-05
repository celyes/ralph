<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\Tasks\TaskBody;
use Celyes\RalphPhp\Tasks\TaskGeneration;

/**
 * Names the branch a pass at a task owns (PROJECT.md section F, item 3).
 *
 * `<prefix><generation slug>-<description>`, for example
 * `ralph/TASK-19E0214A-generate-valid-task-branch-names`. The three parts are
 * in that order on purpose:
 *
 *  - **The configured prefix** comes first so that every branch this package
 *    creates sorts together, is matched by one `git branch --list "ralph/*"`,
 *    and can be excluded from a glob by anyone who wants to.
 *  - **The generation slug** carries identity: the task ID, and the `-g2`
 *    suffix when a finished task was reopened. It is what makes two passes at
 *    one task unable to name the same branch, which is the mechanism behind
 *    "a separately owned branch" in PROJECT.md section C, item 8. It is placed
 *    before the description so that it survives every shortening below.
 *  - **The description** is a courtesy to the person reading `git branch`, and
 *    nothing depends on it. It is reduced to lowercase `a-z`, `0-9` and `-`:
 *    the subset every Git, every remote host and every terminal renders the
 *    same way. Accents and non-Latin scripts are legal in a Git reference, but
 *    this package cannot apply Unicode normalisation on every machine (see
 *    {@see TaskBody}), so a name containing them could
 *    differ byte-for-byte between a developer's checkout and CI while looking
 *    identical. A description that reduces to nothing simply produces a name
 *    without a readable half, which is complete and unambiguous on its own.
 *
 * **A recorded branch is never regenerated.** {@see self::resolve()} returns
 * what the generation's row holds whenever it holds anything, without comparing
 * it to what the current configuration would produce. This is the half of the
 * item that is about resume rather than about naming: `git.branch_prefix` is a
 * setting a user may edit between two runs, and a resumed pass that recomputed
 * its name would start a second branch while the first one still held its
 * commit — the work would not be lost, but nothing would be able to find it.
 * The spelling of a branch is therefore an input to naming exactly once, and a
 * stored fact for the rest of the pass's life.
 *
 * The same reasoning is why a branch name is never read *back* as an identity.
 * `ralph/TASK-19E0214A-g2-...` is genuinely ambiguous — a first-generation task
 * whose description begins "g2" produces the same shape — so which pass owns a
 * branch is answered by the state store, which recorded it, and never by
 * parsing. {@see BranchName::hasPrefix()} is as far as spelling is trusted.
 *
 * Free of I/O, like {@see BaseCommit}: this decides what a branch is called,
 * and the repository is asked about it elsewhere.
 */
final class BranchNamer
{
    /**
     * Characters a generated name is allowed to end in, after the prefix.
     *
     * The practical limit is the filesystem's: a loose ref is a file whose name
     * must leave room for the `.lock` Git writes beside it, so a segment over
     * roughly 250 bytes fails with "File name too long" at the moment the
     * branch is created. This is well inside that even when the prefix is long,
     * and it also keeps a branch name inside a terminal line in `git branch`
     * and `ralph status` output.
     */
    public const int MAX_LENGTH = 120;

    /**
     * How much of the name the readable half may take.
     *
     * Enough for the first clause of a task — about seven words — which is what
     * makes a list of branches scannable. The rest is cut at a word boundary:
     * a reader who needs the whole task reads the checklist, and the ID in the
     * name is what connects the two.
     */
    public const int DESCRIPTION_LENGTH = 48;

    /**
     * The shortest readable half worth producing.
     *
     * Two uses, both about shortening rather than about what the user wrote: a
     * name with less room than this left over gets no readable half at all, and
     * a description cut this close to its start is cut at the budget instead of
     * at a word boundary. A short description is a whole description and is
     * kept as it is — `ralph/TASK-19E0214A-fix-ci` says everything the
     * checklist does.
     */
    private const int MINIMUM_DESCRIPTION = 4;

    public function __construct(private readonly string $prefix = Defaults::BRANCH_PREFIX)
    {
    }

    /**
     * The namer for the project this configuration describes.
     */
    public static function forProject(Configuration $configuration): self
    {
        return new self($configuration->git()->branchPrefix());
    }

    /**
     * The prefix every generated name starts with.
     */
    public function prefix(): string
    {
        return trim($this->prefix);
    }

    /**
     * The name a pass would be given, were it starting now.
     *
     * @param string $description the task as the checklist words it; optional
     *        because the name is complete without it
     *
     * @throws ConfigurationException when the configured prefix produces a name
     *                                Git would refuse
     */
    public function nameFor(TaskGeneration $generation, string $description = ''): BranchName
    {
        $prefix = $this->prefix();
        $identity = $prefix . $generation->slug();
        $readable = $this->readableHalf($description, self::MAX_LENGTH - strlen($identity) - 1);
        $name = $readable === '' ? $identity : $identity . '-' . $readable;

        $problem = BranchName::problemWith($name);

        if ($problem !== null) {
            throw new ConfigurationException(
                sprintf(
                    'git.branch_prefix is "%s", which names the branch "%s" for %s, and Git would refuse it: it %s.',
                    $this->prefix,
                    $name,
                    $generation->label(),
                    $problem,
                ),
                [
                    sprintf('Task branches are named "<prefix><task>", such as "%s".', Defaults::BRANCH_PREFIX . 'TASK-19E0214A'),
                    'Correct git.branch_prefix in .ralph/config.json, then run the command again.',
                ],
            );
        }

        return BranchName::fromString($name);
    }

    /**
     * The branch a pass owns: the one it is already recorded under, or a new
     * name when it owns none yet.
     *
     * The ordinary entry point, and the one that keeps ownership across a
     * resume — see the class note on why a recorded name is never recomputed.
     *
     * @param string|null $recorded the `branch` column of the pass's row
     *
     * @throws ConfigurationException when a new name cannot be generated
     * @throws StateException         when the recorded name is not one Git
     *                                could hold
     */
    public function resolve(
        TaskGeneration $generation,
        string $description = '',
        ?string $recorded = null,
    ): TaskBranch {
        if ($recorded !== null && trim($recorded) !== '') {
            return TaskBranch::retained($generation, $this->retain($generation, $recorded));
        }

        return TaskBranch::generated($generation, $this->nameFor($generation, $description));
    }

    /**
     * The branch a recorded pass owns, from its row and its description.
     *
     * @throws ConfigurationException when a new name cannot be generated
     * @throws StateException         when the recorded name is not one Git
     *                                could hold
     */
    public function forRecord(GenerationRecord $record, string $description = ''): TaskBranch
    {
        return $this->resolve($record->generation(), $description, $record->branch());
    }

    /**
     * A stored branch name, read back as it was written.
     *
     * Reaching the failure means the row holds something this package did not
     * put there, since a generated name has been through
     * {@see BranchName::problemWith()} already. Reported rather than replaced
     * with a freshly generated name: the recorded branch may hold a commit, and
     * a run that renamed its way out of the problem would leave it behind.
     *
     * @throws StateException when Git could not hold a branch under that name
     */
    private function retain(TaskGeneration $generation, string $recorded): BranchName
    {
        $problem = BranchName::problemWith(trim($recorded));

        if ($problem !== null) {
            throw new StateException(
                sprintf(
                    'The state database records %s on the branch "%s", which Git could not hold: it %s.',
                    $generation->label(),
                    $recorded,
                    $problem,
                ),
                [
                    'Ralph PHP will not rename the pass onto a generated branch, because the recorded one may hold its commit.',
                    sprintf('Check "git branch --list" for the branch of %s, then correct or retry the task.', $generation->task()->value()),
                ],
            );
        }

        return BranchName::fromString($recorded);
    }

    /**
     * The description as the tail of a branch name, within a budget.
     *
     * Every run of characters outside `a-z0-9` becomes a single dash, which is
     * what makes the result predictable for any wording: punctuation,
     * capitalisation and the spacing of a rewrapped line all reduce the same
     * way. Shortening happens at a dash where there is one, so the name ends on
     * a whole word.
     */
    private function readableHalf(string $description, int $budget): string
    {
        $budget = min($budget, self::DESCRIPTION_LENGTH);

        if ($budget < self::MINIMUM_DESCRIPTION) {
            return '';
        }

        $slug = $this->slug($description);

        if (strlen($slug) <= $budget) {
            return $slug;
        }

        $cut = substr($slug, 0, $budget + 1);
        $boundary = strrpos($cut, '-');

        if ($boundary !== false && $boundary >= self::MINIMUM_DESCRIPTION) {
            return rtrim(substr($cut, 0, $boundary), '-');
        }

        return rtrim(substr($slug, 0, $budget), '-');
    }

    /**
     * A description reduced to `a-z`, `0-9` and single dashes.
     *
     * Normalised through {@see TaskBody} first, so the
     * folding is the same one task identity uses — which is why re-casing or
     * re-spacing a task cannot change its branch name any more than it can
     * change its ID.
     */
    private function slug(string $description): string
    {
        if (!mb_check_encoding($description, 'UTF-8')) {
            return '';
        }

        $normalized = TaskBody::of($description)->normalized();

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $normalized), '-');
    }
}
