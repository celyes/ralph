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

| Agent | CLI version | Demonstrated | Tasks | Commits |
| --- | --- | --- | --- | --- |
| Claude Code | 2.1.226 | **yes**, 11 November 2026 | 3 of 3 completed | 3 |
| Codex | — | **not yet** — see [below](#codex-not-yet-demonstrated) | — | — |

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
   this run worked around: the rest of the pass simply did not pipe. See
   [Known limitations](#known-limitations).

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

## Codex: not yet demonstrated

**No equivalent run exists, and the checklist item stays open because of it.**

The Codex adapter's command construction, result normalisation, blocking,
malformed output, process failure and cancellation are covered by the automated
suite against the fixture worker (section J, item 4), and `preflight` checks the
executable and its version floor. What has *not* happened is a real,
credentialed Codex run on a real package.

It is blocked on the machine rather than on the package: no `codex` is
installed here, and installing one is only half of it — a real run needs that
CLI *signed in*, which is an interactive, paid, supervised step that belongs to
a person and not to an unattended iteration of the loop. Until someone with a
signed-in `codex` repeats the pass above and records it here, treat the Codex
adapter as tested but not demonstrated.

Reproducing it is the walkthrough in [`examples/README.md`](../examples/README.md)
with `"agent": "codex"` left as the example ships it, or `ralph run --agent
codex`.

## Known limitations

From this pass, beyond the ones the [README already
lists](../README.md#limitations):

- **A closed standard output crashes the command.** Piping into `head`, or
  quitting a pager early, ends the run with an `ErrorException` about a broken
  pipe instead of exiting quietly. Durable state is unaffected — whatever was
  committed and checked off stays committed and checked off — but the run is
  recorded as failed and any remaining tasks are not started. Avoid piping
  `ralph run`; redirect to a file and read that instead.
- **`ralph init` makes `ralph run` refuse once.** The ignore rules `init` adds
  are an uncommitted change, and the pre-run inspection will not commit them for
  you. Commit them, then run.
- **The agent may change more than the task.** See intervention 3. Nothing in
  the loop limits a diff to the files a task named, and nothing could; the
  commit is the unit of review.
- **Each worktree re-resolves its dependencies.** The example does not commit a
  `composer.lock`, so every task's `composer install` reports *"No composer.lock
  file present. Updating dependencies to latest instead"* and resolves afresh.
  Two seconds here; on a real project with a committed lock file it installs
  from the lock as usual.
- **One agent, one machine, three tasks, one pass.** This is a demonstration,
  not the ten-task evaluation PROJECT.md section 7 describes. It says the loop
  works end to end with Claude Code on a small PHP package. It does not say
  anything about how often that holds.
- **Cost is not metered.** Three agent calls were made and the package has no
  idea what they cost.
