<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Tasks;

use Celyes\RalphPhp\Exception\ChecklistException;
use Celyes\RalphPhp\Init\Templates;
use Celyes\RalphPhp\Tasks\CheckboxEdit;
use Celyes\RalphPhp\Tasks\CheckboxEditor;
use Celyes\RalphPhp\Tasks\TaskId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ticking one task and leaving the document alone.
 *
 * The claim under test is narrow and absolute: the result differs from the
 * input by the single character inside one checkbox. So most of these tests do
 * not inspect the output's structure at all — they rebuild the expected
 * document from the input by replacing that one marker, and assert the two are
 * identical. Anything the editor reformatted, re-indented, re-wrapped or
 * re-ended would show up as a difference whether or not a test thought to look
 * for it.
 */
#[CoversClass(CheckboxEditor::class)]
#[CoversClass(CheckboxEdit::class)]
final class CheckboxEditorTest extends TestCase
{
    private CheckboxEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new CheckboxEditor();
    }

    #[Test]
    public function ticking_a_task_changes_its_marker_and_nothing_else(): void
    {
        $markdown = "# Tasks\n\n- [ ] Add a --dry-run option\n- [ ] Write the README\n";

        $edit = $this->editor->tick($markdown, TaskId::fromDescription('Add a --dry-run option'));

        self::assertSame(
            "# Tasks\n\n- [x] Add a --dry-run option\n- [ ] Write the README\n",
            $edit->markdown(),
        );
        self::assertTrue($edit->isChanged());
        self::assertTrue($edit->isChecked());
        self::assertSame(3, $edit->line());
    }

    #[Test]
    public function the_second_task_is_the_one_ticked_when_it_is_selected(): void
    {
        $markdown = "- [ ] First\n- [ ] Second\n- [ ] Third\n";

        $edit = $this->editor->tick($markdown, TaskId::fromDescription('Second'));

        self::assertSame("- [ ] First\n- [x] Second\n- [ ] Third\n", $edit->markdown());
        self::assertSame(2, $edit->line());
    }

    #[Test]
    public function the_bullet_character_and_the_spacing_around_the_checkbox_survive(): void
    {
        $markdown = "*  [ ]   Spaced out   \n+\t[ ]\tTabbed\n";

        $ticked = $this->editor->tick($markdown, TaskId::fromDescription('Spaced out'));

        self::assertSame("*  [x]   Spaced out   \n+\t[ ]\tTabbed\n", $ticked->markdown());

        $tabbed = $this->editor->tick($ticked->markdown(), TaskId::fromDescription('Tabbed'));

        self::assertSame("*  [x]   Spaced out   \n+\t[x]\tTabbed\n", $tabbed->markdown());
    }

    #[Test]
    public function windows_line_endings_are_not_rewritten(): void
    {
        $markdown = "# Tasks\r\n\r\n- [ ] Ship it\r\n- [ ] Later\r\n";

        $edit = $this->editor->tick($markdown, TaskId::fromDescription('Ship it'));

        self::assertSame("# Tasks\r\n\r\n- [x] Ship it\r\n- [ ] Later\r\n", $edit->markdown());
    }

    #[Test]
    public function a_file_that_mixes_line_endings_keeps_its_mixture(): void
    {
        $markdown = "- [ ] First\r\n- [ ] Second\n- [ ] Third\r- [ ] Fourth";

        $edit = $this->editor->tick($markdown, TaskId::fromDescription('Third'));

        self::assertSame("- [ ] First\r\n- [ ] Second\n- [x] Third\r- [ ] Fourth", $edit->markdown());
    }

    #[Test]
    public function a_checklist_without_a_final_newline_does_not_gain_one(): void
    {
        $markdown = "- [ ] Only task";

        $edit = $this->editor->tick($markdown, TaskId::fromDescription('Only task'));

        self::assertSame('- [x] Only task', $edit->markdown());
    }

    #[Test]
    public function a_byte_order_mark_stays_in_front_of_the_first_task(): void
    {
        $markdown = "\xEF\xBB\xBF- [ ] First line task\n- [ ] Second\n";

        $edit = $this->editor->tick($markdown, TaskId::fromDescription('First line task'));

        self::assertSame("\xEF\xBB\xBF- [x] First line task\n- [ ] Second\n", $edit->markdown());
        self::assertSame(1, $edit->line());
    }

    #[Test]
    public function the_details_written_under_a_task_are_untouched(): void
    {
        $markdown = <<<'MARKDOWN'
            # Backlog

            - [ ] Add the importer
              - accepts CSV
              - rejects anything else

                An extra paragraph, indented differently.

            - [ ] Something else
            MARKDOWN;

        $edit = $this->editor->tick($markdown, TaskId::fromDescription('Add the importer'));

        self::assertSame(
            str_replace('- [ ] Add the importer', '- [x] Add the importer', $markdown),
            $edit->markdown(),
        );
    }

    #[Test]
    public function a_checkbox_inside_a_fenced_example_is_never_the_line_that_is_ticked(): void
    {
        $markdown = <<<'MARKDOWN'
            Write tasks like this:

            ```markdown
            - [ ] Add a --dry-run option
            ```

            - [ ] Add a --dry-run option
            MARKDOWN;

        $edit = $this->editor->tick($markdown, TaskId::fromDescription('Add a --dry-run option'));

        self::assertSame(7, $edit->line());
        self::assertSame(
            <<<'MARKDOWN'
                Write tasks like this:

                ```markdown
                - [ ] Add a --dry-run option
                ```

                - [x] Add a --dry-run option
                MARKDOWN,
            $edit->markdown(),
        );
    }

    #[Test]
    public function a_task_added_to_the_generated_checklist_is_ticked_without_its_instructions_changing(): void
    {
        // The example inside the generated checklist's fenced block states the
        // very task appended below it, so a writer that searched the text would
        // tick the example instead of the task — and would leave the user with
        // an instruction page that claims work is done.
        $description = 'Add a --dry-run option to the import command';
        $markdown = Templates::tasks() . "\n- [ ] " . $description . "\n";

        $edit = $this->editor->tick($markdown, TaskId::fromDescription($description));

        self::assertTrue($edit->isChanged());
        self::assertSame(
            Templates::tasks() . "\n- [x] " . $description . "\n",
            $edit->markdown(),
        );
        self::assertStringContainsString("```markdown\n- [ ] " . $description, $edit->markdown());
    }

    #[Test]
    public function ticking_a_task_that_is_already_ticked_changes_nothing(): void
    {
        $markdown = "- [x] Done already\n- [ ] Not yet\n";

        $edit = $this->editor->tick($markdown, TaskId::fromDescription('Done already'));

        self::assertFalse($edit->isChanged());
        self::assertTrue($edit->isChecked());
        self::assertSame($markdown, $edit->markdown());
        self::assertSame(1, $edit->line());
    }

    #[Test]
    public function unticking_a_task_reopens_it(): void
    {
        $markdown = "- [x] Verified too soon\n- [x] Genuinely done\n";

        $edit = $this->editor->untick($markdown, TaskId::fromDescription('Verified too soon'));

        self::assertSame("- [ ] Verified too soon\n- [x] Genuinely done\n", $edit->markdown());
        self::assertTrue($edit->isChanged());
        self::assertFalse($edit->isChecked());
    }

    #[Test]
    public function an_upper_case_marker_is_recognised_and_rewritten(): void
    {
        $markdown = "- [X] Shouted done\n";

        $reopened = $this->editor->untick($markdown, TaskId::fromDescription('Shouted done'));
        self::assertSame("- [ ] Shouted done\n", $reopened->markdown());

        $again = $this->editor->tick($reopened->markdown(), TaskId::fromDescription('Shouted done'));
        self::assertSame("- [x] Shouted done\n", $again->markdown());
    }

    #[Test]
    public function unticking_a_task_that_is_already_open_changes_nothing(): void
    {
        $markdown = "- [ ] Still to do\n";

        $edit = $this->editor->untick($markdown, TaskId::fromDescription('Still to do'));

        self::assertFalse($edit->isChanged());
        self::assertFalse($edit->isChecked());
        self::assertSame($markdown, $edit->markdown());
    }

    #[Test]
    public function a_task_that_moved_is_still_found_at_its_new_line(): void
    {
        $markdown = "- [ ] Was second\n- [ ] Was first\n";

        $edit = $this->editor->tick($markdown, TaskId::fromDescription('Was first'));

        self::assertSame(2, $edit->line());
        self::assertSame("- [ ] Was second\n- [x] Was first\n", $edit->markdown());
    }

    #[Test]
    public function a_task_the_checklist_no_longer_states_is_refused(): void
    {
        $markdown = "- [ ] Reworded since the run started\n";
        $id = TaskId::fromDescription('As it was worded before');

        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('no longer states the task ' . $id->value());

        $this->editor->tick($markdown, $id);
    }

    #[Test]
    public function the_refusal_names_the_file_when_it_came_from_one(): void
    {
        try {
            $this->editor->tick("- [ ] Something\n", TaskId::fromDescription('Gone'), '/tmp/tasks.md');
            self::fail('Expected the missing task to be refused.');
        } catch (ChecklistException $exception) {
            self::assertStringContainsString('The task checklist /tmp/tasks.md', $exception->getMessage());
            self::assertNotSame([], $exception->hints());
        }
    }

    #[Test]
    public function a_checklist_that_cannot_be_parsed_is_refused_before_anything_is_written(): void
    {
        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('has an indented checkbox on line 2');

        $this->editor->tick(
            "- [ ] A task\n  - [ ] A subtask\n",
            TaskId::fromDescription('A task'),
        );
    }

    #[Test]
    public function a_checklist_that_states_one_task_twice_is_refused(): void
    {
        $this->expectException(ChecklistException::class);
        $this->expectExceptionMessage('states the same task on lines 1 and 2');

        $this->editor->tick("- [ ] Repeated\n- [ ] repeated\n", TaskId::fromDescription('Repeated'));
    }

    #[Test]
    public function the_edit_reports_the_task_it_was_asked_about(): void
    {
        $id = TaskId::fromDescription('Add a --dry-run option');

        $edit = $this->editor->tick("- [ ] Add a --dry-run option\n", $id);

        self::assertTrue($edit->taskId()->equals($id));
    }

    #[Test]
    public function repeated_ticks_leave_the_checklist_exactly_once_changed(): void
    {
        $markdown = "- [ ] First\n- [ ] Second\n";
        $id = TaskId::fromDescription('First');

        $once = $this->editor->tick($markdown, $id);
        $twice = $this->editor->tick($once->markdown(), $id);

        self::assertSame($once->markdown(), $twice->markdown());
        self::assertFalse($twice->isChanged());
    }
}
