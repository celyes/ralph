<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Agent;

use Celyes\RalphPhp\Agent\ClaudeAdapter;
use Celyes\RalphPhp\Agent\CodexAdapter;
use Celyes\RalphPhp\Agent\VersionedAdapter;
use Celyes\RalphPhp\Agent\VersionRequirement;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What an adapter may require of its CLI, and how a CLI's answer is read.
 *
 * PROJECT.md section E, item 9's incompatible-version half, as a unit: the
 * parsing of a version out of whatever a CLI prints, the comparison against a
 * floor, and the refusal of a requirement an adapter could not have meant.
 * Running the query and deciding what its outcome means belong to
 * {@see \Celyes\RalphPhp\Preflight\Check\AgentVersionCheck} and are tested
 * against real programs in the preflight suite.
 */
#[CoversClass(VersionRequirement::class)]
final class VersionRequirementTest extends TestCase
{
    #[Test]
    public function it_states_the_cli_its_floor_and_the_query(): void
    {
        $requirement = VersionRequirement::of('Codex CLI', '0.40.0');

        self::assertSame('Codex CLI', $requirement->cli());
        self::assertSame('0.40.0', $requirement->minimum());
        self::assertSame(['--version'], $requirement->arguments());
        self::assertSame('Codex CLI 0.40.0 or newer', $requirement->describe());
    }

    #[Test]
    public function an_adapter_may_name_the_arguments_its_cli_answers_to(): void
    {
        // Nothing assumes "--version" means anything in particular: section 4
        // is explicit that two agents' CLIs have nothing in common.
        $requirement = VersionRequirement::of('Acme', '1.2.0', 'version', '--plain');

        self::assertSame(['version', '--plain'], $requirement->arguments());
        self::assertSame(['/opt/acme/bin/acme', 'version', '--plain'], $requirement->command('/opt/acme/bin/acme'));
    }

    #[Test]
    public function the_query_is_an_argument_array_so_a_path_may_contain_spaces(): void
    {
        $requirement = VersionRequirement::of('Claude Code', '2.0.0');

        self::assertSame(
            ['/home/a user/.local/bin/claude', '--version'],
            $requirement->command('/home/a user/.local/bin/claude'),
        );
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function answers(): iterable
    {
        yield 'claude code' => ['2.1.226 (Claude Code)', '2.1.226'];
        yield 'codex' => ['codex-cli 0.44.0', '0.44.0'];
        yield 'a bare number' => ['1.4', '1.4.0'];
        yield 'trailing build text' => ['0.44.0-nightly.3', '0.44.0'];
        yield 'a vendor suffix' => ['2.43.0.windows.1', '2.43.0'];
        yield 'a leading v' => ['v3.2.1', '3.2.1'];
        yield 'a blank first line' => ["\n\n  codex-cli 0.44.0\n", '0.44.0'];
        yield 'the first line that has one' => ["acme cli\nversion 2.0.1\n", '2.0.1'];
        yield 'surrounding words' => ['Acme 1.0.0 (build 77)', '1.0.0'];
        yield 'nothing at all' => ['', null];
        yield 'words without a number' => ['Codex, the coding agent', null];
        yield 'a single number' => ['7', null];
    }

    #[Test]
    #[DataProvider('answers')]
    public function it_reads_the_version_out_of_whatever_the_cli_printed(string $printed, ?string $expected): void
    {
        self::assertSame($expected, VersionRequirement::versionIn($printed));
    }

    #[Test]
    public function a_version_is_satisfied_by_its_floor_and_by_anything_above_it(): void
    {
        $requirement = VersionRequirement::of('Codex CLI', '0.40.0');

        self::assertTrue($requirement->isSatisfiedBy('0.40.0'));
        self::assertTrue($requirement->isSatisfiedBy('0.44.1'));
        self::assertTrue($requirement->isSatisfiedBy('1.0.0'));

        // An operator running something newer is never refused for being
        // ahead; only what is below the floor is.
        self::assertFalse($requirement->isSatisfiedBy('0.39.9'));
        self::assertFalse($requirement->isSatisfiedBy('0.4.0'));
    }

    #[Test]
    public function a_requirement_an_adapter_could_not_have_meant_is_refused(): void
    {
        // Programmer errors in an adapter, raised where they are written
        // rather than reported to a user as a failed check about their
        // machine.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"latest" declared for Acme is not a version number');

        VersionRequirement::of('Acme', 'latest');
    }

    #[Test]
    public function a_requirement_must_name_the_cli_it_is_about(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name the CLI it is about');

        VersionRequirement::of('   ', '1.0.0');
    }

    #[Test]
    public function an_empty_query_argument_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contain an empty one');

        VersionRequirement::of('Acme', '1.0.0', 'version', ' ');
    }

    #[Test]
    public function both_shipped_adapters_declare_the_release_they_were_written_for(): void
    {
        foreach ([new CodexAdapter(), new ClaudeAdapter()] as $adapter) {
            self::assertInstanceOf(VersionedAdapter::class, $adapter);

            $requirement = $adapter->versionRequirement();

            self::assertSame(['--version'], $requirement->arguments());
            self::assertNotNull(VersionRequirement::versionIn($requirement->minimum()));

            // Asked for again on every preflight: the answer comes from what
            // the adapter knows about itself, not from anything it ran.
            self::assertEquals($requirement, $adapter->versionRequirement());
        }

        self::assertSame(CodexAdapter::MINIMUM_VERSION, (new CodexAdapter())->versionRequirement()->minimum());
        self::assertSame(ClaudeAdapter::MINIMUM_VERSION, (new ClaudeAdapter())->versionRequirement()->minimum());
        self::assertSame('Codex CLI', (new CodexAdapter())->versionRequirement()->cli());
        self::assertSame('Claude Code', (new ClaudeAdapter())->versionRequirement()->cli());
    }

    #[Test]
    public function each_floor_refuses_the_generation_its_adapter_is_not_written_for(): void
    {
        // Pinned as behaviour rather than as a literal: these are the releases
        // whose command lines the adapters spell, and the ones whose absence
        // would be a usage message once per attempt.
        $codex = (new CodexAdapter())->versionRequirement();

        self::assertFalse($codex->isSatisfiedBy('0.39.0'), 'a Codex without this exec invocation was accepted');
        self::assertTrue($codex->isSatisfiedBy('0.44.0'), 'the Codex this adapter was written against was refused');

        $claude = (new ClaudeAdapter())->versionRequirement();

        self::assertFalse($claude->isSatisfiedBy('1.0.119'), 'a Claude Code without --permission-mode was accepted');
        self::assertTrue($claude->isSatisfiedBy('2.1.226'), 'the Claude Code this adapter was tested against was refused');
    }
}
