<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Git;

use Celyes\RalphPhp\Config\Configuration;
use Celyes\RalphPhp\Exception\PublicationException;
use Celyes\RalphPhp\Exception\StateException;
use Celyes\RalphPhp\Log\OutputSink;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\State\GenerationRecord;
use Celyes\RalphPhp\State\GenerationStore;
use Celyes\RalphPhp\State\PushResult;
use Celyes\RalphPhp\State\PushState;

/**
 * Publishes a pass's commit to the configured remote, when the pass is one that
 * publishes (PROJECT.md section F, item 8).
 *
 * Pushing is opt-in and almost everything here is about the half of the item
 * that follows the semicolon: *retain local commits and publication status when
 * a push fails*. So a remote that refuses the push is not an exception. It is
 * recorded on the pass as a failed publication with the reason Git gave, the
 * commit is left exactly where it was, and the task stays unchecked — which is
 * what lets PROJECT.md section H, item 7 retry the push and nothing before it.
 * {@see PublicationException} is kept for the cases where a push must not be
 * *attempted*, because the repository and the state store disagree about what
 * this pass produced.
 *
 * **The row decides whether to push, not the live configuration.** A pass
 * records what publication it owes when it starts
 * ({@see self::expectationFor()}, written by {@see GenerationStore::start()}),
 * and this follows that record. Reading `git.push` again here would mean a
 * configuration edited while a run is in progress — by a worker, even — could
 * change what a committed pass is judged by, and the same snapshot reasoning
 * governs the verification commands of section G, item 3.
 *
 * **Order, and why it is the opposite of the committer's.** {@see TaskCommitter}
 * records its branch before creating it, because a reference nothing owns is
 * refused as unrelated work. Publication writes the other way round: the push
 * runs first, and the row is written after. A crash in between leaves a pass
 * whose commit is on the remote and whose row says `pending`, and the retry
 * that follows is a second push of a commit the remote already has — which Git
 * answers with "Everything up-to-date" and a zero status. There is no
 * equivalent of a duplicate commit to be made here, so the cheap, idempotent
 * operation is the one allowed to happen twice.
 *
 * **What is pushed is the recorded commit, by its object name.** The refspec is
 * `<sha>:refs/heads/<branch>`, not the branch name on both sides, and the
 * branch's tip is checked against the recorded SHA before anything leaves the
 * machine. A branch that has moved on since the commit was recorded is somebody
 * else's business — a worker's extra commit, a person's amend — and section F,
 * item 9 owns reconciling it; what this must not do is put a commit nobody here
 * has seen onto a remote other people fetch.
 *
 * **An ordinary push.** No `--force`, no `--force-with-lease`, no
 * `--set-upstream`, and no `--no-verify`: a rejected push is reported, never
 * won, a `pre-push` hook of the project's is the project's decision to keep,
 * and an upstream is `branch.<name>.*` configuration in the user's repository
 * that this was not asked to write.
 */
