<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

/**
 * Why a change in a task workspace was kept out of the task's commit.
 *
 * PROJECT.md section F, item 6 names two things staging must exclude, and they
 * are excluded for opposite reasons — which is why this is an enum rather than
 * a boolean. Runtime data is the package's own and nobody's work; a local file
 * is the user's and would be a secret or a machine-specific setting published
 * by accident. A user reading a staging record needs to be able to tell which
 * of the two they are looking at, because only one of them is ever a surprise.
 *
 * Git's own ignore rules are a third exclusion and deliberately not a case
 * here: an ignored path never reaches {@see WorkspaceStager} at all, because
 * `git status` does not report one. That is the project's standing statement
 * about what is not committable, and the package has nothing to add to it.
 */
enum ExclusionReason: string
{
    /**
     * Ralph PHP's own configuration, checklist and runtime data.
     *
     * The package reads and writes these in the controlling checkout by
     * design, and a copy of them inside a task workspace is either a checkout
     * of a tracked configuration file or something the agent created while
     * looking around. Neither belongs in a task's commit: the one file a
     * successful task changes here is the checklist, and the runner ticks that
     * box itself in the controlling checkout, after the commit, as the record
     * of work that is already finished.
     */
    case Runtime = 'runtime';

    /**
     * A file `workspace.copy` brought in from the controlling checkout.
     *
     * The setting's whole definition is "exists on this machine and in no
     * commit" — a `.env`, an `auth.json`, a local `phpunit.xml`. Committing one
     * would publish a credential or pin everybody else's build to this
     * machine's configuration, and the file is untracked in the workspace
     * precisely because the project has already decided it is not committable.
     */
    case Local = 'local';

    /**
     * A phrase naming the reason inside a sentence.
     */
    public function label(): string
    {
        return match ($this) {
            self::Runtime => 'Ralph PHP\'s own files',
            self::Local => 'a local file copied into the workspace',
        };
    }
}
