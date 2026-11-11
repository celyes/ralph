<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

/**
 * A process and everything it started, read from the process filesystem.
 *
 * What "the managed worker/process tree" of PROJECT.md section H, item 4 means
 * in practice. A coding agent's CLI is rarely one process: it spawns a language
 * server, a test run, a `git` of its own, sometimes a shell around all of them.
 * Signalling only the process the loop itself started leaves those behind — still
 * holding the task worktree open, still writing files the runner is about to
 * stage — and a run that reported itself stopped while they were running would
 * be exactly the state H, item 1's lock exists to make impossible.
 *
 * **Read before anything is signalled, which is why this is a value.** The
 * parent links only exist while the processes do: once the direct child is
 * dead, its children are reparented to init and nothing connects them to the
 * run any more. So the tree is collected first and then acted on, and the list
 * is a snapshot by construction rather than by convention.
 *
 * **`/proc`, and nothing else.** The supported platforms are Linux and WSL
 * (PROJECT.md section 2), where this needs no extension, no fork of `ps` and no
 * permission to the processes being asked about. A system without it reports
 * {@see self::isReadable()} false and reads no relationships at all, so the
 * caller is left with the direct child it already has a handle on: that child
 * is still stopped and still confirmed stopped, and anything it started is out
 * of reach. That is the "where supported" in the item's wording, stated rather
 * than pretended away.
 *
 * **A liveness answer that counts a zombie as gone.** {@see self::isAlive()} is
 * separate from {@see ProcessProbe} on purpose: the lock asks whether a process
 * id is *in use*, and a zombie's is. Termination asks whether a process is
 * still *running*, and a zombie has stopped — it is waiting to be reaped, which
 * for a descendant of a child this process has already closed happens outside
 * this process's control. Confirming termination through the probe would make a
 * correctly killed tree report itself unconfirmed for as long as init took.
 */
final class ProcessTree
{
    /**
     * The most processes a tree is read as.
     *
     * A bound rather than a limit anybody should reach: an agent with more than
     * this many descendants is a runaway, and the point of the bound is that
     * reading a pathological `/proc` cannot turn into an unbounded walk inside
     * a signal-handling path.
     */
    public const int MAXIMUM = 512;

    /**
     * @param list<int> $descendants every process below the root, nearest first
     */
    private function __construct(
        private readonly int $root,
        private readonly array $descendants,
        private readonly string $procfs,
    ) {
    }

    /**
     * The tree below a process, as the process filesystem has it now.
     *
     * A root whose own entry has gone still reports a tree: its descendants
     * outlive it, and they are the ones that would be left behind.
     */
    public static function rootedAt(int $pid, string $procfs = '/proc'): self
    {
        if (!self::isReadable($procfs)) {
            return new self($pid, [], $procfs);
        }

        $children = self::childrenByParent($procfs);
        $descendants = [];
        $queue = [$pid];
        $seen = [$pid => true];

        while ($queue !== [] && count($descendants) < self::MAXIMUM) {
            $parent = (int) array_shift($queue);

            foreach ($children[$parent] ?? [] as $child) {
                // A cycle cannot exist in a process table, and a `/proc` read
                // while processes are exiting can still hand back two entries
                // that look like one: the walk is bounded by what it has seen
                // either way.
                if (isset($seen[$child])) {
                    continue;
                }

                $seen[$child] = true;
                $descendants[] = $child;
                $queue[] = $child;

                if (count($descendants) >= self::MAXIMUM) {
                    break 2;
                }
            }
        }

        return new self($pid, $descendants, $procfs);
    }

    /**
     * Whether process relationships can be read at all here.
     */
    public static function isReadable(string $procfs = '/proc'): bool
    {
        return is_dir($procfs) && is_readable($procfs);
    }

    /**
     * The process the tree hangs off.
     */
    public function root(): int
    {
        return $this->root;
    }

    /**
     * Everything the root started, and everything they started.
     *
     * @return list<int>
     */
    public function descendants(): array
    {
        return $this->descendants;
    }

    /**
     * The whole tree, deepest first.
     *
     * The order termination uses: a child signalled before its parent cannot be
     * handed to a parent that respawns it, and a shell killed before the
     * command it is waiting on would leave that command orphaned and running.
     *
     * @return list<int>
     */
    public function deepestFirst(): array
    {
        return [...array_reverse($this->descendants), $this->root];
    }

