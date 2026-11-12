<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console\Command;

use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Console\ExitCode;
use Celyes\RalphPhp\Console\Input;
use Celyes\RalphPhp\Console\Output;
use Celyes\RalphPhp\Exception\UsageException;
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
     * Whether this run was told to stop at the first task that does not pass.
     *
     * Three answers, not two: `--bail`, `--no-bail`, and nothing said — and
     * nothing said is null rather than false, so that
     * `limits.bail_on_failure` in the project file still decides
     * ({@see RunIntent::bails()}). A command line that reads the setting back as
     * `false` would make every invocation an implicit `--no-bail`.
     *
     * Both at once is refused rather than resolved by precedence. There is no
     * reading of `ralph run --bail --no-bail` that is more likely than a
     * mistake, and a run that guessed would be a run that worked through a
     * checklist the user expected it to stop in.
     *
     * @throws UsageException when both options are given
     */
    protected function requestedBail(Input $input): ?bool
    {
        $bail = $input->hasOption('bail');
        $carryOn = $input->hasOption('no-bail');

        if ($bail && $carryOn) {
            throw new UsageException(
                sprintf('"ralph %s" cannot be given both "--bail" and "--no-bail".', $this->name()),
                [
                    'Give one of them, or neither to use "limits.bail_on_failure" from the '
                    . 'project configuration.',
                ],
            );
        }

        return match (true) {
            $bail => true,
            $carryOn => false,
            default => null,
        };
    }

    /**
     * The exit status for a run that finished and reported itself.
     *
     * One line, because the mapping from an ending to a status is
     * {@see ExitCode::forStop()} and is the same for all three commands: a
     * resume that stops on a decision and a run that stops on the same decision
     * are the same event to whoever called them. Read off the stop's kind
     * rather than counted from the task results — a run can finish every task
     * it took on and still be a run that stopped short — and off the kind
     * rather than {@see RunReport::stoppedForAttention()}, because the four
     * unhappy endings now have a code each (PROJECT.md section I, item 5)
     * instead of sharing one.
     */
    protected function status(RunReport $report): int
    {
        return ExitCode::forStop($report->stop()->kind());
    }
}
