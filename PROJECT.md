# PRD: Ralph PHP

Version: 0.1 draft, revision 4  
Date: 26 October 2026  
Owner: Ilyes Chouia  
Product name: Ralph PHP

## 1. What we are building

Ralph PHP is a standalone Composer package that implements a Ralph-style development loop in PHP.

The package reads development tasks from a Markdown checklist, passes the next task and relevant memory to the selected coding agent, runs verification commands, commits successful changes, records progress, and repeats. Codex and Claude Code are supported through separate adapters. It must stop when work is complete, a limit is reached, or human input is required.

The deliverable is an installable PHP package with a command-line executable, working source code, tests, and documentation.

## 2. First-release scope

- Install as a Composer development dependency or global tool.
- Work with an existing local Git repository.
- Read pending work from a Markdown checklist.
- Execute one task at a time.
- Let users select Codex or Claude Code through project configuration or a command-line option, and allow additional agents through the adapter interface.
- Maintain project context, attempt history, and progress between runs.
- Create a branch for each task and commit verified changes.
- Push branches automatically when explicitly enabled in configuration.
- Check off completed tasks.
- Provide commands to initialize, run, inspect, resume, and retry work.
- Enforce iteration, attempt, and execution-time limits.

Version 0.1 does not include parallel execution, automatic merges, deployments, pull-request creation, or generating an entire task list from a product idea.

Initial support target: PHP 8.3+, Linux, and WSL. Declare and verify exact PHP extensions, Composer dependencies, and Git requirements during implementation. Laravel must not be required to install or run the package.

Task branches start from the configured base commit. The first release assumes tasks can be implemented independently; dependent tasks requiring unmerged changes are outside this release.

## 3. Proposed command interface

These names describe commands to implement, not an already published package.

| Command | Expected behavior |
| --- | --- |
| `vendor/bin/ralph init` | Create project configuration, task checklist, context file, and ignore rules. |
| `vendor/bin/ralph run` | Process pending tasks within configured limits. |
| `vendor/bin/ralph run --agent codex` | Process pending tasks using the Codex adapter. |
| `vendor/bin/ralph run --agent claude` | Process pending tasks using the Claude Code adapter. |
| `vendor/bin/ralph status` | Display tasks, generated IDs, state, attempts, branches, and commit references. |
| `vendor/bin/ralph logs TASK-ID` | Display the selected task's execution and verification logs. |
| `vendor/bin/ralph resume` | Reconcile an interrupted run and continue from its recorded phase. |
| `vendor/bin/ralph retry TASK-ID` | Retry a failed or blocked task while preserving its history. |

## 4. Core behavior

The PHP runner owns task selection, lifecycle state, Git actions, verification, and completion. The selected agent performs implementation through its execution adapter. Agent-specific command construction, prompt handling, output interpretation, and cancellation belong inside the adapter, keeping the loop independent of a particular agent.

### Agent selection and configuration

Ship built-in adapters named `codex` and `claude`. The project's `agent` setting selects the default; `run --agent NAME` overrides it for that run. Agent definitions configure the executable path, optional model, extra argument arrays, and names of environment variables needed for authentication. Credentials remain outside committed configuration.

For example, the agent-selection portion of project configuration can be:

```json
{
  "agent": "codex",
  "agents": {
    "codex": {"adapter": "codex", "executable": "codex"},
    "claude": {"adapter": "claude", "executable": "claude"}
  }
}
```

These are Ralph PHP configuration fields. Each adapter must implement and document the actual invocation supported by its tested agent CLI version; do not assume different agents accept the same native flags or emit the same result format.

Register additional adapters through a documented extension point without modifying the runner. Preserve the common request/result contract across adapters. A missing executable, unsupported adapter, incompatible CLI version, or unavailable authentication must produce a clear error. Do not silently switch to another agent.

Select one agent per run. Persist the resolved agent and non-secret settings with the run and attempts; show them in status and logs. Resume uses that recorded agent and configuration. Changing agents requires an explicit new run after reconciling interrupted work; pending tasks remain available and verified task history is preserved. Agent selection does not enable simultaneous or parallel execution.

A zero exit code from the worker is not sufficient to complete a task. Configured verification commands must pass, the package must record a commit or a justified no-change result, and any required push must succeed. Only then does the package mark the item checked.

Use Markdown for task descriptions and human-readable context. Use SQLite for execution state and recovery records. Suggested project files:

