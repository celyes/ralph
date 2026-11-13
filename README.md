<p align="center">
  <img src="https://upload.wikimedia.org/wikipedia/en/1/14/Ralph_Wiggum.png?utm_source=en.wikipedia.org&utm_campaign=imageinfo&utm_content=thumbnail_unscaled" alt="Ralph PHP" width="180">
</p>

<h1 align="center">Ralph PHP</h1>

<p align="center">A Ralph-style development loop for PHP.</p>

<p align="center">
  <a href="https://packagist.org/packages/celyes/ralph-php"><img src="https://img.shields.io/packagist/v/celyes/ralph-php?label=packagist&color=blue" alt="Latest version on Packagist"></a>
  <a href="https://packagist.org/packages/celyes/ralph-php/stats"><img src="https://img.shields.io/packagist/dt/celyes/ralph-php?label=installs&color=blue" alt="Installs"></a>
  <a href="https://github.com/celyes/ralph/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/celyes/ralph/ci.yml?branch=main&label=CI" alt="CI status"></a>
  <img src="https://img.shields.io/badge/tests-2859%20passing-brightgreen" alt="2859 tests">
  <img src="https://img.shields.io/packagist/dependency-v/celyes/ralph-php/php?color=777bb4" alt="Required PHP version">
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/celyes/ralph-php?color=green" alt="MIT licensed"></a>
</p>

You keep a Markdown checklist of tasks. Ralph PHP works through it one task at a
time. For each task it opens an isolated Git worktree, hands the task to a
coding agent — Codex or Claude Code — runs your verification commands itself,
commits what passed on a branch of its own, and only then ticks the box. When
something needs a person, it stops and says so.

**The loop owns the work; the agent only implements it.** Task selection, state,
Git and verification belong to Ralph PHP. That split is what makes a run
bounded, resumable and reviewable: every commit it produces is one you can find,
read and throw away.

