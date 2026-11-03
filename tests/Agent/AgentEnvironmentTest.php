<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AgentEnvironment;
use Celyes\RalphPhp\Config\AgentDefinition;
use Celyes\RalphPhp\Process\ProcessRunner;
use Celyes\RalphPhp\Tests\Support\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Resolving an agent's declared authentication variables.
 *
 * The behaviour under test is almost entirely about what does *not* come out:
 * PROJECT.md section E, item 8 wants environment-based authentication "without
 * printing or persisting credentials", so most of these assertions are that a
 * known value is absent from a description, from the structured form, and from
 * every accessor except the one that feeds `proc_open`.
 *
 * The secret used throughout is a recognisable literal rather than a realistic
 * key, so that a test failing because it leaked says so unmistakably.
 */
#[CoversClass(AgentEnvironment::class)]
final class AgentEnvironmentTest extends TestCase
{
    /**
     * A value no other part of the suite could produce by accident.
     */
    private const string SECRET = 'sk-ralph-test-value-must-never-be-printed';

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
    public function an_agent_that_declares_nothing_is_complete_and_adds_nothing(): void
    {
        $resolved = AgentEnvironment::of($this->definition());

        self::assertTrue($resolved->isComplete());
        self::assertFalse($resolved->isDeclared());
        self::assertSame([], $resolved->names());
        self::assertSame([], $resolved->missing());
        self::assertSame([], $resolved->forProcess());
        self::assertSame('no authentication variables declared', $resolved->describe());
    }

