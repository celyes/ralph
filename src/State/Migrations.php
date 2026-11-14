<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

/**
 * The schema, as the ordered list of steps that build it.
 *
 * There is no separate "current schema" definition to keep in step with the
 * migrations: a fresh database is a database with every migration applied, so
 * the two cannot disagree. Version 1 is the whole of the first release's schema
 * in one step; the versions after it are the additions made while the first
 * release was being built, each one appended rather than folded back into
 * version 1, so that a project already carrying runtime state is upgraded
 * instead of being left with a database whose recorded version promises columns
 * it has not got.
 *
 * Conventions the tables follow:
 *
 * - **Identity.** Every table has an integer primary key for foreign keys to
 *   point at, and the identifier a human sees — `RUN-…`, `TASK-…`,
 *   `TASK-…-g2` — is a separate unique column. Rows are related by the integer;
 *   a user is only ever shown the text.
 * - **Timestamps** are ISO-8601 in UTC, stored as text. SQLite has no date
 *   type, and text that sorts chronologically and reads correctly in `sqlite3`
 *   is worth more here than an integer that needs a timezone to interpret.
 * - **Booleans** are `0`/`1` integers with a `CHECK`, which is as close to a
 *   boolean as SQLite offers.
 * - **Structured values** — argument arrays, effective configuration, agent
 *   settings — are JSON text. They are written and read whole and never
 *   queried into, so a table of key/value rows would buy nothing. None of them
 *   may contain a credential: agent definitions carry variable *names*.
 * - **Vocabularies** come from {@see Schema}, rendered into `CHECK`
 *   constraints, so the database refuses a state or a phase the package does
 *   not know.
 * - **`ON DELETE CASCADE`** from a task down through its generations, attempts
 *   and verification results. Deleting a task is not something the runner does,
 *   but when a user clears one out by hand the evidence must not be left
 *   pointing at nothing.
 *
 * Two invariants are enforced by partial unique indexes rather than by
 * application code, because they are the ones whose violation destroys work:
 *
 * - **One branch, one generation — unless the branch is the run's.** A branch
 *   a pass owns alone appears on at most one generation, which is the storage
 *   half of the promise that reopening a finished task gets a separately owned
 *   branch and can never reset the branch the earlier pass left behind. A
 *   shared branch is marked as such on the row and excluded from the index,
 *   because every task of a shared-branch run commits to it
 *   ({@see self::sharedBranchPasses()}).
 * - **One open pass per task.** A task has at most one generation in a state it
 *   can be continued from. This is the invariant `GenerationHistory` refuses to
 *   guess at; if two rows could claim to be the current pass, a run would have
 *   to choose which branch to write to.
 *
 * Tables are not declared `STRICT`. That keyword needs SQLite 3.37, and the
 * package supports whatever libsqlite the user's PHP was built against;
 * affinities plus the `CHECK` constraints above cover what matters without
 * ruling out an older build.
 */
final class Migrations
{
    /**
     * Every migration, oldest first.
     *
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [self::initial(), self::stopAction(), self::sharedBranch(), self::sharedBranchPasses()];
    }

    /**
     * The newest version this build knows how to produce.
     */
    public static function latestVersion(): int
    {
        $versions = array_map(
            static fn (Migration $migration): int => $migration->version(),
            self::all(),
        );

        return $versions === [] ? 0 : max($versions);
    }

    /**
     * Version 1: runs, tasks, execution generations, attempts, verification
     * results, Git references and checklist synchronisation.
     */
    private static function initial(): Migration
    {
        return new Migration(1, 'initial schema', [
            self::runs(),
            self::tasks(),
            self::generations(),
            self::attempts(),
            self::verificationResults(),
            self::gitReferences(),
            self::checklistSnapshots(),
            self::checklistUpdates(),
            ...self::indexes(),
        ]);
    }

