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
 * Reading does not change the file: ticking a box is {@see ChecklistWriter}'s
 * job and nothing else writes to the checklist, which is what keeps `status`
 * and `logs` unable to alter it.
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
        return $this->parser->parse(ChecklistFile::read($file), $file);
    }
}