    /**
     * The common case being "nothing declared" is why it is not a problem:
     * both built-in CLIs can be authenticated by an interactive login.
     */
    #[Test]
    public function a_declared_variable_that_is_set_is_passed_to_the_process(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['ANTHROPIC_API_KEY']),
            $this->reader(['ANTHROPIC_API_KEY' => self::SECRET]),
        );

        self::assertTrue($resolved->isComplete());
        self::assertTrue($resolved->isDeclared());
        self::assertSame(['ANTHROPIC_API_KEY' => self::SECRET], $resolved->forProcess());
    }

    #[Test]
    public function the_declared_names_keep_their_configuration_order(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['OPENAI_BASE_URL', 'OPENAI_API_KEY']),
            $this->reader(['OPENAI_API_KEY' => self::SECRET, 'OPENAI_BASE_URL' => 'https://example.test']),
        );

        self::assertSame(['OPENAI_BASE_URL', 'OPENAI_API_KEY'], $resolved->names());
    }

    #[Test]
    public function a_variable_the_environment_does_not_provide_is_missing(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['OPENAI_API_KEY', 'OPENAI_BASE_URL']),
            $this->reader(['OPENAI_BASE_URL' => 'https://example.test']),
        );

        self::assertFalse($resolved->isComplete());
        self::assertSame(['OPENAI_API_KEY'], $resolved->missing());
        self::assertSame(['OPENAI_API_KEY', 'OPENAI_BASE_URL'], $resolved->names());
    }

    /**
     * An exported but empty variable is the shape an unset one takes in a shell
     * profile or a CI configuration. Treating it as present would send an agent
     * off to report "invalid API key" in prose twenty minutes later.
     */
    #[Test]
    public function an_empty_or_blank_value_counts_as_not_set(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['EMPTY_KEY', 'BLANK_KEY']),
            $this->reader(['EMPTY_KEY' => '', 'BLANK_KEY' => "  \n\t "]),
        );

        self::assertSame(['EMPTY_KEY', 'BLANK_KEY'], $resolved->missing());
        self::assertSame([], $resolved->forProcess());
    }

    /**
     * A missing variable is left out rather than passed as the empty string: an
     * agent reads an empty key as a malformed one, not as an absent one.
     */
    #[Test]
    public function a_missing_variable_is_omitted_rather_than_passed_empty(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['PRESENT_KEY', 'ABSENT_KEY']),
            $this->reader(['PRESENT_KEY' => self::SECRET]),
        );

        self::assertSame(['PRESENT_KEY' => self::SECRET], $resolved->forProcess());
        self::assertArrayNotHasKey('ABSENT_KEY', $resolved->forProcess());
    }

    /**
     * `export ANTHROPIC_API_KEY=$(cat key.txt)` keeps the file's newline, and a
     * key pasted into a CI secret often brings one. The agent then reports a
     * correct key as invalid, which is the least diagnosable form this failure
     * takes — so the value handed to the child is the trimmed one.
     */
    #[Test]
    public function surrounding_whitespace_is_trimmed_off_a_value(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['ANTHROPIC_API_KEY']),
            $this->reader(['ANTHROPIC_API_KEY' => "  " . self::SECRET . "\n"]),
        );

        self::assertSame(['ANTHROPIC_API_KEY' => self::SECRET], $resolved->forProcess());
    }

    #[Test]
    public function a_name_listed_twice_is_resolved_and_reported_once(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['OPENAI_API_KEY', 'OPENAI_API_KEY']),
            $this->reader([]),
        );

        self::assertSame(['OPENAI_API_KEY'], $resolved->names());
        self::assertSame(['OPENAI_API_KEY'], $resolved->missing());
    }

    #[Test]
    public function the_description_names_the_variables_without_their_values(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['ANTHROPIC_API_KEY']),
            $this->reader(['ANTHROPIC_API_KEY' => self::SECRET]),
        );

        self::assertSame('authenticating with ANTHROPIC_API_KEY (value set)', $resolved->describe());
        self::assertStringNotContainsString(self::SECRET, $resolved->describe());
    }

    #[Test]
    public function the_description_says_which_declared_variables_are_not_set(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['OPENAI_API_KEY', 'OPENAI_BASE_URL']),
            $this->reader(['OPENAI_BASE_URL' => 'https://example.test']),
        );

        self::assertSame(
            'declared OPENAI_API_KEY, OPENAI_BASE_URL; OPENAI_API_KEY not set',
            $resolved->describe(),
        );
    }

    #[Test]
    public function the_plural_is_right_when_several_variables_are_set(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['A_KEY', 'B_KEY']),
            $this->reader(['A_KEY' => self::SECRET, 'B_KEY' => self::SECRET]),
        );

        self::assertSame('authenticating with A_KEY, B_KEY (values set)', $resolved->describe());
    }

    /**
     * The structured form is what goes beside
     * {@see \Celyes\RalphPhp\Agent\AgentInvocation::toArray()} in a log line,
     * which is a file on disk inside the user's repository.
     */
    #[Test]
    public function the_structured_form_carries_names_and_presence_only(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['PRESENT_KEY', 'ABSENT_KEY']),
            $this->reader(['PRESENT_KEY' => self::SECRET]),
        );

        self::assertSame(
            ['declared' => ['PRESENT_KEY', 'ABSENT_KEY'], 'missing' => ['ABSENT_KEY']],
            $resolved->toArray(),
        );
        self::assertStringNotContainsString(
            self::SECRET,
            (string) json_encode($resolved->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The guarantee the whole type exists for, asserted as a property of its
     * surface rather than of the three call sites that happen to exist today.
     *
     * Every public method is called, and the only one allowed to return the
     * secret is {@see AgentEnvironment::forProcess()}, whose caller hands it
     * straight to `proc_open`. A future `value()`, `all()` or `__toString()`
     * fails this test, which is the point: a credential that can be asked for
     * is a credential that eventually gets interpolated into a message.
     */
    #[Test]
    public function no_accessor_but_the_one_for_the_process_exposes_a_value(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['ANTHROPIC_API_KEY']),
            $this->reader(['ANTHROPIC_API_KEY' => self::SECRET]),
        );

        $asked = 0;

        foreach (get_class_methods($resolved) as $method) {
            $reflected = new ReflectionMethod($resolved, $method);

            if ($reflected->isStatic() || $reflected->getNumberOfParameters() > 0) {
                continue;
            }

            $asked++;
            $returned = $reflected->invoke($resolved);
            $printed = var_export($returned, true);

            if ($method === 'forProcess') {
                self::assertStringContainsString(self::SECRET, $printed);

                continue;
            }

            self::assertStringNotContainsString(
                self::SECRET,
                $printed,
                sprintf('%s() exposed a credential value.', $method),
            );
        }

        // Guards the loop itself: a renamed surface that asked nothing would
        // otherwise pass by not looking.
        self::assertGreaterThanOrEqual(6, $asked);
    }

    /**
     * Without a reader the values come from the process environment, which is
     * the only source in production: a credential read from a file would be a
     * credential this package could write to one.
     */
    #[Test]
    public function the_process_environment_is_the_default_source(): void
    {
        $this->environment->set('RALPH_TEST_AGENT_KEY', self::SECRET);
        $this->environment->remove('RALPH_TEST_AGENT_ABSENT_KEY');

        $resolved = AgentEnvironment::of(
            $this->definition(['RALPH_TEST_AGENT_KEY', 'RALPH_TEST_AGENT_ABSENT_KEY']),
        );

        self::assertSame(['RALPH_TEST_AGENT_KEY' => self::SECRET], $resolved->forProcess());
        self::assertSame(['RALPH_TEST_AGENT_ABSENT_KEY'], $resolved->missing());
    }

    /**
     * The override array is typed as what
     * {@see \Celyes\RalphPhp\Process\ProcessRunner::run()} accepts, so that
     * passing it on needs no translation step that could drop a variable.
     */
    #[Test]
    public function the_override_array_is_what_the_process_runner_takes(): void
    {
        $resolved = AgentEnvironment::of(
            $this->definition(['ANTHROPIC_API_KEY']),
            $this->reader(['ANTHROPIC_API_KEY' => self::SECRET]),
        );

        $runner = new ReflectionMethod(ProcessRunner::class, 'run');
        $parameter = $runner->getParameters()[3];
        $type = $parameter->getType();

        self::assertSame('environment', $parameter->getName());
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('array', $type->getName());

        // And the values survive a round trip through a real process.
        $output = (new ProcessRunner())->run(
            [PHP_BINARY, '-r', 'echo getenv("ANTHROPIC_API_KEY");'],
            sys_get_temp_dir(),
            30,
            $resolved->forProcess(),
        );

        self::assertSame(self::SECRET, trim($output->standardOutput()));
    }

    /**
     * @param list<string> $environment
     */
    private function definition(array $environment = []): AgentDefinition
    {
        return new AgentDefinition('scripted', 'scripted', PHP_BINARY, null, [], $environment);
    }

    /**
     * A reader over a fixed map, so a test does not depend on the variables the
     * suite happens to run with.
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
