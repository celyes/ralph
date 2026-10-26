<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Support;

use RuntimeException;

/**
 * Runs the `ralph` binary as a real subprocess.
 *
 * Driving the application in-process cannot show that the entry point itself
 * works: the shebang, the runtime check, and the Composer-autoloader discovery
 * in `bin/ralph` only run when the file is actually executed. This helper
 * covers that, and later sections reuse it for runs that have to be
 * interrupted or time-limited.
 */
final class Cli
{
    /**
     * Seconds to wait before a test invocation is considered hung.
     */
    private const int TIMEOUT_SECONDS = 60;

    /**
     * Run `php bin/ralph <arguments>` and capture its result.
     *
     * The current PHP binary is used explicitly rather than relying on the
     * shebang, so the test exercises the runtime the suite is running on.
     *
     * @param list<string> $arguments arguments after the binary name
     * @param array<string, string|false> $environment variables to add to, or
     *        with `false`, remove from the inherited environment
     */
    public static function run(
        array $arguments = [],
        ?string $workingDirectory = null,
        array $environment = [],
    ): CliResult {
        $command = [PHP_BINARY, Paths::inPackage('bin/ralph'), ...$arguments];

        $descriptors = [
            0 => ['file', self::nullDevice(), 'rb'],
            1 => ['pipe', 'wb'],
            2 => ['pipe', 'wb'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $workingDirectory ?? Paths::packageRoot(),
            self::environment($environment),
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start ' . implode(' ', $command));
        }

        // Both pipes are drained together: reading one to completion first can
        // deadlock on a child that fills the other pipe's buffer meanwhile.
        [$standardOutput, $errorOutput] = self::drain($pipes);

        $exitCode = proc_close($process);

        return new CliResult($command, $exitCode, $standardOutput, $errorOutput);
    }

    /**
     * Read both output pipes until the child closes them.
     *
     * @param array<int, resource> $pipes
     * @return array{string, string}
     */
    private static function drain(array $pipes): array
    {
        $captured = [1 => '', 2 => ''];

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $open = $pipes;
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while ($open !== []) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(sprintf(
                    'ralph did not finish within %d seconds.',
                    self::TIMEOUT_SECONDS,
                ));
            }

            $read = $open;
            $write = $except = [];

            if (stream_select($read, $write, $except, 1) === false) {
                throw new RuntimeException('Unable to wait for output from ralph.');
            }

            foreach ($read as $stream) {
                $descriptor = (int) array_search($stream, $open, true);

                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($open[$descriptor]);

                    continue;
                }

                $captured[$descriptor] .= $chunk;
            }
        }

        return [$captured[1], $captured[2]];
    }

    /**
     * The environment for the child process.
     *
     * The inherited environment is passed through so the suite works under the
     * developer's PHP configuration, except that COMPOSER_VENDOR_DIR is dropped
     * unless a test sets it: `bin/ralph` prefers it when locating the
     * autoloader, and a value left over from the surrounding shell would make
     * these tests depend on it.
     *
     * @param array<string, string|false> $overrides
     * @return array<string, string>
     */
    private static function environment(array $overrides): array
    {
        $environment = getenv();
        unset($environment['COMPOSER_VENDOR_DIR']);

        foreach ($overrides as $name => $value) {
            if ($value === false) {
                unset($environment[$name]);

                continue;
            }

            $environment[$name] = $value;
        }

        return array_filter($environment, 'is_string');
    }

    private static function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }

    private function __construct()
    {
    }
}
