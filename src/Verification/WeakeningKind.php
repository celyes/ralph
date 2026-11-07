<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Verification;

/**
 * One way a task's own diff can make the project's verification weaker than it
 * was.
 *
 * PROJECT.md section G, item 5. The checks of item 2 answer "does the work
 * pass?", and a diff that deletes the test that would have failed passes them
 * honestly — the suite it is run against is the suite the diff left behind.
 * That is the hole this closes: the checks are frozen against a worker's edit
 * to `.ralph/config.json` (item 3), and these kinds are frozen against a
 * worker's edit to what those checks *execute*.
 *
 * The kinds divide into three groups, by what a diff touched:
 *
 *  - **The tests themselves** — {@see self::TestsDeleted},
 *    {@see self::CasesRemoved}, {@see self::AssertionsRemoved},
 *    {@see self::CasesDisabled}. A suite that has lost a case, lost its
 *    assertions, or gained a `markTestSkipped` proves less than it did.
 *  - **What the tests are run by** — {@see self::ConfigurationNarrowed}. An
 *    `<exclude>` in `phpunit.xml`, a path removed from `phpstan.neon`: the
 *    files there have not changed, and the check no longer looks at them.
 *  - **The command itself** — {@see self::CommandChanged}. A frozen check
 *    that runs `bin/check`, in a diff that rewrites `bin/check`; or one that
 *    runs `composer test`, in a diff that rewrites that script.
 *
 * **Every kind blocks, and none is reconcilable.** Unlike
 * {@see \Celyes\RalphPhp\Git\DriftKind}, where putting a repository back is
 * sometimes a mechanical act, there is nothing here for a runner to put back:
 * a deleted test may have been deleted on purpose by a task that asked for it,
 * and re-adding it, or dropping the change, would be the package deciding what
 * the project's test suite ought to contain. So the outcome is always the same
 * — stop, keep the diff, and let a person say which it is.
 *
 * **Detection is a set of conventions, not a guarantee.** It reads the diff's
 * added and removed lines for the shapes test code usually has; it has no
 * parser, no coverage measurement, and no way to run the old suite against the
 * new code. {@see ChecksAudit} states what follows from that for a reviewer.
 */
enum WeakeningKind: string
{
    /**
     * A file that holds tests is gone from the tree, or was renamed to
     * somewhere tests are not looked for.
     *
     * The plainest weakening and the one least likely to be a false reading: a
     * suite cannot run a file that is not there. A rename *within* the test
     * locations is not this — the same cases are still collected under a new
     * name — which is why the new path is examined and not only the old one.
     */
    case TestsDeleted = 'tests_deleted';

    /**
     * The diff declares fewer test cases than it removes.
     *
     * Counted across the whole diff rather than per file, deliberately: moving
     * a case from one file to another is a refactor and nets to zero, while
     * deleting one is negative wherever in the tree it happened.
     */
    case CasesRemoved = 'cases_removed';

    /**
     * The diff removes more assertions from test files than it adds.
     *
     * The weakening that keeps the case and empties it. A test whose body is
     * replaced by `$this->assertTrue(true)` still runs, still passes, and
     * proves nothing; the case count is unchanged and this is what notices it.
     */
    case AssertionsRemoved = 'assertions_removed';

    /**
     * The diff adds a marker that stops a test from reporting.
     *
     * `markTestSkipped`, `markTestIncomplete`,
     * `expectNotToPerformAssertions`, a `->skip()`, an `xit`. A skipped test is
     * a green suite with a hole in it, and a suite read as passing is exactly
     * what this item is about. Net of markers removed, so a diff that *lifts* a
     * skip is not reported for having touched one.
     */
    case CasesDisabled = 'cases_disabled';

    /**
     * A file that configures one of the frozen checks lost lines, or gained a
     * directive that narrows what the check looks at.
     *
     * A `phpunit.xml` whose test suite directory was removed, a `phpstan.neon`
     * that gained `excludePaths`. Pure additions to such a file are not
     * reported on their own: a project adding a second test suite is
     * strengthening its verification, and reporting that would make the finding
     * mean nothing.
     */
    case ConfigurationNarrowed = 'configuration_narrowed';

    /**
     * The diff changes a file one of the frozen checks runs.
     *
     * The complete bypass: a check whose command is `./bin/check` verifies
     * whatever `./bin/check` does after the diff, so a single `exit 0` added
     * there makes every check that follows meaningless. Any change at all is
     * reported, because unlike a configuration file there is no shape of edit
     * to a program that can be assumed harmless.
     *
     * A check that runs a *script* — `composer test`, `make test` — is the same
     * finding read out of the manifest that declares it, and only out of the
     * declaration: editing `composer.json` to add a dependency changes nothing
     * about what a check does.
     */
    case CommandChanged = 'command_changed';

    /**
     * A short phrase naming the finding, for a log line or a status table.
     */
    public function label(): string
    {
        return match ($this) {
            self::TestsDeleted => 'a test file removed',
            self::CasesRemoved => 'test cases removed',
            self::AssertionsRemoved => 'assertions removed',
            self::CasesDisabled => 'tests disabled',
            self::ConfigurationNarrowed => 'a check\'s configuration narrowed',
            self::CommandChanged => 'a check\'s own command changed',
        };
    }
}
