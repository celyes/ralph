<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Agent;

use Celyes\RalphPhp\Config\Defaults;
use Celyes\RalphPhp\Config\Path;
use Celyes\RalphPhp\Exception\ConfigurationException;
use Throwable;

/**
 * A project's own adapter registrations, read from `.ralph/adapters.php`.
 *
 * The documented half of PROJECT.md section E, item 3. {@see AdapterRegistry}
 * is where an adapter is registered; this is how a project gets a registration
 * into it when Ralph PHP is the program being run rather than a library being
 * called. The file is optional, and a project that uses only Codex and Claude
 * Code never has one.
 *
 * It is PHP rather than a class name in `config.json` because a provider may
 * need constructing — a path, a client, an adapter wrapping another — and
 * because the project's classes have to be loadable, which only the project
 * knows how to arrange. It returns an array of {@see AdapterProvider}
 * instances; keys are ignored, since each provider names the adapter it
 * registers:
 *
 * ```php
 * <?php // .ralph/adapters.php
 *
 * require __DIR__ . '/../vendor/autoload.php';
 *
 * return [new Acme\Ralph\AcmeAdapterProvider()];
 * ```
 *
 * **Loading the autoloader is the file's own job.** A Ralph PHP installed as a
 * project dev dependency already shares the project's autoloader, so the
 * `require` above is redundant there — and harmless, because Composer's
 * autoloader is idempotent. Installed as a global tool it is not: the package
 * runs under its own autoloader and knows nothing of the project's classes.
 * One line that works in both cases beats a rule that depends on how the user
 * installed the tool.
 *
 * **This executes code from the project.** Deliberately, and with the same
 * trust a project's `phpunit.xml` bootstrap or a Composer script already has:
 * it is a tracked file in the repository the user is running the loop in,
 * reviewed like the rest of it. Two things follow. The path is fixed
 * ({@see Defaults::ADAPTERS_FILE}) rather than configurable, so nothing in a
 * settings file can redirect which code a run loads; and the file is the user's
 * to create — `ralph init` does not write one, because a project that does not
 * extend the package should not acquire an executed PHP file by default.
 *
 * Problems are reported as configuration failures before a run does anything:
 * a file that does not return what it must, an entry that is not a provider, or
 * an error raised while loading it. None of them stop at a stack trace, because
 * the user's next action is to edit one named file.
 */
final class AdapterRegistrationFile
{
    /**
     * What the file is loaded once per process and remembered by, keyed by
     * resolved path.
     *
     * `require` executes a file every time, so a second load of a file that
     * declares a class would be a fatal redeclaration — and `require_once`
     * cannot be used instead, because its second call returns `true` rather
     * than the file's value and there would be nothing left to validate.
     *
     * @var array<string, mixed>
     */
    private static array $loaded = [];

    private function __construct(private readonly string $path)
    {
    }

    /**
     * The registration file of a project, whether or not it exists.
     */
    public static function in(string $projectRoot): self
    {
        return self::at(Path::normalize($projectRoot . '/' . Defaults::ADAPTERS_FILE));
    }

    /**
     * The registration file at an exact path, for tests and for a caller that
     * has already resolved it.
     */
    public static function at(string $path): self
    {
        return new self($path);
    }

    /**
     * Absolute path of the file, for an error message or a log header.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Whether the project has a registration file at all.
     */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Register everything the file provides, and name what was registered.
     *
     * A project without the file registers nothing and is not an error: that is
     * every project that uses the adapters the package ships.
     *
     * @return list<string> the adapter names this file registered, in order
     *
     * @throws ConfigurationException when the file cannot be loaded, does not
     *         return an array of providers, or registers a name that is taken
     */
    public function registerInto(AdapterRegistry $registry): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        // Checked before loading rather than caught afterwards: a `require`
        // that cannot open its file is a fatal compile error, which no `catch`
        // reaches. A path that exists but cannot be read is also a different
        // mistake from not having a registration file at all.
        if (!$this->exists() || !is_readable($this->path)) {
            throw new ConfigurationException(
                sprintf('The adapter registration file %s cannot be read.', $this->path),
                [
                    is_dir($this->path) ? 'It is a directory.' : 'Check its permissions.',
                    'It must be a PHP file returning an array of ' . AdapterProvider::class . ' instances.',
                ],
            );
        }

        $returned = $this->load();

        if (!is_array($returned)) {
            throw new ConfigurationException(
                sprintf(
                    'The adapter registration file %s must return an array, %s returned.',
                    $this->path,
                    get_debug_type($returned),
                ),
                $this->shapeHints(),
            );
        }

        $names = [];

        foreach ($returned as $key => $provider) {
            if (!$provider instanceof AdapterProvider) {
                throw new ConfigurationException(
                    sprintf(
                        'The adapter registration file %s returned %s at %s, not %s.',
                        $this->path,
                        get_debug_type($provider),
                        is_int($key) ? sprintf('index %d', $key) : sprintf('key "%s"', $key),
                        AdapterProvider::class,
                    ),
                    $this->shapeHints(),
                );
            }

            // Not caught: a name that is taken, or unusable, is the registry's
            // own error and already names this file's provider class.
            $registry->register($provider);
            $names[] = $provider->adapter();
        }

        return $names;
    }

    /**
     * The file's value, loaded in a scope of its own.
     *
     * A static closure, so the file cannot reach this object through `$this`,
     * and `$path` is its only visible variable.
     *
     * @throws ConfigurationException when loading raised anything at all
     */
    private function load(): mixed
    {
        $key = realpath($this->path);

        if ($key === false) {
            $key = $this->path;
        }

        if (array_key_exists($key, self::$loaded)) {
            return self::$loaded[$key];
        }

        $load = static fn (string $path): mixed => require $path;

        try {
            return self::$loaded[$key] = $load($this->path);
        } catch (Throwable $error) {
            // Including an Error: a parse error, a class that is not
            // autoloadable, a constructor that throws. All of them are the
            // user's file failing to load, and reporting them as a Ralph PHP
            // crash would point at the wrong code.
            throw new ConfigurationException(
                sprintf('The adapter registration file %s could not be loaded.', $this->path),
                [
                    sprintf('%s: %s', $error::class, $error->getMessage()),
                    'If its classes come from the project, the file has to load the project\'s '
                        . 'autoloader itself: Ralph PHP installed as a global tool does not have it.',
                ],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function shapeHints(): array
    {
        return [
            sprintf('Return an array of %s instances, or an empty array.', AdapterProvider::class),
            'For example: return [new Acme\\Ralph\\AcmeAdapterProvider()];',
            'Keys are ignored: each provider names the adapter it registers.',
        ];
    }
}
