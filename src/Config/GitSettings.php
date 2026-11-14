<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * The `git` section: where task branches start, how they are named, how a run
 * distributes its commits across them, and whether they are published.
 */
final class GitSettings
{
    public function __construct(
        private readonly string $baseReference = Defaults::BASE_REFERENCE,
        private readonly string $branchPrefix = Defaults::BRANCH_PREFIX,
        private readonly ?PushSettings $push = null,
        private readonly string $strategy = Defaults::BRANCH_STRATEGY,
        private readonly ?string $sharedBranch = Defaults::SHARED_BRANCH,
    ) {
    }

    public static function fromRaw(RawConfiguration $raw): self
    {
        $raw->rejectUnknownKeys(['base', 'branch_prefix', 'push', 'strategy', 'shared_branch']);

        return new self(
            $raw->string('base', Defaults::BASE_REFERENCE),
            $raw->string('branch_prefix', Defaults::BRANCH_PREFIX),
            PushSettings::fromRaw($raw->section('push')),
            $raw->string('strategy', Defaults::BRANCH_STRATEGY),
            $raw->nullableString('shared_branch', Defaults::SHARED_BRANCH),
        );
    }

    /**
     * The reference task branches are created from. Resolved to a commit SHA
     * and recorded once per run, so that later edits to the base cannot change
     * what a running task was built on.
     */
    public function baseReference(): string
    {
        return $this->baseReference;
    }

    public function branchPrefix(): string
    {
        return $this->branchPrefix;
    }

    /**
     * Whether the run gives each task a branch or commits them all to one.
     *
     * Already validated by the time a run can read it
     * ({@see ConfigurationValidator}), so an unparseable value here would mean
     * the settings were assembled in code rather than loaded from a file. It
     * falls back to {@see Defaults::BRANCH_STRATEGY} rather than raising: this
     * accessor is read on the way to naming a branch, and a project that said
     * nothing readable about the mode is in the mode a project that said
     * nothing at all is in.
     */
    public function strategy(): BranchStrategy
    {
        return BranchStrategy::parse($this->strategy)
            ?? BranchStrategy::parse(Defaults::BRANCH_STRATEGY)
            ?? BranchStrategy::PerTask;
    }

    /**
     * The `strategy` setting exactly as the file spells it.
     *
     * For the validator, which reports what a user wrote rather than what it
     * was read as.
     */
    public function configuredStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * The branch every task of a run commits to under
     * {@see BranchStrategy::Shared}.
     *
     * Derived from the branch prefix when the setting is absent, so that the
     * shared branch sorts with the per-task ones, is matched by the same
     * `git branch --list "ralph/*"`, and follows a project that renames its
     * prefix. A project that wants the run on a branch of its own naming —
     * `feature/checkout`, say — sets `git.shared_branch` and the prefix stops
     * applying to it.
     *
     * Read in per-task mode as well, by `status` and by the validator: a name
     * is only acted on in shared mode, but it is worth reporting a name that
     * could never be used before the run that would use it.
     */
    public function sharedBranch(): string
    {
        $configured = $this->sharedBranch === null ? '' : trim($this->sharedBranch);

        if ($configured !== '') {
            return $configured;
        }

        return trim($this->branchPrefix) . Defaults::SHARED_BRANCH_SEGMENT;
    }

    /**
     * The `shared_branch` setting exactly as the file spells it, or null when
     * the file leaves it out.
     *
     * For the validator, which reports a blank name against the setting a user
     * wrote rather than against the derivation {@see self::sharedBranch()}
     * falls back to.
     */
    public function configuredSharedBranch(): ?string
    {
        return $this->sharedBranch;
    }

    /**
     * Whether the shared branch is the project's choice rather than the derived
     * default.
     */
    public function sharedBranchIsConfigured(): bool
    {
        return $this->sharedBranch !== null && trim($this->sharedBranch) !== '';
    }

    public function push(): PushSettings
    {
        return $this->push ?? new PushSettings();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'base' => $this->baseReference,
            'branch_prefix' => $this->branchPrefix,
            'strategy' => $this->strategy,
            'shared_branch' => $this->sharedBranch,
            'push' => $this->push()->toArray(),
        ];
    }
}
