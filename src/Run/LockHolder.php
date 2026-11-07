<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Run;

use Celyes\RalphPhp\State\Timestamp;
use Celyes\RalphPhp\Support\Clock;
use InvalidArgumentException;

/**
 * What the lock file says about the command that took it.
 *
 * The lock itself is the kernel's: an `flock` on the file, released when the
 * process holding it exits however it exits. That is what actually prevents two
 * runners overlapping, and it needs no file contents at all. This record is
 * what makes the refusal *readable* — "another Ralph PHP run holds this
 * repository" is not an actionable sentence without the run, the process, the
 * machine and the moment — and it is what a resume reads to decide whether the
 * runner that left the lock behind has really stopped (PROJECT.md section H,
 * item 1).
 *
 * **The file is never deleted, only rewritten.** A released lock is a record
 * that says it was released, not an absent file. Deleting it would unlink the
 * inode the `flock` is attached to, so a runner still holding the lock on the
 * old inode and a runner that has just created a new file at the same path
 * would both believe they hold it — the exact situation the lock exists to
 * prevent.
 *
 * **It is evidence, not authority.** Nothing here is trusted over the kernel:
 * a record claiming to be held while the lock is free describes a runner that
 * died, and a record whose process is somehow still running contradicts the
 * free lock and is reported as ambiguous rather than resolved in either
 * direction.
 */
final class LockHolder
{
    private function __construct(
        private readonly string $purpose,
        private readonly ?string $host,
        private readonly ?int $pid,
        private readonly ?string $runId,
        private readonly Timestamp $acquiredAt,
        private readonly ?Timestamp $releasedAt,
    ) {
    }

    /**
     * The record this process writes when it takes the lock.
     *
     * @param string $purpose the command taking it — `run`, `resume`, `retry` —
     *        so that a conflict names what is already going on rather than only
     *        that something is
     *
     * @throws InvalidArgumentException when the purpose says nothing
     */
    public static function here(string $purpose, Clock $clock, ?string $runId = null): self
    {
        return new self(
            self::named($purpose),
            self::thisHost(),
            self::thisPid(),
            self::identifier($runId),
            Timestamp::now($clock),
            null,
        );
    }

    /**
     * Read a record back, or null when there is nothing readable in it.
     *
     * A file that holds something this cannot read is treated as no record
     * rather than as a failure. The contents are generated, they are only ever
     * read to explain a refusal, and a project that could not be run again
     * because its lock file was truncated by a full disk would be a worse
     * outcome than a takeover that says less about what it displaced.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromStorage(array $data): ?self
    {
        $purpose = $data['purpose'] ?? null;
        $acquired = $data['acquired_at'] ?? null;

        if (!is_string($purpose) || trim($purpose) === '' || !is_string($acquired)) {
            return null;
        }

        try {
            $acquiredAt = Timestamp::fromStorage($acquired);
            $releasedAt = is_string($data['released_at'] ?? null)
                ? Timestamp::fromStorage((string) $data['released_at'])
                : null;
        } catch (InvalidArgumentException) {
            return null;
        }

        $host = $data['host'] ?? null;
        $pid = $data['pid'] ?? null;
        $runId = $data['run_id'] ?? null;

        return new self(
            trim($purpose),
            is_string($host) && trim($host) !== '' ? trim($host) : null,
            is_int($pid) && $pid > 0 ? $pid : null,
            is_string($runId) && trim($runId) !== '' ? trim($runId) : null,
            $acquiredAt,
            $releasedAt,
        );
    }

    /**
     * The same record, now naming the run it opened.
     *
     * The lock is taken before the run row exists — it has to be, since opening
     * the row is already writing to the project — so the identifier is written
     * in a second time once there is one.
     *
     * @throws InvalidArgumentException when the identifier says nothing
     */
    public function forRun(string $runId): self
    {
        return new self(
            $this->purpose,
            $this->host,
            $this->pid,
            self::identifier($runId),
            $this->acquiredAt,
            $this->releasedAt,
        );
    }

