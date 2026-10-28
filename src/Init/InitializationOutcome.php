<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

/**
 * What `ralph init` did to one path.
 *
 * Reported per path rather than summarised, because the difference matters to
 * the user: a kept file is their own content, which initialisation did not read
 * and did not change.
 */
enum InitializationOutcome: string
{
    /**
     * The path did not exist and was written from the template.
     */
    case Created = 'created';

    /**
     * The path already existed and was left exactly as it was.
     */
    case Kept = 'kept';

    /**
     * Label used in command output.
     */
    public function label(): string
    {
        return $this->value;
    }
}
