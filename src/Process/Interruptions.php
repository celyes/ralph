<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Process;

/**
 * Where this process learns that it has been asked to stop, and what it did
 * about it.
 *
 * PROJECT.md section H, item 4. Ctrl+C is not an event the loop can be handed
 * as a parameter: it arrives at an arbitrary moment, in a signal handler, while
 * the call stack is somewhere inside an agent's twenty-minute attempt. So there
 * is one place every part of the package can ask "has a stop been requested",
 * and it is process-wide — which is not a convenience here but the shape of the
 * thing being modelled. There is one process, one terminal and one Ctrl+C, and
 * a per-object flag would be invisible to the {@see ProcessRunner} an adapter
 * built for itself.
 *
 * **The handler does nothing but remember.** Killing a process tree, writing to
 * SQLite and closing a lock file are not things to do inside a signal handler,
 * where the interpreter may be halfway through any of them already. The handler
 * records the signal; {@see ProcessRunner} notices between reads of the
 * worker's output and stops it from ordinary control flow, where a failure can
 * be reported and a database write can be rolled back.
 *
 * **Noticing is polling, and that is why it is cheap.** {@see self::requested()}
 * dispatches any pending signal and reads a flag. It is called in a loop that
 * is already waiting on `stream_select`, so the cost is one function call per
 * chunk of agent output.
 *
 * **Insisting works.** A second signal of the same kind restores that signal's
 * default disposition, so a user who presses Ctrl+C again while the package is
 * waiting for a stubborn agent to die gets the ordinary, immediate behaviour. A
 * run that ends that way leaves its lock record claiming the repository, which
 * is the state H, item 1's takeover check exists for.
 *
 * **Without ext-pcntl there is no handler to install.** {@see self::isSupported()}
 * says so, `ralph` runs exactly as it does now, and Ctrl+C terminates PHP where
 * it stands — leaving an interrupted run to be reconciled on resume rather than
 * recorded on the way out. That is the "where supported" in the item's wording;
 * the extension is suggested by `composer.json` and reported by preflight, and
 * it is not required, because requiring it would make the package uninstallable
 * on a build where everything except this one courtesy works.
 */
final class Interruptions
{
    /**
     * The signals a run stops for.
     *
     * Ctrl+C, an ordinary `kill`, and the terminal going away. Not SIGQUIT,
     * which a user sends when they want a core dump rather than a tidy stop.
     *
     * @var list<int>
     */
    public const array SIGNALS = [Interruption::SIGINT, Interruption::SIGTERM, Interruption::SIGHUP];

    /**
     * The first stop that was asked for, or null while none has been.
     *
     * The *first*: a user pressing Ctrl+C twice has not changed their mind
     * about why, and the run's recorded reason should be the keystroke that
     * started the stop rather than the one that lost patience with it.
     */
    private static ?Interruption $requested = null;

    /**
     * The handlers that were installed before this package's, by signal.
     *
     * Kept so {@see self::release()} can put them back: the package is a
     * library as well as a command, and a host application that had its own
     * SIGTERM handling should not find it replaced after calling the runner.
     *
     * @var array<int, callable|int|null>
     */
    private static array $previous = [];

    /**
     * Processes a stop was sent to and that could not be confirmed stopped.
     *
     * The reason this class holds anything beyond the flag. The worker is
     * stopped deep inside an adapter's {@see ProcessRunner}, and the decision
     * that turns on whether it died — whether the repository lock may be
     * released (section H, item 4) — is made in the runner's outermost
     * `finally`. One is not reachable from the other, and a surviving worker is
     * process-wide in exactly the way the interruption is.
     *
     * @var list<int>
     */
    private static array $unconfirmed = [];

    /**
     * Whether a signal handler can be installed at all.
     */
    public static function isSupported(): bool
    {
        return function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
    }


    /**
     * Install the handlers, and say whether there was anything to install.
     *
     * Idempotent: a second call while the handlers are in place does nothing,
     * so a nested runner — a library host calling `run` inside its own command
     * — cannot double-install or lose the saved dispositions.
     */
    public static function listen(): bool
    {
        if (!self::isSupported()) {
            return false;
        }

        if (self::$previous !== []) {
            return true;
        }

        // Handlers run as the signal arrives rather than only where the
        // package remembers to dispatch, so a stop is noticed inside a
        // blocking read as well as in the loop's own polling.
        pcntl_async_signals(true);

        foreach (self::SIGNALS as $signal) {
            self::$previous[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, self::handler());
        }

        return true;
    }

