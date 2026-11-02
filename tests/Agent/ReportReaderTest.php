<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AgentReport;
use Celyes\RalphPhp\Agent\ReportReader;
use Celyes\RalphPhp\Agent\ReportReading;
use Celyes\RalphPhp\Agent\ResultProtocol;
use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\State\AttemptOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading an agent's output for the one thing the loop needs it to have said.
 *
 * Half of these are about refusing. That is the item's wording — absent or
 * malformed result output is rejected — and the reason it is worth this many
 * assertions is that every near-miss here has an obvious tolerant reading, and
 * taking any of them would mean committing work on the strength of a block
 * nobody could parse.
 */
#[CoversClass(ReportReader::class)]
#[CoversClass(ReportReading::class)]
final class ReportReaderTest extends TestCase
{
    #[Test]
    public function a_candidate_is_read_out_of_the_middle_of_a_transcript(): void
    {
        $reading = ReportReader::readText(implode("\n", [
            'Reading src/Import/ImportCommand.php',
            'Editing src/Import/ImportCommand.php',
            '',
            '```' . ResultProtocol::FENCE,
            '{"outcome": "candidate", "summary": "Added --dry-run and a test."}',
            '```',
            '',
        ]));

        $report = $reading->report();

        self::assertTrue($reading->hasReport());
        self::assertFalse($reading->isAbsent());
        self::assertFalse($reading->isMalformed());
        self::assertInstanceOf(AgentReport::class, $report);
        self::assertSame(AttemptOutcome::Candidate, $report->outcome());
        self::assertSame('Added --dry-run and a test.', $report->summary()->text());
        self::assertSame(OutputStream::Standard, $reading->stream());
        self::assertSame(1, $reading->blocks());
    }

    #[Test]
    public function a_request_for_human_input_keeps_both_halves_of_its_question(): void
    {
        $reading = ReportReader::readText($this->block([
            'outcome' => 'blocked',
            'summary' => 'There are two queue drivers configured and the task does not say which.',
            'next_action' => 'Name the driver in .ralph/CONTEXT.md and retry the task.',
        ]));

        $report = $reading->report();

        self::assertInstanceOf(AgentReport::class, $report);
        self::assertTrue($report->requiresHumanInput());
        self::assertSame('Name the driver in .ralph/CONTEXT.md and retry the task.', $report->nextAction());
    }

    #[Test]
    public function an_agent_can_report_that_it_could_not_do_the_work(): void
    {
        $reading = ReportReader::readText($this->block([
            'outcome' => 'error',
            'summary' => 'composer install fails in this workspace: no vendor directory and no network.',
        ]));

        self::assertTrue($reading->report()?->isError());
    }

    /**
     * The briefing shows the format, and an agent may quote it, explain it, or
     * report and then keep working. The instructions say the report comes last,
     * so the last block is the answer — and how many there were is kept,
     * because two blocks is also what an agent trying two answers looks like.
     */
    #[Test]
    public function the_last_block_is_the_one_read(): void
    {
        $reading = ReportReader::readText(implode("\n", [
            'I will finish by printing:',
            $this->block(['outcome' => 'candidate', 'summary' => 'the example I was shown']),
            'Actually the schema is the problem.',
            $this->block([
                'outcome' => 'blocked',
                'summary' => 'The column does not exist.',
                'next_action' => 'Decide whether a migration is in scope.',
            ]),
        ]));

        self::assertTrue($reading->report()?->requiresHumanInput());
        self::assertSame(2, $reading->blocks());
        self::assertStringContainsString('the last of 2 blocks', $reading->describe());
    }

    #[Test]
    public function an_output_with_no_block_at_all_is_absent_rather_than_anything_else(): void
    {
        $reading = ReportReader::readText("Done! I have implemented the feature and all tests pass.\n");

        self::assertTrue($reading->isAbsent());
        self::assertFalse($reading->hasReport());
        self::assertFalse($reading->isMalformed());
        self::assertNull($reading->problem());
        self::assertNull($reading->stream());
        self::assertSame(0, $reading->blocks());
        self::assertSame('printed no result block', $reading->describe());
    }

    #[Test]
    public function an_empty_output_is_absent(): void
    {
        self::assertTrue(ReportReader::readText('')->isAbsent());
    }

    /**
     * A block the agent started and did not finish: a transcript cut short, or
     * a model that forgot the closing line. Said as its own problem, because
     * "printed no result block" would send a user looking for a report that is
     * visibly there in the log.
     */
    #[Test]
    public function an_unclosed_block_is_refused_rather_than_called_absent(): void
    {
        $reading = ReportReader::readText(implode("\n", [
            'Working on it.',
            '```' . ResultProtocol::FENCE,
            '{"outcome": "candidate", "summary": "cut off here',
        ]));

        self::assertTrue($reading->isMalformed());
        self::assertFalse($reading->isAbsent());
        self::assertStringContainsString('never closed', (string) $reading->problem());
    }

