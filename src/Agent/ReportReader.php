<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Log\OutputStream;
use Celyes\RalphPhp\Process\ProcessResult;
use Celyes\RalphPhp\State\AttemptOutcome;
use InvalidArgumentException;
use JsonException;

/**
 * Reads an agent's output for the one thing the loop needs it to have said.
 *
 * PROJECT.md section E, item 5: tell a candidate implementation, a request for
 * human input and an execution error apart, and refuse output that is absent or
 * malformed. The refusing is the harder half, and it is the reason this is a
 * strict reader rather than a tolerant one.
 *
 * **Nothing is salvaged.** A block that does not decode, holds something other
 * than an object, names an outcome that does not exist or omits what its
 * outcome requires is returned as {@see ReportReading::malformed()} with the
 * reason and the block. It is never read as a candidate. The cost of being
 * wrong is asymmetric: refusing a good attempt wastes one attempt, while
 * accepting a broken report commits work nobody checked, so the doubtful case
 * goes to the error — and the only thing a tolerant reader would buy is the
 * ability to guess about exactly the fact it must not guess about.
 *
 * **What is normalised, and why that is not guessing.** The outcome is matched
 * case-insensitively after trimming, because `Candidate` and `candidate` are
 * the same token written by a model that capitalises sentences, and nothing is
 * being inferred from prose. A missing summary is allowed for a candidate —
 * which of the three cases it is, is the part the loop acts on — and required
 * for the two that exist to tell a person something.
 *
 * **The last block wins.** The briefing contains an example of the format and
 * an agent may quote it, explain it, or print a report and then keep working.
 * The instructions say the report comes last, so the last complete block is the
 * one read, and how many there were is kept for the log.
 *
 * **Standard output first, then error output.** Plenty of CLIs write their
 * whole transcript to standard error, so output is not searched in one place
 * only; the first stream that holds any block at all is the one answered from,
 * so that a broken block is not quietly replaced by an older-looking one on the
 * other stream.
 */
final class ReportReader
{
    /**
     * How deep the object may nest.
     *
     * A report is flat. The limit is here because `json_decode`'s default of
     * 512 is a tolerance nothing needs, and a deeply nested payload where a
     * report belongs is a sign the agent printed something else entirely.
     */
    private const int DEPTH = 8;

    /**
     * Read a finished process's output.
     */
    public static function read(ProcessResult $result): ReportReading
    {
        $standard = self::readText($result->standardOutput(), OutputStream::Standard);

        if (!$standard->isAbsent()) {
            return $standard;
        }

        return self::readText($result->errorOutput(), OutputStream::Error);
    }

    /**
     * Read one piece of text, said to have arrived on the given stream.
     *
     * Separate from {@see self::read()} for the adapter whose CLI hands over
     * the agent's message as a field of its own structured output rather than
     * as the process's raw output (section E, items 6 and 7): the block is
     * looked for in the same way wherever the text came from.
     */
    public static function readText(string $text, OutputStream $stream = OutputStream::Standard): ReportReading
    {
        $found = preg_match_all(self::pattern(), $text, $matches, PREG_SET_ORDER);

        if ($found === false || $found === 0) {
            // An opening fence with nothing closing it is a block the agent
            // started and did not finish — a transcript cut short, or a model
            // that forgot the closing line. Worth saying, because "printed no
            // result block" would send the user looking for a report that is
            // visibly there in the log.
            if (preg_match(self::openingPattern(), $text) === 1) {
                return ReportReading::malformed(
                    'it was opened and never closed, so there is no telling how much of it was printed',
                    '',
                    $stream,
                );
            }

            return ReportReading::absent();
        }

        $blocks = count($matches);

        return self::parse($matches[$blocks - 1]['body'], $stream, $blocks);
    }

