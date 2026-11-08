<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\State;

use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Exception\StateException;
use InvalidArgumentException;
use JsonException;

/**
 * The agent a pass was carried out with, as it is written down.
 *
 * Three columns — `agent`, `adapter`, `agent_settings` — that appear on the
 * run, on every generation and on every attempt. The repetition is deliberate:
 * PROJECT.md requires resume to continue with the recorded agent rather than
 * re-reading a configuration file that may have changed, and requires `ralph
 * logs` to be able to say which agent produced a given attempt long after the
 * project's default moved on. Neither is answerable from a single column on the
 * run.
 *
 * The name and the adapter are separate columns because they are different
 * facts and are asked about differently. The name is the user's label — what
 * they typed after `--agent` — and may be anything; the adapter is which
 * built-in or registered implementation performed the invocation, and is
 * constrained to what the registry knows. A project can configure two agents
 * named `fast` and `careful` that both use the `claude` adapter, and status has
 * to show the name while resume has to find the adapter.
 *
 * **No credentials, ever.** The settings are {@see AgentDefinition::toArray()},
 * which carries the *names* of the environment variables an agent
 * authenticates with and never their values. That is what makes this safe to
 * store in a file inside the user's repository and to print in diagnostics, and
 * it is the reason this type exists rather than the stores serialising whatever
 * configuration object they happen to be holding.
 */
final class AgentRecord
{
    /**
     * @param array<string, mixed> $settings
     */
    private function __construct(
        private readonly string $name,
        private readonly string $adapter,
        private readonly array $settings,
    ) {
    }

    /**
     * The agent a run resolved.
     */
    public static function of(AgentSelection $selection): self
    {
        return self::ofDefinition($selection->definition());
    }

    /**
     * The agent a definition describes.
     */
    public static function ofDefinition(AgentDefinition $definition): self
    {
        return new self($definition->name(), $definition->adapter(), $definition->toArray());
    }

    /**
     * Read the three columns back.
     *
     * The settings are read as whatever JSON object was stored, without being
     * validated against today's {@see AgentDefinition}: the point of the column
     * is to say what the earlier run was configured with, and a later version
     * that adds a setting must still be able to read a row written before it
     * existed.
     *
     * @throws InvalidArgumentException when a name or adapter is missing, or
     *                                 the settings are not a JSON object
     */
    public static function fromStorage(string $name, string $adapter, string $settings): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($settings, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException(
                sprintf('The stored settings for agent "%s" are not readable JSON.', $name),
                0,
                $failure,
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(
                sprintf('The stored settings for agent "%s" are not a JSON object.', $name),
            );
        }

        /** @var array<string, mixed> $decoded */
        return new self(
            self::text($name, 'name'),
            self::text($adapter, 'adapter'),
            $decoded,
        );
    }

    /**
     * The configured agent name, which is what `--agent` takes.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The adapter that performed the invocation.
     */
    public function adapter(): string
    {
        return $this->adapter;
    }

    /**
     * The non-secret settings, as stored.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * One setting, or null when the stored row did not have it.
     */
    public function setting(string $key): mixed
    {
        return $this->settings[$key] ?? null;
    }

    /**
     * The executable the pass was run with, when it was recorded.
     *
     * Named because it is the setting resume and diagnostics actually ask for:
     * an agent that worked yesterday and fails today usually fails because that
     * path changed.
     */
    public function executable(): ?string
    {
        $executable = $this->setting('executable');

        return is_string($executable) ? $executable : null;
    }

    /**
     * The model the pass was run with, when one was configured.
     */
    public function model(): ?string
    {
        $model = $this->setting('model');

        return is_string($model) ? $model : null;
    }

