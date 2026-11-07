<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

use Celyes\RalphPhp\State\FailureEvidence;

/**
 * What a task's staged diff does to the project's own verification, and whether
 * the pass may commit it (PROJECT.md section G, item 5).
 *
 * The reading that sits between the patch and the one thing allowed to act on
 * it, {@see ChecksAuditor}. Two verdicts and no third: either nothing in the
 * diff reads as weakening the checks, or something does and the pass stops with
 * the patch kept at {@see self::diffPath()} for a person to read.
 *
 * **Why a verified task can still be refused.** The checks passed — that is
 * why there is a diff to look at at all. What they passed *against* is the
 * suite the diff leaves behind, so a diff that deletes the failing test passes
 * them truthfully and proves nothing. PROJECT.md section 4 already says a zero
 * exit code does not complete a task; this is the same sentence applied to the
 * checks themselves.
 *
 * **Comprehensive detection is not guaranteed, and this is the statement of
 * it.** The item asks for that limit to be documented, so it is documented
 * where a user meets it — in {@see self::hints()}, printed with every refusal —
 * as well as here. What the reading rests on is the shape of test code in a
 * diff's added and removed lines: it has no parser, it does not run the
 * project's old suite against its new code, and it measures no coverage. So:
 *
 *  - **It misses things.** A test whose subject is quietly changed to something
 *    easier, an assertion inverted rather than removed, a fixture emptied, a
 *    mock that now returns whatever is asked of it, a test helper weakened in a
 *    file that holds no tests, a conditional that skips a case without using any
 *    word this looks for — none of these are detected. A clean audit is not a
 *    statement that the verification is as strong as it was.
 *  - **It reports things that are fine.** A task that was *asked* to delete an
 *    obsolete test, or to replace twenty assertions with one better one, is a
 *    task whose correct diff is reported here. That is the intended bias: the
 *    cost of a finding is a person reading a patch, and the cost of a miss is
 *    a green run that verified nothing.
 *
 * Both halves of that are why no finding is reconcilable and why nothing here
 * edits the diff: which of the two cases a reviewer is looking at is not a
 * question a runner can answer.
 */
final class ChecksAudit
{
    /**
     * Findings named individually in a message.
     *
     * A diff that reads as weakening the checks in six ways needs a person to
     * read the patch, and listing every one of them would bury that advice
     * under the evidence. The count is always exact and every finding is on the
     * value.
     */
    public const int FINDINGS_IN_MESSAGE = 4;

    /**
     * Quoted evidence lines shown per finding.
     */
    public const int QUOTED_IN_MESSAGE = 3;

    /**
     * @param list<Weakening> $findings in the order they were established
     * @param list<string> $examined the staged paths the reading was made over
     * @param string|null $diffPath where the patch was kept, when it was
     */
    private function __construct(
        private readonly string $workspace,
        private readonly array $findings,
        private readonly array $examined,
        private readonly ?string $diffPath,
    ) {
    }

    /**
     * One reading.
     *
     * @param list<Weakening> $findings
     * @param list<string> $examined
     */
    public static function of(
        string $workspace,
        array $findings,
        array $examined = [],
        ?string $diffPath = null,
    ): self {
        return new self($workspace, $findings, $examined, $diffPath);
    }

    /**
     * A reading that found nothing.
     *
     * @param list<string> $examined
     */
    public static function clean(string $workspace, array $examined = []): self
    {
        return new self($workspace, [], $examined, null);
    }

    /**
     * The same reading, with the patch recorded as kept at this path.
     *
     * Separate from construction because the two happen in that order: the
     * reading decides whether there is anything to preserve, and only then is
     * the file written.
     */
    public function keptAt(string $diffPath): self
    {
        return new self($this->workspace, $this->findings, $this->examined, $diffPath);
    }

    /**
     * The workspace the diff was taken of.
     */
    public function workspace(): string
    {
        return $this->workspace;
    }

    /**
     * Every finding, in the order it was established.
     *
     * @return list<Weakening>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * The staged paths this reading was made over.
     *
     * Kept so that a clean audit says what it looked at rather than only that
     * it found nothing: a reading over no test files at all is a different
     * statement from a reading over nine of them.
     *
     * @return list<string>
     */
    public function examined(): array
    {
        return $this->examined;
    }

    /**
     * Where the patch was kept for review, or null when there was nothing to
     * keep it for.
     */
    public function diffPath(): ?string
    {
        return $this->diffPath;
    }

