<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

/**
 * What `ralph init` did to one path.
 *
 * Reported per path rather than summarised, because the difference matters to
 * the user: a kept file is their own content, which initialisation did not read
 * and did not change, while a replaced one is content that now only exists in
 * its backup.
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
     * The path existed with different content and was written from the
     * template. Only reachable when overwriting was explicitly requested, and
     * always paired with a backup of what was there.
     */
    case Replaced = 'replaced';

    /**
     * Label used in command output.
     */
    public function label(): string
    {
        return $this->value;
    }
}
