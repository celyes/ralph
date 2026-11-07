<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight\Check;

use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\PreflightCheck;
use Celyes\RalphPhp\Preflight\PreflightContext;
use Celyes\RalphPhp\Ralph;
use PDO;

/**
 * The PHP extensions the package declares, checked against the build that is
 * actually running.
 *
 * Composer enforces the same requirement at install time, under whichever PHP
 * resolved the dependency tree. That is not the same question: a global tool is
 * routinely installed under one PHP build and invoked under another, and a
 * missing `pdo_sqlite` then surfaces as a failure to open the state store
 * halfway into a run.
 *
 * `pdo_sqlite` is checked twice over — the extension, and the `sqlite` driver
 * PDO reports — because the extension being loaded is not quite the thing the
 * state store needs.
 */
final class ExtensionsCheck implements PreflightCheck
{
    public function id(): CheckId
    {
        return CheckId::Extensions;
    }

    public function inspect(PreflightContext $context): CheckResult
    {
        $missing = [];

        foreach (Ralph::REQUIRED_EXTENSIONS as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        $driver = $this->sqliteDriverProblem();
        $details = [
            sprintf('required: %s', implode(', ', Ralph::REQUIRED_EXTENSIONS)),
            ...$this->optional(),
        ];

        $configuration = php_ini_loaded_file();
        if (is_string($configuration)) {
            $details[] = sprintf('php.ini: %s', $configuration);
        }

        if ($missing === [] && $driver === null) {
            return CheckResult::passed(
                $this->id(),
                sprintf('%s loaded', implode(', ', Ralph::REQUIRED_EXTENSIONS)),
                $details,
            );
        }

        $problems = $missing === []
            ? (string) $driver
            : sprintf(
                '%s not loaded%s',
                implode(', ', $missing),
                $driver === null ? '' : '; ' . $driver,
            );

        return CheckResult::failed(
            $this->id(),
            $problems,
            $details,
            [
                sprintf('Install the extensions for this PHP build (%s) and enable them.', PHP_BINARY),
                'The runner keeps its task, attempt and recovery records in SQLite, so pdo_sqlite is '
                    . 'not optional.',
                sprintf('"%s -m" lists the extensions this build has.', PHP_BINARY),
            ],
        );
    }

    /**
     * What the optional extensions would add, where they are missing.
     *
     * A detail and never a failure: {@see Ralph::OPTIONAL_EXTENSIONS} are
     * things the package does better with and works without, so the check
     * passes either way. Reported at all because the one they affect is Ctrl+C
     * (PROJECT.md section H, item 4), and a user is better told before a run
     * that stopping it will end the process where it stands than after.
     *
     * @return list<string>
     */
    private function optional(): array
    {
        $details = [];

        foreach (Ralph::OPTIONAL_EXTENSIONS as $extension => $what) {
            if (!extension_loaded($extension)) {
                $details[] = sprintf('%s is not loaded, which otherwise %s', $extension, $what);
            }
        }

        return $details;
    }

    /**
     * Why PDO could not open a SQLite database, or null when it can.
     */
    private function sqliteDriverProblem(): ?string
    {
        if (!extension_loaded('pdo') || !class_exists(PDO::class)) {
            // Already reported as a missing extension; saying it twice would
            // not tell the user anything new.
            return null;
        }

        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return null;
        }

        return 'PDO has no "sqlite" driver';
    }
}