    /**
     * Put the previous handlers back and forget this run's state.
     *
     * Called on the way out of a run, however it ends. The flag is cleared with
     * them: the next run in the same process — a test suite, a library host
     * with a queue of work — has not been interrupted.
     */
    public static function release(): void
    {
        if (self::isSupported()) {
            foreach (self::$previous as $signal => $handler) {
                pcntl_signal($signal, $handler ?? SIG_DFL);
            }
        }

        self::$previous = [];
        self::$requested = null;
        self::$unconfirmed = [];
    }

    /**
     * The stop that has been asked for, or null while none has been.
     *
     * Dispatches first, so that a build with asynchronous signals turned off by
     * a host application still notices.
     */
    public function requested(): ?Interruption
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return self::$requested;
    }

    /**
     * Whether a stop has been asked for.
     */
    public function isRequested(): bool
    {
        return $this->requested() !== null;
    }

    /**
     * Record a stop as though a signal had arrived.
     *
     * The seam this class is testable through, in the same way
     * {@see \Celyes\RalphPhp\Support\FrozenClock} is the clock's: a test that
     * had to send itself a real signal to cover one branch could not run on a
     * build without ext-pcntl, and would be at the mercy of whatever else the
     * suite had installed a handler for. It is also the one path left on such a
     * build for a host application that has its own signal handling and wants
     * to stop a run from it.
     *
     * The first request stands, exactly as it does for a real signal.
     */
    public static function request(int $signal = Interruption::SIGINT): Interruption
    {
        return self::$requested ??= Interruption::fromSignal($signal);
    }

    /**
     * Note what stopping a worker achieved.
     *
     * Only the unconfirmed survivors are kept, because that is the only part
     * anything later acts on: a confirmed termination leaves nothing to say.
     */
    public static function recordTermination(Termination $termination): void
    {
        if ($termination->isConfirmed()) {
            return;
        }

        foreach ($termination->survivors() as $pid) {
            if (!in_array($pid, self::$unconfirmed, true)) {
                self::$unconfirmed[] = $pid;
            }
        }

        // An unconfirmable termination has no survivors to name — this system
        // cannot name them — and still must not be read as a clean stop. The
        // signalled processes are what it has.
        if ($termination->survivors() === []) {
            foreach ($termination->signalled() as $pid) {
                if (!in_array($pid, self::$unconfirmed, true)) {
                    self::$unconfirmed[] = $pid;
                }
            }
        }
    }

    /**
     * Workers this process asked to stop and could not confirm had.
     *
     * Re-read rather than trusted: a process that had not died when it was
     * given up on may well have died since, and the question is only ever asked
     * at the end of a run, seconds later. One that has gone is dropped, so a
     * lock is not held over a worker that stopped a moment late.
     *
     * @return list<int>
     */
    public static function unconfirmed(): array
    {
        $surviving = [];

        foreach (self::$unconfirmed as $pid) {
            if (ProcessTree::isProcessAlive($pid) !== false) {
                $surviving[] = $pid;
            }
        }

        return self::$unconfirmed = $surviving;
    }

    /**
     * Whether a worker was left behind that nothing can account for.
     */
    public static function hasUnconfirmedWorkers(): bool
    {
        return self::unconfirmed() !== [];
    }

    /**
     * The handler itself: remember, and let a second press through.
     */
    private static function handler(): callable
    {
        return static function (int $signal): void {
            if (self::$requested === null) {
                self::$requested = Interruption::fromSignal($signal);

                return;
            }

            // Asked twice. The package is already stopping, and whatever it is
            // waiting for is evidently not stopping fast enough for the person
            // watching, so this signal goes back to meaning what it means to
            // every other program.
            pcntl_signal($signal, SIG_DFL);
        };
    }

    /**
     * A reader of the process-wide state, installing nothing.
     *
     * Public and trivial so that it can be {@see ProcessRunner}'s default: an
     * adapter builds its own runner and would otherwise start an agent nothing
     * could stop. Every instance reads the same state, because there is one
     * process and one Ctrl+C.
     */
    public function __construct()
    {
    }
}
