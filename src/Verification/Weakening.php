<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

use InvalidArgumentException;

/**
 * One reading of a task's diff that says the project's verification is weaker
 * after it than before.
 *
 * A finding, not a decision: {@see ChecksAuditor} makes them,
 * {@see ChecksAudit} collects them, and the pass acts on the audit. The same
 * division {@see \Celyes\RalphPhp\Git\WorkspaceDrift} makes against
 * {@see \Celyes\RalphPhp\Git\WorkspaceAuditor}, for the same reason: a value
 * that cannot delete a file is not a place where deleting one can be reached
 * for by mistake.
 *
 * Every finding names the paths it is about and quotes its own evidence,
 * because of where it is read. A task blocked here is a task a person has to
 * form an opinion about, from a terminal line and a preserved patch, and
 * "verification was weakened" without a path and a line is not something an
 * opinion can be formed from.
 *
 * Factories rather than a public constructor, one per kind, so that a finding's
 * kind and the sentence describing it are written together and cannot drift
 * apart.
 */
final class Weakening
{
    /**
     * Characters of a quoted source line kept in the finding.
     *
     * A finding is read in a terminal and stored in a blocker; the whole line
     * is in the preserved patch, which is where someone goes to read it in
     * context.
     */
    public const int QUOTED_CHARACTERS = 120;

    /**
     * @param string       $summary one sentence, phrased to stand alone in a list
     * @param list<string> $paths   the files the finding is about
     * @param list<string> $quoted  lines from the diff that are the evidence
     */
    private function __construct(
        private readonly WeakeningKind $kind,
        private readonly string $summary,
        private readonly array $paths,
        private readonly array $quoted,
    ) {
        if (trim($summary) === '') {
            throw new InvalidArgumentException('A weakening must say what was found.');
        }
    }

    /**
     * A file holding tests is no longer in the tree.
     *
     * @param string|null $renamedTo where it went, when the diff renamed it out
     *        of the test locations rather than deleting it — a distinction a
     *        reviewer acts on differently, since the cases may still exist
     */
    public static function testsDeleted(string $path, ?string $renamedTo = null): self
    {
        return new self(
            WeakeningKind::TestsDeleted,
            $renamedTo === null
                ? sprintf('the test file %s is deleted by this diff', $path)
                : sprintf(
                    'the test file %s is renamed to %s, where tests are not looked for',
                    $path,
                    $renamedTo,
                ),
            $renamedTo === null ? [$path] : [$path, $renamedTo],
            [],
        );
    }

    /**
     * The diff declares fewer test cases than it removes.
     *
     * @param list<string> $paths the test files the count was taken over
     * @param list<string> $quoted the removed declarations
     */
    public static function casesRemoved(int $removed, int $added, array $paths, array $quoted): self
    {
        return new self(
            WeakeningKind::CasesRemoved,
            sprintf(
                'this diff removes %d test case declaration%s and adds %d, across %d test file%s',
                $removed,
                $removed === 1 ? '' : 's',
                $added,
                count($paths),
                count($paths) === 1 ? '' : 's',
            ),
            $paths,
            $quoted,
        );
    }

    /**
     * The diff removes more assertions from test files than it adds.
     *
     * @param list<string> $paths the test files the count was taken over
     * @param list<string> $quoted the removed assertions
     */
    public static function assertionsRemoved(int $removed, int $added, array $paths, array $quoted): self
    {
        return new self(
            WeakeningKind::AssertionsRemoved,
            sprintf(
                'this diff removes %d assertion%s from test files and adds %d',
                $removed,
                $removed === 1 ? '' : 's',
                $added,
            ),
            $paths,
            $quoted,
        );
    }

    /**
     * The diff adds a marker that stops a test from reporting.
     *
     * @param list<string> $paths
     * @param list<string> $quoted the added markers
     */
    public static function casesDisabled(int $count, array $paths, array $quoted): self
    {
        return new self(
            WeakeningKind::CasesDisabled,
            sprintf(
                'this diff adds %d marker%s that stop%s a test from reporting',
                $count,
                $count === 1 ? '' : 's',
                $count === 1 ? 's' : '',
            ),
            $paths,
            $quoted,
        );
    }

    /**
     * A file configuring one of the frozen checks lost lines or gained a
     * narrowing directive.
     *
     * @param string|null $check the check the file belongs to, named as it is
     *        named everywhere else a person reads about one, or null when the
     *        file configures a tool none of the frozen checks mentions by name
     * @param list<string> $quoted the removed lines, or the narrowing directives
     */
    public static function configurationNarrowed(string $path, ?string $check, array $quoted): self
    {
        return new self(
            WeakeningKind::ConfigurationNarrowed,
            $check === null
                ? sprintf('%s configures how this project is checked, and this diff narrows it', $path)
                : sprintf(
                    '%s configures the check "%s", and this diff narrows what it covers',
                    $path,
                    $check,
                ),
            [$path],
            $quoted,
        );
    }

    /**
     * The diff changes the script one of the frozen checks runs.
     *
     * The manifest a script lives in is also the file an ordinary task most
     * often has a legitimate reason to touch, so the finding names the script
     * rather than the file: adding a dependency to `composer.json` is not this,
     * and rewriting its `test` script is.
     *
     * @param string $script the script the check invokes
     * @param string $check  the check that invokes it
     * @param list<string> $quoted the changed lines that declare it
     */
    public static function scriptChanged(string $path, string $script, string $check, array $quoted): self
    {
        return new self(
            WeakeningKind::CommandChanged,
            sprintf(
                '%s defines the "%s" script that the check "%s" runs, and this diff changes it',
                $path,
                $script,
                $check,
            ),
            [$path],
            $quoted,
        );
    }

    /**
     * The diff changes a file one of the frozen checks runs.
     *
     * @param string $check the check whose command names the path
     */
    public static function commandChanged(string $path, string $check): self
    {
        return new self(
            WeakeningKind::CommandChanged,
            sprintf('the check "%s" runs %s, and this diff changes it', $check, $path),
            [$path],
            [],
        );
    }

    public function kind(): WeakeningKind
    {
        return $this->kind;
    }

    /**
     * One sentence saying what was found, phrased to stand alone in a list.
     */
    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * The files the finding is about.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * Lines from the diff that are the evidence, shortened for reading.
     *
     * @return list<string>
     */
    public function quoted(): array
    {
        return $this->quoted;
    }

    /**
     * One line for a log or a status table.
     */
    public function describe(): string
    {
        return sprintf('%s: %s', $this->kind->value, $this->summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'summary' => $this->summary,
            'paths' => $this->paths,
            'quoted' => $this->quoted,
        ];
    }

    /**
     * A source line as a finding quotes it: one line, trimmed, shortened.
     *
     * Static and here rather than in the auditor, so that every quoted line in
     * every finding is bounded the same way — a finding is stored in a blocker
     * whose own limit would otherwise truncate the remedy to make room for a
     * minified file's single 40,000-character line.
     */
    public static function quote(string $line): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $line) ?? $line);

        return strlen($text) <= self::QUOTED_CHARACTERS
            ? $text
            : substr($text, 0, self::QUOTED_CHARACTERS - 1) . '…';
    }
}
