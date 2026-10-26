<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * The `git.push` setting: whether verified task branches are published, and
 * where to.
 *
 * Disabled unless the user enables it explicitly. When it is enabled, a push
 * is part of completing a task: a task whose push fails keeps its local commit
 * and stays unchecked.
 */
final class PushSettings
{
    public function __construct(
        private readonly bool $enabled = Defaults::PUSH_ENABLED,
        private readonly string $remote = Defaults::PUSH_REMOTE,
    ) {
    }

    public static function fromRaw(RawConfiguration $raw): self
    {
        $raw->rejectUnknownKeys(['enabled', 'remote']);

        return new self(
            $raw->bool('enabled', Defaults::PUSH_ENABLED),
            $raw->string('remote', Defaults::PUSH_REMOTE),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function remote(): string
    {
        return $this->remote;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'remote' => $this->remote,
        ];
    }
}
