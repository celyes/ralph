<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

/**
 * What `ralph init` did to the project's ignore file.
 *
 * Separate from {@see InitializationOutcome} because the ignore file is the one
 * path initialisation is not allowed to own: it usually belongs to the project
 * already, so it is only ever appended to. There is no `replaced` here, and
 * `--force` does not add one.
 */
enum IgnoreFileOutcome: string
{
    /**
     * There was no ignore file, and one was written with the rules.
     */
    case Created = 'created';

    /**
     * An ignore file existed, and the missing rules were appended to it. Every
     * line that was already there is untouched.
     */
    case Extended = 'extended';

    /**
     * Every rule was already decided by the file — ignored, or explicitly
     * un-ignored by the user — so nothing was written.
     */
    case Kept = 'kept';

    /**
     * The rules could not be written, and the reason is reported. The rest of
     * initialisation still stands: ignore rules are a convenience, and failing
     * a whole `ralph init` over them would be worse than saying so.
     */
    case Skipped = 'skipped';

    /**
     * Label used in command output.
     */
    public function label(): string
    {
        return $this->value;
    }

    /**
     * Whether anything was written to the file.
     */
    public function wrote(): bool
    {
        return $this === self::Created || $this === self::Extended;
    }
}
