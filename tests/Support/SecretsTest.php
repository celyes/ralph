<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use Celyes\RalphPhp\Agent\AgentEnvironment;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Config\ConfigurationLoader;
use Celyes\RalphPhp\Support\Secrets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What redaction does, and the two things it deliberately does not do.
 *
 * PROJECT.md section I, item 6, at the level the rest of the package depends
 * on: the value of a declared variable is replaced by the variable's name,
 * replacement is exact rather than clever, and a value too short to replace
 * safely is left alone and *said* rather than silently skipped.
 *
 * Nothing here needs a run, a process or a file. The seams that use this —
 * the terminal, the attempt logs, the event stream, the checks' excerpts — have
 * their own cases where they are, which is what keeps this suite about the rule
 * itself.
 */
#[CoversClass(Secrets::class)]
final class SecretsTest extends TestCase
{
    private const string SECRET = 'sk-ralph-0123456789-never-printed';

    private Environment $environment;

    protected function setUp(): void
    {
        $this->environment = new Environment();
    }

    protected function tearDown(): void
    {
        $this->environment->restore();
    }

    #[Test]
    public function a_declared_value_is_replaced_by_the_variable_that_holds_it(): void
    {
        $secrets = $this->of(['ANTHROPIC_API_KEY' => self::SECRET]);

        self::assertSame(
            'the agent reported: using [redacted ANTHROPIC_API_KEY] for this attempt',
            $secrets->redact('the agent reported: using ' . self::SECRET . ' for this attempt'),
        );
        self::assertSame(['ANTHROPIC_API_KEY'], $secrets->names());
        self::assertSame([], $secrets->unredactable());
        self::assertFalse($secrets->isEmpty());
        self::assertSame(1, $secrets->count());
    }

    /**
     * The placeholder names the variable because the name is the actionable
     * half and is printed elsewhere anyway, and because a reader has to be able
     * to tell a redaction from an agent that typed the word "redacted".
     */
    #[Test]
    public function the_placeholder_names_the_variable(): void
    {
        self::assertSame('[redacted ANTHROPIC_API_KEY]', Secrets::placeholderFor('ANTHROPIC_API_KEY'));
    }

    #[Test]
    public function every_occurrence_goes_including_one_inside_a_word(): void
    {
        $secrets = $this->of(['KEY' => self::SECRET]);

        self::assertSame(
            '[redacted KEY] [redacted KEY] Bearer:[redacted KEY]',
            $secrets->redact(self::SECRET . ' ' . self::SECRET . ' Bearer:' . self::SECRET),
        );
    }

    /**
     * A second pass is a no-op, which is what makes it safe for a seam to
     * redact text another seam has already been through — and the package has
     * several that can both see the same sentence.
     */
    #[Test]
    public function redacting_twice_changes_nothing(): void
    {
        $secrets = $this->of(['KEY' => self::SECRET]);
        $once = $secrets->redact('ran with ' . self::SECRET);

        self::assertSame($once, $secrets->redact($once));
    }

    /**
     * The nothing-to-do case, which is a real object rather than a null: a
     * project that declares no authentication variables takes the same code
     * path as one that does.
     */
    #[Test]
    public function nothing_is_secret_until_something_is_declared(): void
    {
        $secrets = Secrets::none();

        self::assertTrue($secrets->isEmpty());
        self::assertSame(0, $secrets->count());
        self::assertSame([], $secrets->names());
        self::assertSame('ran with ' . self::SECRET, $secrets->redact('ran with ' . self::SECRET));
    }

    /**
     * The refusal that protects the diagnostics: replacing every occurrence of
     * a handful of characters would corrupt every path and sentence the package
     * writes, and no credential is that short. The name is reported so the
     * decision can be printed where the attempt is set up rather than left
     * silent.
     */
    #[Test]
    public function a_value_too_short_to_replace_safely_is_left_alone_and_reported(): void
    {
        $secrets = $this->of(['DEBUG_MODE' => 'on', 'SHORT_KEY' => 'abc']);

        self::assertTrue($secrets->isEmpty());
        self::assertSame(['DEBUG_MODE', 'SHORT_KEY'], $secrets->unredactable());
        self::assertSame([], $secrets->names());
        self::assertSame(
            'running on src/on/abc with 3 of abc checks',
            $secrets->redact('running on src/on/abc with 3 of abc checks'),
        );
    }