| Path | Purpose |
| --- | --- |
| `.ralph/config.json` | Default agent, adapter settings, checks, base reference, Git options, and limits. |
| `.ralph/tasks.md` | Tasks as unchecked or checked Markdown list items. |
| `.ralph/CONTEXT.md` | User-maintained project instructions and conventions. |
| `.ralph/runtime/state.sqlite` | Task, attempt, Git, and recovery records. |
| `.ralph/runtime/memory/` | Summaries and observations supplied to later attempts. |
| `.ralph/runtime/logs/` | Worker and verification output. |
| `.ralph/runtime/worktrees/` | Isolated task workspaces. |

Ignore runtime files and the operational task checklist in Git by default. Users can explicitly track the checklist; the runner must distinguish its own checkbox changes from unrelated edits. It must never commit the controlling checkout's progress edits automatically.

## 5. Actual implementation tasks

The checkboxes below are the work required to BUILD THE PHP PACKAGE. They are the delegation checklist. Implement them in section order and integrate each section before starting work that depends on it. Mark an item complete only when its behavior works and the relevant checks pass.

### A. Package foundation

- [x] Create the Ralph PHP repository and `composer.json` with its description, PHP requirement, license metadata, development dependencies, and the `ralph-php` package name under the publisher's Composer vendor namespace.
- [x] Configure PSR-4 autoloading for package classes and development autoloading for tests.
- [x] Create `bin/ralph`, register it as a Composer binary, and make it executable.
- [x] Add the CLI application bootstrap with help output, version output, and readable handling of exceptions.
- [x] Set up a PHP test runner and a minimal test proving that the CLI can start successfully.
- [x] Verify installation as both a project development dependency and a global Composer tool.

### B. Configuration and initialization

- [x] Implement configuration loading for the task-file path, context-file path, default agent, per-agent adapter settings, verification commands, base Git reference, branch prefix, push settings, and execution limits.
- [x] Resolve agent selection from the project default or `run --agent NAME`; validate adapter names and preserve the selected non-secret settings for the run.
- [x] Validate configuration types and values; reject invalid paths, empty commands, nonpositive limits, and missing required settings before starting work.
- [x] Implement `ralph init` to create the default configuration, an empty task checklist with usage instructions, the context file, and runtime directories.
- [x] Make initialization preserve existing user files and require an explicit option before overwriting them.
- [x] Add ignore rules for runtime data and the local task checklist without deleting or replacing the user's existing ignore rules.
- [x] Implement preflight checks for the declared PHP extensions, Git, repository location, base reference, Git author identity, executor availability, and a usable verification command.

### C. Markdown task checklist

- [x] Implement parsing of top-level `- [ ]` pending tasks and `- [x]` or `- [X]` checked tasks from `.ralph/tasks.md`.
- [x] Attach indented ordinary bullets and paragraphs to the preceding task as optional implementation details or acceptance criteria.
- [x] Ignore headings, unrelated prose, and checklist-like text inside fenced code blocks; reject empty descriptions and unsupported nested checkable subtasks with readable errors.
- [x] Generate stable internal task IDs from normalized task content, excluding checkbox state and file position; reject duplicate normalized task bodies.
- [x] Select pending tasks in file order and skip checked items; label imported checked items as user-completed rather than runner-verified.
- [x] Implement checkbox updates that change only the selected task's marker and preserve all unrelated text and formatting.
- [x] Detect task edits or removal during active execution and pause for reconciliation instead of silently applying old progress to changed work.
- [x] Handle reopening a previously completed item by preserving its history and creating a new execution generation with a separately owned branch.

### D. State and memory

- [x] Create the SQLite schema and migration/bootstrap logic for runs, tasks, execution generations, attempts, verification results, Git references, and checklist synchronization.
- [x] Implement the lifecycle states `pending`, `running`, `completed`, `skipped`, `blocked`, `failed`, and `interrupted`.
- [x] Record the active phase as `preparing`, `executing`, `verifying`, `committing`, `publishing`, or `finalizing`.
- [x] Persist task content hashes, attempt counters, timestamps, selected agent and non-secret settings, worktree ownership, base SHA, branch, commit SHA, push result, and blocker details.
- [x] Load project context and bounded relevant memory when preparing each attempt.
- [x] Save concise attempt summaries and failure evidence without treating model-written notes or checkboxes as verified execution state.

### E. Agent adapters