New here, read three things in order: [How it works](#how-it-works) for the
shape of a run, [Getting started](#getting-started) for the five minutes of
setup, and [Example project](#example-project) for a repository you can run it
on today. The rest is reference you can come back to.

## How it works

### The loop, one phase at a time

`ralph run` takes the checklist's pending tasks in file order. Each task goes
through six phases, and the run names each one as it enters it:

| Phase | What happens | Who does the work |
| --- | --- | --- |
| **preparing** | A worktree and a branch are created for the task off the base commit, and the briefing is assembled: the task, your context file, and notes from earlier attempts. | Ralph PHP |
| **executing** | The agent is started in that worktree with the briefing on its standard input. Its output is streamed to your terminal and to the task's log. | the agent |
| **verifying** | Your `checks` commands are run in the worktree. Each command's output, exit code and duration are recorded. | Ralph PHP |
| **committing** | The worktree's changes are staged and committed on the task branch, with the task identifier in the commit trailers. | Ralph PHP |
| **publishing** | The branch is pushed to your remote, if you enabled pushing. Skipped otherwise. | Ralph PHP |
| **finalizing** | The task's checkbox is ticked in `.ralph/tasks.md`. | Ralph PHP |

The order matters: the box is ticked last, when there is already a commit to
point at. Each phase is recorded as it begins, which is how `ralph resume` can
tell that an interrupted task needs only its push retried rather than its agent
run again.

### What stops a task, and what happens next

| Situation | What Ralph PHP does |
| --- | --- |
| A check failed | Attempts the task again with the failure evidence in the next briefing, until `limits.attempts_per_task` runs out. The box stays unticked. |
| The agent asked a question | Ends the run. The answer is yours to give, so no further task is started. |
| Something is ambiguous — a branch owned by unrelated work, a change that looks like it weakens your checks | Stops that task, keeps the worktree and the diff as evidence, and says what to look at. |
| You pressed Ctrl+C | Stops the agent, records the interruption, keeps the files, exits 130. `ralph resume` picks it up. |
| A limit was reached | Stops at a task boundary and exits 124. Running `ralph run` again carries on. |

A task the loop gives up on does not take the rest of the run with it by
default: the next task is independent work, so it is taken on, and the failed
one is left for you to retry. `--bail` changes that — see [Bailing out of a
run](#bailing-out-of-a-run).

### Words this README uses

| Word | What it means here |
| --- | --- |
| **task** | One top-level `- [ ]` item in `.ralph/tasks.md`. Identified by a hash of its description, like `TASK-19E0214A`. |
| **run** | One invocation of `ralph run`, processing tasks until they run out or a limit stops it. |
| **pass** | One go at a single task, with its own branch and worktree. Unticking a completed task starts a second pass, whose names carry a `-g2` suffix. Also called a *generation*. |
| **attempt** | One invocation of the agent inside a pass. Failed checks buy another attempt; they do not start a new pass. |
| **check** | One verification command from `checks`, run by Ralph PHP in the task's worktree, never by the agent. |
| **workspace** | The linked Git worktree a pass works in, under `.ralph/runtime/worktrees/`. Your own checkout is never worked in. |
| **state** | Where a pass stands: `pending`, `running`, `completed`, `skipped`, `blocked`, `failed` or `interrupted`. |

### What it never does

- **It never works in your checkout.** Nothing of yours is stashed, discarded or
  committed. The only file of yours it writes is the checkbox of a task that
  passed.
- **It never merges, deploys, or opens a pull request.** It produces branches
  and commits for you to review.
- **It never takes the agent's word for it.** A zero exit code, a confident
  summary, or a checkbox the agent ticked itself is not a completed task — only
  passing checks and a real commit are.
- **It never silently switches agents.** A missing executable or an unsupported
  CLI version is an error, not a fallback.

### One task through the loop, abridged

Lines beginning `---` are Ralph PHP's own notes; everything else is the agent's
output or your test suite's. On a terminal this is in colour — see
[Colour](#colour).

```
$ ralph run
run-20261111-222204-dcc43e: codex (adapter "codex", selected by the project configuration), processing pending tasks
base HEAD (4f2a1c90b3d7)
checks: tests (frozen aebd852638df)

[1/1] TASK-19E0214A Add a --dry-run option to the import command
--- workspace TASK-19E0214A in .ralph/runtime/worktrees/TASK-19E0214A, from HEAD (4f2a1c90b3d7) (created by this run)
--- codex: TASK-19E0214A, attempt 1, agent codex: Add a --dry-run option to the import command (first of 3 attempts, up to 1800 seconds; context 1480 chars, 0 notes)
    … the agent's output, streamed as it arrives and copied to the task's log …
--- check tests: composer test (in .ralph/runtime/worktrees/TASK-19E0214A, timeout 600s)
    … your suite's output, run by Ralph PHP rather than by the agent …
--- check tests passed in 12.4s
--- verification: 1 of 1 checks passed in 12.4s
--- staging: 3 file(s) staged, +81 -4
--- checks audit: nothing weakened
--- commit: 9c3b7e1a2f40 TASK-19E0214A: Add a --dry-run option to the import command
--- completed: verified and committed
---   checklist: .ralph/tasks.md line 3, now checked
TASK-19E0214A completed: verified and committed (9c3b7e1a on ralph/TASK-19E0214A-add-a-dry-run-option)

run-20261111-222204-dcc43e: 1 of 1 tasks completed with codex, 0 remaining — every pending task was completed (1 task)
  next: Review the task branches, then add the next work to .ralph/tasks.md and run `ralph run`.
```

## Contents

**Start here**

- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Getting started](#getting-started)
- [Example project](#example-project)

**Daily use**

- [Commands](#commands)
- [Task checklist syntax](#task-checklist-syntax)
- [Configuration](#configuration)
- [Agent setup](#agent-setup)
- [Agent selection](#agent-selection)
- [Troubleshooting](#troubleshooting)

**Reference**

- [Custom adapters](#custom-adapters)
- [Git behaviour](#git-behaviour)
- [Context and memory](#context-and-memory)
- [Verification](#verification)
- [Limits](#limits)
- [Recovery](#recovery)
- [Logs and diagnostics](#logs-and-diagnostics)
- [Colour](#colour)
- [Exit codes](#exit-codes)
- [Limitations](#limitations)
- [Why so few dependencies](#why-so-few-dependencies)
- [Development](#development)
- [License](#license)

## Requirements

| | |
| --- | --- |
| PHP | 8.3 or newer |
| Extensions | `json`, `mbstring`, `pdo`, `pdo_sqlite` (required); `pcntl`, `posix` (recommended — see below) |
| Git | 2.24 or newer, with a working tree and a commit author identity |
| Platform | Linux and WSL are the supported and tested targets |
| An agent | the Codex CLI (0.40+) or the Claude Code CLI (2.0+), authenticated |

Laravel is not required, and neither is any framework. `ralph preflight` checks
every one of these against your project and tells you which is missing.

Without `pcntl` and `posix`, Ctrl+C cannot be handled cleanly: the run cannot
stop the agent, record the interruption and release its lock in order. Install
them if you intend to interrupt runs.

## Installation

> **Not on Packagist yet.** Until it is, add the repository to the
> `composer.json` of the project you are installing into, and the commands below
> will resolve:
>
> ```json
> {
>   "repositories": [
>     {"type": "vcs", "url": "https://github.com/celyes/ralph-php"}
>   ]
> }
> ```

There are two ways to install it, and both are tested.

**As a development dependency** of the project you want worked on:

```bash
composer require --dev celyes/ralph-php
vendor/bin/ralph --version
```

**As a global tool**, to use it in any repository:

```bash
composer global require celyes/ralph-php
ralph --version          # with ~/.composer/vendor/bin or ~/.config/composer/vendor/bin on PATH
```

The choice matters in exactly one place: a global installation runs under its
own autoloader and knows nothing of your project's classes, which affects
[custom adapters](#custom-adapters) and nothing else.

Examples below are written as `ralph`. Read them as `vendor/bin/ralph` if you
installed it into a project.

## Getting started

```bash
cd your-php-project          # a Git repository with a clean working tree
ralph init                   # writes .ralph/ and appends ignore rules
$EDITOR .ralph/CONTEXT.md    # describe the project for the agent
$EDITOR .ralph/tasks.md      # write one task
$EDITOR .ralph/config.json   # add the command your project is verified with
git commit -am "Ignore Ralph PHP runtime data"   # init edited .gitignore
ralph preflight              # check this machine can do the work
ralph run
```

The `config.json` step is optional and worth doing. `checks` starts empty —
Ralph PHP does not assume how your project is verified — and a run with no
checks completes a task on the agent's own result and the commit it produced.
See [Verification](#verification).

`ralph init` creates:

| Path | What it is |
| --- | --- |
| `.ralph/config.json` | every setting, at its default value |
| `.ralph/tasks.md` | the checklist, with instructions and no tasks |
| `.ralph/CONTEXT.md` | project instructions passed to every attempt |
| `.ralph/runtime/` | state, logs, memory and task worktrees |

and appends ignore rules for `.ralph/runtime/`, `.ralph/tasks.md` and the `.bak`
files `--force` makes. Existing files are kept as they are; `ralph init
--force` resets the three generated files, backing up any whose content differs
first. Your ignore file is never rewritten in either mode — missing rules are
appended, and a rule you have negated with `!` is left negated, so tracking the
checklist yourself is a decision `ralph init` will not undo.

**Why you have to commit in the middle of that.** `ralph init` never runs
`git`, so the ignore rules it appends are left as an uncommitted change in your
checkout. A run refuses to start over changes it did not write
([Git behaviour](#git-behaviour)), so that change has to be yours to deal with.
`init` prints the commit as one of its next steps for exactly this reason.

## Example project

`examples/text-tools/` is a working PHP package — two classes, a PHPUnit suite,
`composer test` — shipped with three unfinished tasks in its `.ralph/tasks.md`.
Copy it out of this repository, give it a Git repository of its own and run the
loop on it:

```bash
cp -r examples/text-tools /tmp/text-tools && cd /tmp/text-tools
git init && git add --all && git commit -m "Import the example package"
composer install
ralph init          # keeps the three .ralph/ files the example ships
git commit -am "Ignore Ralph PHP runtime data"   # init edited .gitignore
ralph preflight
ralph run           # three tasks, three branches, three commits
```

[`examples/README.md`](examples/README.md) walks through it: what the three
tasks change, what to look at afterwards, how to run it with Claude Code
instead of Codex, and how to start over. A run calls a paid coding agent once
per task.

[`docs/demonstrations.md`](docs/demonstrations.md) is what happened when that
pass was actually run, twice: Claude Code 2.1.226 over all three tasks, with the
commits, the verification output, the elapsed time, and what a person had to do
— three interventions the first time, none the second.

The example has to be its own repository because each task is implemented in a
linked worktree on a branch off the base commit — run it inside this checkout
and those branches would be made here.

## Commands

| Command | Use it to |
| --- | --- |
| [`ralph init`](#ralph-init---force) | Set the project up: configuration, checklist, context file, ignore rules. Run once. |
| [`ralph preflight`](#ralph-preflight---agent-name) | Ask whether a run could start, without starting one. Writes nothing. |
| [`ralph run`](#ralph-run---agent-name---bail--no-bail) | Work through the pending tasks. This is the loop. |
| [`ralph status`](#ralph-status---json) | See where every task stands, and how the last run ended. |
| [`ralph logs TASK-ID`](#ralph-logs-task-id) | Read what the agent and the checks actually produced for one task. |
| [`ralph resume`](#ralph-resume---agent-name---bail--no-bail) | Continue an interrupted run from the phase it stopped in. |
| [`ralph retry TASK-ID`](#ralph-retry-task-id) | Have another go at one failed or blocked task, keeping its history. |
| [`ralph help`](#ralph-help-command-ralph-version) | Read the usage for the application or one command. |

Every command takes `-h`/`--help` for its own usage, and `-v`/`--verbose` to
include stack traces in error output. `ralph help COMMAND` prints the same
detail as `--help`. `--color` and `--no-color` decide the colour of what is
printed ([Colour](#colour)). `-V`/`--version` belongs to the application rather
than to a command, so it is `ralph --version`, not `ralph run --version`.

### `ralph init [--force]`

Creates the configuration, checklist, context file and runtime directories, and
appends the ignore rules. Preserves existing files unless `--force` is given.

### `ralph preflight [--agent NAME]`

Checks the PHP version, the required extensions, Git's presence and version,
that this directory is a non-bare working tree, that `git.base` names a commit,
that Git can determine an author and committer, that the selected agent's
executable and credentials are present, that its CLI version is supported, and
that every configured verification command could run.

Writes nothing and starts no agent session: the check commands are resolved
rather than run, and the agent is only asked for its version. Safe at any time,
including while a run is interrupted. Exits 0 when a run could start — warnings
included — and 78 when a check failed. `run` and `resume` perform the same
checks before they start work.

### `ralph run [--agent NAME] [--bail|--no-bail]`

The loop itself: the checklist's pending tasks in file order, one at a time,
each through the six phases described in [How it works](#how-it-works). It is
the only command that starts an agent, writes a commit, or ticks a box.

`--bail` stops the run at the first task it gives up on instead of carrying on,
and `--no-bail` carries on even when `limits.bail_on_failure` is set in the
project configuration. With neither, that setting decides; it is off by default.
See [Bailing out of a run](#bailing-out-of-a-run).

### `ralph status [--json]`

Prints the configuration in force, the checklist it reads, the selected agent,
how many tasks are done and how many remain, the latest run with its state and
why it stopped, and then every task in checklist order with its identifier,
state, phase, attempt count, branch, commit and check results.

A box ticked by a verified run is reported as the runner's; one ticked by hand is
reported as yours, because a box with no commit behind it is not work this
package can vouch for.

`--json` prints the same report as one JSON document with a schema version,
unabbreviated SHAs and full task descriptions. Problems still go to standard
error, so standard output stays machine-readable.

Writes nothing and starts no agent, so it is safe to run while a run is going on
in another terminal. Its exit code reports what the project is holding *now*,
not how the last run ended.

### `ralph logs TASK-ID`

Prints what was recorded for one task: each pass, newest first, and within a
pass each attempt's worker output and the result of every check that ran. Files
beside it are named rather than printed — the staged diff a stopped pass
preserved, the evidence behind a no-change completion, the record behind a
ticked box. See [Logs and diagnostics](#logs-and-diagnostics).

### `ralph resume [--agent NAME] [--bail|--no-bail]`

Reconciles worktree, branch and recorded commit state after an interruption and
continues with the agent and settings recorded for the original run. A task is
picked up at the phase it stopped in, and nothing earlier is repeated. See
[Recovery](#recovery).

`--bail` and `--no-bail` work as they do for `run`, and are this resume's own
decision: they shape the tasks still to come rather than the work already done.
The agent, unlike bailing, cannot be changed by a resume.

### `ralph retry TASK-ID`

Starts a new attempt at one task, continuing the pass that stopped: its earlier
attempts, logs, branch and blocker details are kept, and the new attempt is
given the recorded failure as evidence. The per-task attempt allowance starts
again, so a task that ran out of attempts can be tried. Only the named task is
taken on.

For a task that is *interrupted*, use `resume` instead — it finishes the pass
from where it stopped rather than attempting it again. For a task that was
checked off, untick the item and run `ralph run`: the work is taken on as a new
pass with its own branch, and the finished one keeps its commit and evidence.

### `ralph help [COMMAND]`, `ralph version`

Help for the application or one command; the version, the package name and the
PHP binary in use.

## Task checklist syntax

`.ralph/tasks.md` is ordinary Markdown. Top-level `- [ ]` items are pending
tasks; `- [x]` and `- [X]` are done.

```markdown
# Tasks

- [x] Extract the CSV reader into its own class
- [ ] Add a --dry-run option to the import command
  - It must print the planned changes and write nothing.
  - Cover it with a test in tests/Import/.
- [ ] Reject a negative --limit with a readable error
```

Indented ordinary bullets and paragraphs under an item are passed to the agent
as implementation details or acceptance criteria. Headings and prose are
ignored, as is anything inside a fenced code block — so a checklist in an
example is not picked up as a task.

Two things are errors rather than skipped, so no task you wrote is quietly left
out: a checkbox with nothing after it, and a checkbox indented under another
item. This release runs top-level items only — promote a nested item, or write
it as a plain `- ` bullet to keep it as a detail of the item above.

**Task identity.** A task's identifier is a hash of its description alone —
like `TASK-19E0214A` — with capitalisation and spacing normalised away. Neither
its checkbox nor its position in the file is part of it. In practice:

| You do this | And the task's history |
| --- | --- |
| Reorder the list | survives — position is not part of the identity |
| Tick or untick an item | survives — the checkbox is not part of the identity |
| Rewrite the indented notes under an item | survives — only the item's own line counts |
| Reword the item itself | is left behind: that is now a *different* task |

Because the comparison ignores case and spacing, two items that read the same
are one task. A repeated description is an error rather than work done twice.

**What makes a task work well here:** it describes one change small enough to
verify on its own; it says how the change is verified, not only what it should
do; it names the files or areas involved when you know them; and it stands on
its own, because this release does not build one task on top of another task's
unmerged branch.

Avoid editing the file while a run is in progress. The runner rewrites the
checkbox of the task it finishes, and it will notice a task that changed under
it and pause for reconciliation rather than apply old progress to changed work.

## Configuration

`.ralph/config.json`, written whole by `ralph init`. Every setting has a
default, so a key you delete falls back to the value below. Invalid types,
empty commands, nonpositive limits and unresolvable paths are rejected before
any work starts.

```json
{
  "tasks": ".ralph/tasks.md",
  "context": ".ralph/CONTEXT.md",
  "agent": "codex",
  "agents": {
    "codex":  {"adapter": "codex",  "executable": "codex",  "model": null, "arguments": [], "environment": []},
    "claude": {"adapter": "claude", "executable": "claude", "model": null, "arguments": [], "environment": []}
  },
  "checks": [],
  "workspace": {
    "prepare": [],
    "copy": []
  },
  "git": {
    "base": "HEAD",
    "branch_prefix": "ralph/",
    "push": {"enabled": false, "remote": "origin"}
  },
  "limits": {
    "iterations": 10,
    "attempts_per_task": 3,
    "task_timeout": 1800,
    "run_timeout": 7200,
    "bail_on_failure": false
  }
}
```

| Setting | Default | Meaning |
| --- | --- | --- |
| `tasks` | `.ralph/tasks.md` | the Markdown checklist to read |
| `context` | `.ralph/CONTEXT.md` | project instructions passed to every attempt |
| `agent` | `codex` | which `agents` entry to use when `--agent` is not given |
| `agents.NAME.adapter` | — | the adapter that drives it: `codex`, `claude`, or one you registered |
| `agents.NAME.executable` | the agent name | path or command name of the CLI |
| `agents.NAME.model` | `null` | model passed to the CLI, when it takes one |
| `agents.NAME.arguments` | `[]` | extra arguments, appended after the adapter's own |
| `agents.NAME.environment` | `[]` | **names** of environment variables the agent authenticates with |
| `checks[]` | `[]` | verification commands, run in order, as argument arrays |
| `checks[].timeout` | `600` | seconds one check may run |
| `workspace.prepare[]` | `[]` | commands that make a fresh worktree usable |
| `workspace.copy[]` | `[]` | files copied in from your checkout, such as a local `.env` |
| `git.base` | `HEAD` | commit task branches start from |
| `git.branch_prefix` | `ralph/` | prefix for generated branch names |
| `git.push.enabled` | `false` | whether to push task branches |
| `git.push.remote` | `origin` | remote to push to |
| `limits.*` | see above | see [Limits](#limits) |

Commands are argument *arrays*, not shell strings: nothing is passed through a
shell, so a task description cannot become part of a command line.

Three paths are deliberately **not** configurable: `.ralph/runtime/state.sqlite`
(the file that says which commit belongs to which task),
`.ralph/runtime/events.jsonl`, and `.ralph/adapters.php` (code this package
executes). A configuration that could redirect any of them would be a
configuration that can lose a task's history or change which code a run loads.

## Agent setup

Ralph PHP does not install or authenticate agents. It invokes the CLI you
already use and refuses to start if it cannot.

### Codex

Install the Codex CLI (0.40 or newer) and log in so that `codex` works in your
terminal. Each attempt is run as:

```
codex exec --cd WORKSPACE --sandbox workspace-write [--model M] [your arguments…] -
```

`exec` is the headless form of the CLI; the briefing goes in on standard input,
so a large context file cannot overflow an argument list; `--sandbox
workspace-write` keeps writes inside the task worktree. Anything you put in
`agents.codex.arguments` is appended last, so a repeated flag's final
occurrence — yours — is the one Codex keeps.

### Claude Code

Install the Claude Code CLI (2.0 or newer) and log in so that `claude` works.
Each attempt is run as:

```
claude --print --output-format text --permission-mode bypassPermissions [--model M] [your arguments…]
```

`--print` is the non-interactive form; the briefing goes in on standard input.
`--permission-mode bypassPermissions` is needed because in print mode there is
nobody to approve a tool call, and an agent that cannot run your test suite
cannot check its own work. Note the asymmetry with Codex: **Claude Code has no
sandbox flag**, so this adapter cannot make a write outside the workspace fail
— it can only make sure such a write is never staged, and is left behind in a
checkout the package has promised not to touch. If you want confinement, run
the loop inside a container. If you would rather give up running commands, put
`--permission-mode acceptEdits` in `agents.claude.arguments`.

The invocations above were exercised against Codex 0.4x and Claude Code
2.1.226. A CLI older than the adapter's floor is refused by preflight rather
than discovered from a usage message in an attempt's log.

### Credentials

List the *names* of the variables your authentication needs:

```json
{"agents": {"claude": {"adapter": "claude", "executable": "claude", "environment": ["ANTHROPIC_API_KEY"]}}}
```

Ralph PHP reads those variables from its own environment and passes them
through. It never stores a value, prints one, or writes one to configuration;
values of the named variables are replaced with the variable's name in
everything this package composes. Both built-in agents default to an empty list,
because an interactive login is a perfectly good way to be authenticated and
demanding an API key would fail preflight for a working setup.

## Agent selection

One agent per run. `agent` in the configuration is the default; `--agent NAME`
overrides it for that run:

```bash
ralph run                   # the configured default
ralph run --agent claude    # this run only
```

The resolved agent and its non-secret settings are persisted with the run and
with every attempt, and shown by `ralph status` and `ralph logs`. A missing
executable, an unknown adapter, an unsupported CLI version or unavailable
authentication is a clear error — Ralph PHP never silently falls back to another
agent.

`ralph resume` uses the agent the interrupted run recorded, and refuses to
change it; an edit to the agent settings since that run was opened is reported
and not applied. To switch agents, reconcile the outstanding work and then start
a *new* run with `ralph run --agent NAME`. Completed tasks stay completed, and
their commits and attempt history stay attributed to the agent that earned them.

## Custom adapters

Any agent with a command-line interface can be driven without editing the
package. Implement two small interfaces — `ExecutionAdapter`, which runs the
agent once against one task, and `AdapterProvider`, which names the adapter and
builds it — then register the provider.

```php
<?php // src/Ralph/AcmeAdapterProvider.php

use Celyes\RalphPhp\Agent\AdapterProvider;
use Celyes\RalphPhp\Agent\ExecutionAdapter;
use Celyes\RalphPhp\Config\AgentDefinition;

final class AcmeAdapterProvider implements AdapterProvider
{
    public function adapter(): string
    {
        return 'acme';
    }

    public function create(AgentDefinition $definition): ExecutionAdapter
    {
        return new AcmeAdapter($definition);
    }
}
```

Register it from `.ralph/adapters.php`, which returns an array of providers:

```php
<?php // .ralph/adapters.php

require __DIR__ . '/../vendor/autoload.php';

return [new AcmeAdapterProvider()];
```

and then the agent is an ordinary `agents` entry naming that adapter:

```json
{"agent": "acme", "agents": {"acme": {"adapter": "acme", "executable": "acme-cli"}}}
```

Notes worth having in advance:

- **The `require` is the file's job.** Under a project installation it is
  redundant and harmless; under a global installation it is what makes your
  classes loadable at all.
- **The path is fixed**, not a setting, because it is code this package executes
  in its own process — with the same trust your `phpunit.xml` bootstrap already
  has. `ralph init` does not create it; a project that does not extend the
  package should not acquire an executed PHP file by default.
- **Three obligations on an adapter**, all of them about the loop being able to
  trust what comes back:
  1. **Report one of the defined outcomes** — candidate implementation, request
     for human input, execution error, or timeout — and never guess at a
     candidate. These outcomes are what keep verification and Git behaviour
     identical whichever agent ran.
  2. **Stop within the time budget it was given.**
  3. **Work only in the workspace it was handed**, launched with an argument
     array rather than a shell string.
- Extending `ProcessAdapter` gives you the executable resolution, working
  directory, streaming, budget enforcement, log notes and cancellation that both
  built-in adapters share, so a new CLI adapter is usually a flag list and a
  version floor.
- Used as a library rather than as a command, register straight into
  `AdapterRegistry`.

## Git behaviour

**Your checkout is inspected first, and never touched.** A working tree holding
unrelated uncommitted changes stops the run with exit 78. Nothing is stashed,
discarded or committed on your behalf. The only write to a file you own is the
checkbox of a task that passed.

**Each task gets its own linked worktree** under
`.ralph/runtime/worktrees/TASK-ID/`, created from the commit `git.base`
resolved to at run start — recorded as a SHA, so every task in a run starts from
the same place. A fresh worktree has your tracked files and nothing else, which
is why `workspace.prepare` exists — list `composer install` there if your checks
need `vendor/` — and why `workspace.copy` can bring in local files no commit
carries, such as an `.env`. Both are empty by default: a project that works from
its tracked files alone needs neither, and nothing is run that you did not ask
for.
A copied file is never staged, so a credential listed there reaches the agent
without reaching the branch.

**Each task gets its own branch**, `<prefix><TASK-ID>-<description-slug>`, for
example `ralph/TASK-19E0214A-add-a-dry-run-option`. A task reopened after being
completed takes a `-g2` suffix on the identifier —
`ralph/TASK-19E0214A-g2-add-a-dry-run-option` — so two passes at one task can
never name the same branch. Ownership is recorded, so a
resume knows the branch is its own; a branch that belongs to unrelated work is
refused rather than reset or overwritten.

**Only verified task-workspace changes are staged**, and never runtime data,
Ralph PHP's own files, or anything your ignore rules cover. A build directory
that is *not* ignored will be committed — the staged file list printed before
each commit is where to notice that. The commit's subject is the task as the
checklist states it, not what the agent said it did, followed by trailers:

```
Add a --dry-run option to the import command

Ralph-Task: TASK-19E0214A
Ralph-Generation: 1
Ralph-Run: 7
Ralph-Attempt: 2
Ralph-Base: 4f2a1c9…
Ralph-Agent: codex
```

Those are queryable with the tools you already have — `git log
--grep='Ralph-Task: TASK-19E0214A'` finds every pass at one task — and they
travel with the commit into every clone, which the state database does not. Only
the agent's *name* is written, never its settings.

**Pushing is opt-in.** With `git.push.enabled` true, each task branch is pushed
to `git.push.remote` after its commit. A rejected push keeps the local commit
and records the publication as outstanding; `ralph resume` retries the push
alone, without re-running the agent or making a second commit.

**Unexpected Git activity is reconciled, not accepted.** A worker that created
its own commits or moved a branch stops the pass with its work preserved.

**Cleanup is biased towards leaving things alone.** A failed or interrupted
pass's worktree is kept, because it is the evidence. A worktree is removed only
when every one of these holds:

- this pass created it;
- the pass is closed;
- Git has it registered and unlocked;
- nothing in it is uncommitted;
- its HEAD is the commit the record names;
- the pass's branch holds that commit, so the commit stays reachable once the
  worktree is gone.

Anything else is kept, with the reason stated. Cleanup never withdraws work that
has already been earned.

## Context and memory

Each attempt is briefed with the task and its criteria, your `.ralph/CONTEXT.md`
in full, bounded notes from earlier attempts at the same task, and the previous
attempt's failure evidence.

`.ralph/CONTEXT.md` is yours to maintain and is read on *every* attempt, so keep
it short and specific — what the project is, how it is run and verified, its
conventions, the files a change usually touches, and what is out of scope.
Anything useful for only one task belongs in that task. The template `ralph
init` writes has those headings.

Memory lives in `.ralph/runtime/memory/` as concise attempt summaries and
failure evidence. It is bounded, and deliberately not configurable: the context
file is capped at 20,000 characters, and at most 10 notes totalling 12,000
characters reach an attempt, newest first. Without a ceiling the prompt would
grow with every attempt until the agent refused it — or worse, quietly dropped
the end, which is the half holding the most recent failure. When something is
trimmed, Ralph PHP says so.

Notes an agent writes about its own work are kept as *claims*, not as state. A
model's summary is never treated as evidence that something was verified, and a
checkbox an agent ticks is not a completed task.

## Verification

`checks` are run by Ralph PHP, in the task's worktree, independently of the
agent. Each command's output, exit code and duration are captured and recorded.

A zero exit from the agent is not enough to complete a task. The checks must
pass, a commit must be recorded or a no-change result justified with evidence,
and any required push must succeed. Only then is the box ticked.

**`checks` is empty by default, and an empty `checks` is allowed.** Only your
project knows how it is verified — `composer test`, `vendor/bin/phpunit`, `make
check`, several commands in order — so Ralph PHP asks instead of assuming, and
you do not have to invent a command to get your first run started. What you give
up is stated rather than hidden, in three places you will actually see it:
`ralph preflight` warns, `ralph run` says so before the first task, and each
completed task's record names it. A run with no checks decides a task on the
agent's reported result and the commit it produced, so **review those commits
before merging them**. Adding one command under `checks` is what turns the tick
back into evidence:

```json
{"checks": [{"name": "tests", "command": ["composer", "test"]}]}
```

- **The check configuration is snapshotted at run start**, so an agent that
  edits `.ralph/config.json` mid-run cannot replace the checks it will be
  judged by.
- **Failures feed forward.** Bounded failure evidence goes into the next
  attempt, and the task stays unchecked while verification fails.
- **Weakened verification blocks.** A change that appears to weaken existing
  checks stops the pass with the diff preserved for review. This detection is
  best-effort and is not guaranteed to be comprehensive.
- **An already-satisfied task** is a verified no-change outcome with supporting
  evidence, rather than an empty commit.
- **A checkbox write that fails** leaves the implementation and commit intact and
  the task pending finalisation, so a resume retries only the checklist update.

## Limits

| Limit | Default | What it bounds |
| --- | --- | --- |
| `limits.iterations` | 10 | tasks one run may process |
| `limits.attempts_per_task` | 3 | attempts before a task is left failed |
| `limits.task_timeout` | 1800s | seconds one task may spend in the agent |
| `limits.run_timeout` | 7200s | seconds of active run time before stopping at a task boundary |
| `limits.bail_on_failure` | `false` | whether the first task the loop gives up on ends the run |
| `checks[].timeout` | 600s | seconds one verification command may run |
| `workspace.prepare[].timeout` | 900s | seconds one preparation command may run |

A run that reaches the iteration limit or the run-time budget exits 124 with
accurate persisted status, and running `ralph run` again carries on from where it
stopped. Counters and elapsed time carry across a `resume`, so resuming finishes
the interrupted run's work rather than restarting its budget. A deliberately new
run starts with the limits whole and is identifiable as a new run, without losing
completed task history.

### Bailing out of a run

By default a task the loop gives up on does not take the rest of the run with
it: the task after it is independent work, so it is taken on, and the failed one
is left unchecked for you to retry. Set `limits.bail_on_failure` to `true` to
stop the run at the first task that does not pass instead:

```json
{"limits": {"bail_on_failure": true}}
```

`ralph run --bail` turns it on for one run, and `ralph run --no-bail` turns it
off for one run when the configuration has it on. Both options work on `resume`
too. Giving both at once is refused rather than guessed at.

What a bail changes is only what happens *next*. The task it stopped on is left
exactly as a failure the loop walks past would be — unchecked, retriable, with
its attempts, workspace and logs kept — and the tasks after it are never
started, so they stay pending and untouched and the next `ralph run` takes them
on. A bailed run exits 1 and names the task it stopped on, together with how
many tasks it left untouched.

Worth turning on when a checklist's later tasks build on its earlier ones, or
when you would rather look at a failure while it is the newest thing in the
project than find six of them in the morning. A task that stops to *ask a
question*, and an interrupted run, end the run whatever this setting says.

## Recovery

**One runner per repository.** A repository-level lock prevents overlapping
runs, and a resume confirms the old worker has actually stopped before taking
over.

**Ctrl+C** stops the managed agent and its process tree where the platform
supports it, records the interruption, preserves the workspace, and releases
ownership only once termination is confirmed. The run exits 130.

**`ralph resume`** reconciles worktree, branch and recorded commit state and
continues from the phase the pass stopped in. Nothing earlier is repeated:

- a pass whose commit was made but whose push was rejected retries the push
  alone;
- a pass whose checkbox could not be written writes only the checkbox;
- a crash between the commit and the database update is recognised from the
  repository itself, so the commit is adopted rather than made twice.

Neither case invokes the agent again or creates a second commit.

**Checkbox writes are protected** by source-content checks and atomic
replacement. A concurrent edit to the task file is preserved and the conflicting
write is blocked rather than forced.

**A run stops on an unresolved decision, exhausted attempts or ambiguous
ownership**, with a readable reason and the next action — on the last line of
output, and on the run's record for `ralph status`.

## Logs and diagnostics

Worker output is streamed to your terminal and written to
`.ralph/runtime/logs/TASK-ID/`, one file per attempt, readable only by you (a
reopened task's pass logs into `TASK-ID-g2/`, beside the first pass's).
`ralph init` ignores the runtime directory, so nothing here is committed or
pushed. Files stay until you delete them or the task's workspace is cleaned up;
the package never trims or rotates them.

`.ralph/runtime/events.jsonl` is an append-only stream, one JSON object per
line, for a supervising process to follow: `schema`, `id`, `seq`, `at`, `run`,
`task`, `event`, `outcome` and `data`. Event IDs are stable, so a consumer can
deduplicate, and a completion event is emitted only after the work is durably
finalised.

**On secrets.** Values of the variables named in `agents.*.environment` are
replaced with the variable's name in everything Ralph PHP composes: terminal
output, its notes in the logs, the event stream, the state store and the
excerpts `ralph logs` prints. They are **not** removed from the raw worker
output in the log files, which is a verbatim copy of another process's bytes. A
secret Ralph PHP was never told about cannot be redacted at all — a token inside
a Git remote URL, a credential an agent read from a file, a key printed by your
own test suite. Treat these logs as you would the agent's terminal, and read one
before pasting it into an issue.

Lines beginning `--- ` in a log are Ralph PHP's own notes about the invocation;
everything else is the agent's.

**A terminal that goes away does not stop a run.** `ralph run | head`, or a
pager quit on the first screen, closes the thing the command was writing to.
The remaining output is dropped — the logs and the event stream are unaffected,
because they are files — and the run carries on to its last task and exits on
its own terms.

## Colour

Ralph PHP colours the lines it writes itself: the heading over each task a run
takes on, the outcome of a task and of the run, the verdict of each preflight
check, the state column of `ralph status`, and the subject of each `--- ` note
in a run's progress output. Labels are dimmed so values stand out, and a run's
tasks are separated by a blank line so an agent's last line of output and the
loop's next step do not read as one block of text.

A coloured line is the same line with sequences around its fields: removing
them gives the plain output byte for byte, so nothing is said in colour alone.

**It is decided per stream, and per invocation.** Standard output and standard
error are asked separately, so `ralph status > status.txt` writes a plain file
while the problems it reports stay marked on the terminal, and
`ralph status --json` is never coloured at all. In order:

| Setting | Effect |
| --- | --- |
| `--no-color` | Never colour this invocation. `--no-colour` also works. |
| `--color` | Colour this invocation even when it is a pipe or a file — for `less -R`, `tee`, or a CI log viewer that renders sequences. |
| `NO_COLOR` set to anything but the empty string | Never colour, for every invocation ([no-color.org](https://no-color.org/)). Wins over `FORCE_COLOR`. |
| `FORCE_COLOR` set to anything but `0` | Colour even through a pipe. |
| `TERM=dumb` | Never colour. |
| nothing of the above | Colour when the stream is a terminal. |

`--color` and `--no-color` together is a usage error rather than a precedence
rule: there is no reading of it more likely than a typo.

**The files stay plain.** Attempt logs, the event stream, the state store, the
preserved diffs and the completion records are written without any of this, so
`grep` and `jq` see what they always saw and `ralph logs` prints a verbatim copy
of what the agent wrote. Colour is added where a line is put on a terminal and
nowhere nearer the work.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | the command did what it was asked |
| 1 | work was attempted and did not succeed |
| 64 | the command line was wrong; nothing ran |
| 69 | the command is not implemented in this build |
| 70 | an unexpected internal error, which is a bug in Ralph PHP |
| 75 | stopped with something waiting for a person; the work is preserved |
| 78 | the project or its environment is not in a state to run in |
| 124 | a configured limit stopped the run before the work ran out |
| 130 | the run was stopped from outside; `ralph resume` continues it |

Three of them mean "a person has to act", and they are told apart on purpose:
**75** is work that exists and is waiting on a decision, **130** is work that
exists and is waiting on `ralph resume`, and **78** is work that never started.
The values follow convention — 64–78 is BSD `sysexits`, 124 is what `timeout`
reports, 130 is 128 + SIGINT — so a shell or CI reads them as it already does.

A code reports what the command found, not history: a run that spent its
iteration budget exits 124, and `ralph status` afterwards exits 0, because the
tasks it left are indistinguishable from the pending tasks of a project nobody
has run yet.

## Troubleshooting

Where to look first when a run does not do what you expected. Every command also
prints a `next:` line naming the action to take, and `ralph status` is always
safe to run.

| What you see | What it means | What to do |
| --- | --- | --- |
| `ralph run` refuses to start, exit 78, naming uncommitted changes | Your checkout holds changes the run did not write, and a task's worktree would not contain them. | Commit or stash them yourself. Ralph PHP will not do it for you. Right after `ralph init`, the change is the ignore rules it appended. |
| Exit 78 from `ralph preflight` | Something about the machine or project is missing: a PHP extension, Git, the agent's executable, its credentials, a check command that cannot be resolved. | The report names each failing check and what it expected. Fix those and run `preflight` again — it writes nothing and starts no agent. |
| A run says another runner holds the repository | A `ralph run` is in progress elsewhere, or one died without releasing the lock. | Look for the other process. Once it is gone, `ralph resume` confirms it has stopped and takes over. |
| A task is left unchecked and the run carried on | Its checks kept failing, or its attempt allowance ran out. | `ralph logs TASK-ID` shows each attempt and each check. Fix the task's wording or your check, then `ralph retry TASK-ID`. |
| Exit 75, "waiting for a person" | The agent asked a question, or ownership of a branch or change was ambiguous. | Read the last line of output and `ralph logs TASK-ID`. Resolve the question — usually by rewriting the task to answer it — then `ralph retry TASK-ID`. |
| Exit 130 | The run was interrupted. The work is preserved. | `ralph resume`. It continues from the phase the task stopped in and never repeats earlier phases. |
| Exit 124 | A configured limit stopped the run while tasks remained. | Nothing is wrong. Run `ralph run` again, or raise `limits.iterations` / `limits.run_timeout`. |
| A branch was refused as unrelated work | A branch with the name this pass would use already exists and is not recorded as the run's. | Rename or delete that branch, or change `git.branch_prefix`. Ralph PHP will not reset a branch it does not own. |
| A push was rejected, but the commit is there | Publication is recorded as outstanding and the local commit is kept. | Fix the remote side — usually a pull or a force-push decision of your own — then `ralph resume`, which retries only the push. |
| Checks pass in your checkout but fail in the worktree | A fresh worktree has your tracked files and nothing else: no `vendor/`, no `.env`, no build output. | Put the commands that make a worktree usable in `workspace.prepare` (`composer install`), and files no commit carries in `workspace.copy`. |
| The run pauses saying a task changed | The task file was edited while the run was working on it. | Leave `.ralph/tasks.md` alone during a run. Reconcile the edit, then run again. |
| A box is ticked but `ralph status` calls it yours, not the runner's | The box was ticked by hand or by an agent, with no verified commit behind it. | Nothing to fix, but nothing is vouched for either. Untick it to have the loop do the work and earn the tick. |

## Limitations

Worth knowing before you rely on it.

- **Review every commit.** Checks and commits are evidence for review, not proof
  that a natural-language requirement was satisfied. Nothing here merges
  anything.
- **One task at a time.** No parallel execution. Tasks are assumed independent:
  this release does not build one task on top of another's unmerged branch.
- **No merges, deployments or pull requests**, and no generating a task list from
  a product idea.
- **Agent CLIs move.** The invocations were exercised against Codex 0.4x and
  Claude Code 2.1.226. A newer CLI that renames a flag is a problem preflight
  will catch at the version floor, not one it can adapt to.
- **Claude Code attempts are not confined** to the workspace — see
  [Agent setup](#agent-setup).
- **Raw worker logs are not fully redactable**, as described above.
- **Weakened-verification detection is best-effort** and makes no completeness
  claim.
- **Linux and WSL are the tested platforms.** macOS is likely to work and is not
  verified; Windows outside WSL is not supported.
- **Without `pcntl`/`posix`, Ctrl+C handling is degraded.**
- **Agent cost is not metered** by this package.

## Why so few dependencies

Installing Ralph PHP pulls in one package, and that is on purpose. This section
is the reasoning, for anyone auditing the dependency tree or wondering why a
familiar library is missing.

The one package:

| Package | What it does here |
| --- | --- |
| [symfony/process](https://github.com/symfony/process) | Starts and supervises every command the loop runs — `git`, the coding agent, each verification command. The argument-array launch with no shell, both output pipes drained while a briefing is written to standard input, and the exit status read back. |

The rest the package does itself, and each of those is a decision rather than
something nobody got round to:

- **Git** is the `git` binary, run through the component above. The wrappers
  available for PHP (`czproject/git-php`, `gitonomy/gitlib`) model commits,
  branches and logs; what this loop needs is linked worktrees, `status
  --porcelain=v2`, a push whose rejection has to be told apart from a network
  failure, and branch ownership — none of which they expose, so using one would
  mean dropping to its escape hatch for most calls.
- **Stopping a worker** is this package's own (see [Recovery](#recovery)). A
  process library stops the process it started;
  an agent's test runner and language server are its children, and leaving
  those running in a task worktree is the failure the repository lock exists to
  make impossible. So the process tree is read from `/proc` and signalled
  deepest first here.
- **The task checklist** is parsed and edited by the package. A Markdown
  library (`league/commonmark`) would read it, but what is written back is one
  checkbox character in a file the user also edits by hand: every other byte,
  including the ones no parser preserves — trailing spaces, tab indentation,
  the exact blank lines — has to survive, and a concurrent edit has to be
  detected by comparing the source that was read. That is a byte-level job, not
  an abstract-syntax-tree one.
- **The command line** is the package's own small parser and help text. A
  console framework would replace them with its own conventions and its own
  error messages; `ralph` prefers to say `Unknown option "--jason" for "ralph
  status".` and name the command to run next, which is a difference you see on
  every mistake. The same goes for the colour of the output
  ([Colour](#colour)): the implementation is a handful of SGR codes and one
  per-stream decision, and `symfony/console` would earn its place as a *command
  framework* rather than as a replacement for that — which means taking its
  argument parsing and its help layout with it.
- **State** is SQLite through PDO, which is the maintained database layer PHP
  ships. There is no schema a query builder or an ORM would help with: eight
  tables, written by one process holding a lock.

## Development

```bash
composer install
composer test              # the whole suite
composer test:installation # install as a dev dependency and as a global tool
composer test:example      # the shipped example project, and a run inside a copy of it
vendor/bin/phpstan analyse src bin tests --level=9
```

PHPStan at level 9 currently reports 44 errors, all of one kind: casts of
values PDO types as `mixed` when a SQLite row is read, plus the same in the
tests that read one. They are known and tracked, and they are why CI runs the
test suite and `composer validate` rather than static analysis.

The suite needs no model credentials and no paid external service: agents are
replaced by a deterministic fixture worker with modes for success, failure,
blocking, slow execution and interruption, and Git behaviour is tested against
temporary repositories and a local bare remote. CI runs it on every supported
PHP version.

It does use Composer and a network in two places, both of which skip themselves
when Composer is absent: the installation group, which resolves the package's
runtime dependency the way a real `composer require` does, and the
example-project test that runs a task through the loop in a copy of
`examples/text-tools/` — that run installs PHPUnit into the task's worktree,
exactly as a reader's run would.

## License

MIT. See [LICENSE](LICENSE).

The loop is named after the Ralph technique, which is itself named after Ralph
Wiggum. The drawing at the top of this file is an original illustration in that
spirit; it is not artwork from the television series, and this package is not
affiliated with or endorsed by its rightsholders.