    /**
     * The edge of the rule, both sides of it, so that the threshold is a stated
     * number rather than whatever the implementation happens to do.
     */
    #[Test]
    public function the_threshold_is_the_documented_length(): void
    {
        $atTheLimit = str_repeat('a', Secrets::MINIMUM_LENGTH);
        $under = str_repeat('b', Secrets::MINIMUM_LENGTH - 1);

        $secrets = $this->of(['AT_LIMIT' => $atTheLimit, 'UNDER' => $under]);

        self::assertSame(['AT_LIMIT'], $secrets->names());
        self::assertSame(['UNDER'], $secrets->unredactable());
        self::assertSame(
            '[redacted AT_LIMIT] and ' . $under,
            $secrets->redact($atTheLimit . ' and ' . $under),
        );
    }

    /**
     * A secret that contains another is replaced whole, and a placeholder is
     * never redacted again by a value that happens to occur inside it. Both
     * come from the single longest-match pass rather than from ordering the
     * caller has to get right.
     */
    #[Test]
    public function the_longer_of_two_overlapping_values_wins(): void
    {
        $secrets = $this->of([
            'PREFIX' => 'sk-ralph-prefix',
            'WHOLE' => 'sk-ralph-prefix-and-the-rest',
        ]);

        self::assertSame(
            '[redacted WHOLE] then [redacted PREFIX]',
            $secrets->redact('sk-ralph-prefix-and-the-rest then sk-ralph-prefix'),
        );
    }

    /**
     * Two variables set to one value are one replacement: the value is what
     * appears in a diagnostic, and which variable it arrived through cannot be
     * known from the text.
     */
    #[Test]
    public function two_names_for_one_value_are_one_replacement(): void
    {
        $secrets = $this->of(['FIRST_KEY' => self::SECRET, 'SECOND_KEY' => self::SECRET]);

        self::assertSame(1, $secrets->count());
        self::assertSame('[redacted FIRST_KEY]', $secrets->redact(self::SECRET));
    }

    /**
     * A credential is an opaque string, so the replacement must not be a
     * pattern: a value full of characters that mean something to a regular
     * expression, a shell or JSON is matched literally.
     */
    #[Test]
    public function a_value_is_matched_literally_and_not_as_a_pattern(): void
    {
        $awkward = 'tok.$1*+?[]{}\\/"\'`|&(en)';
        $secrets = $this->of(['AWKWARD_KEY' => $awkward]);

        self::assertSame('used [redacted AWKWARD_KEY].', $secrets->redact('used ' . $awkward . '.'));
        self::assertSame('tok.$1 is not it', $secrets->redact('tok.$1 is not it'));
    }