    public function isOnlyRoot(): bool
    {
        return $this->descendants === [];
    }

    public function count(): int
    {
        return count($this->descendants) + 1;
    }

    /**
     * One phrase for a log note.
     */
    public function describe(): string
    {
        if ($this->descendants === []) {
            return sprintf('process %d', $this->root);
        }

        return sprintf(
            'process %d and %d it started (%s)',
            $this->root,
            count($this->descendants),
            implode(', ', $this->descendants),
        );
    }

    /**
     * Whether a process is still running, counting one that has exited but not
     * been reaped as stopped.
     *
     * Null when this system offers no way to tell, which is a different answer
     * from "no" and is reported as such: an unconfirmed termination is what
     * keeps the repository lock from being released (section H, item 4).
     */
    public function isAlive(int $pid): ?bool
    {
        return self::isProcessAlive($pid, $this->procfs);
    }

    /**
     * The same question, without a tree in hand.
     *
     * `/proc` first, because it is the only reading that can tell a running
     * process from one waiting to be reaped. A system without it falls back to
     * an unsignalled `posix_kill`, which answers the narrower question — does
     * this id exist — and is enough: on such a system a zombie is somebody
     * else's to reap and will go on its own.
     *
     * Null only where neither is available, which is a third answer and not a
     * no ({@see Termination}).
     */
    public static function isProcessAlive(int $pid, string $procfs = '/proc'): ?bool
    {
        if ($pid < 1) {
            return false;
        }

        if (self::isReadable($procfs)) {
            $state = self::stateOf($pid, $procfs);

            if ($state === null) {
                return false;
            }

            // Z: exited, not yet reaped. X: being torn down. Neither is a
            // process that can still write to the workspace, which is the only
            // thing termination is asked to establish.
            return $state !== 'Z' && $state !== 'X';
        }

        if (!function_exists('posix_kill')) {
            return null;
        }

        if (posix_kill($pid, 0)) {
            return true;
        }

        // The signal being refused means the process is there and is not ours,
        // which is a different answer from no such process. EPERM is 1 on
        // Linux, and PHP exposes no constant for it on every build.
        return posix_get_last_error() === 1;
    }

    /**
     * Every process id with a parent, grouped by that parent.
     *
     * One pass over `/proc` rather than a lookup per candidate: the walk below
     * asks about the children of several processes, and a directory listing is
     * a great deal cheaper than re-reading the table for each of them.
     *
     * @return array<int, list<int>>
     */
    private static function childrenByParent(string $procfs): array
    {
        $entries = @scandir($procfs);

        if ($entries === false) {
            return [];
        }

        $children = [];

        foreach ($entries as $entry) {
            if ($entry === '' || !ctype_digit($entry)) {
                continue;
            }

            $pid = (int) $entry;
            $parent = self::parentOf($pid, $procfs);

            if ($parent === null) {
                continue;
            }

            $children[$parent][] = $pid;
        }

        return $children;
    }

    /**
     * The parent of a process, or null when it cannot be read.
     */
    private static function parentOf(int $pid, string $procfs): ?int
    {
        $fields = self::statFieldsOf($pid, $procfs);

        // The fields after the command name are state, ppid: the second is the
        // parent.
        return isset($fields[1]) && ctype_digit($fields[1]) ? (int) $fields[1] : null;
    }

    /**
     * The single-character run state of a process, or null when it has gone.
     */
    private static function stateOf(int $pid, string $procfs): ?string
    {
        $fields = self::statFieldsOf($pid, $procfs);

        return $fields === [] ? null : $fields[0];
    }

    /**
     * The fields of `/proc/PID/stat` after the command name.
     *
     * Split after the last `)` rather than on every space, because the command
     * name is the second field, is parenthesised, and may itself contain spaces
     * and parentheses — an executable called `my (test) runner` would otherwise
     * shift every field after it and make this read a state character as a
     * parent process id.
     *
     * @return list<string>
     */
    private static function statFieldsOf(int $pid, string $procfs): array
    {
        $path = $procfs . '/' . $pid . '/stat';

        clearstatcache(true, $path);
        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return [];
        }

        $end = strrpos($contents, ')');

        if ($end === false) {
            return [];
        }

        $rest = preg_split('/\s+/', trim(substr($contents, $end + 1)));

        return $rest === false ? [] : array_values(array_filter($rest, static fn (string $f): bool => $f !== ''));
    }
}
