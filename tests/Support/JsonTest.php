<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use Celyes\RalphPhp\Support\Json;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How this package writes JSON for another program to read.
 *
 * Two shapes — the line of a JSONL stream and the document `status --json`
 * prints — and one property that matters more than either: nothing it is handed
 * can make it throw. Almost every string in a status document or an event came
 * out of another process, and `json_encode` refuses invalid UTF-8 outright. A
 * diagnostic that fails on the one run whose output was unusual is a diagnostic
 * that fails when it is needed.
 */
#[CoversClass(Json::class)]
final class JsonTest extends TestCase
{
    /**
     * One object on one line, with no line ending of its own: the writer
     * decides that, and a value carrying its own would be a second record as
     * far as a reader is concerned.
     */
    #[Test]
    public function a_line_is_one_object_and_adds_no_line_ending(): void
    {
        $line = Json::line(['event' => 'task.finished', 'seq' => 2]);

        self::assertSame('{"event":"task.finished","seq":2}', $line);
        self::assertStringEndsNotWith("\n", $line);
    }

    /**
     * A string holding a newline is still one line, because the encoder
     * escapes it: one call is always exactly one record.
     */
    #[Test]
    public function text_with_its_own_line_endings_stays_on_one_line(): void
    {
        $line = Json::line(['output' => "first\nsecond\r\nthird"]);

        self::assertStringNotContainsString("\n", $line);
        self::assertSame(
            ['output' => "first\nsecond\r\nthird"],
            json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Invalid UTF-8 off a worker's pipe is dropped on the way out rather than
     * taking the report down with it. The exact bytes are not lost: the task's
     * log is the copy meant to be exact, and this is a report about it.
     */
    #[Test]
    public function bytes_that_are_not_valid_utf8_are_dropped_rather_than_refused(): void
    {
        /** @var array{output: string} $decoded */
        $decoded = json_decode(Json::line(['output' => "the check printed \xB1\x31 and stopped"]), true, flags: JSON_THROW_ON_ERROR);

        self::assertStringContainsString('the check printed', $decoded['output']);
        self::assertStringContainsString('and stopped', $decoded['output']);
    }

    /**
     * The floats JSON has no spelling for become null, which says the number
     * is missing rather than inventing one — including inside nested values,
     * where an unguarded one would throw from several levels down.
     */
    #[Test]
    public function numbers_json_cannot_hold_become_null(): void
    {
        $line = Json::line(['checks' => [['name' => 'tests', 'seconds' => INF]], 'ratio' => NAN]);

        self::assertSame(
            ['checks' => [['name' => 'tests', 'seconds' => null]], 'ratio' => null],
            json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A document is indented, because a person ends up reading it too, and
     * neither shape escapes a slash or a non-ASCII character: `.ralph/runtime`
     * is a path somebody reads, and `\/` is noise in it.
     */
    #[Test]
    public function a_document_is_indented_and_leaves_paths_and_text_alone(): void
    {
        $document = Json::document([
            'checklist' => '.ralph/tasks.md',
            'description' => 'Ajouter une option « --dry-run »',
        ]);

        self::assertStringContainsString("\n    ", $document);
        self::assertStringContainsString('.ralph/tasks.md', $document);
        self::assertStringContainsString('« --dry-run »', $document);
        self::assertStringNotContainsString('\/', $document);
        self::assertStringNotContainsString('\\u00ab', $document);
    }
}