    public function isClean(): bool
    {
        return $this->findings === [];
    }

    /**
     * Whether this stops the pass.
     *
     * Every finding blocks — {@see WeakeningKind} says why none of them can be
     * put back — so this is "anything was found" and is written as its own
     * question anyway, because that is what the caller is asking.
     */
    public function isBlocking(): bool
    {
        return $this->findings !== [];
    }

    public function count(): int
    {
        return count($this->findings);
    }

    public function has(WeakeningKind $kind): bool
    {
        return $this->first($kind) !== null;
    }

    /**
     * The first finding of a kind, or null when there is none.
     */
    public function first(WeakeningKind $kind): ?Weakening
    {
        foreach ($this->findings as $finding) {
            if ($finding->kind() === $kind) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * Every path any finding is about, each once.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->findings as $finding) {
            foreach ($finding->paths() as $path) {
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * One line saying what was found.
     */
    public function summary(): string
    {
        if ($this->isClean()) {
            return sprintf(
                'nothing in the staged diff reads as weakening the project\'s checks (%d file(s) examined)',
                count($this->examined),
            );
        }

        return sprintf(
            'the staged diff weakens the project\'s own verification in %d way%s',
            $this->count(),
            $this->count() === 1 ? '' : 's',
        );
    }

    /**
     * The evidence, one item per line.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $details = [];
        $shown = array_slice($this->findings, 0, self::FINDINGS_IN_MESSAGE);

        foreach ($shown as $finding) {
            $details[] = sprintf('%s: %s.', $finding->kind()->label(), $finding->summary());

            foreach (array_slice($finding->quoted(), 0, self::QUOTED_IN_MESSAGE) as $line) {
                $details[] = '    ' . $line;
            }
        }

        if ($this->count() > count($shown)) {
            $details[] = sprintf('… and %d more.', $this->count() - count($shown));
        }

        return $details;
    }

    /**
     * What the user can do about it.
     *
     * The order is what a person needs in the order they need it: where the
     * work is, where the patch is, what the package will not decide for them,
     * and only then that the reading is not exhaustive. The last of those is
     * PROJECT.md section G, item 5's documentation requirement put where it is
     * actually read — a limitation stated only in a docblock is a limitation
     * stated to nobody.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        if ($this->isClean()) {
            return [];
        }

        $hints = [];

        if ($this->diffPath !== null) {
            $hints[] = sprintf('The whole staged diff is kept at %s for review.', $this->diffPath);
        }

        $hints[] = sprintf(
            'Nothing was committed. The implementation is left in %s, staged, so the diff can be read '
                . 'with "git diff --cached" there as well.',
            $this->workspace,
        );
        $hints[] = 'Ralph PHP will not undo part of a diff or restore a test it thinks is missing: a task '
            . 'may legitimately have been asked to remove one, and deciding which this is would be the '
            . 'package deciding what your suite should contain.';
        $hints[] = 'If the change is wanted, commit it yourself from the workspace and tick the task off; '
            . 'if it is not, reword the task to say what must keep passing and run it again.';
        $hints[] = 'This reading is not comprehensive. It matches the shapes test code usually has in a '
            . 'diff, so a weakening written another way can pass it — review the commit rather than '
            . 'treating a clean audit as a guarantee.';

        return $hints;
    }

    /**
     * One phrase for a log header.
     */
    public function describe(): string
    {
        if ($this->isClean()) {
            return 'checks audit: nothing weakened';
        }

        return sprintf(
            'checks audit: %d finding%s (%s)',
            $this->count(),
            $this->count() === 1 ? '' : 's',
            implode(', ', array_map(
                static fn (Weakening $finding): string => $finding->kind()->value,
                array_slice($this->findings, 0, self::FINDINGS_IN_MESSAGE),
            )),
        );
    }

    /**
     * The findings as evidence for the attempt's row.
     *
     * Each finding is one entry, so the next person — or the next attempt —
     * reads what was found rather than a count of findings.
     */
    public function evidence(): FailureEvidence
    {
        $evidence = FailureEvidence::none();

        foreach ($this->findings as $finding) {
            $evidence = $evidence->with(
                $finding->kind()->label(),
                implode("\n", [$finding->summary(), ...$finding->quoted()]),
            );
        }

        return $evidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace' => $this->workspace,
            'clean' => $this->isClean(),
            'blocking' => $this->isBlocking(),
            'examined' => $this->examined,
            'diff' => $this->diffPath,
            'findings' => array_map(
                static fn (Weakening $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }
}
