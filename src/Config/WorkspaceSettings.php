<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Config;

/**
 * The `workspace` section: what a freshly created task worktree needs before
 * the project can be built, tested or worked on in it (PROJECT.md section F,
 * item 5).
 *
 * A linked worktree is a checkout of the repository's *tracked* files and
 * nothing else. Everything a PHP project needs that Git does not carry is
 * therefore absent from a workspace the moment it is created: `vendor/`,
 * because it is installed rather than committed; a `phpunit.xml` that is
 * git-ignored next to a committed `phpunit.xml.dist`; a `.env`, an `auth.json`,
 * a built asset directory. A run that skipped this would send the agent into a
 * directory where `composer test` fails for reasons that have nothing to do
 * with the task, and then charge the agent for not fixing it.
 *
 * Two settings, because the absent things divide cleanly in two:
 *
 *  - **`prepare`** — commands run inside the new workspace, in order.
 *    `composer install` is the default and the whole point: dependencies are
 *    reproducible from committed files, so they are installed rather than
 *    copied.
 *  - **`copy`** — files copied in from the controlling checkout. For what is
 *    *not* reproducible: a local configuration file or a credential that exists
 *    on this machine and in no commit.
 *
 * Copies happen before the commands, since an install may need an `auth.json`
 * or a `.env` to work at all.
 */
final class WorkspaceSettings
{
    /**
     * @param list<PreparationCommand> $prepare commands, in the order they run
     * @param list<string> $copy paths relative to the project root, copied into
     *        the workspace at the same relative path
     */
    public function __construct(
        private readonly array $prepare = [],
        private readonly array $copy = [],
    ) {
    }

    public static function fromRaw(RawConfiguration $raw): self
    {
        $raw->rejectUnknownKeys(['prepare', 'copy']);

        return new self(self::commands($raw), $raw->stringList('copy', Defaults::copies()));
    }

    /**
     * The commands that prepare a workspace, in the order they must run.
     *
     * @return list<PreparationCommand>
     */
    public function prepare(): array
    {
        return $this->prepare;
    }

    /**
     * The paths copied in from the controlling checkout, relative to the
     * project root.
     *
     * @return list<string>
     */
    public function copy(): array
    {
        return $this->copy;
    }

    /**
     * Whether a workspace needs anything doing to it at all. A project with
     * neither setting is one whose checkout is usable as Git leaves it.
     */
    public function isEmpty(): bool
    {
        return $this->prepare === [] && $this->copy === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prepare' => array_map(
                static fn (PreparationCommand $command): array => $command->toArray(),
                $this->prepare,
            ),
            'copy' => $this->copy,
        ];
    }

    /**
     * The configured preparation commands, or the defaults when the section
     * does not mention them.
     *
     * Replaced wholesale rather than merged, for the reason `checks` is: a user
     * who lists one command means that one, not that one plus the package's
     * assumption. An explicit empty list is therefore how a project says its
     * worktrees need no preparation.
     *
     * @return list<PreparationCommand>
     */
    private static function commands(RawConfiguration $raw): array
    {
        $configured = $raw->sectionList('prepare');

        if ($configured === null) {
            $configured = array_map(
                static fn (array $command): RawConfiguration => RawConfiguration::fromArray(
                    $command,
                    'the built-in defaults',
                ),
                Defaults::prepare(),
            );
        }

        return array_map(
            static fn (RawConfiguration $command): PreparationCommand => PreparationCommand::fromRaw($command),
            $configured,
        );
    }
}