    /**
     * Version 2: what the user is expected to do about a run that stopped.
     *
     * `stop_reason` has always said why a run ended. PROJECT.md section H,
     * item 9 asks for the other half — the next action — and a column is where
     * it has to live, because the user who needs it is usually not the one
     * watching the terminal at the time: they are reading `ralph status` the
     * next morning. {@see \Celyes\RalphPhp\Run\RunStop} writes both together.
     *
     * Nullable, and no backfill. A run recorded by an earlier build stopped for
     * a reason nobody wrote an action for, and inventing one now would be
     * putting words into a finished run's mouth.
     */
    private static function stopAction(): Migration
    {
        return new Migration(2, 'the next action for a stopped run', [
            'ALTER TABLE runs ADD COLUMN stop_action TEXT',
            sprintf(
                'ALTER TABLE runs ADD COLUMN stop_kind TEXT CHECK (stop_kind IN (%s))',
                Schema::literals(Schema::stopKinds()),
            ),
        ]);
    }

    /**
     * Version 3: the branch a shared-branch run established.
     *
     * PROJECT.md section K, item 3 asks for the shared branch to be created or
     * validated once per run and for its identity to be persisted, so that a
     * resume continues on the same branch. The identity is a column and not a
     * derivation for the reason every other frozen setting is a column: the
     * name a shared-branch run commits to comes from `git.shared_branch` or
     * from `git.branch_prefix`, both of which a user may edit between an
     * interrupted run and the resume that finishes it, and a resume that
     * recomputed the name would continue on a second branch while the first one
     * still held the run's commits.
     *
     * Nullable, and no backfill, for the two cases that are genuinely empty: a
     * per-task run commits to no one branch, and a run recorded by an earlier
     * build established nothing. {@see RunRecord::sharedBranch()} falls back to
     * what such a run's own configuration snapshot names, which is the honest
     * answer for a row that predates the column.
     *
     * The `generations_branch` unique index was left as it was by this
     * migration, because at the time only the pass that created the shared
     * branch could record it; {@see self::sharedBranchPasses()} is where the
     * carry-forward of section K, item 4 relaxes it, and why.
     */
    private static function sharedBranch(): Migration
    {
        return new Migration(3, 'the branch a shared-branch run established', [
            'ALTER TABLE runs ADD COLUMN shared_branch TEXT',
        ]);
    }

    /**
     * Version 4: several passes of one run on one shared branch.
     *
     * PROJECT.md section K, item 4 asks for a shared-branch run's tasks to
     * carry each other's work forward while every task keeps a commit of its
     * own. That makes the `generations_branch` unique index false: the branch
     * is the *run's*, and each task of the run records it as the branch holding
     * its commit.
     *
     * The index is not simply dropped, because the invariant it enforces is
     * still the thing that stops a reopened task from resetting the branch an
     * earlier pass left behind in per-task mode (section F, item 4). So the row
     * says which kind of branch it holds, and the index excludes the shared
     * ones:
     *
     *  - `branch_shared = 0` — a branch this pass owns alone, which is every
     *    per-task branch. Still unique, still refused to a second pass.
     *  - `branch_shared = 1` — the branch the run established, recorded by
     *    every pass that commits to it. Shared by construction, so uniqueness
     *    would be the wrong question.
     *
     * The default is 0 and there is no backfill, which is the honest reading of
     * every row written before this: a pass recorded by an earlier build either
     * owned a per-task branch or was the only pass that could record the shared
     * one, and in both cases the branch was its alone.
     *
     * SQLite cannot add a condition to an existing index, so the index is
     * dropped and recreated. Both statements run inside the migration's
     * transaction ({@see Migrator}), so a failure leaves the old index in
     * place rather than the table with none.
     */
    private static function sharedBranchPasses(): Migration
    {
        return new Migration(4, 'several passes of a run on one shared branch', [
            'ALTER TABLE generations ADD COLUMN branch_shared INTEGER NOT NULL DEFAULT 0 '
                . 'CHECK (branch_shared IN (0, 1))',
            'DROP INDEX generations_branch',
            'CREATE UNIQUE INDEX generations_branch
                ON generations (branch) WHERE branch IS NOT NULL AND branch_shared = 0',
        ]);
    }