final class TaskPublisher
{
    /**
     * Seconds `git push` may take.
     *
     * Long, because this is the one command in section F that waits on a
     * network and on somebody else's server: a first push of a branch over a
     * slow link, with a `pre-push` hook and a remote-side hook behind it, is
     * not a twenty-second operation. Still bounded, because the failure it
     * bounds is the one nobody can clear by hand — an `ssh` asking for a key
     * passphrase on a terminal that is not there ({@see Repository::runRemote()}).
     */
    public const int PUSH_TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly GenerationStore $generations,
        private readonly string $projectRoot,
        private readonly string $executable = 'git',
        private readonly ProcessRunner $processes = new ProcessRunner(),
    ) {
    }

    /**
     * The publisher for a project.
     *
     * @param string $executable the `git` to run, which a run takes from what
     *        {@see \Celyes\RalphPhp\Preflight\Check\GitCheck} resolved rather
     *        than searching the path again
     */
    public static function forProject(
        Configuration $configuration,
        GenerationStore $generations,
        string $executable = 'git',
        ProcessRunner $processes = new ProcessRunner(),
    ): self {
        return new self($generations, $configuration->projectRoot(), $executable, $processes);
    }

    /**
     * What publication a pass started under this configuration owes.
     *
     * The one place `git.push` becomes a pass's obligation. A run reads it once
     * and hands the result to {@see GenerationStore::start()}; from then on the
     * row is the authority and this class never consults the configuration
     * again.
     */
    public static function expectationFor(Configuration $configuration): PushResult
    {
        $push = $configuration->git()->push();

        return PushResult::expected($push->isEnabled(), $push->remote());
    }

    /**
     * Publish the pass's commit, if it has one to publish and owes a push.
     *
     * Takes only the row, deliberately: that is all a resumed run has
     * (PROJECT.md section H, item 7), and a publisher that needed the workspace
     * or the commit value could not be called from there. The push runs in the
     * controlling checkout rather than in the task's worktree because it has to
     * work when the worktree is gone — pushing touches no working tree and no
     * index, and the branch and the remotes live in the repository the two
     * share.
     *
     * @param OutputSink|null $sink where the push's own output and this
     *        package's notes about it go. Never closed here: the caller that
     *        opened the log owns it.
     *
     * @throws PublicationException when the pass has nothing publishable, or
     *                              the branch is not where the row says it is
     */
    public function publish(GenerationRecord $generation, ?OutputSink $sink = null): TaskPublication
    {
        $push = $generation->push();

        if ($push->state() === PushState::NotRequired) {
            return TaskPublication::nothingToPublish($generation);
        }

        if ($push->state() === PushState::Published) {
            return TaskPublication::alreadyPublished(
                $generation,
                $this->requireCommit($generation),
                $this->requireBranch($generation),
            );
        }

        if ($generation->isNoChange()) {
            return TaskPublication::nothingToPublish($this->settle($generation, $sink));
        }

        $sha = $this->requireCommit($generation);
        $branch = $this->requireBranch($generation);
        $remote = (string) $push->remote();

        $git = Repository::at($this->projectRoot, $this->executable, $this->processes);
        $this->requireBranchAtCommit($git, $generation, $branch, $sha);

        $sink?->note(sprintf(
            'push: %s → %s %s on %s',
            $generation->slug(),
            $remote,
            substr($sha, 0, TaskCommit::SHORT_LENGTH),
            $branch,
        ));

        $result = $git->runRemote(
            self::PUSH_TIMEOUT_SECONDS,
            $sink,
            'push',
            '--end-of-options',
            $remote,
            sprintf('%s:refs/heads/%s', $sha, $branch),
        );

        $recorded = $result->succeeded()
            ? $this->recordSuccess($generation, $push, $remote, $sha, $branch, $sink)
            : $this->recordFailure($git, $generation, $push, $remote, $sha, $branch, $result, $sink);

        return TaskPublication::attempted($recorded, $sha, $branch);
    }

    /**
     * The commit the row records.
     *
     * @throws PublicationException when there is none, which means something
     *                              asked for a publication before there was an
     *                              implementation to publish
     */
    private function requireCommit(GenerationRecord $generation): string
    {
        $sha = $generation->commitSha();

        if ($sha !== null) {
            return $sha;
        }

        throw new PublicationException(
            sprintf(
                '%s has no recorded commit, so there is nothing to publish to "%s".',
                $generation->slug(),
                (string) $generation->push()->remote(),
            ),
            [
                'A pass is published after it has committed. One that never got that far is attempted '
                    . 'again rather than pushed.',
                'Nothing was pushed and nothing was recorded.',
            ],
        );
    }

    /**
     * The branch the row records.
     *
     * @throws PublicationException when the row names none, which cannot
     *                              happen alongside a recorded commit and is
     *                              refused rather than guessed at
     */
    private function requireBranch(GenerationRecord $generation): string
    {
        $branch = $generation->branch();

        if ($branch !== null) {
            return $branch;
        }

        throw new PublicationException(
            sprintf(
                '%s records a commit but no branch, so there is no reference to publish it as.',
                $generation->slug(),
            ),
            [
                sprintf('The commit is in the repository as %s.', (string) $generation->commitSha()),
                'Ralph PHP claims a pass\'s branch before it commits, so a row with one and not the other '
                    . 'is a defect in Ralph PHP rather than something to put right in the repository; the '
                    . 'message above is what to report.',
            ],
        );
    }

    /**
     * Refuse to push unless the branch is exactly where the row says it is.
     *
     * The check that keeps a shared remote from receiving work this run did not
     * produce. Both failures it reports — a branch that is not there, a branch
     * that has moved — are reconciliation (PROJECT.md section F, item 9), and
     * neither is resolved by pushing: the recorded commit is safe locally
     * either way, and the hints say how to look at it.
     *
     * @throws PublicationException when the branch is missing or at another
     *                              commit, or Git cannot answer
     */
    private function requireBranchAtCommit(
        Repository $git,
        GenerationRecord $generation,
        string $branch,
        string $sha,
    ): void {
        $result = $git->query('rev-parse', '--verify', '--quiet', '--end-of-options', 'refs/heads/' . $branch);
        $tip = $result->trimmedOutput();

        if ($tip === $sha) {
            return;
        }

        if ($tip === '') {
            throw new PublicationException(
                sprintf(
                    'the branch %s of %s is not in the repository, so its commit cannot be published.',
                    $branch,
                    $generation->slug(),
                ),
                [
                    sprintf('The commit itself is still there: run "git show %s" to see it.', $sha),
                    sprintf('Running "git branch %s %s" puts the branch back, after which resume publishes it.', $branch, $sha),
                    'Nothing was pushed and nothing was recorded.',
                ],
            );
        }

        throw new PublicationException(
            sprintf(
                'the branch %s of %s is at %s rather than at its recorded commit %s, so the two have diverged.',
                $branch,
                $generation->slug(),
                substr($tip, 0, TaskCommit::SHORT_LENGTH),
                substr($sha, 0, TaskCommit::SHORT_LENGTH),
            ),
            [
                'Ralph PHP publishes the commit it recorded and nothing else, so a branch that has moved on '
                    . 'since — a worker\'s extra commit, an amend, a reset — is reported rather than sent to '
                    . 'a remote other people fetch.',
                sprintf('Run "git log %s" to see what the branch holds.', $branch),
                'Nothing was pushed and nothing was recorded.',
            ],
        );
    }

    /**
     * Record that the commit reached the remote.
     *
     * The push has already happened by the time this runs, so a failure here
     * leaves the remote holding a commit the row still calls pending. That is
     * recoverable by design — the retry is another push of a commit the remote
     * has, which is a no-op — and the message says so rather than implying the
     * work needs doing again.
     *
     * @throws PublicationException when the row cannot be written
     */
    private function recordSuccess(
        GenerationRecord $generation,
        PushResult $push,
        string $remote,
        string $sha,
        string $branch,
        ?OutputSink $sink,
    ): GenerationRecord {
        try {
            $recorded = $this->generations->recordPush($generation, $push->succeeded());
        } catch (StateException $exception) {
            throw new PublicationException(
                sprintf(
                    '%s was published to "%s" but the result could not be recorded: %s',
                    $generation->slug(),
                    $remote,
                    $exception->getMessage(),
                ),
                [
                    sprintf('The commit %s is on "%s" as %s.', substr($sha, 0, TaskCommit::SHORT_LENGTH), $remote, $branch),
                    'The task is left unchecked. Resume pushes again, which a remote that already has the '
                        . 'commit answers with "Everything up-to-date"; nothing is implemented or committed '
                        . 'a second time.',
                ],
            );
        }

        $sink?->note(sprintf(
            'push: %s published %s as %s on "%s"',
            $generation->slug(),
            substr($sha, 0, TaskCommit::SHORT_LENGTH),
            $branch,
            $remote,
        ));

        return $recorded;
    }

    /**
     * Record that the remote refused the push.
     *
     * The item's second half, and the reason this is a recorded outcome rather
     * than a throw: the local commit is untouched, the reason is kept where
     * `ralph status` and resume can both read it, and the task stays unchecked
     * because {@see GenerationRecord::isFinalizable()} will not call an
     * outstanding publication settled.
     *
     * @throws PublicationException when the failure itself cannot be recorded,
     *                              since a run that could neither push nor say
     *                              that it could not push has nothing left to
     *                              report with
     */
    private function recordFailure(
        Repository $git,
        GenerationRecord $generation,
        PushResult $push,
        string $remote,
        string $sha,
        string $branch,
        ProcessResult $result,
        ?OutputSink $sink,
    ): GenerationRecord {
        $reason = $this->reasonFor($result);

        try {
            $recorded = $this->generations->recordPush($generation, $push->refused($reason));
        } catch (StateException $exception) {
            throw new PublicationException(
                sprintf(
                    'the push of %s to "%s" was refused, and the refusal could not be recorded: %s',
                    $generation->slug(),
                    $remote,
                    $exception->getMessage(),
                ),
                [
                    sprintf('Git said: %s', $result->failureReason()),
                    sprintf(
                        'The commit %s is intact on %s and nothing has been undone.',
                        substr($sha, 0, TaskCommit::SHORT_LENGTH),
                        $branch,
                    ),
                ],
            );
        }

        $sink?->note(sprintf(
            'push: %s was refused by "%s": %s',
            $generation->slug(),
            $remote,
            $result->failureReason(),
        ));

        foreach ($this->hintsFor($git, $remote, $branch, $result) as $hint) {
            $sink?->note('push: ' . $hint);
        }

        return $recorded;
    }

    /**
     * A pass with nothing to publish, settled rather than left owing a push.
     *
     * A task that was already satisfied is a verified outcome with no commit
     * behind it (PROJECT.md section G, item 6), so there is nothing a remote
     * could be given. Left as it arrived it would owe a publication forever and
     * never finalise, so the obligation is discharged here, in the one place
     * that can see both facts at once.
     *
     * @throws PublicationException when the row cannot be written
     */
    private function settle(GenerationRecord $generation, ?OutputSink $sink): GenerationRecord
    {
        try {
            $recorded = $this->generations->recordPush($generation, PushResult::notRequired());
        } catch (StateException $exception) {
            throw new PublicationException(
                sprintf(
                    '%s left the task unchanged, and that could not be recorded as nothing to publish: %s',
                    $generation->slug(),
                    $exception->getMessage(),
                ),
                ['Nothing was pushed. The task is left unchecked.'],
            );
        }

        $sink?->note(sprintf(
            'push: %s changed nothing, so there is no commit to publish',
            $generation->slug(),
        ));

        return $recorded;
    }

    /**
     * What is kept on the row as the reason for a refusal.
     *
     * Both streams, not just the first line: Git explains a non-fast-forward
     * over several lines of advice, and the advice is the part that tells a user
     * what to do. {@see PushResult} bounds the length; the whole output is in
     * the task's log, which is where the sink has been putting it as it
     * arrived.
     */
    private function reasonFor(ProcessResult $result): string
    {
        $output = trim($result->errorOutput() . "\n" . $result->standardOutput());

        return $output === '' ? $result->failureReason() : $output;
    }

    /**
     * Lines worth saying about a particular refusal.
     *
     * Three failures account for nearly every rejected push, and each needs a
     * different thing done about it, so each is named rather than left under a
     * general "the push failed". The remote list is read only once a push has
     * already failed: checking beforehand would be a second round trip on the
     * path that works.
     *
     * @return list<string>
     */
    private function hintsFor(Repository $git, string $remote, string $branch, ProcessResult $result): array
    {
        $output = $result->errorOutput() . "\n" . $result->standardOutput();
        $hints = [];

        if (!$this->hasRemote($git, $remote)) {
            $configured = $this->remotesOf($git);

            $hints[] = $configured === []
                ? sprintf(
                    'This repository has no remotes, so "%s" cannot be pushed to. Add one with '
                        . '"git remote add %s <url>", or turn publication off with "git.push.enabled": false.',
                    $remote,
                    $remote,
                )
                : sprintf(
                    'This repository has no remote named "%s"; the ones it has are %s.',
                    $remote,
                    implode(', ', $configured),
                );
        }

        if ($this->mentions($output, ['non-fast-forward', 'fetch first', '[rejected]'])) {
            $hints[] = sprintf(
                'The remote already has a branch named %s holding commits this one does not. Ralph PHP '
                    . 'pushes ordinarily and never forces, so the remote branch is left as it is.',
                $branch,
            );
        }

        if ($this->mentions($output, ['Authentication failed', 'could not read Username', 'terminal prompts disabled', 'Permission denied', 'access rights'])) {
            $hints[] = 'The remote did not accept the credentials available to this run. Ralph PHP cannot '
                . 'prompt for one — nothing it runs has a terminal to ask on — so the credential has to be '
                . 'in a helper, an SSH agent, or the environment before the run starts.';
        }

        $hints[] = sprintf(
            'The commit is intact on %s locally. The task is left unchecked, and resume retries the push '
                . 'without implementing or committing anything again.',
            $branch,
        );

        return $hints;
    }

    /**
     * Whether the repository has a remote by this name.
     */
    private function hasRemote(Repository $git, string $remote): bool
    {
        return in_array($remote, $this->remotesOf($git), true);
    }

    /**
     * The remotes the repository has configured.
     *
     * @return list<string>
     */
    private function remotesOf(Repository $git): array
    {
        $result = $git->query('remote');

        if (!$result->succeeded()) {
            return [];
        }

        $names = [];

        foreach (explode("\n", $result->trimmedOutput()) as $line) {
            $name = trim($line);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<string> $needles
     */
    private function mentions(string $output, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($output, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