    /**
     * The invocation this row describes, built back into a definition.
     *
     * How a resume continues with the agent the work was started under
     * (PROJECT.md section H, item 6): the executable, the model and the
     * argument array come off the row rather than out of `.ralph/config.json`,
     * so an edit to the file between the interruption and the resume cannot
     * change how the agent already half-way through a task is invoked.
     *
     * Only the settings an invocation is built from are read back. A row
     * written by a later version that stored something more carries it in
     * {@see settings()}, which is what `status` and `logs` print, and loses it
     * here, where the question is narrower: these five values are what a
     * command line is assembled from.
     *
     * **Still no credentials.** `environment` is the variable *names*; their
     * values are read from the process environment when the adapter runs, so a
     * resume authenticates with whatever the environment holds now and nothing
     * authenticating was ever in the database to read back.
     *
     * @throws StateException when the row does not say how to invoke the agent,
     *         which is not something to guess at: resuming with a different
     *         executable than the one recorded is the implicit change of agent
     *         this is here to prevent
     */
    public function definition(): AgentDefinition
    {
        $executable = $this->executable();

        if ($executable === null || trim($executable) === '') {
            throw new StateException(
                sprintf('The recorded settings for agent "%s" do not name an executable.', $this->name),
                [
                    'A resume invokes the agent the recorded settings describe, and this row does not describe one.',
                    sprintf(
                        'Start a new run instead: `ralph run --agent %s` reads the settings from the configuration file.',
                        $this->name,
                    ),
                ],
            );
        }

        return new AgentDefinition(
            $this->name,
            $this->adapter,
            $executable,
            $this->model(),
            $this->textList('arguments'),
            $this->textList('environment'),
        );
    }

    /**
     * Whether another record names the same agent and adapter.
     *
     * The settings are not compared. This is the question resume asks — "is
     * this the agent the work was started with?" — and PROJECT.md rejects an
     * implicit change of *agent*, not an edit to its executable path, which a
     * user moving a binary legitimately makes between runs.
     */
    public function isSameAgent(self $other): bool
    {
        return $this->name === $other->name && $this->adapter === $other->adapter;
    }

    /**
     * Whether this is the named agent.
     */
    public function is(string $name): bool
    {
        return $this->name === $name;
    }

    /**
     * One line for a status table or a log header.
     */
    public function describe(): string
    {
        $model = $this->model();

        return $model === null
            ? sprintf('%s (adapter "%s")', $this->name, $this->adapter)
            : sprintf('%s (adapter "%s", model "%s")', $this->name, $this->adapter, $model);
    }

    /**
     * The three values a row holds, ready to bind.
     *
     * @return array{agent: string, adapter: string, settings: string}
     */
    public function toColumns(): array
    {
        return [
            'agent' => $this->name,
            'adapter' => $this->adapter,
            'settings' => json_encode(
                $this->settings,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        ];
    }

    /**
     * One stored setting as a list of strings.
     *
     * A setting holding anything but strings is refused rather than filtered.
     * These are the agent's argument array and the names of its authentication
     * variables: an invocation assembled from part of a recorded array is a
     * different invocation, and dropping an authentication variable would fail
     * the agent for a reason no message would name.
     *
     * @return list<string>
     *
     * @throws StateException when the setting is not a list of strings
     */
    private function textList(string $key): array
    {
        $value = $this->setting($key);

        if ($value === null) {
            return [];
        }

        $strings = is_array($value) ? array_values(array_filter($value, 'is_string')) : [];

        if (!is_array($value) || count($strings) !== count($value)) {
            throw new StateException(
                sprintf(
                    'The recorded "%s" setting for agent "%s" is not a list of strings.',
                    $key,
                    $this->name,
                ),
                [
                    'A resume invokes the agent exactly as it was recorded, so a setting it cannot read is not worked around.',
                    sprintf(
                        'Start a new run instead: `ralph run --agent %s` reads the settings from the configuration file.',
                        $this->name,
                    ),
                ],
            );
        }

        return $strings;
    }

    /**
     * @throws InvalidArgumentException when the text says nothing
     */
    private static function text(string $value, string $what): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf('A recorded agent must have a %s.', $what));
        }

        return $trimmed;
    }
}
