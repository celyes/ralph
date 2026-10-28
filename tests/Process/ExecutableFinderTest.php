<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Tests\Process;

use Celyes\RalphPhp\Process\ExecutableFinder;
use Celyes\RalphPhp\Tests\Support\Environment;
use Celyes\RalphPhp\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Finding the program a configured command would run.
 *
 * PATH is set for the duration of each test and restored afterwards: what the
 * finder does with a real search path is the behaviour preflight depends on.
 */
#[CoversClass(ExecutableFinder::class)]
final class ExecutableFinderTest extends TestCase
{
    private ExecutableFinder $finder;

    private string $directory;

    private Environment $environment;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('The executable bit is a POSIX notion; the supported platforms are Linux and WSL.');
        }

        $this->finder = new ExecutableFinder();
        $this->directory = TemporaryDirectory::create('ralph-executables');
        $this->environment = new Environment();
    }

    protected function tearDown(): void
    {
        $this->environment->restore();
        TemporaryDirectory::remove($this->directory);
    }

    #[Test]
    public function it_finds_a_bare_name_on_the_search_path(): void
    {
        $program = $this->writeProgram('bin/agent');
        $this->environment->set('PATH', $this->directory . '/bin');

        self::assertSame($program, $this->finder->find('agent', $this->directory));
    }

    #[Test]
    public function the_search_path_is_honoured_in_order(): void
    {
        $first = $this->writeProgram('first/agent');
        $this->writeProgram('second/agent');

        $this->environment->set('PATH', $this->directory . '/first' . PATH_SEPARATOR . $this->directory . '/second');

        self::assertSame($first, $this->finder->find('agent', $this->directory));
    }

    #[Test]
    public function a_file_that_is_not_executable_is_not_found(): void
    {
        $this->writeProgram('bin/agent', executable: false);
        $this->environment->set('PATH', $this->directory . '/bin');

        self::assertNull($this->finder->find('agent', $this->directory));
    }

    #[Test]
    public function a_directory_with_a_matching_name_is_not_found(): void
    {
        mkdir($this->directory . '/bin/agent', 0o700, true);
        $this->environment->set('PATH', $this->directory . '/bin');

        self::assertNull($this->finder->find('agent', $this->directory));
    }

    #[Test]
    public function an_unset_search_path_finds_nothing_and_says_so(): void
    {
        $this->writeProgram('bin/agent');
        $this->environment->remove('PATH');

        self::assertNull($this->finder->find('agent', $this->directory));
        self::assertSame('PATH is empty or unset', $this->finder->describeSearchPath());
    }

    #[Test]
    public function an_empty_search_path_entry_is_not_treated_as_the_current_directory(): void
    {
        $this->writeProgram('agent');
        $this->environment->set('PATH', PATH_SEPARATOR . PATH_SEPARATOR);

        self::assertSame([], $this->finder->searchPath());
        self::assertNull($this->finder->find('agent', $this->directory));
    }

    #[Test]
    public function an_absolute_path_is_checked_where_it_points(): void
    {
        $program = $this->writeProgram('tools/agent');
        // Nothing of that name is on the search path, so only the path itself
        // can be what answers.
        $this->environment->set('PATH', '/nonexistent-' . bin2hex(random_bytes(4)));

        self::assertSame($program, $this->finder->find($program, $this->directory));
        self::assertNull($this->finder->find($this->directory . '/tools/missing', $this->directory));
        self::assertFalse(ExecutableFinder::isSearched($program));
    }

    #[Test]
    public function a_relative_path_is_resolved_against_the_working_directory(): void
    {
        $program = $this->writeProgram('tools/agent');

        self::assertSame($program, $this->finder->find('tools/agent', $this->directory));
        self::assertSame($program, $this->finder->find('./tools/agent', $this->directory));
        self::assertFalse(ExecutableFinder::isSearched('tools/agent'));
        self::assertNull($this->finder->find('tools/agent', sys_get_temp_dir()));
    }

    #[Test]
    public function a_bare_name_is_searched_rather_than_used_as_a_path(): void
    {
        self::assertTrue(ExecutableFinder::isSearched('codex'));
        self::assertNull($this->finder->find('', $this->directory));
    }

    #[Test]
    public function a_short_search_path_is_listed_and_a_long_one_is_counted(): void
    {
        $this->environment->set('PATH', implode(PATH_SEPARATOR, ['/one', '/two']));
        self::assertSame('PATH: /one, /two', $this->finder->describeSearchPath());

        $this->environment->set('PATH', implode(PATH_SEPARATOR, ['/1', '/2', '/3', '/4', '/5', '/6']));
        self::assertSame('PATH, which has 6 directories', $this->finder->describeSearchPath());
    }

    /**
     * Write a program at a path inside the temporary directory, and return the
     * path it resolves to.
     */
    private function writeProgram(string $relative, bool $executable = true): string
    {
        $path = $this->directory . '/' . $relative;
        $parent = dirname($path);

        if (!is_dir($parent)) {
            mkdir($parent, 0o700, true);
        }

        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, $executable ? 0o700 : 0o600);

        return (string) realpath($path);
    }
}
