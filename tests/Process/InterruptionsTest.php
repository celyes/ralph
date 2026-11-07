<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Process;

use Celyes\RalphPhp\Process\Interruption;
use Celyes\RalphPhp\Process\Interruptions;
use Celyes\RalphPhp\Process\Termination;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Noticing that the run has been asked to stop.
 *
 * Both routes are exercised: a real signal sent to this process, which is what
 * Ctrl+C is and what proves the handler is installed and does what it claims,
 * and {@see Interruptions::request()}, which is the seam the rest of the suite
 * uses and the only route available on a build without ext-pcntl.
 *
 * Every test releases the watch afterwards. The state is process-wide — that is
 * its whole design — so a test that left a stop recorded would have the next
 * one start in an interrupted run.
 */
#[CoversClass(Interruptions::class)]
#[CoversClass(Interruption::class)]
final class InterruptionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Interruptions::release();
    }

    #[Test]
    public function a_run_that_has_not_been_stopped_reports_nothing(): void
    {
        Interruptions::listen();

        self::assertNull((new Interruptions())->requested());
        self::assertFalse((new Interruptions())->isRequested());
        self::assertFalse(Interruptions::hasUnconfirmedWorkers());
    }

    #[Test]
    public function a_real_keyboard_interrupt_is_noticed(): void
    {
        $this->requireSignals();

        self::assertTrue(Interruptions::listen());

        posix_kill((int) getmypid(), SIGINT);

        $interruption = (new Interruptions())->requested();

        self::assertNotNull($interruption, 'The handler did not record the signal.');
        self::assertTrue($interruption->is(Interruption::SIGINT));
        self::assertSame('SIGINT', $interruption->name());
        self::assertSame('stopped by SIGINT (Ctrl+C)', $interruption->reason());
    }

    #[Test]
    public function a_termination_signal_is_noticed_too(): void
    {
        $this->requireSignals();
        Interruptions::listen();

        posix_kill((int) getmypid(), SIGTERM);

        self::assertSame('stopped by SIGTERM', (new Interruptions())->requested()?->reason());
    }

    /**
     * The first stop is the one recorded: a user pressing Ctrl+C twice has not
     * changed their mind about why.
     */
    #[Test]
    public function a_second_signal_does_not_replace_the_first(): void
    {
        $this->requireSignals();
        Interruptions::listen();

        posix_kill((int) getmypid(), SIGINT);
        $first = (new Interruptions())->requested();
        posix_kill((int) getmypid(), SIGINT);

        self::assertSame($first, (new Interruptions())->requested());
    }

    /**
     * Insisting works: the second press puts the signal's ordinary meaning
     * back, so a user waiting on a stubborn agent is not trapped by a package
     * that has promised to be tidy.
     */
    #[Test]
    public function asking_twice_restores_the_signals_default_behaviour(): void
    {
        $this->requireSignals();
        Interruptions::listen();

        posix_kill((int) getmypid(), SIGINT);
        self::assertNotSame(SIG_DFL, pcntl_signal_get_handler(SIGINT));

        posix_kill((int) getmypid(), SIGINT);

        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGINT), 'A third press would not be handled.');
    }

    #[Test]
    public function releasing_puts_the_previous_handler_back_and_forgets_the_stop(): void
    {
        $this->requireSignals();

        $mine = static function (int $signal): void {
        };

        pcntl_signal(SIGTERM, $mine);

        try {
            Interruptions::listen();
            self::assertNotSame($mine, pcntl_signal_get_handler(SIGTERM), 'The watch did not install its own.');

            Interruptions::request(Interruption::SIGTERM);
            Interruptions::release();

            self::assertSame($mine, pcntl_signal_get_handler(SIGTERM), 'A host application\'s handler was lost.');
            self::assertNull((new Interruptions())->requested(), 'A released watch still reports a stop.');
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
        }
    }

    #[Test]
    public function installing_twice_keeps_one_set_of_saved_handlers(): void
    {
        $this->requireSignals();

        $mine = static function (int $signal): void {
        };

        pcntl_signal(SIGHUP, $mine);

        try {
            self::assertTrue(Interruptions::listen());
            self::assertTrue(Interruptions::listen(), 'A second call is not a failure.');

            Interruptions::release();

            self::assertSame($mine, pcntl_signal_get_handler(SIGHUP), 'The second install overwrote the saved handler.');
        } finally {
            pcntl_signal(SIGHUP, SIG_DFL);
        }
    }

    /**
     * The seam the rest of the suite stops a run through, and the one path a
     * build without ext-pcntl has.
     */
    #[Test]
    public function a_stop_can_be_asked_for_without_a_signal(): void
    {
        $asked = Interruptions::request(Interruption::SIGTERM);

        self::assertSame($asked, (new Interruptions())->requested());
        self::assertSame(Interruption::SIGTERM, $asked->signal());
        self::assertSame($asked, Interruptions::request(Interruption::SIGINT), 'The first request stands.');
    }

    #[Test]
    public function a_confirmed_termination_leaves_nothing_owed(): void
    {
        Interruptions::recordTermination(Termination::confirmed([4321]));
        Interruptions::recordTermination(Termination::nothingToStop());

        self::assertSame([], Interruptions::unconfirmed());
        self::assertFalse(Interruptions::hasUnconfirmedWorkers());
    }

    #[Test]
    public function a_surviving_worker_is_remembered_while_it_is_running(): void
    {
        $pid = (int) getmypid();

        // This process is certainly running, which is what makes it a usable
        // stand-in for an agent that would not die.
        Interruptions::recordTermination(Termination::survived([$pid], [$pid]));

        self::assertSame([$pid], Interruptions::unconfirmed());
        self::assertTrue(Interruptions::hasUnconfirmedWorkers());
    }

    /**
     * A termination this system could not account for names what it signalled:
     * there are no survivors to name, and it still must not read as a clean
     * stop.
     */
    #[Test]
    public function an_unconfirmable_termination_owes_what_it_signalled(): void
    {
        $pid = (int) getmypid();

        Interruptions::recordTermination(Termination::unconfirmable([$pid]));

        self::assertSame([$pid], Interruptions::unconfirmed());
    }

    /**
     * A worker that died a moment after it was given up on is not held against
     * the run: the question is only ever asked at the end of one.
     */
    #[Test]
    public function a_worker_that_has_since_gone_is_no_longer_owed(): void
    {
        $gone = $this->anIdNothingIsUsing();

        Interruptions::recordTermination(Termination::survived([$gone], [$gone]));

        self::assertSame([], Interruptions::unconfirmed());
    }

    #[Test]
    public function the_same_worker_is_only_owed_once(): void
    {
        $pid = (int) getmypid();

        Interruptions::recordTermination(Termination::survived([$pid], [$pid]));
        Interruptions::recordTermination(Termination::survived([$pid], [$pid]));

        self::assertSame([$pid], Interruptions::unconfirmed());
    }

    #[Test]
    public function a_signal_it_does_not_name_is_still_described(): void
    {
        $interruption = Interruption::fromSignal(31);

        self::assertSame('signal 31', $interruption->name());
        self::assertSame('signal 31', $interruption->describe());
        self::assertSame('stopped by signal 31', $interruption->reason());
        self::assertGreaterThan(0.0, $interruption->at());
    }

    #[Test]
    public function a_closed_terminal_says_so(): void
    {
        self::assertSame('SIGHUP (the terminal closed)', Interruption::fromSignal(Interruption::SIGHUP)->describe());
    }

    #[Test]
    public function the_signals_a_run_stops_for_are_the_three_it_installs(): void
    {
        self::assertSame(
            [Interruption::SIGINT, Interruption::SIGTERM, Interruption::SIGHUP],
            Interruptions::SIGNALS,
        );

        self::assertSame(
            function_exists('pcntl_signal') && function_exists('pcntl_async_signals'),
            Interruptions::isSupported(),
        );
    }

    private function requireSignals(): void
    {
        if (!Interruptions::isSupported() || !function_exists('posix_kill')) {
            self::markTestSkipped('Sending this process a real signal needs ext-pcntl and ext-posix.');
        }
    }

    /**
     * An id in range that nothing holds.
     */
    private function anIdNothingIsUsing(): int
    {
        for ($pid = 30000; $pid > 1; $pid--) {
            if (!is_dir('/proc/' . $pid) && (!function_exists('posix_kill') || !posix_kill($pid, 0))) {
                return $pid;
            }
        }

        self::markTestSkipped('Every process id on this machine is in use.');
    }
}
