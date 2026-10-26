<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * The `git` section: where task branches start, how they are named, and
 * whether they are published.
 */
final class GitSettings
{
    public function __construct(
        private readonly string $baseReference = Defaults::BASE_REFERENCE,
        private readonly string $branchPrefix = Defaults::BRANCH_PREFIX,
        private readonly ?PushSettings $push = null,
    ) {
    }

    public static function fromRaw(RawConfiguration $raw): self
    {
        $raw->rejectUnknownKeys(['base', 'branch_prefix', 'push']);

        return new self(
            $raw->string('base', Defaults::BASE_REFERENCE),
            $raw->string('branch_prefix', Defaults::BRANCH_PREFIX),
            PushSettings::fromRaw($raw->section('push')),
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
            'push' => $this->push()->toArray(),
        ];
    }
}
