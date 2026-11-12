# Example project

`text-tools/` is a small, working PHP package with three unfinished tasks in its
`.ralph/tasks.md`. It exists so that you can watch Ralph PHP do a complete pass
— worktree, agent, verification, commit, checkbox — on something real before you
point it at your own code.

It is a package, not a toy: two classes, two test files, `composer test`, and no
dependency beyond PHPUnit. A full install takes seconds, and its suite runs in
milliseconds, so a failed task fails for the reason the task failed.

## What you need

- PHP 8.3+ with `json`, `mbstring`, `pdo` and `pdo_sqlite`, Git 2.24+ and
  Composer — the same requirements as Ralph PHP itself.
- Ralph PHP on your PATH, or `vendor/bin/ralph` from a project installation.
- One coding agent CLI, signed in: `codex` or `claude`. Nothing here needs an
  API key of its own; the agent uses its own credentials.

A run calls a paid coding agent three times, once per task. Keep that in mind
before leaving it going.

## Run it

**1. Copy it out of this repository.** Ralph PHP works on *a* Git repository,
and the example has to be its own — a run inside this checkout would create
branches and worktrees in Ralph PHP's repository instead.

```bash
cp -r examples/text-tools /tmp/text-tools
cd /tmp/text-tools
git init
git add --all
git commit -m "Import the example package"
```

**2. Install the package.** This is for *your* checkout; each task's worktree
gets its own install from `workspace.prepare`.

```bash
composer install
composer test        # OK (12 tests, 13 assertions)
```

**3. Initialise.** The example already ships `.ralph/config.json`,
`.ralph/tasks.md` and `.ralph/CONTEXT.md`, so `init` keeps all three — it says
so — and adds the runtime directories and the ignore rules:

```bash
ralph init
```

```
  kept     .ralph/config.json
  kept     .ralph/tasks.md
  kept     .ralph/CONTEXT.md
  created  .ralph/runtime/
  …
  extended .gitignore
```

Commit that `.gitignore`, because `init` just changed your checkout and the next
step will not touch a change it did not write:

```bash
git commit -am "Ignore Ralph PHP runtime data"
```

Skip it and `ralph run` refuses to start, naming the file — it will not stash,
discard or commit work of yours, and it cannot tell the rules it just wrote from
anything else you had in flight.

**4. Check the machine, and the work.**

```bash
ralph preflight      # PHP, extensions, Git, the base commit, the agent, the checks
ralph status
```

```
  checklist      .ralph/tasks.md — 3 tasks, 0 checked, 3 pending
  agent          codex (project default)
  progress       0 of 3 completed, 3 remaining

Tasks
  state        id             attempts  commit        task
  pending      TASK-44AEB58A  -         -             Add a maximum length to the slug generator
  pending      TASK-FC1A4E61  -         -             Make Excerpt cut on a word boundary instead o…
  pending      TASK-31D64F84  -         -             Add a ReadingTime class that estimates how lo…
```

`preflight` fails on the `agent` check if the configured CLI is not installed;
it names the setting to change. To use Claude Code instead of Codex, either run
`ralph run --agent claude` or set `"agent": "claude"` in `.ralph/config.json`.

**5. Run.**

```bash
ralph run
```

Each task is done in its own linked worktree under `.ralph/runtime/worktrees/`,
on its own branch off `HEAD`. The agent's output is streamed as it arrives and
kept in `.ralph/runtime/logs/TASK-ID/`.

## What you should see afterwards

```bash
ralph status                 # three completed tasks, with their branches and commits
git branch --list 'ralph/*'  # one branch per task
git show ralph/TASK-44AEB58A-add-a-maximum-length-to-the-slug-generator
git diff -- .ralph/tasks.md  # the three checkboxes the runner ticked
ralph logs TASK-44AEB58A     # the agent's output and the verification output
```

Three things are worth looking at specifically:

- **The branches are separate and nothing is merged.** Reviewing and merging is
  yours to do; this release does not do either.
- **The ticks are in your checkout, uncommitted.** Ralph PHP writes the
  checkbox of a task that passed and commits nothing in your own checkout. (The
  example tracks `.ralph/tasks.md` on purpose, with a negated ignore rule, so
  that `git diff` shows them.)
- **A task that failed verification is not ticked**, has its attempts counted in
  `ralph status`, and keeps its worktree for you to look at.

## Starting again

Throw the copy away and start from step 1. Within one copy:

```bash
git checkout -- .ralph/tasks.md                     # untick the boxes
# drop the task branches:
git branch --list 'ralph/*' --format='%(refname:short)' | xargs -r git branch -D
rm -rf .ralph/runtime                               # drop state, logs and worktrees
git worktree prune
```

`rm -rf .ralph/runtime` deletes the task history `ralph status` reads, which is
the point here and is not what you want in a real project.

## The three tasks

| Task | What it changes |
| --- | --- |
| Add a maximum length to the slug generator | `src/Slugger.php`, `tests/SluggerTest.php` |
| Make Excerpt cut on a word boundary instead of mid-word | `src/Excerpt.php`, `tests/ExcerptTest.php` |
| Add a ReadingTime class that estimates how long a text takes to read | new `src/ReadingTime.php`, new test |

Each is small, verifiable by `composer test` alone, and touches its own files —
which is what this release needs, because every branch starts from the base
commit and no task can build on another's unmerged work.

They are also written the way the README asks you to write a task: one change
per item, with the acceptance criteria as indented bullets under it. Read
`.ralph/tasks.md` beside the result of a run; where an agent went somewhere you
did not intend, the criteria are usually what was missing.

## What this example does not show

- **It is not a demonstration of a specific agent's quality.** What a run
  produces depends on the agent, its model and its version.
- **Nothing here is pushed.** `git.push.enabled` is `false`, because a directory
  you copied a minute ago has no remote.
- **The tasks are deliberately easy.** They were chosen so that a failure is
  likely to be a real failure of the loop — a worktree, a check, a commit — and
  not an agent struggling with a hard problem.