- [x] Define an execution adapter interface that receives the task, optional criteria, workspace, context, previous failure evidence, and remaining execution limits.
- [x] Implement a process adapter using argument arrays and an explicit working directory; keep arbitrary task text out of shell command interpolation.
- [x] Implement an adapter registry and a documented registration interface so additional coding agents can be added without editing the runner.
- [x] Stream worker output to the terminal and task-specific logs while capturing exit code, timing, and a structured result.
- [x] Distinguish a candidate implementation, a request for human input, and an execution error; reject absent or malformed result output.
- [x] Implement the Codex adapter, including tested CLI invocation, prompt/context handling, workspace selection, streamed output, normalized result handling, and cancellation.
- [x] Implement the Claude Code adapter with its own tested CLI invocation, prompt/context handling, workspace selection, streamed output, normalized result handling, and cancellation.
- [x] Support per-agent executable paths, optional models, extra argument arrays, and environment-based authentication without printing or persisting credentials.
- [x] Add preflight errors for missing executables, unavailable authentication, unsupported adapters, and incompatible CLI versions; never silently fall back to a different agent.
- [x] Normalize both adapters into the same candidate-implementation, blocked, and execution-error outcomes so verification and Git behavior remain agent-independent.
- [x] Build a deterministic fixture worker for automated tests, with modes for success, failure, blocking, slow execution, and interruption.

### F. Git workspaces and commits

- [x] Inspect the user's checkout before a run and reject unrelated uncommitted changes without stashing, discarding, or committing them.
- [x] Resolve the configured base reference to a recorded commit SHA and create an isolated linked worktree for each task.
- [x] Generate valid task branch names from the configured prefix, task ID, and description; retain branch ownership for resume.
- [x] Reject branches owned by unrelated work instead of resetting or overwriting them.
- [x] Document and implement the example project's workspace preparation, including dependencies and test configuration that do not automatically exist in a new worktree.
- [x] Stage only task-workspace changes after successful verification, excluding runtime data and credentials, and record the staged diff summary.
- [x] Create task commits with the task ID and run/task identifiers, then persist the resulting SHA.
- [x] Implement optional ordinary pushes to the configured remote; retain local commits and publication status when a push fails.
- [x] Detect unexpected worker-created commits or branch changes and reconcile or block instead of silently accepting them.
- [x] Preserve failed and interrupted worktrees for inspection; provide documented cleanup behavior that protects uncommitted work and completed branches.

### G. Runner and verification

- [x] Implement the sequential loop that selects a pending task, prepares its workspace and context, invokes the adapter, verifies results, commits, optionally pushes, and finalizes progress.
- [x] Run the configured verification commands independently of the worker, capturing each command, output, exit code, and timeout.
- [x] Snapshot verification configuration at run start so worker edits cannot silently replace the required checks.
- [x] Feed bounded verification-failure evidence into the next attempt and leave the task unchecked while verification fails.
- [x] Block when detected changes weaken existing verification; preserve the diff for review and document that comprehensive detection is not guaranteed.
- [x] Support an already-satisfied task as a verified no-change outcome with supporting evidence rather than creating an empty commit.
- [x] Check off a task only after verification, Git requirements, and optional publication succeed; record completion evidence together.
- [x] Handle checkbox-write failure as pending finalization, preserving the successful implementation and commit so resume only retries the checklist update.

### H. Limits, interruption, and recovery

- [x] Add a repository-level lock to prevent overlapping runners; confirm that an old worker has stopped before allowing resume.
- [x] Enforce the configured run iteration limit, per-task attempt limit, task execution timeout, and total active run-time budget.
- [x] Preserve counters across resume; make a deliberately new run identifiable without losing completed task history.
- [x] Handle Ctrl+C by stopping the managed worker/process tree where supported, recording interruption, preserving files, and releasing ownership only after termination is confirmed.
- [x] Reconcile worktree, branch, and recorded commit state on resume, including a crash immediately after a commit but before the database update.
- [x] Resume with the originally recorded agent and non-secret settings; reject implicit agent changes and document how to explicitly begin a new run with a different agent.
- [x] Resume failed publication without repeating implementation or creating a duplicate commit.
- [x] Protect checkbox writes with source-content checks and atomic replacement; preserve detected concurrent edits and block conflicting writes.
- [x] Stop on unresolved decisions, exhausted attempts, or ambiguous ownership with a readable reason and documented next action.