    /**
     * The same record, released at this moment.
     */
    public function releasedAt(Clock $clock): self
    {
        return new self(
            $this->purpose,
            $this->host,
            $this->pid,
            $this->runId,
            $this->acquiredAt,
            Timestamp::now($clock),
        );
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function host(): ?string
    {
        return $this->host;
    }

    public function pid(): ?int
    {
        return $this->pid;
    }

    /**
     * The run this lock was taken for, once it had been opened.
     */
    public function runId(): ?string
    {
        return $this->runId;
    }

    public function acquiredAt(): Timestamp
    {
        return $this->acquiredAt;
    }

    public function releaseMoment(): ?Timestamp
    {
        return $this->releasedAt;
    }

    /**
     * Whether the record claims the lock is still held.
     *
     * A claim only. The kernel decides whether it is true.
     */
    public function isHeld(): bool
    {
        return $this->releasedAt === null;
    }

    public function isReleased(): bool
    {
        return $this->releasedAt !== null;
    }

    /**
     * Whether this record was written on the machine asking.
     *
     * Null when either side cannot be named, which is a real answer and not a
     * no: a state directory reached through a network share can hold a record
     * from a host this one has no way to compare itself against, and that is
     * precisely the case where a takeover must not be assumed safe.
     */
    public function isOn(?string $host): ?bool
    {
        if ($this->host === null || $host === null) {
            return null;
        }

        return $this->host === $host;
    }

    /**
     * Whether this record names the process asking.
     *
     * The case of a run that died without releasing inside a process that is
     * still alive — a hard failure between taking the lock and the release that
     * would have followed it. Nothing can be overlapping here, because the only
     * runner this process could overlap with is itself.
     */
    public function isProcess(?int $pid): bool
    {
        return $pid !== null && $this->pid === $pid;
    }

    /**
     * One line naming who held it, for a refusal or a takeover notice.
     */
    public function describe(): string
    {
        $where = match (true) {
            $this->pid !== null && $this->host !== null => sprintf('process %d on %s', $this->pid, $this->host),
            $this->pid !== null => sprintf('process %d', $this->pid),
            $this->host !== null => sprintf('an unrecorded process on %s', $this->host),
            default => 'an unrecorded process',
        };

        return sprintf(
            '%s, taken by %s at %s%s',
            $this->runId ?? sprintf('a "%s" command', $this->purpose),
            $where,
            $this->acquiredAt->value(),
            $this->releasedAt === null
                ? ''
                : sprintf(' and released at %s', $this->releasedAt->value()),
        );
    }

    /**
     * The record as the lock file holds it.
     *
     * @return array<string, scalar|null>
     */
    public function toStorage(): array
    {
        return [
            'purpose' => $this->purpose,
            'host' => $this->host,
            'pid' => $this->pid,
            'run_id' => $this->runId,
            'acquired_at' => $this->acquiredAt->value(),
            'released_at' => $this->releasedAt?->value(),
        ];
    }

    /**
     * The machine this process is on, where it can be named.
     */
    public static function thisHost(): ?string
    {
        $host = gethostname();

        return $host === false ? null : $host;
    }

    /**
     * This process's id, where it can be read.
     */
    public static function thisPid(): ?int
    {
        $pid = getmypid();

        return $pid === false ? null : $pid;
    }

    /**
     * @throws InvalidArgumentException when the purpose says nothing
     */
    private static function named(string $purpose): string
    {
        $trimmed = trim($purpose);

        if ($trimmed === '') {
            throw new InvalidArgumentException('A lock record must name the command that took it.');
        }

        return $trimmed;
    }

    /**
     * @throws InvalidArgumentException when a given identifier says nothing
     */
    private static function identifier(?string $runId): ?string
    {
        if ($runId === null) {
            return null;
        }

        if (trim($runId) === '') {
            throw new InvalidArgumentException('A lock record cannot name a blank run.');
        }

        return trim($runId);
    }
}