    /**
     * Plenty of CLIs write their whole transcript to standard error, so the
     * block is looked for there too — and the stream it was found on is kept,
     * since that is the first thing to check when an agent reports correctly
     * and the package disagrees.
     */
    #[Test]
    public function a_report_on_the_error_stream_is_still_a_report(): void
    {
        $reading = ReportReader::read(new ProcessResult(
            [PHP_BINARY],
            0,
            "nothing on standard output\n",
            $this->block(['outcome' => 'candidate', 'summary' => 'wrote the parser']),
            false,
            1.0,
        ));

        self::assertTrue($reading->hasReport());
        self::assertSame(OutputStream::Error, $reading->stream());
        self::assertStringContainsString('stderr', $reading->describe());
    }

    #[Test]
    public function standard_output_is_answered_from_when_both_streams_hold_one(): void
    {
        $reading = ReportReader::read(new ProcessResult(
            [PHP_BINARY],
            0,
            $this->block(['outcome' => 'candidate', 'summary' => 'from standard output']),
            $this->block(['outcome' => 'error', 'summary' => 'from the error stream']),
            false,
            1.0,
        ));

        self::assertSame('from standard output', $reading->report()?->summary()->text());
        self::assertSame(OutputStream::Standard, $reading->stream());
    }

    /**
     * A broken block on standard output is the answer, rather than being
     * quietly replaced by whatever is on the other stream: an agent that
     * reported twice in two places has a problem a user should see, and the
     * tolerant reading hides it.
     */
    #[Test]
    public function a_broken_block_on_standard_output_is_not_passed_over(): void
    {
        $reading = ReportReader::read(new ProcessResult(
            [PHP_BINARY],
            0,
            "```" . ResultProtocol::FENCE . "\nnot json at all\n```\n",
            $this->block(['outcome' => 'candidate', 'summary' => 'from the error stream']),
            false,
            1.0,
        ));

        self::assertTrue($reading->isMalformed());
        self::assertSame(OutputStream::Standard, $reading->stream());
    }

