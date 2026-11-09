<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Run\RunIntent;
use Celyes\RalphPhp\Run\RunReport;
use Celyes\RalphPhp\Run\Runner;
use Celyes\RalphPhp\Verification\CommandVerifier;
use Celyes\RalphPhp\Verification\Verifier;

/**
 * What `run`, `resume` and `retry` have in common: all of it but the intent.
 *
 * The three commands do the same thing — hand {@see Runner} a project, a real
 * verifier and a terminal, and turn what came back into an exit status — and
 * differ only in the {@see RunIntent} they ask for. Keeping that the only
 * difference is deliberate: the loop, the repository lock, the frozen checks,
 * the per-task sequence and the six endings are the runner's, and three
 * commands each assembling them would be three chances to assemble them
 * differently.
 *
 * Nothing here reformats the run's output. The runner prints as it goes,
 * because a loop that can take an hour has to be watchable, and a command that
 * summarised it afterwards in its own words would be a second account that
 * could disagree with the one written to the run's row.
 *
 * The verifier is {@see CommandVerifier}, which starts the configured check
 * commands itself (PROJECT.md section G, item 2). It is injectable so that
 * these commands' own tests need no test suite installed inside a temporary
 * worktree, and for no other reason: nothing but a real one is wired up here.
 */
abstract class LoopCommand extends ProjectCommand
{
    public function __construct(
        private readonly Verifier $verifier = new CommandVerifier(),
        ConfigurationLoader $loader = new ConfigurationLoader(),
    ) {
        parent::__construct($loader);
    }

    public function run(Input $input, Output $output): int
    {
        $this->rejectUnsupportedInput($input);

        $configuration = $this->configuration();
        $intent = $this->intent($input, $configuration);

        $report = Runner::forProject($configuration, $this->verifier, $output)->start($intent);

        return $this->status($report);
    }

    /**
     * What this command asks the runner to do.
     *
     * @throws \Celyes\RalphPhp\Exception\UsageException when the command line
     *         does not say
     */
    abstract protected function intent(Input $input, Configuration $configuration): RunIntent;

    /**
     * The exit status for a run that finished and reported itself.
     *
     * Read off the stop's kind rather than counted from the task results: a
     * run can finish every task it took on and still be a run that stopped
     * short. {@see ExitCode::ATTENTION} says which, and PROJECT.md section I,
     * item 5 is where it becomes a code for each of the four endings.
     */
    protected function status(RunReport $report): int
    {
        return $report->stoppedForAttention() ? ExitCode::ATTENTION : ExitCode::SUCCESS;
    }
}