    /**
     * Turn one block's contents into a report, or say why it is not one.
     */
    private static function parse(string $body, OutputStream $stream, int $blocks): ReportReading
    {
        $refuse = static fn (string $problem): ReportReading
            => ReportReading::malformed($problem, $body, $stream, $blocks);

        if (trim($body) === '') {
            return $refuse('it is empty');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode(trim($body), true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $refuse(sprintf('it is not valid JSON (%s)', rtrim($exception->getMessage(), '.')));
        }

        // A list is JSON and is not this: separated from an object explicitly so
        // that `["candidate"]` is refused for the shape it has rather than for
        // a missing key it could never have had.
        if (!is_array($decoded) || array_is_list($decoded)) {
            return $refuse(sprintf(
                'it holds %s rather than an object with an "%s" key',
                self::jsonType($decoded),
                ResultProtocol::OUTCOME,
            ));
        }

        if (!array_key_exists(ResultProtocol::OUTCOME, $decoded)) {
            return $refuse(sprintf(
                'it has no "%s" key, which has to be one of %s',
                ResultProtocol::OUTCOME,
                self::listed(ResultProtocol::outcomes()),
            ));
        }

        /** @var mixed $declared */
        $declared = $decoded[ResultProtocol::OUTCOME];

        if (!is_string($declared)) {
            return $refuse(sprintf(
                '"%s" holds %s rather than one of %s',
                ResultProtocol::OUTCOME,
                self::jsonType($declared),
                self::listed(ResultProtocol::outcomes()),
            ));
        }

        $outcome = ResultProtocol::outcomeNamed(strtolower(trim($declared)));

        if ($outcome === null) {
            return $refuse(sprintf(
                '"%s" is not one of %s',
                trim($declared),
                self::listed(ResultProtocol::outcomes()),
            ));
        }

        /** @var mixed $summary */
        $summary = $decoded[ResultProtocol::SUMMARY] ?? '';

        if (!is_string($summary)) {
            return $refuse(sprintf(
                '"%s" holds %s rather than a string',
                ResultProtocol::SUMMARY,
                self::jsonType($summary),
            ));
        }

        /** @var mixed $next */
        $next = $decoded[ResultProtocol::NEXT_ACTION] ?? '';

        if (!is_string($next)) {
            return $refuse(sprintf(
                '"%s" holds %s rather than a string',
                ResultProtocol::NEXT_ACTION,
                self::jsonType($next),
            ));
        }

        try {
            return ReportReading::found(self::report($outcome, $summary, $next), $stream, $blocks);
        } catch (InvalidArgumentException) {
            // The report's own requirements, said in terms of the block the
            // agent printed rather than of the constructor that refused it.
            return $refuse(self::missing($outcome, $summary, $next));
        }
    }

    /**
     * @throws InvalidArgumentException when the declared outcome needs
     *                                 something the block does not have
     */
    private static function report(AttemptOutcome $outcome, string $summary, string $nextAction): AgentReport
    {
        return match ($outcome) {
            AttemptOutcome::Blocked => AgentReport::blocked($summary, $nextAction),
            AttemptOutcome::Error => AgentReport::error($summary),
            default => AgentReport::candidate($summary),
        };
    }

    /**
     * Which requirement of the declared outcome the block did not meet.
     */
    private static function missing(AttemptOutcome $outcome, string $summary, string $nextAction): string
    {
        if ($outcome === AttemptOutcome::Blocked && trim($nextAction) === '') {
            return sprintf(
                'it is "%s" without a "%s" saying what is needed, which leaves the run'
                    . ' stopped with nothing to act on',
                AttemptOutcome::Blocked->value,
                ResultProtocol::NEXT_ACTION,
            );
        }

        return sprintf(
            'it is "%s" without a "%s" saying why',
            $outcome->value,
            ResultProtocol::SUMMARY,
        );
    }

    /**
     * The complete block: an opening fence with the info string on its own
     * line, a body, and a closing fence of the same length.
     *
     * Deliberately not given the `u` modifier. An agent may print invalid UTF-8
     * — a truncated multi-byte character at a buffer boundary is enough — and a
     * Unicode-mode match fails outright on it, which would turn a report that
     * is on disk and perfectly readable into "printed no result block".
     */
    private static function pattern(): string
    {
        return sprintf(
            '/^[ \t]*(`{3,})[ \t]*%s[ \t]*$(?<body>.*?)^[ \t]*\1[ \t]*$/ms',
            preg_quote(ResultProtocol::FENCE, '/'),
        );
    }

    /**
     * An opening fence, whether or not anything closed it.
     */
    private static function openingPattern(): string
    {
        return sprintf('/^[ \t]*`{3,}[ \t]*%s[ \t]*$/m', preg_quote(ResultProtocol::FENCE, '/'));
    }

    /**
     * What a decoded value is, in JSON's own words.
     *
     * `get_debug_type()` alone says `int` and `bool`, which are PHP's names for
     * things the agent wrote as JSON; a message about a block the agent printed
     * should describe what it printed.
     */
    private static function jsonType(mixed $value): string
    {
        return match (true) {
            is_array($value) && array_is_list($value) => 'a JSON array',
            is_array($value) => 'a JSON object',
            is_string($value) => 'a JSON string',
            is_int($value), is_float($value) => 'a JSON number',
            is_bool($value) => 'a JSON boolean',
            $value === null => 'JSON null',
            default => 'a JSON ' . get_debug_type($value),
        };
    }

    /**
     * @param list<string> $values
     */
    private static function listed(array $values): string
    {
        return '"' . implode('", "', $values) . '"';
    }

    private function __construct()
    {
    }
}
