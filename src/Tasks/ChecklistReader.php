<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tasks;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\ChecklistException;

/**
 * Reads the project's task checklist from disk.
 *
 * The one place that turns the configured `tasks` path into a
 * {@see Checklist}, so every command reports a missing or unreadable checklist
 * the same way and {@see ChecklistParser} stays free of I/O and testable
 * against strings.
 *
 * Reading does not change the file: the checkbox updates of PROJECT.md section
 * C, item 6 are a separate writer, which is what keeps `status` and `logs`
 * unable to alter the user's checklist.
 */
final class ChecklistReader
{
    public function __construct(private readonly ChecklistParser $parser = new ChecklistParser())
    {
    }

    /**
     * Read the checklist the configuration points at.
     *
     * @throws ChecklistException when the file is missing, is not a readable
     *                            file, or cannot be parsed
     */
    public function read(Configuration $configuration): Checklist
    {
        return $this->readFile($configuration->tasksFile());
    }

    /**
     * Read a checklist from an absolute path.
     *
     * @throws ChecklistException
     */
    public function readFile(string $file): Checklist
    {
        if (is_dir($file)) {
            throw new ChecklistException(
                sprintf('The task checklist %s is a directory.', $file),
                ['Point the "tasks" setting at a Markdown file.'],
            );
        }

        if (!is_file($file)) {
            throw new ChecklistException(
                sprintf('No task checklist at %s.', $file),
                [
                    'Run "ralph init" in the project to create one.',
                    'If the checklist lives elsewhere, set "tasks" in the configuration.',
                ],
            );
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new ChecklistException(
                sprintf('Unable to read the task checklist %s.', $file),
                ['Check the file\'s permissions.'],
            );
        }

        return $this->parser->parse($contents, $file);
    }
}
