<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Init;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Ralph;
use JsonException;

/**
 * The starting contents `ralph init` writes.
 *
 * The templates live in PHP rather than in a `resources/` directory because a
 * consumer receives only what `composer.json` declares — the autoload roots and
 * the binary — so a template file outside `src/` would be missing from an
 * installed package and `ralph init` would fail exactly where it is first used.
 *
 * The task checklist is deliberately *empty of tasks*: its example is inside a
 * fenced code block, which the checklist reader ignores, so a freshly
 * initialised project has nothing pending rather than a template line the agent
 * would try to implement.
 */
final class Templates
{
    /**
     * The default configuration document.
     *
     * Written in full, including the settings that match the built-in defaults,
     * so the file itself shows every setting a project can change. Because it is
     * {@see Defaults::toArray()}, an initialised project and a project with an
     * empty `{}` behave identically.
     *
     * @throws JsonException when the defaults cannot be encoded, which would be
     *                       a defect in {@see Defaults}
     */
    public static function config(): string
    {
        return json_encode(
            Defaults::toArray(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }

    /**
     * The task checklist, with usage instructions and no tasks.
     */
    public static function tasks(): string
    {
        $name = Ralph::NAME;

        return <<<MARKDOWN
        # Tasks

        Add the work you want done as top-level Markdown checklist items. {$name} reads
        this file from top to bottom, starts the first unchecked item, and checks it off
        only after the configured verification commands pass and the change is
        committed.

        Indented bullets under an item are passed to the agent as implementation details
        or acceptance criteria:

        ```markdown
        - [ ] Add a --dry-run option to the import command
          - It must print the planned changes and write nothing.
          - Cover it with a test.
        ```

        What makes a task work well here:

        - It describes one change, small enough to verify on its own.
        - It says how the change is verified, not only what it should do.
        - It names the files or areas involved, when you already know them.
        - It stands on its own: this release does not build one task on top of another
          task's unmerged branch.

        Headings and ordinary prose are ignored, as is anything inside a fenced code
        block — which is why the example above is not picked up as a task. Items already
        marked `- [x]` are skipped and are reported as completed by you rather than
        verified by {$name}.

        Two things are reported as errors rather than skipped, so that no task you wrote
        is quietly left out: a checkbox with nothing after it, and a checkbox indented
        under another item. This release runs top-level items only — promote a nested
        item to the start of the line, or write it as a plain `- ` bullet to keep it as a
        detail of the item above.

        A task is identified by its description alone — not by its checkbox and not by
        where it sits in the file — so you can reorder the list, tick an item or rewrite
        the notes under one without {$name} losing that task's history. Capitalisation
        and spacing are ignored in that comparison, which also means two items that read
        the same are one task: a repeated description is reported as an error instead of
        being run twice.

        Avoid editing this file while a run is in progress: the runner rewrites the
        checkbox of the task it finishes.

        ## Tasks

        Write your first task below, then run "ralph run".

        MARKDOWN;
    }

    /**
     * The project context file, as prompts for the sections an agent needs.
     */
    public static function context(): string
    {
        $name = Ralph::NAME;
        $config = Defaults::CONFIG_FILE;

        return <<<MARKDOWN
        # Project context

        {$name} passes this file to the coding agent with every task. Keep it short and
        specific: it is read in full on every attempt, so anything that is only useful
        for one task belongs in that task instead.

        Replace the guidance under each heading with your project's own.

        ## What this project is

        One or two sentences: what it does, and who uses it.

        ## How it is run and verified

        How to install dependencies and run the tests, the static analysis and the
        formatter. The commands {$name} runs itself are the `checks` in
        `{$config}`; this section is for what a human or an agent needs to know
        around them.

        ## Conventions

        Code style, directory layout, naming, how errors are handled, how tests are
        written, what is committed and what is generated.

        ## Architecture and important files

        The few entry points and directories a change usually touches.

        ## Out of scope

        What must not be changed: generated files, vendored code, interfaces that are
        frozen, migrations that have already shipped.

        MARKDOWN;
    }

    private function __construct()
    {
    }
}
