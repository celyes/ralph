# Demonstration runs

What happened when the loop was pointed at a real coding agent and a real PHP
package, written down so that a reader can tell what has actually been observed
from what is only covered by the test suite. The automated suite runs against a
[deterministic fixture worker](../tests/) and needs no credentials; this file is
the other half of the evidence — the paid agent, its real CLI, and the commits
it left behind.

PROJECT.md section J, item 11 asks for the agent CLI versions, the resulting
commits, the verification output, the interventions, the elapsed time and the
known limitations. Each run below records all six.

## Status at a glance

| Pass | Agent | CLI version | Tasks | Commits | Interventions |
| --- | --- | --- | --- | --- | --- |
| [12 November 2026](#claude-code-12-november-2026) | Claude Code | 2.1.226 | 4 of 4 completed | 4 | none |
| [11 November 2026](#claude-code-11-november-2026) | Claude Code | 2.1.226 | 3 of 3 completed | 3 | 3 — [below](#interventions-1) |
| — | Codex | — | **not demonstrated** — see [below](#codex-tested-not-demonstrated) | — | — |

Both passes work through the three tasks of the shipped example project; the
second one reopens one of them afterwards, which is its fourth. That pass is on
the code this release is tagged from and is the one to read first; the first is
kept because the three interventions it hit are what the two code changes
between them were for.

## Claude Code, 12 November 2026

The pass this release is tagged on: the same three tasks again, on the package
as it stands after the two defects the previous pass turned up were fixed, plus
a fourth task reopened on purpose. **Four tasks, four commits, four checked
boxes, every one verified on the first attempt, and nobody had to step in.**

### The machine

| | |
| --- | --- |
| Agent CLI | **Claude Code 2.1.226**, at `/home/celyes/.local/share/claude/versions/2.1.226`, signed in with its own credentials |
| Adapter | `claude` — `claude --print --output-format text --permission-mode bypassPermissions`, briefing on standard input |
| Ralph PHP | `0.1.0-dev` at `6cf26e1`, run as `php bin/ralph` from this checkout |
| PHP | 8.5.11 (cli), with `json`, `mbstring`, `pdo`, `pdo_sqlite` |
| Git / Composer | Git 2.43.0, Composer 2.9.5 |
| Platform | WSL2 (Linux 5.15), the tested platform |
| Project | a copy of `examples/text-tools/` in `/tmp/ralph-demo2`, its own Git repository, base commit `a3da8e1c0845` |
| Verification | one check, `composer test` (PHPUnit 11.5.57), timeout 300s |
| Publication | off — `git.push.enabled` is `false`, so each commit stays local |

`limits.iterations` is `3` as the example ships it, so the three pending tasks
are one `ralph run`; the reopened task is a second one. The agent was chosen
with `ralph run --agent claude` rather than by editing the file, which is why
`ralph status` still reports `codex (project default)` throughout — the run
records the agent it actually used.

### What it produced

| Task | Commit | Branch | Diff |
| --- | --- | --- | --- |
| `TASK-44AEB58A` Add a maximum length to the slug generator | `f235d10a1e6f` | `ralph/TASK-44AEB58A-add-a-maximum-length-to-the-slug-generator` | 2 files, +70 −6 |
| `TASK-FC1A4E61` Make Excerpt cut on a word boundary instead of mid-word | `1797d5de2971` | `ralph/TASK-FC1A4E61-make-excerpt-cut-on-a-word-boundary-instead-of` | 2 files, +35 −7 |
| `TASK-31D64F84` Add a ReadingTime class that estimates how long a text takes to read | `7fe91ad39c24` | `ralph/TASK-31D64F84-add-a-readingtime-class-that-estimates-how-long` | 2 files, +112 −0 |
| `TASK-FC1A4E61` again, generation 2 | `c917ca01ac54` | `ralph/TASK-FC1A4E61-g2-make-excerpt-cut-on-a-word-boundary-instead-of` | 2 files, +34 −7 |

Four branches, all four off the base commit and none off another:

```
* c917ca0 TASK-FC1A4E61: Make Excerpt cut on a word boundary instead of mid-word
| * 7fe91ad TASK-31D64F84: Add a ReadingTime class that estimates how long a text t…
|/
| * 1797d5d TASK-FC1A4E61: Make Excerpt cut on a word boundary instead of mid-word
|/
| * f235d10 TASK-44AEB58A: Add a maximum length to the slug generator
|/
* a3da8e1 Ignore Ralph PHP runtime data
* d0fb4c4 Import the example package
```

The reopened task's commit says which generation and which run it belongs to,
so the two commits for one task are told apart by their trailers alone:

```
TASK-FC1A4E61: Make Excerpt cut on a word boundary instead of mid-word

Ralph-Task: TASK-FC1A4E61
Ralph-Generation: 2
Ralph-Run: 2
Ralph-Attempt: 1
Ralph-Base: a3da8e1c0845ba92f2041ec92ef1fe1cb845fb92
Ralph-Agent: claude
```

### Verification output

The package ran `composer test` itself, in each task's own worktree, from the
check set frozen at the run's start (`checks: tests (frozen a82c0fd8a1e4)`).
Verbatim, for the first task:

```
--- check tests: composer test (in /tmp/ralph-demo2/.ralph/runtime/worktrees/TASK-44AEB58A, timeout 300s)
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.11
Configuration: /tmp/ralph-demo2/.ralph/runtime/worktrees/TASK-44AEB58A/phpunit.xml.dist

.................                                                 17 / 17 (100%)

Time: 00:00.008, Memory: 8.00 MB

OK (17 tests, 19 assertions)
--- check tests passed in 0.2s
--- verification: 1 of 1 checks passed in 0.2s
```

| Task | Suite afterwards | Check time | Checks audit |
| --- | --- | --- | --- |
| `TASK-44AEB58A` | `OK (17 tests, 19 assertions)` | 0.2s | nothing weakened |
| `TASK-FC1A4E61` | `OK (14 tests, 14 assertions)` | 0.2s | nothing weakened |
| `TASK-31D64F84` | `OK (20 tests, 21 assertions)` | 0.2s | nothing weakened |
| `TASK-FC1A4E61` g2 | `OK (14 tests, 14 assertions)` | 0.2s | nothing weakened |

The example's suite is 12 tests before any of this, and each branch has only
its own task's additions, because each starts from the base commit.

### Elapsed time

| Task | Agent | Workspace preparation |
| --- | --- | --- |
| `TASK-44AEB58A` | 1m 1s | 1.5s (`composer install`) |
| `TASK-FC1A4E61` | 52.5s | 1.7s |
| `TASK-31D64F84` | 30.2s | 1.7s |
| `TASK-FC1A4E61` g2 | 52.3s | 1.7s |

The three-task run took **2m 30s of wall clock** (`active time 150s` in
`status`), of which 2m 24s is the agent thinking; the reopened task's run took
55s, of which 52.3s is the agent. The loop's own share — worktree, preparation,
verification, staging, commit, checklist, cleanup — is a few seconds per task,
the same as the previous pass.

### A reopened task, and a reader who walks away

The fourth task is the same `TASK-FC1A4E61`, unticked by hand in
`.ralph/tasks.md` after the first run finished. The next run took it as a new
execution generation rather than as the task it had already done: a worktree at
`…/worktrees/TASK-FC1A4E61-g2`, its own branch, its own commit, its own log
directory — and generation 1's branch, commit and logs left exactly as they
were. `ralph logs TASK-FC1A4E61` reports the current generation and names the
branch:

```
TASK-FC1A4E61 (generation 2): completed, attempt 1, ralph/TASK-FC1A4E61-g2-make-excerpt-cut-on-a-word-boundary-instead-of
  base a3da8e1c0845, commit c917ca01ac54
```

That run was also **piped into `head -20` on purpose** — the thing that ended a
run with exit 70 in the previous pass. The pipe closed after twenty lines of
`composer install` output, and the run carried on without them: agent, suite,
staging, commit, checkbox, cleanup. Afterwards:

```
Latest run run-20261112-105139-fee526 — the checklist was worked through
  active time  55s
  stopped      every pending task was completed (1 task)
```

A piped run now also ends with the exit code it earned rather than with a
crash:

```
$ ralph run --agent claude | head -1; echo "run exit: ${PIPESTATUS[0]}"
run-20261112-105306-6f47ab: claude (adapter "claude", selected by the --agent option), processing pending tasks
run exit: 0
```

### Interventions

**None.** No attempt was retried, no task was blocked, the agent asked for no
decisions, and nothing had to be fixed by hand for a run to start or finish.
The previous pass's three are worth checking off individually:

1. **The refusal after `init` did not happen.** `ralph init` now lists
   *"Commit .gitignore: a run will not start while the checkout holds
   uncommitted changes it did not write"* among its next steps; following them
   in order, `ralph run` started first time. The refusal itself is unchanged —
   a run still will not touch a change it did not write.
2. **Piping did not crash the run.** See above. This is the only one of the
   three that was a defect in the package, and it is the one the run was
   deliberately set up to hit again.
3. **No task changed a file it was not asked to**, this time: each of the four
   commits is exactly its task's two files. That is agent variance rather than
   anything the loop now enforces — nothing in it constrains a diff to the
   files a task named, and the commit is still the unit of review.

### What the code looked like

Reviewed, not merely verified, and it holds up as well as the previous pass's:
`final` classes, `private readonly` promoted arguments, `declare(strict_types=1)`,
`InvalidArgumentException` naming the value it got, `mb_*` with an explicit
`'UTF-8'`, and the existing tests updated where behaviour changed rather than
contradicted by new ones. Both tasks that edited an existing class removed the
stale *"the task in `.ralph/tasks.md` does this"* docblock their own change made
untrue; the third adds a file and had none to remove. The
agent's own report of the first task — kept with the attempt, and labelled
unverified where `ralph logs` prints it — describes the boundary cut and the
fallback for a word longer than the limit, which is what the criteria asked
for.

### State afterwards

```
  checklist      .ralph/tasks.md — 3 tasks, 3 checked, 0 pending
  progress       3 of 3 completed, 0 remaining
```

`git worktree list` holds only the checkout — all four worktrees were removed
on completion — and the only uncommitted change in the repository is the one
the package is supposed to leave: `.ralph/tasks.md`, three markers flipped and
every other byte as it was. Four log directories remain under
`.ralph/runtime/logs/`, 16K each, one of them `TASK-FC1A4E61-g2`.

## Claude Code, 11 November 2026

A complete pass over the three tasks the shipped example project
([`examples/text-tools/`](../examples/text-tools/)) leaves unfinished: three
branches, three commits, three checked boxes, no attempt retried and no human
help asked for.

### The machine

| | |
| --- | --- |
| Agent CLI | **Claude Code 2.1.226**, at `/home/celyes/.local/share/claude/versions/2.1.226`, signed in with its own credentials |
| Adapter | `claude` — `claude --print --output-format text --permission-mode bypassPermissions`, briefing on standard input |
| Ralph PHP | `0.1.0-dev`, run as `php bin/ralph` from this checkout |
| PHP | 8.5.11 (cli), with `json`, `mbstring`, `pdo`, `pdo_sqlite` |
| Git / Composer | Git 2.43.0, Composer 2.9.5 |
| Platform | WSL2 (Linux 5.15), the tested platform |
| Project | a copy of `examples/text-tools/` in `/tmp/ralph-demo`, its own Git repository, base commit `a085a0d68e65` |
| Verification | one check, `composer test` (PHPUnit 11.5.57), timeout 300s |
| Publication | off — `git.push.enabled` is `false`, so each commit stays local |

`limits.iterations` was left at `1`, so each task is its own `ralph run`. That
is three runs rather than one, which is also why each task's own elapsed time is
separable below.

### What it produced

All three tasks: **completed, verified and committed on the first attempt.**

| Task | Description | Commit | Branch | Diff |
| --- | --- | --- | --- | --- |
| `TASK-44AEB58A` | Add a maximum length to the slug generator | `6008de650aef` | `ralph/TASK-44AEB58A-add-a-maximum-length-to-the-slug-generator` | 3 files, +94 −7 |
| `TASK-FC1A4E61` | Make Excerpt cut on a word boundary instead of mid-word | `81c0095dfe7c` | `ralph/TASK-FC1A4E61-make-excerpt-cut-on-a-word-boundary-instead-of` | 2 files, +48 −7 |
| `TASK-31D64F84` | Add a ReadingTime class that estimates how long a text takes to read | `113cd6f4157d` | `ralph/TASK-31D64F84-add-a-readingtime-class-that-estimates-how-long` | 2 files, +131 −0 |

Each branch starts from the same base commit rather than from the one before
it — the independent-task assumption section 2 states, visible in the graph:

```
* 113cd6f TASK-31D64F84: Add a ReadingTime class that estimates how long a text t…
| * 81c0095 TASK-FC1A4E61: Make Excerpt cut on a word boundary instead of mid-word
|/
| * 6008de6 TASK-44AEB58A: Add a maximum length to the slug generator
|/
* a085a0d Ignore Ralph PHP runtime data
```

Every commit carries its trailers, so a reviewer can trace one back to the run
and the agent that made it without consulting the database:

```
TASK-FC1A4E61: Make Excerpt cut on a word boundary instead of mid-word

Ralph-Task: TASK-FC1A4E61
Ralph-Generation: 1
Ralph-Run: 2
Ralph-Attempt: 1
Ralph-Base: a085a0d68e6587ae33d8a17add7195f21ddb426c
Ralph-Agent: claude
```

### Verification output

The package ran `composer test` itself, in the task's worktree, from the check
set the run froze at its start — not from the agent and not from the
configuration as it stood afterwards. Verbatim, for the first task:

```
--- check tests: composer test (in /tmp/ralph-demo/.ralph/runtime/worktrees/TASK-44AEB58A, timeout 300s)
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.11
Configuration: /tmp/ralph-demo/.ralph/runtime/worktrees/TASK-44AEB58A/phpunit.xml.dist

....................                                              20 / 20 (100%)

Time: 00:00.008, Memory: 8.00 MB

OK (20 tests, 22 assertions)
--- check tests passed in 0.2s
--- verification: 1 of 1 checks passed in 0.2s
```

| Task | Suite afterwards | Check time |
| --- | --- | --- |
| `TASK-44AEB58A` | `OK (20 tests, 22 assertions)` | 0.2s |
| `TASK-FC1A4E61` | `OK (15 tests, 15 assertions)` | 0.2s |
| `TASK-31D64F84` | `OK (20 tests, 21 assertions)` | 3.1s |

The example's suite is 12 tests before any of this, so each task grew it.

### Elapsed time

| Task | Agent | Whole run | Of which workspace preparation |
| --- | --- | --- | --- |
| `TASK-44AEB58A` | 1m 16s | 79s | 2.0s (`composer install`) |
| `TASK-FC1A4E61` | 1m 0s | 63s | ~2s |
| `TASK-31D64F84` | 39.9s | 45s | ~2s |

**About 3 minutes 10 seconds of wall clock for all three tasks**, of which the
agent is roughly 3 minutes: the loop's own share — worktree, preparation,
verification, staging, commit, checklist — is seconds per task, and the cost of
a run is the agent's thinking time and nothing else of consequence.

### Interventions

Three, and the first two are the loop refusing to guess rather than the agent
failing.

1. **`ralph run` refused to start after `ralph init`, because `init` had edited
   `.gitignore`.** `init` adds the runtime ignore rules, which leaves the
   checkout with one uncommitted change, and the pre-run inspection (section F,
   item 1) will not stash, discard or commit anything it did not write:

   ```
   ralph: /tmp/ralph-demo has 1 uncommitted change that are not part of Ralph PHP's own files:
     modified: .gitignore
     Commit the changes, or set them aside yourself with "git stash".
   ```

   Exit code 78. `git commit` on `.gitignore`, and the run started. This is
   correct behaviour meeting itself head-on: the rules `init` writes are files
   the user owns, so the next command treats them as the user's. It is also the
   first thing a reader following `examples/README.md` will hit, which is why
   that walkthrough now commits them.

   **Since this pass:** `ralph init` prints the commit as one of its own next
   steps when it wrote ignore rules, so the refusal is now a documented step
   rather than a surprise, and the refusal itself reads *"1 uncommitted change
   that is not part of…"*. The behaviour is unchanged: a run still will not
   touch a change it did not write.

2. **Piping `ralph run` into `head` crashed the run.** `ralph run | head -20`
   closes standard output as soon as `head` has its twenty lines; the next write
   raises `fwrite(): Write of 129 bytes failed with errno=32 Broken pipe`, which
   the application's error handler turns into an exception, and the run stops:

   ```
   Latest run run-20261111-235231-45585d — the run could not carry on
     stopped      ErrorException: fwrite(): Write of 129 bytes failed with errno=32 Broken pipe
   ```

   Nothing was lost — the task had already been verified, committed and checked
   off, and `status` showed it `completed` with its commit — but the run is
   reported as a failure it did not have, and the remaining tasks were not
   started. `ralph status | less` quit early is the same bug. It is a real defect
   rather than an operator error, it is recorded as one, and it is not something
   this run worked around: the rest of the pass simply did not pipe.

   **Since this pass: fixed.** A write that a stream will no longer accept is
   dropped rather than raised, and the stream is not written to again, so a run
   whose reader walks away finishes its remaining tasks and exits on its own
   terms. The reproduction is in the suite
   (`RunCommandTest::a_run_whose_output_is_no_longer_read_finishes_its_work`,
   which fails with exactly the exception above against the old code), and the
   two halves of the behaviour are pinned in `OutputTest`.

3. **One task changed a file it was not asked to.** `TASK-44AEB58A` also edited
   `README.md` to document the new argument, and said so in its report — *"I
   also added the new argument to the README example and class table — tell me
   if you'd rather the README stayed out of this change."* The task did not ask
   for it and `.ralph/CONTEXT.md` did not forbid it, so it is in the commit. On
   review it is a change a person would probably keep. It is recorded here
   because it is exactly what the README means by **review every commit**: the
   checks passing says the project is green, not that the diff is the diff you
   asked for.

The agent asked for no decisions, no attempt was retried, and no task was
blocked.

### What the code looked like

Reviewed, not merely verified. All three diffs follow the project's stated
conventions without being told twice: `final` classes, `private readonly`
constructor arguments, `declare(strict_types=1)`, `InvalidArgumentException`
naming the value it got, `mb_*` with an explicit `'UTF-8'`, and docblocks that
describe the edge cases. The existing tests were updated where behaviour
changed rather than contradicted by new ones, which is what the context file
asks for. Each class's stale "the task in `.ralph/tasks.md` adds this"
docblock was removed by the task that made it untrue — nothing asked for that
either.

### State afterwards

```
  checklist      .ralph/tasks.md — 3 tasks, 3 checked, 0 pending
  agent          claude (project default)
  progress       3 of 3 completed, 0 remaining
```

The three worktrees were removed on completion, `git worktree list` holds only
the checkout, and the only uncommitted change left is the one the package is
supposed to leave: `.ralph/tasks.md`, with three markers flipped and every other
byte as it was. A completion record per task is under
`.ralph/runtime/logs/TASK-*/attempt-01-completed.md`, and the agent's full
output beside it in `attempt-01-agent.log`.

## Codex: tested, not demonstrated

**No credentialed Codex run exists.** The checklist item asked for both agents
until 12 November 2026, when it was narrowed to Claude Code; the Codex half is
recorded here rather than quietly dropped, because the package ships the
adapter and the example offers it as its default.

What is covered: the Codex adapter's command construction, result
normalisation, blocking, malformed output, process failure and cancellation are
all in the automated suite against the fixture worker (section J, item 4), and
`ralph preflight` checks the executable and its version floor. What has not
happened is a real, credentialed Codex run on a real package.

It is blocked on the machine rather than on the package: no `codex` is
installed here, and installing one is only half of it — a real run needs that
CLI *signed in*, which is an interactive, paid, supervised step that belongs to
a person and not to an unattended iteration of the loop. Until someone with a
signed-in `codex` repeats either pass above and records it here, treat the
Codex adapter as tested but not demonstrated.

Reproducing it is the walkthrough in [`examples/README.md`](../examples/README.md)
with `"agent": "codex"` left as the example ships it, or `ralph run --agent
codex`.

## Known limitations

From these passes, beyond the ones the [README already
lists](../README.md#limitations):

- **The agent may change more than the task.** The 11 November pass has an
  example ([intervention 3](#interventions-1)); the 12 November pass happens not
  to. Nothing in the loop limits a diff to the files a task named, and nothing
  could; the commit is the unit of review.
- **Each worktree re-resolves its dependencies.** The example does not commit a
  `composer.lock`, so every task's `composer install` reports *"No composer.lock
  file present. Updating dependencies to latest instead"* and resolves afresh.
  Under two seconds here; on a real project with a committed lock file it
  installs from the lock as usual.
- **One agent, one machine, two passes of a three-task example.** This is a
  demonstration, not the ten-task evaluation PROJECT.md section 7 describes. It
  says the loop works end to end with Claude Code on a small PHP package, twice,
  with easy tasks chosen so that a failure would be the loop's. It says nothing
  about how often that holds on work that is hard.
- **Every task here succeeded on its first attempt.** Retry, blocking,
  verification failure and interruption are covered by the suite against the
  fixture worker, and no real agent has driven them in these passes.
- **Cost is not metered.** Seven agent calls were made across the two passes and
  the package has no idea what they cost.

Two limitations recorded on 11 November are gone, and their history is in
[that pass's interventions](#interventions-1): a closed standard output no longer
crashes a command, and `ralph init` now tells you to commit the ignore rules it
wrote instead of leaving the next `ralph run` to refuse.
