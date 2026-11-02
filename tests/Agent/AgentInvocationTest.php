<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\AgentInvocation;
use Celyes\RalphPhp\Agent\AgentSelection;
use Celyes\RalphPhp\Agent\AgentSource;
use Celyes\RalphPhp\Config\AgentDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One agent process, as an argument array and nothing else.
 */
#[CoversClass(AgentInvocation::class)]
final class AgentInvocationTest extends TestCase
{
    #[Test]
    public function the_command_is_the_program_followed_by_its_arguments(): void
    {
        $invocation = AgentInvocation::of('codex', ['exec', '--model', 'gpt-5']);

        self::assertSame('codex', $invocation->program());
        self::assertSame(['exec', '--model', 'gpt-5'], $invocation->arguments());
        self::assertSame(['codex', 'exec', '--model', 'gpt-5'], $invocation->command());
        self::assertNull($invocation->input());
        self::assertFalse($invocation->hasInput());
    }

    #[Test]
    public function an_agents_program_comes_from_its_configured_executable(): void
    {
        $agent = new AgentSelection(
            new AgentDefinition('codex', 'codex', '/opt/codex/bin/codex'),
            AgentSource::ProjectDefault,
        );

        self::assertSame('/opt/codex/bin/codex', AgentInvocation::forAgent($agent)->program());
        self::assertSame(['exec'], AgentInvocation::forAgent($agent, ['exec'])->arguments());
    }

    #[Test]
    public function an_invocation_must_name_a_program(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name the program');

        AgentInvocation::of('   ');
    }

    /**
     * Task text goes in as an argument, so whatever a shell would make of it
     * never comes up. The point of the type is that there is no other way in.
     */
    #[Test]
    public function text_a_shell_would_act_on_is_kept_as_one_argument(): void
    {
        $description = 'Escape `$HOME`; drop "quotes" && rm -rf * | tee /dev/null';

        $invocation = AgentInvocation::of('claude', ['-p'])->with($description);

        self::assertSame(['claude', '-p', $description], $invocation->command());
        self::assertCount(3, $invocation->command());
    }

    #[Test]
    public function appending_arguments_leaves_the_invocation_it_was_appended_to_alone(): void
    {
        $invocation = AgentInvocation::of('codex', ['exec']);

        $extended = $invocation->with('--full-auto', 'do the thing');

        self::assertSame(['exec'], $invocation->arguments());
        self::assertSame(['exec', '--full-auto', 'do the thing'], $extended->arguments());
    }

    #[Test]
    public function a_briefing_can_be_carried_on_standard_input_instead(): void
    {
        $briefing = "# Task\n\nAdd a --dry-run option.\n";

        $invocation = AgentInvocation::of('claude', ['-p'])->withInput($briefing);

        self::assertTrue($invocation->hasInput());
        self::assertSame($briefing, $invocation->input());
        self::assertSame(['claude', '-p'], $invocation->command());
    }

    #[Test]
    public function resolving_the_program_keeps_the_arguments_and_the_input(): void
    {
        $invocation = AgentInvocation::of('codex', ['exec'])->withInput('briefing');

        $resolved = $invocation->at('/usr/local/bin/codex');

        self::assertSame(['/usr/local/bin/codex', 'exec'], $resolved->command());
        self::assertSame('briefing', $resolved->input());
        self::assertSame('codex', $invocation->program());
    }

    #[Test]
    public function resolving_an_invocation_without_input_keeps_it_without_input(): void
    {
        self::assertFalse(AgentInvocation::of('codex')->at('/usr/local/bin/codex')->hasInput());
    }

    /**
     * A NUL byte would be where the argument ends once the process is started,
     * so a description containing one would reach the agent silently cut short.
     */
    #[Test]
    public function a_nul_byte_is_refused_wherever_it_appears(): void
    {
        $cases = [
            fn (): AgentInvocation => AgentInvocation::of("codex\0extra"),
            fn (): AgentInvocation => AgentInvocation::of('codex', ["exec\0--full-auto"]),
            fn (): AgentInvocation => AgentInvocation::of('codex')->with("do the\0thing"),
            fn (): AgentInvocation => AgentInvocation::of('codex')->withInput("briefing\0"),
            fn (): AgentInvocation => AgentInvocation::of('codex')->at("/usr/bin/\0codex"),
        ];

        foreach ($cases as $index => $case) {
            try {
                $case();
                self::fail(sprintf('Case %d was accepted.', $index));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('NUL byte', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_described_invocation_shows_where_one_argument_ends_and_the_next_begins(): void
    {
        $invocation = AgentInvocation::of('codex', ['exec', 'add a --dry-run option', '']);

        self::assertSame("codex exec 'add a --dry-run option' ''", $invocation->describe());
    }

    /**
     * The briefing is thousands of characters and is in the log already; what a
     * log header needs is that there was one and how long it was.
     */
    #[Test]
    public function a_described_invocation_reports_its_input_by_size_and_does_not_print_it(): void
    {
        $described = AgentInvocation::of('claude', ['-p'])->withInput("secret briefing\n")->describe();

        self::assertStringNotContainsString('secret briefing', $described);
        self::assertSame('claude -p (with 16 characters on standard input)', $described);
    }

    #[Test]
    public function the_structured_form_keeps_the_arguments_apart(): void
    {
        $invocation = AgentInvocation::of('codex', ['exec', 'two words'])->withInput('four');

        self::assertSame([
            'program' => 'codex',
            'arguments' => ['exec', 'two words'],
            'input_characters' => 4,
        ], $invocation->toArray());
    }

    #[Test]
    public function the_structured_form_of_an_invocation_without_input_says_so(): void
    {
        self::assertNull(AgentInvocation::of('codex')->toArray()['input_characters']);
    }
}