### I. CLI progress and diagnostics

- [x] Implement `ralph run`, `ralph status`, `ralph logs TASK-ID`, `ralph resume`, and `ralph retry TASK-ID` against the real runner and state store.
- [x] Display the selected agent, actual task phases, completed/remaining counts, attempt counts, branch names, and check results.
- [x] Add `status --json` and structured JSONL events with a schema version, sequence, timestamp, run ID, task ID, and outcome.
- [x] Emit completion events only after durable finalization; retain stable event IDs for deduplication.
- [x] Define and document consistent exit codes for success, configuration failure, execution failure, limit exhaustion, blocking, and interruption.
- [x] Redact known configured secrets from package-generated diagnostics and document the storage and limitations of raw worker logs.

### J. Verification, documentation, and release

- [x] Test checklist parsing, stable IDs, reordering, duplicate descriptions, completed-item skipping, reopened work, and formatting-preserving checkbox updates.
- [x] Test configuration validation, task-state transitions, limits, and the distinction between user-completed and runner-verified tasks.
- [x] Test default-agent selection, command-line overrides, adapter registration, missing-agent errors, and persisted-agent behavior on resume.
- [x] Test each built-in adapter's command construction and result normalization with deterministic fixtures, including success, malformed output, blocking, process failure, and cancellation.
- [x] Use temporary Git repositories and the fixture worker to test branches, worktrees, verification failures, commits, interruptions, and unrelated-user-change protection.
- [x] Test optional pushes against a local bare Git remote, including rejected pushes and publication-only retry.
- [x] Test recovery after a post-commit crash, a failed checkbox write, and a concurrent task-file edit; verify that commits and implementation are not duplicated.
- [x] Add CI that runs the automated suite on supported PHP versions without model credentials or paid external services.
- [x] Write a README covering installation, every command, task checklist syntax, configuration, Codex and Claude Code setup, agent selection, custom adapter registration, Git behavior, context/memory, recovery, logs, and limitations.
- [x] Include a working PHP example project and package-related example tasks that another developer can reproduce.
- [x] Demonstrate real runs with Claude Code on small PHP-package changes; record tested agent CLI versions, resulting commits, verification output, interventions, elapsed time, and known limitations.
- [x] Make sure any functionality of the project like git or printing to terminal is using an external well-maintained package if available.
- [x] Resolve blocking setup or recovery problems before tagging version 0.1.


### K. Enhancements

- [x] Remove all the git logic and let the package work on the current branch of the user. No need for shared-strategy and branch-per-task bullshit. JUST WORK ON THE CURRENT CODE REGARDLESS OF GIT.
- [x] Add a new verbose mode that is not enabled by default. When enabled (using a flag), all the output is printed just like what's happening now (the current output is verbose). When disabled, which is the default, less output should be shown
- [x] Add a newline (\n\n) after every output that the package prints to the console regardless of what it is. There should always be a new line 

## 6. Release acceptance criteria

The release candidate is ready for review when all implementation items above have been completed and the following behaviors have evidence:

1. Composer installation exposes the CLI and initialization preserves existing user files.
2. Plain Markdown tasks execute in order, with checked tasks skipped and unrelated task-file text preserved.
3. A passing task produces a traceable commit and a checked item; a failing or blocked task stays unchecked.
4. Both Codex and Claude Code adapters produce useful changes against a representative PHP project, selected through configuration or `--agent`.
5. Required pushes run automatically when enabled, and publication failures preserve local work.
6. Interrupted runs resume with the recorded agent without duplicate implementation or commits.
7. Iteration and time limits stop execution with accurate persisted status.
8. The automated tests run without live model credentials.
9. Another PHP developer can reproduce the documented setup and example.

Checks and commits provide evidence for review; they do not guarantee that every natural-language requirement is satisfied. Users review generated changes before merging.

## 7. Experiment evaluation

Use the package on ten suitable development tasks. Record whether it saves time after code review and corrections, how often intervention is required, and which failures recur. Capture execution cost only when the backend can report it accurately.

Use those results to decide whether to improve the tool, share it more widely, or keep it as a personal development utility.

## 8. Reference

Behavioral reference: [Ralph Loop](https://ralphloop.sh/) and its [source repository](https://github.com/PageAI-Pro/ralph-loop), reviewed 26 October 2026.

This document defines Ralph PHP's first release. If upstream source is reused, preserve its applicable license and attribution.