    /**
     * One pass of the loop over the checklist.
     *
     * The agent and the effective configuration are stored on the run and not
     * read from the file again, because that is what makes resume honest: it
     * continues with the agent and the checks the work was started under, and
     * an edit to `config.json` midway cannot quietly replace the required
     * verification. `checks` is kept apart from `configuration` even though it
     * is contained in it, so the frozen check list can be read back without
     * depending on the shape of the rest.
     *
     * `host` and `pid` are written for the benefit of the repository lock: the
     * question resume has to answer is not only "was a run interrupted" but
     * "has its worker actually stopped".
     */
    private static function runs(): string
    {
        return sprintf(
            <<<'SQL'
            CREATE TABLE runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id TEXT NOT NULL UNIQUE,
                state TEXT NOT NULL CHECK (state IN (%s)),
                stop_reason TEXT,
                agent TEXT NOT NULL,
                adapter TEXT NOT NULL,
                agent_source TEXT NOT NULL CHECK (agent_source IN (%s)),
                agent_settings TEXT NOT NULL,
                configuration TEXT NOT NULL,
                checks TEXT NOT NULL,
                tasks_path TEXT NOT NULL,
                base_reference TEXT NOT NULL,
                base_sha TEXT,
                host TEXT,
                pid INTEGER,
                resumed_from INTEGER REFERENCES runs (id) ON DELETE SET NULL,
                iterations_used INTEGER NOT NULL DEFAULT 0 CHECK (iterations_used >= 0),
                active_seconds INTEGER NOT NULL DEFAULT 0 CHECK (active_seconds >= 0),
                started_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                finished_at TEXT
            )
            SQL,
            Schema::literals(Schema::RUN_STATES),
            Schema::literals(Schema::agentSources()),
        );
    }

    /**
     * A task the checklist has stated at least once.
     *
     * Identity is the task's own: `task_id` comes from the normalised body and
     * from nothing else, so these rows survive the file being reordered,
     * renamed or partly ticked. `content_hash` is what the task said when it
     * was last read, which is how an edit to a task's details is noticed;
     * `completion` is the checklist-side verdict, and the distinction it
     * carries — a box the user ticked against one the runner earned — is the
     * reason it is a column rather than a boolean.
     *
     * There is deliberately no lifecycle state here: see {@see LifecycleState}.
     */
    private static function tasks(): string
    {
        return sprintf(
            <<<'SQL'
            CREATE TABLE tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id TEXT NOT NULL UNIQUE,
                description TEXT NOT NULL,
                details TEXT NOT NULL DEFAULT '',
                content_hash TEXT NOT NULL,
                completion TEXT NOT NULL CHECK (completion IN (%s)),
                first_seen_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL,
            Schema::literals(Schema::completions()),
        );
    }

    /**
     * One execution generation: the unit of work a branch belongs to.
     *
     * A retry is another attempt at the same generation, on the same branch;
     * reopening a finished task starts a new generation with a branch of its
     * own. The columns here are what that ownership consists of — the base
     * commit the branch started from, the branch, the commit it produced, the
     * worktree and whether this generation created it, and how far publication
     * got.
     *
     * `commit_sha` is null both for a pass that never committed and for one
     * whose task was already satisfied; `no_change` is what tells those apart,
     * since a verified no-change outcome completes a task without an empty
     * commit. `attempts` is a counter on the generation rather than a count of
     * rows in `attempts`, so that it survives a crash between an attempt
     * starting and its row being written and a task cannot escape its attempt
     * limit that way.
     */
    private static function generations(): string
    {
        return sprintf(
            <<<'SQL'
            CREATE TABLE generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id INTEGER NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
                number INTEGER NOT NULL CHECK (number >= 1),
                slug TEXT NOT NULL UNIQUE,
                run_id INTEGER REFERENCES runs (id) ON DELETE SET NULL,
                state TEXT NOT NULL CHECK (state IN (%s)),
                phase TEXT CHECK (phase IS NULL OR phase IN (%s)),
                attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                content_hash TEXT NOT NULL,
                agent TEXT NOT NULL,
                adapter TEXT NOT NULL,
                agent_settings TEXT NOT NULL,
                base_reference TEXT NOT NULL,
                base_sha TEXT NOT NULL,
                branch TEXT,
                commit_sha TEXT,
                no_change INTEGER NOT NULL DEFAULT 0 CHECK (no_change IN (0, 1)),
                push_state TEXT NOT NULL DEFAULT 'not_required' CHECK (push_state IN (%s)),
                push_remote TEXT,
                push_error TEXT,
                worktree_path TEXT,
                owns_worktree INTEGER NOT NULL DEFAULT 0 CHECK (owns_worktree IN (0, 1)),
                blocker TEXT,
                started_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                finished_at TEXT,
                UNIQUE (task_id, number)
            )
            SQL,
            Schema::literals(Schema::states()),
            Schema::literals(Schema::phases()),
            Schema::literals(Schema::pushStates()),
        );
    }

    /**
     * One invocation of the agent, with what came back.
     *
     * Numbered within its generation, so attempt 2 of generation 2 is a second
     * try at reopened work and not the fourth row of a flat list. The agent is
     * recorded again rather than only on the run, because `ralph logs` has to
     * be able to say which agent produced a given attempt long after the
     * project's default has changed.
     *
     * `summary` and `failure_evidence` hold what is fed into the next attempt.
     * They are the agent's own words, and storing them says nothing about
     * whether the work passed: that verdict is `state` and the rows in
     * `verification_results`, which the runner writes.
     */
    private static function attempts(): string
    {
        return sprintf(
            <<<'SQL'
            CREATE TABLE attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                generation_id INTEGER NOT NULL REFERENCES generations (id) ON DELETE CASCADE,
                run_id INTEGER NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
                number INTEGER NOT NULL CHECK (number >= 1),
                state TEXT NOT NULL CHECK (state IN (%s)),
                phase TEXT CHECK (phase IS NULL OR phase IN (%s)),
                agent TEXT NOT NULL,
                adapter TEXT NOT NULL,
                agent_settings TEXT NOT NULL,
                outcome TEXT CHECK (outcome IS NULL OR outcome IN (%s)),
                exit_code INTEGER,
                duration_ms INTEGER CHECK (duration_ms IS NULL OR duration_ms >= 0),
                summary TEXT,
                failure_evidence TEXT,
                blocker TEXT,
                log_path TEXT,
                started_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                finished_at TEXT,
                UNIQUE (generation_id, number)
            )
            SQL,
            Schema::literals(Schema::states()),
            Schema::literals(Schema::phases()),
            Schema::literals(Schema::attemptOutcomes()),
        );
    }

    /**
     * One configured verification command, as it actually ran.
     *
     * The evidence a checked box rests on, so everything needed to defend it is
     * here: the command as an argument array, the directory it ran in, its exit
     * code, how long it took, the timeout it was held to, and an excerpt of its
     * output. The full output stays in the log file — a verification log can be
     * megabytes, and the database is read on every status call.
     *
     * `position` is the order within the attempt, which matters because the
     * checks are a sequence: it is what distinguishes "check 3 failed" from
     * "check 3 never ran because check 2 failed".
     */
    private static function verificationResults(): string
    {
        return sprintf(
            <<<'SQL'
            CREATE TABLE verification_results (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_id INTEGER NOT NULL REFERENCES attempts (id) ON DELETE CASCADE,
                position INTEGER NOT NULL CHECK (position >= 1),
                name TEXT NOT NULL,
                command TEXT NOT NULL,
                working_directory TEXT,
                outcome TEXT NOT NULL CHECK (outcome IN (%s)),
                exit_code INTEGER,
                duration_ms INTEGER CHECK (duration_ms IS NULL OR duration_ms >= 0),
                timeout_seconds INTEGER NOT NULL CHECK (timeout_seconds > 0),
                output_excerpt TEXT,
                log_path TEXT,
                started_at TEXT,
                finished_at TEXT,
                UNIQUE (attempt_id, position)
            )
            SQL,
            Schema::literals(Schema::checkOutcomes()),
        );
    }

    /**
     * Append-only log of what the runner did to the repository.
     *
     * The generation's own columns say what is true now; this says in what
     * order it became true, and that is what recovery needs. The case the
     * design calls out is a crash immediately after a commit but before the
     * database update: a row written here before the commit is attempted turns
     * "there is a commit on this branch I have no record of" into "I was about
     * to commit, so check whether I did".
     *
     * It also records references the runner refused — a branch owned by
     * unrelated work, a rejected push — which is evidence a user needs and
     * which no current-state column has anywhere to keep.
     */
    private static function gitReferences(): string
    {
        return sprintf(
            <<<'SQL'
            CREATE TABLE git_references (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                generation_id INTEGER NOT NULL REFERENCES generations (id) ON DELETE CASCADE,
                attempt_id INTEGER REFERENCES attempts (id) ON DELETE SET NULL,
                kind TEXT NOT NULL CHECK (kind IN (%s)),
                name TEXT,
                sha TEXT,
                remote TEXT,
                outcome TEXT NOT NULL CHECK (outcome IN (%s)),
                detail TEXT,
                recorded_at TEXT NOT NULL
            )
            SQL,
            Schema::literals(Schema::GIT_REFERENCE_KINDS),
            Schema::literals(Schema::GIT_REFERENCE_OUTCOMES),
        );
    }

    /**
     * What the checklist said when a run started.
     *
     * One row per run per file. Its purpose is comparison: a task edited or
     * removed while it was being worked on has to pause for reconciliation
     * rather than have old progress applied to changed work, and that judgement
     * needs the earlier reading to compare against.
     */
    private static function checklistSnapshots(): string
    {
        return <<<'SQL'
            CREATE TABLE checklist_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id INTEGER NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
                path TEXT NOT NULL,
                content_hash TEXT NOT NULL,
                task_count INTEGER NOT NULL CHECK (task_count >= 0),
                pending_count INTEGER NOT NULL CHECK (pending_count >= 0),
                taken_at TEXT NOT NULL
            )
            SQL;
    }

    /**
     * A checkbox the runner intends to change, and whether it has.
     *
     * The row exists before the file is touched, which is what makes a failed
     * checkbox write survivable: the implementation and the commit are already
     * safe, the task is left in pending finalisation, and resume has only the
     * file to write — it does not re-run the agent or commit a second time.
     *
     * `expected_hash` is the file's content when the write was planned and
     * `task_hash` is the task's. Both are compared before writing, so a
     * concurrent edit is detected and preserved rather than overwritten.
     */
    private static function checklistUpdates(): string
    {
        return sprintf(
            <<<'SQL'
            CREATE TABLE checklist_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                generation_id INTEGER NOT NULL REFERENCES generations (id) ON DELETE CASCADE,
                run_id INTEGER NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
                path TEXT NOT NULL,
                marker TEXT NOT NULL CHECK (marker IN (%s)),
                state TEXT NOT NULL CHECK (state IN (%s)),
                expected_hash TEXT NOT NULL,
                task_hash TEXT NOT NULL,
                resulting_hash TEXT,
                attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                applied_at TEXT
            )
            SQL,
            Schema::literals(Schema::checklistMarkers()),
            Schema::literals(Schema::checklistUpdateStates()),
        );
    }

    /**
     * The indexes, including the two that enforce ownership invariants.
     *
     * @return list<string>
     */
    private static function indexes(): array
    {
        return [
            // A branch belongs to exactly one generation. Partial, because a
            // generation that has not been given a branch yet records null, and
            // several of those are ordinary.
            'CREATE UNIQUE INDEX generations_branch
                ON generations (branch) WHERE branch IS NOT NULL',

            // A task has at most one pass that can still be continued. The
            // states listed are Schema::closedStates(); everything else is open.
            sprintf(
                'CREATE UNIQUE INDEX generations_open_per_task
                    ON generations (task_id) WHERE state NOT IN (%s)',
                Schema::literals(Schema::closedStates()),
            ),

            'CREATE INDEX generations_run ON generations (run_id)',
            'CREATE INDEX generations_state ON generations (state)',
            'CREATE INDEX attempts_generation ON attempts (generation_id)',
            'CREATE INDEX attempts_run ON attempts (run_id)',
            'CREATE INDEX verification_results_attempt ON verification_results (attempt_id)',
            'CREATE INDEX git_references_generation ON git_references (generation_id, kind)',
            'CREATE INDEX checklist_snapshots_run ON checklist_snapshots (run_id)',
            'CREATE INDEX checklist_updates_generation ON checklist_updates (generation_id)',

            // Resume looks for exactly this: writes that were planned and never
            // landed.
            "CREATE INDEX checklist_updates_pending
                ON checklist_updates (state) WHERE state = 'pending'",
        ];
    }

    private function __construct()
    {
    }
}
