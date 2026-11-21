<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Log;

/**
 * One thing an agent did, said in terms a terminal can present.
 *
 * The third thing a sink is handed, beside a worker's bytes and the package's
 * own notes ({@see OutputSink}), and it exists because those two cannot answer
 * the question a quiet run asks. The bytes of a modern coding CLI are a machine
 * format — a JSON object per line — so echoing them says nothing, and the
 * package's notes are about the package's own work rather than the agent's. An
 * activity is the middle case: a fact about the session, named rather than
 * rendered, so that the terminal decides how much of it to show
 * ({@see ActivitySink}) and the log keeps the full account either way
 * ({@see LogFile}).
 *
 * **Named, not formatted.** No escape sequences, no glyphs, no indentation, no
 * box: an activity that arrived pre-drawn would be a decision about the
 * terminal taken in an adapter, and the same activity reaches a destination
 * that wants none of that. What an adapter promises is the fact and its
 * subject; where the fact is turned into a line is where the line's width,
 * colour and convention are known.
 *
 * **It is not evidence.** An activity is read off a worker's own account of
 * itself and is the same unverified claim everything else an agent says is
 * ({@see \Celyes\RalphPhp\Support\UnverifiedText}). Nothing is decided from
 * one: what an attempt was is read from its result block against the exit
 * status the package watched.
 */
final class Activity
{
    /**
     * @param string $text the fact's own content: the agent's words, the
     *        command, the excerpt, the summary line
     * @param string $name what it is a fact about, where it has a subject of
     *        its own — the tool being called, and otherwise empty
     */
    private function __construct(
        private readonly ActivityKind $kind,
        private readonly string $text,
        private readonly string $name = '',
    ) {
    }

    /**
     * The session opening, as the agent identifies it.
     */
    public static function session(string $description): self
    {
        return new self(ActivityKind::Session, $description);
    }

    /**
     * The model is reasoning.
     *
     * No text, because the reasoning is not what is being reported
     * ({@see ActivityKind::Thinking}).
     */
    public static function thinking(): self
    {
        return new self(ActivityKind::Thinking, '');
    }

    /**
     * One message the agent wrote, as it wrote it.
     */
    public static function response(string $said): self
    {
        return new self(ActivityKind::Response, $said);
    }

    /**
     * A command the agent is running, and the tool it is running it through.
     */
    public static function command(string $tool, string $command): self
    {
        return new self(ActivityKind::Command, $command, $tool);
    }

    /**
     * A tool call, and what it is being made about.
     */
    public static function toolCall(string $tool, string $subject): self
    {
        return new self(ActivityKind::ToolCall, $subject, $tool);
    }

    /**
     * What a call came back with.
     */
    public static function toolResult(string $excerpt): self
    {
        return new self(ActivityKind::ToolResult, $excerpt);
    }

    /**
     * How the session ended.
     */
    public static function outcome(string $summary): self
    {
        return new self(ActivityKind::Outcome, $summary);
    }

    /**
     * Something that went wrong inside the session.
     */
    public static function problem(string $description): self
    {
        return new self(ActivityKind::Problem, $description);
    }

    public function kind(): ActivityKind
    {
        return $this->kind;
    }

    /**
     * The fact's content, which is empty for a kind that is only itself.
     */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * What it is a fact about, or an empty string where it has no subject.
     */
    public function name(): string
    {
        return $this->name;
    }

    public function is(ActivityKind $kind): bool
    {
        return $this->kind === $kind;
    }
}
