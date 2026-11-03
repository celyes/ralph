<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

/**
 * An adapter that knows which releases of its CLI it was written for.
 *
 * PROJECT.md section E, item 9. An adapter's invocation targets one program's
 * command line, and an installation too old to have those flags fails in a way
 * nothing downstream can read: the CLI prints its usage, exits non-zero, and
 * every attempt and every retry repeats it. Implementing this is how an
 * adapter has that reported before a run starts, by
 * {@see \Celyes\RalphPhp\Preflight\Check\AgentVersionCheck}, rather than
 * discovered by a user from a log.
 *
 * **Optional, and separate from {@see ExecutionAdapter} for that reason.** A
 * registered third-party adapter may have no version to speak of — an agent
 * reached over HTTP, a wrapper script a project maintains itself — and the
 * check reports such an adapter as having stated no requirement rather than
 * assuming `--version` would mean anything to it. Both shipped adapters
 * implement it.
 *
 * The method is called during preflight and may be called more than once; it
 * answers from what the adapter knows about itself and must not run anything.
 * Running the query is the check's business, which is what keeps an adapter
 * from deciding on its own when the agent gets started.
 */
interface VersionedAdapter extends ExecutionAdapter
{
    /**
     * The oldest CLI release this adapter's invocation targets, and how to ask
     * the installed one what it is.
     */
    public function versionRequirement(): VersionRequirement;
}
