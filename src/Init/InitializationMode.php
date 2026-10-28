<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

/**
 * How `ralph init` treats a project file that already exists.
 *
 * Preserving is the default and the only behaviour reachable without an
 * explicit request: a project's checklist and context file are written by the
 * user, and initialisation must never be the reason they are lost. Overwriting
 * exists because a project whose configuration is broken cannot be initialised
 * around, and deleting three files by hand to start over is a worse answer than
 * one documented option.
 */
enum InitializationMode: string
{
    /**
     * Create what is missing and leave everything that exists exactly as it is.
     */
    case Preserve = 'preserve';

    /**
     * Reset the project files to the templates, backing up what they replace.
     */
    case Overwrite = 'overwrite';

    public function overwrites(): bool
    {
        return $this === self::Overwrite;
    }
}
