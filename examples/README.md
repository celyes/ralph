# Example project

`text-tools/` is a small, working PHP package with three unfinished tasks in its
`.ralph/tasks.md`. It exists so that you can watch Ralph PHP do a complete pass
— agent, verification, checkbox — on something real before you point it at your
own code.

It is a package, not a toy: two classes, two test files, `composer test`, and no
dependency beyond PHPUnit. A full install takes seconds, and its suite runs in
milliseconds, so a failed task fails for the reason the task failed.

## What you need

- PHP 8.3+ with `json`, `mbstring`, `pdo` and `pdo_sqlite`, and Composer — the
  same requirements as Ralph PHP itself.
- Ralph PHP on your PATH, or `vendor/bin/ralph` from a project installation.
- One coding agent CLI, signed in: `codex` or `claude`. Nothing here needs an
  API key of its own; the agent uses its own credentials.
- Git, to read what the run did. Ralph PHP does not run it; you will want it.

A run calls a paid coding agent three times, once per task. Keep that in mind
before leaving it going.

## Run it

**1. Copy it out of this repository.** The agent edits the project it is pointed
at, so pointing it at `examples/text-tools/` in place would have it edit Ralph
PHP's own repository. The Git repository in step 1 is for your benefit, not the
package's: it is what makes the run's changes readable afterwards.

```bash
cp -r examples/text-tools /tmp/text-tools
cd /tmp/text-tools
git init
git add --all
git commit -m "Import the example package"
```

**2. Install the package.** The checks run in this directory, so the project has
to be installed before a run can verify anything.

```bash
composer install
composer test        # OK (16 tests, 17 assertions)
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

The `.gitignore` change is yours to commit or leave; nothing refuses to start
over it.

**4. Check the machine, and the work.**

```bash
ralph preflight      # PHP, extensions, the agent, the checks
ralph status
```

```
  checklist      .ralph/tasks.md — 3 tasks, 0 checked, 3 pending
  agent          codex (project default)
  progress       0 of 3 completed, 3 remaining

Tasks
  state        id             attempts  task
  pending      TASK-44AEB58A  -         Add a maximum length to the slug generator
  pending      TASK-FC1A4E61  -         Make Excerpt cut on a word boundary instead o…
  pending      TASK-31D64F84  -         Add a ReadingTime class that estimates how lo…
```

`preflight` fails on the `agent` check if the configured CLI is not installed;
it names the setting to change. To use Claude Code instead of Codex, either run
`ralph run --agent claude` or set `"agent": "claude"` in `.ralph/config.json`.

**5. Run.**

```bash
ralph run
```

All three tasks are worked on in this directory, in checklist order, and nothing
is committed: what each task changed is left in the working tree for the next
one — and for you — to see. The agent's output is streamed as it arrives and
kept in `.ralph/runtime/logs/TASK-ID/`.

## What you should see afterwards

```bash
ralph status                 # three completed tasks, with their attempts
git status --short           # the three tasks' changes, and the ticked boxes
git diff                     # everything the run changed
git diff -- .ralph/tasks.md  # the three checkboxes the runner ticked
ralph logs TASK-44AEB58A     # the agent's output and the verification output
```

Three things are worth looking at specifically:

- **Nothing was committed and nothing was branched.** `git log` is where you
  left it, and the whole run is one diff for you to read, split up and commit as
  you see fit.
- **The ticks are in the file you own.** Ralph PHP writes the checkbox of a task
  that passed and nothing else. (The example tracks `.ralph/tasks.md` on
  purpose, with a negated ignore rule, so that `git diff` shows them.)
- **A task that failed verification is not ticked**, has its attempts counted in
  `ralph status`, and leaves whatever the agent wrote in place for you to look
  at.

## Starting again

Throw the copy away and start from step 1. Within one copy:

```bash
git checkout -- .            # discard the run's work, boxes included
git clean -fd                # and whatever it added
rm -rf .ralph/runtime        # drop state, logs and memory
```

`git checkout -- .` is the only undo there is, and it takes the run's work with
it. `rm -rf .ralph/runtime` deletes the task history `ralph status` reads, which
is the point here and is not what you want in a real project.

## The three tasks

| Task | What it changes |
| --- | --- |
| Add a maximum length to the slug generator | `src/Slugger.php`, `tests/SluggerTest.php` |
| Make Excerpt cut on a word boundary instead of mid-word | `src/Excerpt.php`, `tests/ExcerptTest.php` |
| Add a ReadingTime class that estimates how long a text takes to read | new `src/ReadingTime.php`, new test |

Each is small, verifiable by `composer test` alone, and touches its own files —
which is what keeps three tasks in one working tree readable afterwards. They
run in order, so a later task *could* build on an earlier one.

They are also written the way the README asks you to write a task: one change
per item, with the acceptance criteria as indented bullets under it. Read
`.ralph/tasks.md` beside the result of a run; where an agent went somewhere you
did not intend, the criteria are usually what was missing.

## What this example does not show

- **It is not a demonstration of a specific agent's quality.** What a run
  produces depends on the agent, its model and its version.
- **Nothing here is committed or pushed.** Reviewing and committing is the
  reader's half of the loop.
- **The tasks are deliberately easy.** They were chosen so that a failure is
  likely to be a real failure of the loop — an agent invocation, a check, a
  checkbox — and not an agent struggling with a hard problem.