    #[Test]
    #[DataProvider('refusals')]
    public function a_block_that_cannot_be_read_is_refused_with_a_reason(string $body, string $expected): void
    {
        $reading = ReportReader::readText(sprintf("```%s\n%s\n```\n", ResultProtocol::FENCE, $body));

        self::assertTrue($reading->isMalformed(), 'Expected a refusal, got: ' . $reading->describe());
        self::assertFalse($reading->hasReport());
        self::assertNull($reading->report());
        self::assertStringContainsString($expected, (string) $reading->problem());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusals(): array
    {
        return [
            'empty' => ['', 'it is empty'],
            'prose' => ['I implemented the feature.', 'not valid JSON'],
            'half-written JSON' => ['{"outcome": "candidate"', 'not valid JSON'],
            'a JSON array' => ['["candidate"]', 'a JSON array'],
            'a JSON string' => ['"candidate"', 'a JSON string'],
            'a JSON number' => ['12', 'a JSON number'],
            'a JSON boolean' => ['true', 'a JSON boolean'],
            'null' => ['null', 'JSON null'],
            'no outcome' => ['{"summary": "I did the thing"}', 'no "outcome" key'],
            'an outcome that is a number' => ['{"outcome": 0}', '"outcome" holds a JSON number'],
            'an outcome that is null' => ['{"outcome": null}', '"outcome" holds JSON null'],
            'an outcome that is an object' => [
                '{"outcome": {"name": "candidate"}}',
                '"outcome" holds a JSON object',
            ],
            'an invented outcome' => ['{"outcome": "completed"}', '"completed" is not one of'],
            'an outcome in the wrong words' => ['{"outcome": "success"}', '"success" is not one of'],
            'a summary that is a list' => [
                '{"outcome": "candidate", "summary": ["a", "b"]}',
                '"summary" holds a JSON array',
            ],
            'a next action that is a number' => [
                '{"outcome": "blocked", "summary": "which driver?", "next_action": 7}',
                '"next_action" holds a JSON number',
            ],
            'a question with no next action' => [
                '{"outcome": "blocked", "summary": "Which queue driver?"}',
                'without a "next_action"',
            ],
            'a question with an empty next action' => [
                '{"outcome": "blocked", "summary": "Which queue driver?", "next_action": "  "}',
                'without a "next_action"',
            ],
            'a question with nothing asked' => [
                '{"outcome": "blocked", "summary": "", "next_action": "decide"}',
                'without a "summary"',
            ],
            'an error with nothing said' => ['{"outcome": "error"}', 'without a "summary"'],
        ];
    }

    /**
     * The one thing a refusal has to carry besides its reason. "Could not be
     * read" without the thing that could not be read is not evidence a user can
     * act on, and the cause is usually visible at a glance.
     */
    #[Test]
    public function a_refusal_quotes_the_block_it_refused(): void
    {
        $reading = ReportReader::readText(
            sprintf("```%s\n{\"outcome\": \"finished\"}\n```\n", ResultProtocol::FENCE),
        );

        self::assertStringContainsString('{"outcome": "finished"}', $reading->block());
    }

    #[Test]
    public function a_refused_block_is_bounded_before_it_is_carried_anywhere(): void
    {
        $reading = ReportReader::readText(
            sprintf("```%s\n%s\n```\n", ResultProtocol::FENCE, str_repeat('x', 5000)),
        );

        self::assertTrue($reading->isMalformed());
        self::assertSame(ReportReading::BLOCK_CHARACTERS, mb_strlen($reading->block()));
    }

    /**
     * `Candidate` and `candidate` are the same token written by a model that
     * capitalises sentences. Matching them is normalising a token, not
     * inferring an outcome from prose — `{"outcome": "I think it is done"}` is
     * still refused.
     */
    #[Test]
    public function an_outcome_is_matched_past_capitals_and_whitespace(): void
    {
        $reading = ReportReader::readText($this->block(['outcome' => "  CANDIDATE \n", 'summary' => 'done']));

        self::assertSame(AttemptOutcome::Candidate, $reading->report()?->outcome());
    }

    #[Test]
    public function a_key_the_reader_does_not_know_is_ignored_rather_than_refused(): void
    {
        $reading = ReportReader::readText($this->block([
            'outcome' => 'candidate',
            'summary' => 'rewrote the parser',
            'files_changed' => 3,
            'tests' => ['ParserTest'],
        ]));

        self::assertTrue($reading->hasReport());
        self::assertSame('rewrote the parser', $reading->report()?->summary()->text());
    }

    #[Test]
    public function a_block_the_agent_indented_is_still_found(): void
    {
        $reading = ReportReader::readText(implode("\n", [
            'Here is the result:',
            '',
            '  ```' . ResultProtocol::FENCE,
            '  {"outcome": "candidate", "summary": "indented inside a list item"}',
            '  ```',
        ]));

        self::assertTrue($reading->hasReport(), $reading->describe());
    }

    #[Test]
    public function a_longer_fence_opens_and_closes_a_block(): void
    {
        $reading = ReportReader::readText(
            sprintf("````%s\n{\"outcome\": \"candidate\"}\n````\n", ResultProtocol::FENCE),
        );

        self::assertTrue($reading->hasReport(), $reading->describe());
    }

    /**
     * An agent may print invalid UTF-8 — a multi-byte character truncated at a
     * buffer boundary is enough. A Unicode-mode match fails outright on it,
     * which would turn a report that is on disk and perfectly readable into
     * "printed no result block".
     */
    #[Test]
    public function broken_bytes_elsewhere_in_the_output_do_not_hide_the_block(): void
    {
        $reading = ReportReader::readText(
            "progress: \xC3\x28 \xFF\xFE truncated\n"
                . $this->block(['outcome' => 'candidate', 'summary' => 'survived the noise']),
        );

        self::assertSame('survived the noise', $reading->report()?->summary()->text());
    }

    /**
     * Broken bytes *inside* the block are a different matter: JSON is defined
     * over text, `json_decode` refuses them, and the refusal says so rather
     * than the reader salvaging what it can.
     */
    #[Test]
    public function broken_bytes_inside_the_block_are_refused(): void
    {
        $reading = ReportReader::readText(
            sprintf("```%s\n{\"outcome\": \"candidate\", \"summary\": \"\xC3\x28\"}\n```\n", ResultProtocol::FENCE),
        );

        self::assertTrue($reading->isMalformed());
        self::assertStringContainsString('not valid JSON', (string) $reading->problem());
    }

    #[Test]
    public function a_deeply_nested_payload_where_a_report_belongs_is_refused(): void
    {
        $nested = '{"outcome": "candidate", "summary": "ok", "extra": ' . str_repeat('[', 12) . str_repeat(']', 12) . '}';

        $reading = ReportReader::readText(sprintf("```%s\n%s\n```\n", ResultProtocol::FENCE, $nested));

        self::assertTrue($reading->isMalformed());
        self::assertStringContainsString('not valid JSON', (string) $reading->problem());
    }

    /**
     * A fenced block of some other kind is not a report, which is what the
     * package-specific info string buys: an agent printing a JSON config or a
     * diff is not reporting anything.
     */
    #[Test]
    public function a_fenced_block_of_another_kind_is_not_a_report(): void
    {
        $reading = ReportReader::readText(implode("\n", [
            '```json',
            '{"outcome": "candidate", "summary": "this is an example in a README"}',
            '```',
            '```',
            '{"outcome": "blocked"}',
            '```',
        ]));

        self::assertTrue($reading->isAbsent());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function block(array $fields): string
    {
        return sprintf(
            "```%s\n%s\n```\n",
            ResultProtocol::FENCE,
            json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