    /**
     * Payload redaction, for the JSON this package writes about itself: every
     * string at every depth, keys left alone because they are field names the
     * package chose, and the document still a document afterwards.
     */
    #[Test]
    public function a_structured_payload_is_redacted_at_every_depth(): void
    {
        $secrets = $this->of(['KEY' => self::SECRET]);

        $redacted = $secrets->redactData([
            'reason' => 'the agent printed ' . self::SECRET,
            'attempts' => 2,
            'checks' => [
                ['name' => 'tests', 'output' => 'saw ' . self::SECRET],
            ],
            'nothing' => null,
        ]);

        self::assertSame([
            'reason' => 'the agent printed [redacted KEY]',
            'attempts' => 2,
            'checks' => [
                ['name' => 'tests', 'output' => 'saw [redacted KEY]'],
            ],
            'nothing' => null,
        ], $redacted);

        $encoded = (string) json_encode($redacted, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET, $encoded);
        self::assertIsArray(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function several_lines_are_redacted_one_by_one(): void
    {
        $secrets = $this->of(['KEY' => self::SECRET]);

        self::assertSame(
            ['first', 'key is [redacted KEY]', 'last'],
            $secrets->redactLines(['first', 'key is ' . self::SECRET, 'last']),
        );
    }

    /**
     * A merge is additive and keeps the name the earlier set recorded, so that
     * two diagnostics about one credential cannot disagree about what to call
     * it.
     */
    #[Test]
    public function merging_adds_values_and_keeps_the_first_name(): void
    {
        $first = $this->of(['FIRST_KEY' => self::SECRET]);
        $second = $this->of(['SECOND_KEY' => self::SECRET, 'OTHER_KEY' => 'tok-ralph-other-value']);

        $merged = $first->merge($second);

        self::assertSame(2, $merged->count());

        // Three names over two values: both variables that hold the shared
        // value are reported as covered, while the text it is replaced with
        // stays the one the first set chose.
        self::assertSame(['FIRST_KEY', 'SECOND_KEY', 'OTHER_KEY'], $merged->names());
        self::assertSame(
            '[redacted FIRST_KEY] [redacted OTHER_KEY]',
            $merged->redact(self::SECRET . ' tok-ralph-other-value'),
        );
    }

    /**
     * Every configured agent's variables, not only the selected one's: a key
     * exported for the agent that was not chosen is still in the environment,
     * still reaches the agent that was, and would still be a credential in a
     * log if something printed it.
     */
    #[Test]
    public function a_projects_secrets_are_every_configured_agents(): void
    {
        $configuration = (new ConfigurationLoader())->fromArray([
            'agent' => 'codex',
            'agents' => [
                'codex' => ['adapter' => 'codex', 'executable' => 'codex', 'environment' => ['CODEX_TEST_KEY']],
                'claude' => ['adapter' => 'claude', 'executable' => 'claude', 'environment' => ['CLAUDE_TEST_KEY']],
            ],
        ], '/tmp');

        $secrets = Secrets::forProject($configuration, $this->reader([
            'CODEX_TEST_KEY' => self::SECRET,
            'CLAUDE_TEST_KEY' => 'tok-ralph-claude-value',
        ]));

        self::assertSame(['CODEX_TEST_KEY', 'CLAUDE_TEST_KEY'], $secrets->names());
        self::assertSame(
            'both: [redacted CODEX_TEST_KEY] [redacted CLAUDE_TEST_KEY]',
            $secrets->redact('both: ' . self::SECRET . ' tok-ralph-claude-value'),
        );
    }

    /**
     * A declared variable the environment does not provide is not a secret: an
     * unset one has no value to redact, and the empty string is the shape an
     * unset variable takes in a shell profile. Redacting it would replace every
     * gap in every diagnostic.
     */
    #[Test]
    public function an_unset_variable_contributes_nothing(): void
    {
        $configuration = (new ConfigurationLoader())->fromArray([
            'agents' => [
                'codex' => ['adapter' => 'codex', 'executable' => 'codex', 'environment' => ['ABSENT_KEY']],
            ],
        ], '/tmp');

        $secrets = Secrets::forProject($configuration, $this->reader([]));

        self::assertTrue($secrets->isEmpty());
        self::assertSame([], $secrets->names());
        self::assertSame([], $secrets->unredactable());
        self::assertSame('nothing  to do', $secrets->redact('nothing  to do'));
    }

    /**
     * Without a reader the values come from the process environment, which is
     * the only source a run has.
     */
    #[Test]
    public function the_process_environment_is_the_default_source(): void
    {
        $this->environment->set('RALPH_TEST_SECRETS_KEY', self::SECRET);

        $configuration = (new ConfigurationLoader())->fromArray([
            'agents' => [
                'codex' => [
                    'adapter' => 'codex',
                    'executable' => 'codex',
                    'environment' => ['RALPH_TEST_SECRETS_KEY'],
                ],
            ],
        ], '/tmp');

        self::assertSame(
            '[redacted RALPH_TEST_SECRETS_KEY]',
            Secrets::forProject($configuration)->redact(self::SECRET),
        );
    }

    /**
     * Resolved variables, as the one type that holds values hands them over.
     *
     * Built through {@see AgentEnvironment} rather than by a constructor of its
     * own, because that is the only way a value gets in here in production and
     * a test that took a short cut would be testing a path nothing uses.
     *
     * @param array<string, string> $values
     */
    private function of(array $values): Secrets
    {
        return AgentEnvironment::of(
            new AgentDefinition('fixture', 'codex', 'codex', environment: array_keys($values)),
            $this->reader($values),
        )->secrets();
    }

    /**
     * An environment reader over exactly these variables.
     *
     * @param array<string, string> $values
     *
     * @return callable(string): (string|false)
     */
    private function reader(array $values): callable
    {
        return static fn (string $name): string|false => $values[$name] ?? false;
    }
}
