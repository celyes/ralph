# Progress

## 2026-10-26 — A1: Repository and `composer.json`

**Task:** Create the Ralph PHP repository and `composer.json` with its description, PHP
requirement, license metadata, development dependencies, and the `ralph-php` package name
under the publisher's Composer vendor namespace. *(PROJECT.md section A, item 1)*

**What was done**

- Initialised the Git repository on branch `main` with the publisher's author identity.
- Added `composer.json` as `celyes/ralph-php` (vendor namespace taken from the publisher's
  Git/GitHub handle `celyes`), typed as a `library`, with description, keywords, homepage,
  support links, and the MIT `license` field.
- Declared the runtime requirement `php: ^8.3` per the stated support target, plus the
  extensions the design already commits to: `ext-json`, `ext-mbstring`, `ext-pdo` and
  `ext-pdo_sqlite` (SQLite is the state store). All four are present on the dev machine
  (PHP 8.5.11).
- Declared development dependencies: `phpunit/phpunit ^11.5`, `phpstan/phpstan ^2.1`,
  `friendsofphp/php-cs-fixer ^3.75`.
- Added the MIT `LICENSE` file matching the metadata, and a `.gitignore` for `vendor/`,
  `composer.lock` and tool caches.

**Verification**

- `composer validate --strict` → `./composer.json is valid`.
- `composer update --dry-run` resolves the full dev tree (phpunit 11.5.57 and friends),
  confirming the constraints are installable rather than merely well-formed.

**Notes / deviations**

- `autoload` is deliberately absent: PSR-4 configuration is the next checklist item (A2),
  and the checklist asks for one item at a time.
- `composer.lock` is ignored, which is the normal choice for a distributed library; CI (J8)
  will need to resolve dependencies itself.
- No Git remote is configured and the `gh` CLI is not installed on this machine, so the
  commit is local only — `git push` has nothing to push to yet. A remote needs to be added
  before the "push your changes" step can succeed.
- Added a `.gitattributes` enforcing LF endings (the machine's global `core.autocrlf=true` would
  otherwise give `bin/ralph` a CRLF shebang in A3) and marking dev-only files `export-ignore`;
  `core.autocrlf` is set to `input` for this repository.

## 2026-10-26 — A2: PSR-4 autoloading

**Task:** Configure PSR-4 autoloading for package classes and development autoloading for
tests. *(PROJECT.md section A, item 2)*

**What was done**

- Added `autoload.psr-4` mapping `Celyes\RalphPhp\` → `src/`. The root namespace follows
  from the vendor/package name chosen in A1 (`celyes/ralph-php`).
- Added `autoload-dev.psr-4` mapping `Celyes\RalphPhp\Tests\` → `tests/`, so test-only code
  is never shipped in the production autoload map of a consuming project.
- Created `src/Ralph.php` (`Celyes\RalphPhp\Ralph`): dependency-free package metadata —
  `PACKAGE`, `NAME`, `VERSION` (`0.1.0-dev`), and `PROJECT_DIRECTORY` (`.ralph`). The CLI
  bootstrap (A4) needs `NAME`/`VERSION` for its version output, and the config loader (B1)
  needs the project-directory constant.
- Created `tests/Support/Paths.php` (`Celyes\RalphPhp\Tests\Support\Paths`): resolves the
  package root and paths inside it. Needed by later tests that create temporary Git
  repositories (section J) and useful here as a real class under the dev namespace.
- Ran `composer install`, which resolved the dev tree against the A1 constraints
  (phpunit 11.5.57, phpstan, php-cs-fixer — 58 packages).

**Verification**

- `composer validate --strict` → `./composer.json is valid`.
- `composer dump-autoload --optimize --strict-psr` → no PSR-4 violations reported, so the
  file layout actually matches the declared prefixes rather than relying on a classmap.
- Dev autoload: both `Celyes\RalphPhp\Ralph` and `Celyes\RalphPhp\Tests\Support\Paths`
  resolve through `vendor/autoload.php`, and `Ralph::NAME`/`::VERSION` read back correctly.
- Production autoload: after `composer dump-autoload --no-dev --optimize`, `Ralph` still
  autoloads while `Tests\Support\Paths` is absent — confirming the dev/production split
  rather than just that both prefixes are registered. Dev autoload was regenerated
  afterwards.

**Notes / deviations**

- `src/Ralph.php` and `tests/Support/Paths.php` exist because an autoload configuration
  pointing at empty directories is not verifiable; both are minimal and in scope for A2
  rather than pre-empting A3/A4. No CLI entry point, bootstrap, or PHPUnit configuration was
  added — those are the next three checklist items.
- `vendor/` and `composer.lock` remain git-ignored from A1, so the install is local only.
- Still no Git remote on this machine, so this commit cannot be pushed (see A1 notes).

## 2026-10-26 — A3: `bin/ralph` Composer binary

**Task:** Create `bin/ralph`, register it as a Composer binary, and make it executable.
*(PROJECT.md section A, item 3)*

**What was done**

- Added `bin/ralph` with the `#!/usr/bin/env php` shebang and mode `100755` (recorded in
  Git as `create mode 100755`), plus a `/bin/* text eol=lf` rule already present in
  `.gitattributes` so the shebang cannot be broken by CRLF conversion.
- Registered it in `composer.json` as `"bin": ["bin/ralph"]`, which makes Composer link or
  proxy it into the consumer's `vendor/bin/`.
- The entry point is deliberately limited to the three things that must happen before any
  package class can be loaded:
  - reject non-CLI SAPIs and PHP older than 8.3 with a readable message (the file itself
    avoids 8.3-only syntax, so the version message prints instead of a parse error);
  - locate the Composer autoloader across the three supported layouts — the package's own
    checkout (`../vendor/autoload.php`), installation as a project dependency
    (`../../../autoload.php` from `vendor/celyes/ralph-php/bin/`), and a global install via
    `COMPOSER_VENDOR_DIR` — resolving `__DIR__` through `realpath()` so invocation through a
    symlink works;
  - fail with exit code `78` and the list of attempted paths when no autoloader is found, or
    when the autoloader that was found does not provide the package.
- Pending the CLI bootstrap (A4), the entry point prints `Ralph PHP 0.1.0-dev` and exits `0`.
  Help output, version output, command dispatch and exception handling replace that single
  line; nothing else in the file changes.

**Verification**

- `php -l bin/ralph` → no syntax errors. `composer validate --strict` → valid.
- Package checkout: `./bin/ralph` and `php bin/ralph` both print `Ralph PHP 0.1.0-dev`,
  exit `0`; the shebang is not echoed.
- Invocation through a symlink outside the repository (`/tmp/ralph-symlink`) works, confirming
  the `realpath()` handling.
- Consumer install: a throwaway project at `/tmp/ralph-consumer` required the package through
  a `path` repository with `symlink: false`. Composer created `vendor/bin/ralph` (its generated
  bin proxy, which also strips the shebang) and running it printed the expected line, exit `0`.
  After deleting the mirrored `vendor/celyes/ralph-php/vendor/`, both `vendor/bin/ralph` and
  the mirrored `vendor/celyes/ralph-php/bin/ralph` still worked — so the dependency-layout
  autoloader candidate is genuinely exercised, not just the checkout one.
- `COMPOSER_VENDOR_DIR` pointing at the real vendor directory works; pointing it at a
  nonexistent directory falls back to the path candidates instead of failing.
- Missing autoloader: a copy of the file with no `vendor/` printed the candidate list and
  exited `78`. Autoloader without the package: a stub `vendor/autoload.php` produced the
  "does not provide celyes/ralph-php" error and exited `78`.

**Notes / deviations**

- Exit code `78` (`EX_CONFIG`) is used for these pre-bootstrap environment failures. The full
  documented exit-code set is item I5; that item should adopt or supersede this value
  deliberately rather than leave two conventions.
- The consumer-install check above covers binary registration for the dependency layout. A6
  ("verify installation as both a project development dependency and a global Composer tool")
  is still open — a real global install needs the package published or an explicitly
  configured global path repository, and was not done here.
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-26 — A4: CLI application bootstrap

**Task:** Add the CLI application bootstrap with help output, version output, and readable
handling of exceptions. *(PROJECT.md section A, item 4)*

**What was done**

- `src/Console/Application.php` (`Celyes\RalphPhp\Console\Application`): owns argument
  parsing, command dispatch and error reporting. It takes argv as a parameter, returns the
  exit status instead of calling `exit()`, and accepts an `Output`, so tests can drive it with
  their own argv and in-memory streams. `bin/ralph` now ends with
  `exit(Application::create()->run(array_values($argv)));`; its pre-bootstrap checks are
  unchanged, and its unused `RALPH_EXIT_SUCCESS` define was removed.
- `src/Console/Input.php`: parses one small, predictable grammar — the command name must be
  the first token, `--name=value` and `--name value` set values (the spaced form only for
  options the resolved command declares as value-taking, so `--force path` is not misread),
  `--flag` and clustered short flags set flags, and `--` ends option parsing. Command
  resolution happens before option parsing because which options take values depends on the
  command; `Input::commandName()` exists for that first step.
- `src/Console/Output.php`: a two-stream writer (ordinary output, diagnostics) so `status`
  output can be piped while errors stay visible, and so tests can read back what was written.
- `src/Console/ExitCode.php`: `SUCCESS 0`, `USAGE 64`, `UNAVAILABLE 69`, `SOFTWARE 70`,
  `CONFIGURATION 78`. `CONFIGURATION` is the 78 that `bin/ralph` already used for
  pre-bootstrap environment failures, so the A3 note about two conventions is resolved.
- `src/Console/CommandRegistry.php` + `src/Console/Command/CommandInterface.php`: ordered
  registry (help lists commands in registration order) with Levenshtein-based suggestions for
  mistyped names.
- `src/Console/Command/HelpCommand.php`: the command list for `ralph`, `ralph --help` and
  `ralph help`, and per-command help for `ralph help COMMAND` and `ralph COMMAND --help`.
- `src/Console/Command/VersionCommand.php`: prints `Ralph PHP 0.1.0-dev (celyes/ralph-php)`
  plus the PHP version and binary, because support questions about an agent loop start with
  which runtime it ran on.
- `src/Console/Command/PlannedCommand.php`: the six commands from PRD section 3 (`init`,
  `run`, `status`, `logs`, `resume`, `retry`) are registered as placeholders carrying their
  intended usage and the PROJECT.md section that specifies them. Help therefore shows the real
  documented interface marked `(not implemented yet)`, and invoking one exits 69 with a
  readable message instead of "unknown command". Each is replaced by its real implementation
  as the corresponding checklist item lands.
- `src/Exception/RalphException.php` (abstract base carrying `exitCode()` and optional
  `hints()`), with `UsageException` (64) and `CommandUnavailableException` (69). Every failure
  leaves through one `report()` method: a recognised failure prints `ralph: <message>` plus
  indented hints and no trace; anything else is labelled an unexpected error, named as a
  defect, and suggests `--verbose`. `--verbose`/`-v` prints the file, line and trace of the
  exception and of each `getPrevious()` cause. The verbose flag is detected from raw tokens so
  that a parse failure still honours it.
- During a run, PHP warnings and notices are converted to `ErrorException` so they are reported
  through the same path instead of appearing mid-output; deprecations are deliberately left to
  PHP's own handler so a deprecation inside a dependency cannot abort a run. The previous error
  handler is restored in a `finally`.

**Verification**

- `php -l` on every new file and on `bin/ralph`; `composer validate --strict` → valid;
  `composer dump-autoload --optimize --strict-psr` → no PSR-4 violations.
- `vendor/bin/phpstan analyse src bin --level=9` → `[OK] No errors`.
- Output paths: `ralph`, `ralph --help`, `ralph help` all print the command list, exit 0;
  `ralph --version`, `ralph -V`, `ralph version` print the version/runtime lines, exit 0;
  `ralph help run` and `ralph run --help` print the same per-command help, exit 0.
- Error paths, each checked for message, hints and status: unknown command (`frobnicate`) → 64;
  mistyped command (`runn`) → 64 with `Did you mean "run"?`; `help nope` → 64; unknown global
  option (`--nope`, two of them, `-z`) → 64; `run --agent` with no value → 64; planned command
  (`run`, `status`) → 69.
- Streams: `--help` writes 23 lines to stdout and nothing to stderr; `frobnicate` writes
  nothing to stdout and its error to stderr. Driving the application with `php://memory`
  streams returns status 64 with empty stdout and the message on stderr, confirming the API the
  tests in A5/J will use.
- Unexpected exceptions: a throwaway command registered on the real application threw a
  `RuntimeException` with a `LogicException` cause → exit 70, "unexpected error", defect notice
  and the `--verbose` suggestion; with `--verbose`, both the exception and its `Caused by`
  cause printed with file, line and trace. A command calling `file_get_contents()` on a missing
  path surfaced as a readable `ErrorException` (exit 70) rather than an inline PHP warning,
  while `trigger_error(..., E_USER_DEPRECATED)` printed PHP's own notice and the command
  continued to exit 0.
- Dependency layout: a throwaway consumer project at `/tmp/ralph-consumer` installed the
  package through a `path` repository with `symlink: false`, its mirrored `vendor/` was deleted,
  and `vendor/bin/ralph --version`, `help status` and `status` all behaved as above — so the
  bootstrap loads through the consumer's autoloader, not only in the package checkout. The
  throwaway project and fixture script were removed afterwards.

**Notes / deviations**

- No tests were added: the test runner is the next checklist item (A5), which will cover the
  CLI-start test. The verification above was done by invoking the CLI and by driving
  `Application` from `php -r`.
- `ExitCode` defines only the statuses the bootstrap can produce. Item I5 owns the complete
  documented set (execution failure, limit exhaustion, blocking, interruption) and should extend
  this class rather than introduce a second convention.
- `ralph -- --weird` reports `Unknown command "--weird"`, because the command name is read
  before option parsing. That is accurate, if terse.
- No PHP-CS-Fixer or PHPStan configuration file was added; both tools were run ad hoc, since
  configuring the quality tooling is not part of this item.
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-26 — A5: PHP test runner and CLI start-up test

**Task:** Set up a PHP test runner and a minimal test proving that the CLI can start
successfully. *(PROJECT.md section A, item 5)*

**What was done**

- `phpunit.xml.dist`: PHPUnit 11 configuration bootstrapping `vendor/autoload.php`, one
  `tests/` suite, cache in `.phpunit.cache` (already git-ignored since A1). Strictness is on
  deliberately — `failOnRisky`, `failOnWarning`, `failOnNotice`, `failOnDeprecation` and
  `beStrictAboutOutputDuringTests`, with the `displayDetailsOn*` switches so CI shows why.
  A loop that commits its own work on green checks must not have a suite that tolerates
  warnings. `<source>` includes only `src`, with `restrictNotices`/`restrictWarnings`, so
  warnings raised by package code are reported while a dependency's noise is not.
- `composer.json`: added `scripts.test` → `phpunit` (plus `scripts-descriptions`), so the
  verification command this package will run against itself is `composer test`.
- `tests/Support/Cli.php` + `tests/Support/CliResult.php`: run `bin/ralph` as a real
  subprocess and capture exit code, stdout and stderr separately. Details that exist because
  later sections need them: both pipes are drained with `stream_select()` rather than read
  one after the other (sequential reads deadlock once a child fills the other pipe's buffer —
  streamed worker output in section E will hit that); a 60-second cap turns a hung child into
  a readable failure instead of a stuck suite; stdin is `/dev/null` so nothing can block on
  input; `PHP_BINARY` is used rather than the shebang so the test exercises the runtime the
  suite runs on; and `COMPOSER_VENDOR_DIR` is removed from the inherited environment, since
  `bin/ralph` prefers it when locating the autoloader and a leftover shell value would
  otherwise decide what the test measures. `CliResult::describe()` renders the whole
  invocation as the assertion message.
- `tests/Support/CapturingOutput.php`: wraps `Output` over two `php://memory` streams and
  reads each back, for in-process runs. Read-back seeks to the end afterwards so later writes
  append instead of overwriting.
- `tests/Console/CliStartsTest.php`: four start-up assertions — the binary prints the version
  line and exits 0 with an empty stderr; the binary with no arguments prints the command list
  and exits 0; `Application` driven in-process with captured streams returns 0 for `version`;
  and an unknown command exits 64 with the message on stderr and *nothing* on stdout. The
  last two establish the in-process harness and the stream separation that sections G–I
  depend on.

**Verification**

- `phpunit.xml.dist` validates against `vendor/phpunit/phpunit/phpunit.xsd` (`schemaValidate`
  → `true`), so the configuration is really being applied rather than partly ignored.
- `vendor/bin/phpunit` and `composer test` → `OK (4 tests, 13 assertions)`.
- `composer validate --strict` → valid. `composer dump-autoload --optimize --strict-psr` → no
  PSR-4 violations. `vendor/bin/phpstan analyse src bin tests --level=9` → `[OK] No errors`
  (tests included in the analysis from here on).
- The smoke test is not vacuous: replacing the `exit(Application::create()->run(...))` line in
  `bin/ralph` with `exit(3)` made both subprocess tests fail (`Failed asserting that 3 is
  identical to 0`); the file was restored and `git diff --stat bin/ralph` is empty.
- Environment isolation: the suite still passes with `COMPOSER_VENDOR_DIR=/tmp/does-not-exist-ralph`
  exported, confirming the scrubbing in `Cli::environment()`.
- Strictness has teeth: a throwaway test that echoed during execution was reported risky and
  made PHPUnit exit `1` (not just "OK, but there were issues"), so CI in J8 will fail on it.
  The probe file was removed.

**Notes / deviations**

- `tests/Support/*` files are helpers, not tests; PHPUnit's default `Test.php` suffix leaves
  them out of the suite. They are deliberately a little more capable than these four
  assertions need, because sections E–H require subprocess control and stream capture, and a
  second ad-hoc runner would be the wrong answer then.
- An `E_USER_WARNING` triggered from test code is not reported as a warning: `restrictWarnings`
  applies to the `<source>` set, which is `src` only. That is intended — the suite polices
  package code, not its own scaffolding.
- `/phpunit.xml` (the local override of the distributed config) was added to `.gitignore`, and
  `/phpunit.xml.dist export-ignore` to `.gitattributes` alongside the existing `/tests/` rule.
- Still only PHPUnit configured. PHPStan and PHP-CS-Fixer continue to be run ad hoc with no
  config files; CI and the full check set are item J8.
- A6 ("verify installation as both a project development dependency and a global Composer
  tool") remains the next item, and is where the global install not done in A3 gets covered.
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-26 — A6: Installation as a dependency and as a global tool

**Task:** Verify installation as both a project development dependency and a global Composer
tool. *(PROJECT.md section A, item 6)*

**What was done**

- **Found and fixed a real defect in `bin/ralph`.** Its autoloader candidates were ordered
  with `COMPOSER_VENDOR_DIR` first. That variable describes the vendor directory Composer is
  being pointed at, not the installation that is running, so a globally installed `ralph`
  invoked inside a project that sets it loaded *that project's* autoloader: either a fatal
  error, or — worse — silently running a different copy of the package. Both were reproduced
  against a real global install. The candidates are now ordered by how authoritatively they
  identify *this* installation:
  1. `$GLOBALS['_composer_autoload_path']`, which Composer's generated bin proxy sets to the
     autoloader of the installation whose `bin/` was invoked. This is Composer's own documented
     mechanism and covers the dependency and global layouts alike;
  2. `__DIR__/../vendor/autoload.php` — the package's own checkout;
  3. `__DIR__/../../../autoload.php` — a vendor directory three levels up, which is where the
     plain binary sits in both the dependency and global layouts, whatever the vendor directory
     is named (so a renamed vendor directory needs no environment variable);
  4. `COMPOSER_VENDOR_DIR`, kept only as a last resort.
- `tests/Installation/ComposerInstallationTest.php`: six tests that install the package for
  real and exercise the result — as a development dependency (`composer require --dev`) and as
  a global tool (`composer global require`). They assert the binary is created and executable,
  that it reports its version, that usage errors still exit 64 with stdout clean and the message
  on stderr, that the global binary works from an unrelated working directory and when invoked
  as the plain file rather than through Composer's proxy, and — as a regression test for the
  defect above — that it ignores an unrelated project's autoloader even with
  `COMPOSER_VENDOR_DIR` pointing at it. Two further tests confirm the declared platform
  requirements actually gate installation: `config.platform` claiming PHP 8.2.99 or disabling
  `ext-pdo_sqlite` makes Composer refuse to install, with nothing left in `vendor/`.
- Supporting helpers, all designed for reuse by sections F–H, which need temporary Git
  repositories and worktrees:
  - `tests/Support/TemporaryDirectory.php` — randomly named throwaway directories and a
    recursive delete that refuses any path outside the system temporary directory and handles
    the read-only files and directory symlinks Composer leaves behind.
  - `tests/Support/DistributedPackage.php` — copies the package as a consumer receives it. The
    file set is derived from `composer.json` (the manifest, the licence, the `autoload.psr-4`
    roots, the `bin` entries) rather than hard-coded, so it follows the package's own
    declaration of what it needs at runtime; `autoload-dev` is excluded, as Composer excludes a
    dependency's development files.
  - `tests/Support/Composer.php` — runs Composer with a temporary home and cache, no
    interaction, a fixed root version, `COMPOSER_ALLOW_SUPERUSER` for root CI images, and
    `COMPOSER`/`COMPOSER_VENDOR_DIR`/`COMPOSER_BIN_DIR` scrubbed so inherited settings cannot
    redirect the install. It honours `COMPOSER_BINARY` (set when the suite runs under
    `composer test`) before searching PATH.
  - `tests/Support/InstallationWorkspace.php` — one temporary root holding the package copy,
    the consumer projects, and the Composer homes. Consumers reach the package through a `path`
    repository with `symlink: false`, so the install produces a real directory under
    `vendor/celyes/ralph-php/` as a Packagist download would; a symlinked install would point
    back at the checkout and leave the installed layout untested. Packagist is switched off in
    the fixtures: the package needs no third-party code, so the tests must not need the network.
- `tests/Support/Cli.php` grew `runCommand()` for arbitrary argument arrays — installed
  binaries live outside the checkout, and Composer has to be run too — with `run()` delegating
  to it; the pipe draining, timeout and environment scrubbing are unchanged.
  `CliResult::allOutput()` was added for assertions about messages from a foreign program,
  where which stream it picks is its business and not the behaviour under test.
- `composer.json`: added `scripts.test:installation` → `phpunit --group installation`, matching
  the `#[Group('installation')]` on the test class, so these checks can be run or selected on
  their own. `composer test` still runs everything.

**Verification**

- `composer test` → `OK (10 tests, 55 assertions)` in ~1.4 s; `composer test:installation` →
  `OK (6 tests, 45 assertions)`. No temporary directories are left in `/tmp` afterwards.
- `vendor/bin/phpstan analyse src bin tests --level=9` → `[OK] No errors`.
  `composer validate --strict` → valid. `composer dump-autoload --optimize --strict-psr` → no
  PSR-4 violations. `php -l` clean on every changed file.
- **The regression test is not vacuous:** restoring the old candidate order in `bin/ralph` made
  `the_global_tool_ignores_an_unrelated_project_autoloader` fail with exit 255 and
  `Uncaught RuntimeException: The unrelated project autoloader was loaded`, thrown from
  `global-home/vendor/celyes/ralph-php/bin/ralph`. The fix was restored and the test passes.
- **The fixture matches the real distribution:** `git archive HEAD` ships exactly `LICENSE`,
  `bin/ralph`, `composer.json` and the 13 files under `src/` — the same set
  `DistributedPackage::paths()` produces. So the tests install the file set Packagist would
  serve, not the checkout.
- Manual checks of the things the automated tests deliberately avoid, all in an isolated
  `COMPOSER_HOME` so the developer's real global setup was never touched:
  - the documented global flow — `composer global config repositories.ralph-php --json '{...}'`
    then `composer global require celyes/ralph-php` — then `ralph --version`, `ralph help run`
    and `ralph status` run **through PATH and the shebang** from `/tmp`, giving exit 0, 0 and 69
    (the expected "not implemented in this build" status for a planned command);
  - `composer require --dev` in a throwaway project **with Packagist enabled**, then
    `./vendor/bin/ralph --version` through the shebang → exit 0. `composer show` lists exactly
    one package, confirming the package pulls in no third-party code (PRD section 2: Laravel
    must not be required), and `vendor/phpunit` is absent, so the package's own development
    dependencies do not reach consumers.
  - the last-resort `COMPOSER_VENDOR_DIR` candidate still works: a copy of `bin/ralph` with no
    sibling `vendor/` ran correctly when the variable pointed at the real one, and without it
    printed the candidate list and exited 78.

**Notes / deviations**

- The automated tests run installed binaries through `PHP_BINARY` rather than the shebang,
  because `php` on PATH is not necessarily the runtime the suite is measuring. The executable
  bit is asserted separately, and the shebang and PATH lookup were checked manually as recorded
  above.
- `*@dev` is the version constraint in the fixtures: a Composer `path` repository reports the
  package as `dev-main`, and the package has no release tag yet. Nothing in the tests asserts on
  the resolved version string.
- Section A is complete with this item. The one installation question left open is a real
  Packagist install, which cannot be checked before the package is published; the independent
  install in item J13 is where that gets covered.
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-27 — B1: Configuration loading

**Task:** Implement configuration loading for the task-file path, context-file path, default
agent, per-agent adapter settings, verification commands, base Git reference, branch prefix,
push settings, and execution limits. *(PROJECT.md section B, item 1)*

**What was done**

- `src/Config/ConfigurationLoader.php`: reads `.ralph/config.json` for a project root.
  `load()` requires the file and points at `ralph init` when it is absent; `loadOrDefaults()`
  is for commands that must still work in an uninitialised project (`status`); `fromArray()`
  builds a configuration from decoded data, which is how `init` and the tests avoid touching
  disk; `defaults()` is `fromArray([])`.
- `src/Config/Configuration.php`: the immutable result, with no I/O. Exposes `tasksPath()`
  and `contextPath()` as configured plus `tasksFile()`/`contextFile()` resolved against the
  project root, so messages can quote what the user wrote while the runner uses an absolute
  path. `toArray()` returns the *effective* (merged) configuration — that is what a run has
  to persist and what `status` has to show, because the file's literal contents do not say
  which defaults applied.
- Value objects, one per section, each with a `fromRaw()` reader and a `toArray()`:
  `AgentDefinition` (adapter, executable, model, argument array, environment variable
  *names*), `VerificationCommand` (name, argument array, timeout), `GitSettings` (base,
  branch prefix) and `PushSettings` (enabled, remote), `Limits` (iterations,
  attempts-per-task, task timeout, run timeout).
- `src/Config/Defaults.php`: one source of truth for omitted settings, so that an
  initialised project and a project whose file omits the same setting behave identically —
  `Defaults::toArray()` is both the loader's fallback and the template `ralph init` (B4)
  will write. The round-trip is asserted. Deliberate choices: base reference `HEAD` rather
  than `main`, so the default works whatever the repository's default branch is called;
  pushing off; `composer test` as the single default check; and **no** default
  authentication variables, because Codex and Claude Code can both be authenticated by
  interactive login and demanding `OPENAI_API_KEY`/`ANTHROPIC_API_KEY` would fail preflight
  for a working setup.
- `src/Config/RawConfiguration.php`: a typed reader over the decoded document. Decoded JSON
  is `mixed`, so every setting needs a type check before it can reach a typed constructor,
  and a wrong type has to name the setting the user must fix. Instances are scoped to one
  level and carry their key path, so an error reads `"limits.iterations" must be an integer,
  a string given.` rather than naming `iterations` alone. Notable decisions:
  - booleans, integers and strings are checked, never coerced — `"enabled": "true"` reading
    as *enabled* would publish work the user did not ask to publish;
  - `rejectUnknownKeys()` at every level, so a misspelled setting is an error instead of a
    silently ignored line while the runner uses the default;
  - `sectionList()` returns `null` for an absent setting and `[]` for an empty one, which is
    the distinction B3 needs to reject a configured-but-empty check list;
  - a check command may be written as a single string, which becomes *one* argument and is
    never split on whitespace: splitting would invent argument boundaries the user did not
    write.
- Merge semantics are written out section by section rather than by a generic deep merge,
  because the sections do not merge alike: an agent entry named after a built-in agent
  inherits that agent's fields (so pointing Codex at another executable needs no `adapter`),
  while `checks` is replaced wholesale — a user who lists two checks means those two.
- `src/Config/Path.php`: textual `isAbsolute()`/`normalize()`. Configured paths routinely do
  not exist yet (`init` is what creates them), so resolution cannot use `realpath()` and must
  not follow symlinks.
- `src/Exception/ConfigurationException.php`: exit code 78 (`ExitCode::CONFIGURATION`), the
  status already used for environment failures, with hints.
- `src/Support/Suggestions.php`: the "Did you mean …?" logic, extracted from
  `CommandRegistry::suggestionsFor()` (which now delegates) so a mistyped setting and a
  mistyped command are reported the same way.

**Verification**

- `composer test` → `OK (50 tests, 155 assertions)`; `vendor/bin/phpstan analyse src bin
  tests --level=9` → `[OK] No errors`; `composer validate --strict` → valid;
  `composer dump-autoload --optimize --strict-psr` → no PSR-4 violations; `php -l` clean on
  every new file.
- `tests/Config/ConfigurationLoaderTest.php` (20 tests) uses a real temporary project
  directory — a missing file, unreadable JSON and root-relative resolution are filesystem
  behaviour. It covers: every documented setting read back from a file; `{}` yielding the
  documented defaults; path resolution (relative joined and normalised, absolute left alone);
  an agent entry overriding only the fields it states while the other built-in agent stays
  intact; an additional agent loaded alongside the built-ins and rejected when it names no
  adapter; checks replacing rather than extending the defaults; the string-command and
  name/timeout fallbacks; missing file, empty file, trailing-comma JSON, and a top-level
  array; wrong types for an integer, a boolean, a section and a list element (reported as
  `agents.codex.arguments.1`); misspelled settings at the top level and inside `limits`, each
  with the suggestion and the recognised-settings hint; `Defaults::toArray()` round-tripping
  to the same effective configuration as `{}`; and the persisted configuration carrying
  variable names only.
- `tests/Config/PathTest.php` (19 cases) covers `isAbsolute`/`normalize` directly, including
  `..` collapsing, `..` above the root, relative `..` being kept, Windows drive roots, and
  normalisation of a path that does not exist.
- **The tests are not vacuous.** Two mutations were applied and reverted: dropping the
  built-in agent seeding in `ConfigurationLoader::agents()` failed 4 tests, and replacing the
  boolean type check with `(bool)` coercion failed 5, including
  `a_push_flag_written_as_a_string_is_not_coerced`. (`git checkout` could not revert them —
  the files were still untracked — so both were restored by hand and the restored methods
  re-read before re-running the suite.)
- End-to-end by hand: a `/tmp/ralph-demo/.ralph/config.json` setting only `agent`,
  `agents.claude.model`, one check and `git.base`/`git.push.enabled` loaded to the expected
  effective configuration — Claude's model overridden with its built-in adapter and
  executable intact, Codex still present, `branch_prefix`, push remote and all four limits
  defaulted, and `tasksFile()` resolved to `/tmp/ralph-demo/.ralph/tasks.md`.
- `ralph --version` and the mistyped-command suggestion (`ralph runn` → exit 64,
  `Did you mean "run"?`) still behave as before, confirming the `Suggestions` extraction.

**Notes / deviations**

- **Scope split with B3.** This item loads; B3 validates. The line drawn: shape is checked
  here, because a typed object cannot be built from arbitrary JSON without it, and a missing
  `adapter` for a non-built-in agent is rejected here for the same reason. Value semantics
  are left to B3 — nonpositive limits, an empty `checks` list or an empty command array, a
  configured path outside the repository, and an `adapter` name that is not registered are
  all accepted by the loader today. `Path::isInside()` was written for that check and then
  removed, to avoid shipping an untested helper with no caller; B3 should reintroduce it.
- Settings are named `tasks`, `context`, `agent`, `agents`, `checks`, `git`, `limits`, with
  `snake_case` for multi-word keys (`branch_prefix`, `attempts_per_task`, `task_timeout`,
  `run_timeout`). The PRD fixes only `agent`/`agents`/`adapter`/`executable`; the rest is
  this item's choice and is what B4's template and the README (J9) must document.
- No CLI command reads the configuration yet: `init` is B4, agent selection is B2, and
  `status` is section I. The loader is exercised by the test suite and by the manual run
  above.
- `Configuration` is deliberately richer than B1 strictly needs (`agentNames()`,
  per-section `toArray()`), because D4 persists the selected non-secret settings and G3
  snapshots the verification configuration at run start.
- Still no PHP-CS-Fixer or PHPStan configuration file; both tools continue to be run ad hoc
  (CI and the full check set are J8).
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-27 — B2: Agent selection

**Task:** Resolve agent selection from the project default or `run --agent NAME`; validate
adapter names and preserve the selected non-secret settings for the run.
*(PROJECT.md section B, item 2)*

**What was done**

- `src/Agent/AgentSelector.php`: `select(Configuration, ?string $requested)` resolves the agent
  for a run. The project's `agent` setting is the default; a non-null `$requested` (the value of
  `run --agent`) overrides it. Three failures are raised here, before any task is selected or any
  workspace exists:
  - a blank `--agent` value → `UsageException` (exit 64): the command line is wrong, not the
    configuration;
  - a name no `agents` entry matches → `ConfigurationException` (exit 78) with a "Did you mean …?"
    suggestion, the configured agent names, and a hint pointing at whichever place has to change
    (`"--agent NAME" must name one of them.` versus `The "agent" setting in <file> …`);
  - an entry naming an adapter with no implementation → `ConfigurationException` naming the agent
    *and* the adapter, the available adapters, and the exact setting to fix
    (`agents.house.adapter`).
  There is no fallback on any path, by design: running a different agent than the one asked for
  would attribute its commits, its output and its cost to the wrong tool. The PRD states this as
  "do not silently switch to another agent", and the regression test is named after it.
- `src/Agent/AdapterCatalog.php` + `src/Agent/BuiltInAdapters.php`: validating an adapter name
  needs exactly two questions answered — is there an implementation under this name, and what
  names exist (for the error message) — so that is the whole interface. `BuiltInAdapters` lists
  `codex` and `claude`. Adapter *implementations* arrive in section E; its registry (E3) can
  satisfy this interface without agent selection learning how adapters are constructed, and
  `AgentSelector`'s constructor already takes the catalog, so a project-registered adapter
  (E3's extension point) passes validation with no change here. A test registers a fictional
  `house-agent` adapter through a fake catalog to prove that seam works.
  Adapter names are kept separate from agent names on purpose: `agents` keys are user-chosen
  labels and several may share one adapter (two Codex entries on different models), while adapter
  names identify invocation code.
- `src/Agent/AgentSource.php`: enum `project_default` / `command_line`, with `describe()` for
  messages. Persisted with the run because `status` has to say whether the agent was the
  project's default or a one-off override, and because H6 rejects an implicit agent change on
  resume — which needs to know what was recorded, not what the file says now.
- `src/Agent/AgentSelection.php`: the immutable result — name, adapter, definition, source,
  `isOverride()`, `describe()` for a status line, and `toArray()` for persistence. `toArray()`
  is the "preserve the selected non-secret settings" half of this item: it emits the agent name,
  the source, and the effective settings (adapter, executable, model, arguments, and the *names*
  of the authentication variables). No credential value can travel through it, because
  `AgentDefinition` never holds one.

**Verification**

- `composer test` → `OK (65 tests, 202 assertions)`; `vendor/bin/phpstan analyse src bin tests
  --level=9` → `[OK] No errors`; `composer validate --strict` → valid;
  `composer dump-autoload --optimize --strict-psr` → no PSR-4 violations; `php -l` clean on every
  new file.
- `tests/Agent/AgentSelectorTest.php` (15 tests) loads configurations from a real temporary
  project file, so the paths quoted in the hints are the ones a user would actually edit. It
  covers: the package default (`codex`) and a project default (`claude`); `--agent` overriding the
  default and being recorded as `command_line`; a custom agent's executable, model and arguments
  reaching the selection intact; an `agents` entry that overrides only `executable` keeping the
  built-in adapter; the persisted array and `describe()` asserted in full; an unknown name from
  the command line and from the `agent` setting, each with its own hint; the file `ralph init`
  would create being named when the project has no configuration file; an unsupported adapter; a
  project-registered adapter accepted through a fake catalog; an empty catalog rejecting even a
  built-in agent (`Available adapters: none.`); a blank `--agent` value as exit 64; and a drift
  guard asserting every built-in agent's adapter is present in `BuiltInAdapters`.
- **Credentials are not persisted:** one test sets a real environment variable named in
  `environment`, JSON-encodes `AgentSelection::toArray()`, and asserts the variable *name* is
  present and its value is absent.
- **The tests are not vacuous.** Two mutations were applied and reverted (both files were still
  untracked, so backups were taken and restored by `diff`-verified copy): making an unknown agent
  fall back to the project default failed 2 tests, and making `BuiltInAdapters::has()` return
  `true` for anything failed 2, including the drift guard.
- End-to-end by hand in `/tmp/ralph-b2`, with `ANTHROPIC_API_KEY` exported and three extra agents
  configured: no override selected `claude (adapter "claude", selected by the project
  configuration)` with its configured model and the variable *name* only; `--agent codex-fast`
  selected the custom entry with `/opt/codex/bin/codex` and `--full-auto`; `--agent house` failed
  78 on its `house-agent` adapter; `--agent claud` failed 78 with `Did you mean "claude"?` and all
  four configured names; `--agent ""` failed 64. The exported key appeared nowhere in the
  persisted output.

**Notes / deviations**

- **No CLI wiring.** `ralph run` is still a `PlannedCommand`; the selector is consumed when the
  runner lands (G1) and displayed by `status` (I2). Wiring selection into the placeholder would
  make `ralph run` fail with a configuration error instead of the honest "not implemented in this
  build", so `--agent` validation is deliberately not reachable from the command line yet. Same
  split as B1, where the loader shipped before any command read it.
- Only the *selected* agent's adapter is validated, not every configured entry: an unrelated
  broken `agents` entry should not block a run that does not use it. B3 (validate configuration
  values) may choose to check all entries up front; if it does, this check stays, because the
  selected agent must be validated against the catalog the run will actually use.
- `AgentSelector` takes the catalog as a constructor dependency with `new BuiltInAdapters()` as
  the default, so E3 passes its registry in without touching this class.
- The unknown-agent error is a `ConfigurationException` (78) even when the name came from
  `--agent`, because the fix is almost always to configure the agent; the hints name both the
  command line and the file. A blank option value is the one case reported as usage (64), since
  no configuration could make it valid.
- Still no PHP-CS-Fixer or PHPStan configuration file; both tools continue to be run ad hoc
  (CI and the full check set are J8).
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-27 — B3: Configuration validation

**Task:** Validate configuration types and values; reject invalid paths, empty commands,
nonpositive limits, and missing required settings before starting work.
*(PROJECT.md section B, item 3)*

**What was done**

- `src/Config/ConfigurationValidator.php`: checks that a loaded configuration's *values* can
  be used, as the counterpart to the loader's *shape* checks. Called from
  `ConfigurationLoader::fromRaw()`, so every path into a `Configuration` — `load()`,
  `loadOrDefaults()`, `fromArray()`, `defaults()` — returns a validated object and nothing
  downstream has to ask whether its settings are usable. The loader takes the validator as a
  constructor dependency with a default instance.
  Every failure is a `ConfigurationException` (exit 78) naming the setting in the file's own
  dotted notation (`limits.iterations`, `agents.codex.executable`, `checks.0.command.1`) and
  saying what a usable value is. What it rejects, and why that particular rule:
  - **`checks` configured as empty** — the one failure that would otherwise be silent and
    unsafe. With no checks, a task would be committed and checked off on the agent's own exit
    code, which the PRD explicitly refuses as evidence. `RawConfiguration::sectionList()`
    already distinguished absent (defaults apply) from empty (rejected), which is what makes
    this expressible.
  - **empty commands** — an empty `command` array, and any blank element of one, with the
    first element reported as "the executable to run" and the rest as arguments.
  - **nonpositive limits** — all four of `iterations`, `attempts_per_task`, `task_timeout`,
    `run_timeout`, each named with its unit (`must be at least 1 task, 0 given.`), plus
    per-check `timeout`. A zero limit would stop a run before it started, which is a
    configuration error reported as such rather than a run that mysteriously does no work.
  - **invalid paths** — `tasks` and `context` must be non-blank, must not name the project
    directory itself, must resolve *inside* the project, and must not sit in
    `.ralph/runtime/`, whose contents the runner generates, git-ignores and removes. The two
    may not be the same file: the checklist is rewritten as tasks complete, and that would
    overwrite the user's context.
  - **missing or blank required settings** — `agent`, each agent's `adapter`/`executable`, a
    `model` configured as `""`, blank extra arguments, `git.base`, `git.branch_prefix`,
    `git.push.remote`.
  - **values that would break the layer that consumes them** — control characters anywhere in
    a command, reference or label (a NUL byte would fail in the process layer with a message
    naming neither the setting nor the file); whitespace or a leading `-` in `git.base` and
    `git.push.remote`, which Git would read as an option; agent and adapter names outside
    `[A-Za-z0-9][A-Za-z0-9._-]*`, since agent names are typed as `run --agent NAME`; check
    names containing a path separator, since they label log files; duplicate check names,
    which would make status output and logs ambiguous; `environment` entries that are not
    environment variable names; and branch prefixes Git would refuse (space or `~ ^ : ? * [ \`,
    `..`, an empty path segment, a leading `-`, a segment starting with `.` or ending `.lock`).
- `Path::isInside()`: reintroduced as B1's notes anticipated, with the caller that justifies
  it. Normalises both sides first, so `../../tasks.md` is recognised as outside the repository
  instead of being treated as a harmless relative path; textual and not symlink-aware, like
  the rest of the class, because symlinks are a filesystem question for preflight.
- `Defaults::RUNTIME_DIRECTORY` (`.ralph/runtime`): needed by the runtime-directory path check
  and by section D, rather than a magic string in the validator.
- **The environment-entry error redacts.** `OPENAI_API_KEY=sk-live-…` in `environment` is the
  mistake this rule is really for — a credential written into a file meant to be committed.
  Quoting the entry back in full would copy the secret into an error message, a terminal and
  whatever captured it, so anything after the first `=` is elided (`"OPENAI_API_KEY=..."`).
  This is item I9's rule applied where the first such message appears.

**Verification**

- `composer test` → `OK (115 tests, 360 assertions)`; `vendor/bin/phpstan analyse src bin
  tests --level=9` → `[OK] No errors`; `composer validate --strict` → valid;
  `composer dump-autoload --optimize --strict-psr` → no PSR-4 violations; `php -l` clean on
  every changed file. `ralph --version` and the mistyped-command suggestion still behave as
  before.
- `tests/Config/ConfigurationValidatorTest.php` (41 cases) drives the *loader* against a real
  temporary project rather than the validator directly, because that is the only way a
  configuration reaches a run, and it makes every message quote the file a user would open.
  Its `failure()` helper asserts centrally what every rejection must get right — the message
  starts with the configuration file's path, and the status is `ExitCode::CONFIGURATION` — and
  fails loudly with the settings and the loaded result if a case is *not* rejected, so no test
  can pass by accident. Each case then asserts the exact setting named: the four nonpositive
  limits and a zero check timeout; an empty `checks` list, an empty command, a blank argument
  and an empty executable; duplicate and separator-containing check names; an empty `tasks`
  path, three ways of pointing outside the project (`../`, `docs/../../`, absolute), the
  runtime directory, the project directory itself, and tasks equal to context; a blank
  `agent`; an unusable agent name and adapter name; a blank executable, a control character in
  one, an empty model, a blank argument; an environment entry carrying a value and one that is
  not a variable name; eight branch prefixes Git would reject, an empty prefix; a base
  reference with whitespace and a blank one; and a push remote starting with `-`, including
  with pushing disabled.
- A drift guard asserts the package's own defaults are valid both implicitly and as the
  document `ralph init` will write (`Defaults::toArray()`), since a validator that rejected
  either would make every new project start broken.
- `tests/Config/PathTest.php` gained 10 cases for `isInside()`: a file below the root, the root
  itself and with a trailing separator, the parent, a sibling sharing a name prefix
  (`/srv/project-two`), escaping and returning via `..`, and that the filesystem root contains
  everything without the `rtrim()` turning the prefix into `""`.
- **The tests are not vacuous.** Four mutations were applied and reverted: accepting `0` in
  the limit check failed the two zero cases; removing the validator call from the loader failed
  40 of 41; `Path::isInside()` returning `true` unconditionally failed 45 across both files;
  and dropping the empty-`checks` guard failed 40. (Reverting the two *tracked* files with
  `git checkout` also discarded the item's real edits to them — `Path::isInside()` and the
  loader wiring — which the next full run caught; both were re-applied and the suite and
  PHPStan re-run clean.)
- End-to-end by hand in `/tmp/ralph-b3`, printing each failure as the CLI will: a valid
  partial file loaded with its defaults intact, and zero iterations, no checks, a checklist
  outside the repository, a checklist in the runtime directory, a secret in `environment`, a
  branch prefix with a space, and an empty executable each produced their message, hints and
  exit 78 — with the fake credential value absent from the output.

**Notes / deviations**

- **Rejecting paths outside the project changes B1's behaviour**, and one B1 test asserted the
  old acceptance (`context` at `/etc/ralph/CONTEXT.md`). B1's notes asked for exactly this
  check, so the test was changed to use an absolute path *inside* the project, which keeps its
  real subject — that an absolute path is used as written rather than joined to the root —
  covered. The cost is that a context file shared between projects from `$HOME` is no longer
  configurable; if that turns out to be wanted, it should be a deliberate setting rather than a
  side effect of path resolution.
- **Adapter registration is still not checked here.** The validator checks every configured
  agent's adapter *name shape*, but whether an implementation exists stays in `AgentSelector`,
  which holds the registry the run will use and reports only the agent that was actually
  asked for — an unrelated broken entry should not block a run that does not use it. The same
  split applies to `agent`: blankness is rejected here, and whether it names a configured
  entry remains with selection, which knows whether `--agent` overrode it.
- **No filesystem, environment or Git access**, deliberately: whether the task file exists,
  the agent's executable is installed, or `git.base` resolves are preflight questions (B7) that
  can fail for reasons the configuration cannot fix.
- `limits.task_timeout` larger than `limits.run_timeout` is accepted. It is contradictory on
  its face, but "one task may run longer than the run budget" is a legitimate setup given that
  the run budget is enforced at a task boundary (H2), so it is not rejected.
- The branch-prefix rules cover the prefix only. Validating the whole generated name belongs
  to F3, where the task ID is appended.
- Still no CLI command reads the configuration: `init` is B4 and `status` is section I, so
  these messages are reachable from the test suite and the manual run above, not yet from
  `ralph`. Same split as B1 and B2.
- Still no PHP-CS-Fixer or PHPStan configuration file; both tools continue to be run ad hoc
  (CI and the full check set are J8).
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-28 — B4: `ralph init`

**Task:** Implement `ralph init` to create the default configuration, an empty task
checklist with usage instructions, the context file, and runtime directories.
*(PROJECT.md section B, item 4)*

**What was done**

- `src/Init/ProjectInitializer.php`: creates the project files, with no console output, so
  the behaviour can be asserted directly — it returns a report and the command renders it.
  The order matters: an **existing configuration file is loaded first**, before anything is
  written, so a project that configures `tasks`/`context` elsewhere gets *its* checklist and
  context file created rather than the package defaults. A project with no configuration
  falls back to the defaults, which are the same paths the template writes.
  Nothing that exists is written to: initialisation is additive in this build. `is_link()` is
  checked alongside `file_exists()` because a symlink with a missing target is invisible to
  `file_exists()` and writing through it would create a file somewhere the user never asked
  for. A directory where a file belongs, or a file where a runtime directory belongs, is
  reported with the path and left in place rather than removed.
- `src/Init/Templates.php`: the three starting documents, as PHP rather than files under a
  `resources/` directory — a consumer receives only what `composer.json` declares (the
  autoload roots and the binary), so a template outside `src/` would be missing from an
  installed package and `ralph init` would fail exactly where it is first used.
  - `config()` is `Defaults::toArray()` encoded pretty-printed, so the file lists **every**
    setting at its default value and doubles as the documentation of what can be changed —
    and so an initialised project cannot drift from a project whose file omits the setting.
  - `tasks()` is a checklist with instructions and **no task**: its example is inside a fenced
    code block, which section C's reader ignores, so a freshly initialised project has
    nothing pending instead of a template line the agent would try to implement. A test
    asserts that, stripping fenced blocks and requiring no `- [ ]`/`- [x]` marker survives.
  - `context()` is section prompts (what the project is, how it is verified, conventions,
    important files, out of scope) with a note that the file is read in full on every attempt.
- `src/Init/InitializationReport.php`, `InitializedPath.php`, `InitializationOutcome.php`: the
  result as data — per path, `created` or `kept`, with the absolute path and the label shown to
  the user (directories keep a trailing `/`). Per path rather than summarised because the
  difference matters: a kept file is the user's own content, which initialisation did not read
  and did not change. The report names the three files individually so callers can quote the
  project's real paths; `paths()` returns everything in handling order for display, and section
  I's structured events can render the same object.
- `src/Console/Command/InitCommand.php`: `init` is now a real command, replacing its
  `PlannedCommand` placeholder in `Application::create()`. It initialises the **current working
  directory**, like `git init` and `composer init`, so no path has to be read from the command
  line; a positional argument is rejected with a usage error rather than quietly initialising
  somewhere else. Output is the per-path table, a sentence naming the kept *files* only
  (an already-existing directory is not news), and numbered next steps that quote the
  configured paths from the report — a project whose checklist is `docs/backlog.md` is told
  that, not `.ralph/tasks.md`.
  `--force` is recognised and refused with exit 69 and a pointer to item B5, because the
  help text of the previous build promised it: for a user who types it, silently *not*
  replacing their files is the worst of the three possible answers. Unknown options are a
  usage error instead of being ignored.
- `src/Exception/FilesystemException.php`: a path the package must create cannot be created.
  Separate from `ConfigurationException` because nothing is wrong with the settings — the
  environment refused — but reported with the same exit code 78, since the remedy is the same
  and nothing was executed on the user's behalf.
- `src/Config/Defaults.php`: added `CONFIG_FILE` (now also used by `ConfigurationLoader::fileIn()`,
  removing the duplicated `'/config.json'` literal) and `MEMORY_DIRECTORY`, `LOGS_DIRECTORY`,
  `WORKTREES_DIRECTORY` next to the existing `RUNTIME_DIRECTORY`, so the runtime layout has one
  owner for this item and for section D.

**Verification**

- `composer test` → `OK (135 tests, 453 assertions)`; `vendor/bin/phpstan analyse src bin tests
  --level=9` → `[OK] No errors`; `composer validate --strict` → valid;
  `composer dump-autoload --optimize --strict-psr` → no PSR-4 violations; `php -l` clean on every
  new and changed file.
- `tests/Init/ProjectInitializerTest.php` (13 tests) works on real temporary directories: the
  exact set and order of created paths; the written configuration **loading back** to the same
  effective configuration as the defaults; the checklist containing no pending task; a second
  run keeping the byte content of all three files after they were edited; a single deleted file
  being recreated while the rest are kept; an existing configuration deciding where the
  checklist and context file are created; an unusable existing configuration (`iterations: 0`)
  stopping initialisation before anything is written; the runtime directories having no group
  or other permission bits; a directory where a file belongs and a file where a runtime
  directory belongs each reported with the path and left in place; a missing directory; an
  empty directory argument; and an unwritable directory (skipped for a user that ignores mode
  0500, so the test cannot silently pass as root).
- `tests/Console/InitCommandTest.php` (7 tests) runs the real binary as a subprocess with the
  temporary project as its working directory — the directory it initialises *is* its working
  directory, and changing the test runner's own would leak into the suite. It covers the
  created-path table and the files on disk, next steps quoting `docs/backlog.md`/`docs/AGENT.md`
  for a project configured that way, a second run reporting `kept` with the user's checklist
  intact, `--force` exiting 69 with empty stdout and the file unchanged, an unknown option and
  a directory argument exiting 64 with nothing created, and `help init` documenting the paths
  while the command list no longer marks `init` as not implemented.
- **The tests are not vacuous.** Two mutations were applied and reverted (both files untracked,
  so backups were taken and restored by `diff`-verified copy): dropping the "already exists"
  branch in `createFile()` so initialisation overwrote user files failed 4 tests, including the
  CLI one; putting a `- [ ]` line in the checklist template outside the fenced example failed
  `the_written_checklist_has_no_pending_task`.
- End-to-end by hand, each in a throwaway directory: a fresh `ralph init` created the seven
  paths, with `.ralph` at 0755, the runtime directories at 0700 and the files at 0644; the
  generated `config.json`, `tasks.md` and `CONTEXT.md` were read in full and are the templates
  above; a second run reported all seven as kept; deleting one file recreated only that file; a
  configuration naming `docs/backlog.md`/`docs/AGENT.md` created those instead; an invalid
  configuration failed 78 before writing anything; a directory at `.ralph/tasks.md` and a
  read-only project directory each failed 78 with their own message; and `--force`, `--nope` and
  a path argument failed 69, 64 and 64.

**Notes / deviations**

- **Scope split with B5 and B6.** This item creates; B5 owns the explicit overwrite option and
  the preservation guarantees around it; B6 owns the ignore rules. So this build has no code
  path that can replace a user's file — `--force` is refused rather than accepted — and
  `ralph init` writes no ignore rules yet, which means a user who initialises today should
  ignore `.ralph/runtime/` themselves until B6 lands. The command's help text no longer
  advertises `--force`, since advertising an option that exits 69 is worse than not listing it.
- **Partial initialisation is possible and is the intended behaviour.** When a path cannot be
  created — a directory in the way, a permission failure — the paths already created stay, and
  re-running continues from there, because each path is independent and keeping what succeeded
  is what makes the retry trivial. The command's exit code reports the failure.
- Runtime directories are created 0700 while `.ralph` itself is 0755: the configuration and
  checklist are meant to be shared, whereas the runtime tree collects raw worker output, memory
  summaries and worktrees. The test asserts the absence of group/other bits rather than an exact
  mode, so an unusual umask cannot make it fail spuriously.
- `.ralph/runtime/state.sqlite` is deliberately *not* created: the state store creates and
  migrates it when a run starts (section D). Initialisation only makes the directories.
- `init` does not look at Git at all — whether the directory is a repository, whether
  `git.base` resolves, whether the agent executable exists are preflight questions (B7), which
  can fail for reasons initialisation cannot fix. Initialising a non-repository therefore
  succeeds, and `ralph run` is where that is reported.
- Still no PHP-CS-Fixer or PHPStan configuration file; both tools continue to be run ad hoc
  (CI and the full check set are J8).
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-28 — B5: Preserving user files, and the explicit overwrite option

**Task:** Make initialization preserve existing user files and require an explicit option
before overwriting them. *(PROJECT.md section B, item 5)*

**What was done**

- `src/Init/InitializationMode.php`: `Preserve` / `Overwrite`, passed to
  `ProjectInitializer::initialize()` as a second parameter defaulting to `Preserve`. A named
  mode rather than a boolean, because the call site is the whole point of this item — a
  `true` at the end of an argument list is exactly the kind of thing that gets passed by
  accident.
- `InitializationOutcome` gained `Replaced`, alongside `Created` and `Kept`. A replaced file
  is content that now exists only in its backup, which is a different fact about a user's
  project than either of the other two, so it is reported as its own outcome rather than
  folded into `Created`.
- **`--force` now works instead of exiting 69.** B4 deliberately refused it, because its help
  text had promised it and silently *not* replacing a user's file was the worst available
  answer; the refusal and `CommandUnavailableException` import are gone from `InitCommand`,
  which now maps `-f`/`--force` to `InitializationMode::Overwrite` and nothing else.
- **Nothing is destroyed even on the overwrite path.** Each file whose content differs from
  the template is copied to `<name>.bak` before being written, and the report carries the
  backup's absolute path so the command can print it. Design points:
  - `copy()` before the write rather than `rename()`: a write that then fails leaves the
    original in place *and* a backup, instead of leaving nothing.
  - the copy inherits the source's permission bits, so a configuration file the user
    restricted does not acquire a world-readable twin.
  - a taken `.bak` is not overwritten; the next copy is `.bak.2`, `.bak.3`, … Losing the
    *first* backup to the second `--force` would defeat the point.
  - that numbering is bounded at 20, with an error naming the file and what to delete,
    because `ralph` is built to be driven unattended and an unbounded `.bak.N` series is a
    way for a loop to quietly fill a project directory.
  - a file already byte-identical to the template is reported `kept`, not `replaced`, and
    gets no backup: there is nothing to preserve, and a repeated `init --force` would
    otherwise accumulate identical copies.
- **`--force` resets the project to the defaults, including the configuration — so the
  checklist and context are written at the *default* paths.** The configuration decides where
  those two files live, so honouring the paths of the file being thrown away would create
  files that the configuration then in force does not point at. Files the replaced
  configuration *did* point at are left on disk (`init` deletes nothing) and reported:
  `Nothing was deleted, but Ralph PHP no longer reads them.` A user whose task list has just
  stopped being read should hear it from the command that did it, not from a run that reports
  no pending work.
- **`--force` works on a project whose configuration is too broken to load**, which is the
  main reason to reset one. Under `Preserve` the existing configuration is loaded and
  validated, and an unusable one correctly stops initialisation (B4); under `Overwrite` it is
  not consulted for paths at all. For the "no longer referenced" warning the two path settings
  are read straight from the JSON document via `RawConfiguration`, bypassing validation —
  an invalid `limits.iterations` says nothing about whether `tasks` is legible, and the
  warning matters most in exactly that project. Anything unreadable is passed over silently,
  since this produces a warning and the reset must not depend on it.
- **A symlink is refused under `--force` rather than written through.** `file_exists()` does
  not report a link with a missing target, and replacing a link rewrites its *target*, which
  may be outside the project. Under `Preserve` a link is `kept` as before.
- A directory where a file belongs is still reported and left in place in both modes:
  `--force` is permission to replace file content, not to remove directories.
- `InitializedPath` now has private construction with `file()` / `directory()` named
  constructors, so the directory flag cannot be passed the wrong way round and only a file can
  carry a backup. `InitializationReport` gained `mode()`, `replaced()` and
  `unreferencedPaths()`; its three filters share one `matching()` helper.
- Output: a `replaced` row per file, then the backups listed one per line, then a
  mode-appropriate sentence about kept files — preserving says the user's content was left
  alone and points at both ways to regenerate it, overwriting says the file already matched the
  template and so was not backed up. `%-8s` was kept for the outcome column: `replaced` is
  exactly eight characters, so the paths stay aligned and the existing `created  <path>`
  assertions still hold.

**Verification**

- `composer test` → `OK (149 tests, 522 assertions)`; `composer test:installation` →
  `OK (6 tests, 42 assertions)`; `vendor/bin/phpstan analyse src bin tests --level=9` →
  `[OK] No errors`; `composer validate --strict` → valid; `php -l` clean on every changed
  file; `php-cs-fixer --dry-run --rules=@PSR12` reports no diff on any of the eight.
- `tests/Init/ProjectInitializerTest.php` grew 10 tests: preserving is the default and the
  report says which mode ran; all three files replaced with their backups holding the exact
  previous bytes and the templates now on disk; a file already matching the template producing
  no backup; two successive overwrites giving `.bak` and `.bak.2` with the right content in
  each; a backup not widening `0600`; a configuration that fails validation still being reset;
  the unreferenced-path report surviving that same unvalidatable configuration; unparseable
  JSON resetting cleanly and claiming nothing; a symlinked checklist refused with its outside
  target intact (skipped where symlinks are unavailable); and a directory in the way still
  refused under `--force`.
- `tests/Console/InitCommandTest.php`: the obsolete `force_is_refused_instead_of_being_silently_ignored`
  was replaced by five tests — `--force` replacing two files, naming both backups, reporting
  the configuration as `kept`/already-matching, and leaving the backup readable; `-f` accepted;
  `--force` resetting a configuration that plain `init` refuses with 78; the preserve-mode hint
  offering both routes; and `help init` documenting `-f, --force` and `.bak`.
- **The tests are not vacuous.** Three mutations were applied and reverted (originals copied to
  `/tmp` first and restored by `diff`-verified copy): replacing the `backUp()` call with a bare
  path string so no copy was made failed 4 tests; making `InitCommand` ignore `--force` failed
  3; and dropping the `!$mode->overwrites()` guard so preserve mode overwrote too failed 3,
  including B4's `running_it_again_keeps_the_user_content_of_every_file`. `diff` confirmed both
  files byte-identical afterwards and the suite green.
- End-to-end by hand in six throwaway directories: a preserve-mode second run keeping all three
  edited files and offering both regeneration routes; `--force` replacing the two edited files,
  listing both absolute backup paths, and reporting the untouched configuration as already
  matching; `--force` on a `{"limits":{"iterations":0}}` configuration pointing at
  `docs/backlog.md`/`docs/AGENT.md` — plain `init` exits 78, `--force` succeeds, resets the
  configuration, creates the default checklist, names the two files it no longer reads, and
  leaves their content intact; the 20-backup refusal with `tasks.md` unmodified; a symlinked
  checklist kept under `init` and refused under `--force` with the outside target unchanged; the
  singular/plural wording on one and three files; and `--nope` and a positional argument still
  exiting 64.

**Notes / deviations**

- **`--force` is all-or-nothing by design.** It resets all three files, not a named one. Single-file
  regeneration already has an answer that needs no option and cannot overreach — delete the file
  and run `ralph init` — and the help text says so before it mentions `--force`. A
  `--force=tasks` form would be a second way to express the same thing.
- **`*.bak` is not git-ignored yet.** Ignore rules are item B6, which should decide whether these
  belong in `.gitignore`; `ralph init` writes no ignore rules in this build, so a user who runs
  `--force` today may see `.ralph/tasks.md.bak` in `git status`.
- **Writes are still not atomic.** `file_put_contents()` can leave a partial file if the process
  dies mid-write; the backup makes that recoverable but does not prevent it. Atomic replacement is
  specified for checkbox writes in item H8, and that mechanism should be reused here once it exists.
- **Partial initialisation remains the intended behaviour** (B4's note): when one path fails, the
  paths already handled stay as they are and re-running continues from there. Under `--force` that
  means an earlier file may already be replaced — with its backup — when a later one fails, which
  the backup-limit run above shows.
- `InitializationMode` is string-backed (`preserve` / `overwrite`) although nothing persists it
  yet; the structured events in item I3 will.
- Section B now has items 6 (ignore rules) and 7 (preflight) remaining.
- Still no PHP-CS-Fixer or PHPStan configuration file; both tools continue to be run ad hoc
  (CI and the full check set are J8).
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-28 — B6: Git ignore rules

**Task:** Add ignore rules for runtime data and the local task checklist without deleting or
replacing the user's existing ignore rules. *(PROJECT.md section B, item 6)*

**What was done**

- `src/Init/IgnoreRules.php`: the rules a project needs, **derived from its own
  configuration** rather than hard-coded — `/.ralph/runtime/`, the configured checklist
  (`/docs/backlog.md` for a project that configures it there), and `.bak*` for each of the
  three files `init --force` copies. The configuration and the context file are deliberately
  *not* ignored: they are meant to be shared. Every rule is anchored with a leading `/` so it
  cannot match a same-named file elsewhere in the repository, the runtime rule carries a
  trailing `/` so it cannot match a file, and a configured path is escaped (`\* \? \[ \]`,
  trailing space) so a checklist called `sprint [1].md` is matched literally instead of as a
  pattern. `ProjectInitializer::BACKUP_SUFFIX` became public so the backup rules are built
  from the same `.bak` the backups are named with.
- `missingFrom()` is what keeps the user's file intact: it returns only the rules the file
  does not already **decide**. Decided covers the same rule written differently (with or
  without a leading/trailing slash, escaped), a bare file name (which Git matches at any
  depth), a matching wildcard, an ignored parent directory — and an explicit `!` negation,
  because a user who has chosen to track their checklist (PROJECT.md section 4) must not have
  the rule put back on the next `ralph init`. A negated *parent* decides nothing, since Git
  cannot re-include a path whose parent directory is excluded. The comparison is textual and
  documented as incomplete in both directions: something subtler is treated as missing (a
  duplicate rule changes nothing), and an existing `*.bak` is taken to decide our `.bak*`
  (so a second numbered backup can stay visible in `git status`). Neither can un-ignore a
  path.
- `src/Init/IgnoreFileWriter.php`: writes those rules into `.gitignore` at the project root.
  **It only ever appends.** The ignore file is the one path `ralph init` writes that it does
  not own, so nothing in it is reordered, rewritten or removed, and `--force` does not change
  that — `--force` is permission to replace the files the package wrote, not the project's
  ignore rules. The block is built as one string from the previous contents rather than
  appended to an open handle, so a file that does not end in a newline does not get a rule
  glued onto its last line, and the block is separated from existing rules by exactly one
  blank line. It is preceded by a two-line header saying what the rules are and that negating
  one with `!` is respected.
- **A failure here does not fail initialisation.** A directory at `.gitignore`, a symlink
  (refused rather than written through, since that would edit a file outside the project and
  Git would not read it as this repository's rules anyway), or an unwritable file produce
  `IgnoreFileOutcome::Skipped` with the reason and the rules the user now has to add by hand.
  Losing the configuration, checklist and context file over an unwritable `.gitignore` would
  be the worse outcome.
- `src/Init/IgnoreFileOutcome.php` (`created` / `extended` / `kept` / `skipped`) and
  `src/Init/IgnoreFileUpdate.php` (path, outcome, the rules, the reason). Kept separate from
  `InitializationOutcome`/`InitializedPath` because the ignore file is not a path
  initialisation owns: there is no `replaced` among its outcomes and `--force` does not add
  one, and it is reported through `InitializationReport::ignoreFile()` rather than among
  `paths()`.
- `ProjectInitializer` takes an `IgnoreFileWriter` (constructor default) and updates the
  ignore file last, from the same `Configuration` that decided where the checklist and
  context file were written — so under `--force`, where the configuration is reset to the
  defaults, the rules name the default paths.
- `InitCommand` prints the ignore file as a row of the existing table (`created  .gitignore`,
  `extended`, `kept`, `skipped` — all eight characters or fewer, so the `%-8s` alignment and
  the existing assertions hold), then **lists the rules it added**, because lines appended to
  a file the user owns are a change to their project and whether to track the checklist is
  their decision to make. A skipped update goes to stderr as a warning with the rules to add
  by hand, while the command still exits 0. `help init` documents `.gitignore` in its path
  table and states that the file is never rewritten in either mode.

**Verification**

- `composer test` → `OK (178 tests, 613 assertions)`; `vendor/bin/phpstan analyse src bin
  tests --level=9` → `[OK] No errors`; `composer validate --strict` → valid;
  `composer dump-autoload --optimize --strict-psr` → no PSR-4 violations; `php -l` clean on
  every new and changed file; `php-cs-fixer --dry-run --rules=@PSR12` reports no diff on any
  of the ten.
- `tests/Init/IgnoreFileWriterTest.php` (22 tests, real temporary projects): the exact rule
  list for a default project and the file it is written to; the configuration and context
  file *not* being ignored; the rules following a configured `docs/backlog.md`; a configured
  `docs/sprint [1].md` escaped to `/docs/sprint \[1\].md`; existing rules kept with the block
  separated from them; a file with no trailing newline keeping its last rule; a second update
  leaving the file byte-identical; a user's `!` negation not overruled; eight spellings of an
  equivalent rule (the same rule, no leading slash, escaped, the bare name, the parent
  directory with and without a slash, a name wildcard, an anchored wildcard) each recognised
  as deciding the checklist; `.ralph/` + `*.bak` deciding everything; a *negated* parent
  deciding nothing; commented-out rules not counting; and the three skipped cases — a
  directory in the way, a symlink with its outside target untouched, and a read-only file
  (skipped for a user that can write one anyway).
- `tests/Init/ProjectInitializerTest.php` gained 4: the rules written on a fresh init and the
  ignore file reported apart from `paths()`; an existing file only appended to, with the rule
  it already had not repeated; `--force` resetting the three files while still only appending
  to `.gitignore` and leaving no `.gitignore.bak`; and a directory at `.gitignore` reported
  while the three files are still created.
- `tests/Console/InitCommandTest.php` gained 3 (the `created  .gitignore` row, the listed
  rules and the negation hint; a second run reporting `kept` and "already decides every
  rule"; the unusable file warning on stderr with exit 0 and the project still initialised),
  and `help init` is now asserted to document `.gitignore`.
- **Real Git agrees.** In a throwaway `git init` repository with a pre-existing `.gitignore`
  (`vendor/`, a comment), `ralph init` appended the block and `git check-ignore -v` then
  attributed `.ralph/tasks.md`, `.ralph/runtime/logs/x.log`, `.ralph/tasks.md.bak` and
  `.ralph/CONTEXT.md.bak` to the expected lines, while `.ralph/config.json`,
  `.ralph/CONTEXT.md` and `.gitignore` itself were *not* ignored.
- **The tests are not vacuous.** Three mutations were applied and reverted (originals copied
  to `/tmp` first and restored by `diff`-verified copy): writing every rule instead of only
  the missing ones failed 13 tests; making the block replace the file instead of being
  appended to it failed 5; and dropping the writer call from `ProjectInitializer` failed 7.
  The suite was re-run green after each restore.
- End-to-end by hand in six throwaway directories: a fresh project (file created with the
  header and five rules); a second run (`kept`, byte-identical); a `!/.ralph/tasks.md`
  negation respected; a project whose `.gitignore` already had `.ralph/` and `*.bak` and no
  trailing newline (`kept`, file untouched); a configuration naming `docs/backlog.md` and
  `docs/AGENT.md` (rules named those); and a directory at `.gitignore` (warning on stderr,
  exit 0).

**Notes / deviations**

- **A deleted rule comes back; a negated one does not.** Nothing distinguishes a line the
  user removed from a project that never had the rule, and recording what the package once
  wrote would mean keeping state about a file the user owns. So the honest answer is the one
  the written header, the command output and `help init` all give: negate the rule with `!` to
  track a path yourself. B5's draft wording claimed deletion was enough; it is not, and was
  corrected.
- **`IgnoreRules` has no "cannot be ignored" reporting.** A `tasks` or `context` path outside
  the project would need a rule that cannot exist, but B3's validator already rejects both
  before a configuration can load, so the branch would be unreachable; the `Path::isInside()`
  guard is kept so such a path could never be turned into a rule about an unrelated file. This
  follows B1's precedent of not shipping an untested helper with no caller.
- `.gitignore` is the only ignore file written. Git's other mechanisms
  (`.git/info/exclude`, a global `core.excludesFile`) are deliberately untouched: rules in
  the project file are the ones a collaborator also gets, and the ones a user looking for a
  rule they did not write will actually find.
- Writes are still not atomic, as in B5: the append is a single `file_put_contents()`, so a
  process killed mid-write can leave a truncated file. H8's atomic replacement should be
  reused here and in `ProjectInitializer` once it exists.
- `ralph init` still does not run `git`, and does not require the project to be a repository:
  a `.gitignore` in a directory that is not one is harmless, and whether the repository and
  `git.base` are usable is a preflight question (B7), the one item left in section B.
- Still no PHP-CS-Fixer or PHPStan configuration file; both tools continue to be run ad hoc
  (CI and the full check set are J8).
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-28 — B7: Preflight checks

**Task:** Implement preflight checks for the declared PHP extensions, Git, repository
location, base reference, Git author identity, executor availability, and a usable
verification command. *(PROJECT.md section B, item 7)*

**What was done**

- **A process layer, because preflight has to ask `git` questions.** `src/Process/` gained
  three classes, kept deliberately small:
  - `ProcessRunner` runs an argument array with an explicit working directory and no shell,
    drains both pipes together through one `stream_select()` loop (draining one first
    deadlocks a child that fills the other), enforces a timeout by terminating and then
    killing, reads stdin from the null device so a command that decides to prompt sees
    end-of-input, and inherits the environment with per-call overrides (`false` removes a
    variable). Failure to *start* — no such program, missing working directory — is a
    `ProcessException` (new, exit status 78) rather than a result, because nothing ran.
  - `ProcessResult` is the value: command, exit code (`null` when killed), both streams,
    timed-out flag, duration, plus the readers everything downstream wants —
    `succeeded()`, `trimmedOutput()`, `firstOutputLine()`, `failureReason()` (first line of
    stderr, `fatal: ` stripped) and `describe()`.
  - `ExecutableFinder` resolves what a configured command would actually run: a name with a
    separator is a path (relative ones against the working directory), a bare name is
    searched in PATH as the shell would, and nothing is expanded or aliased. Empty PATH
    entries are deliberately not treated as "the current directory".
  Streaming a worker's output while it runs is E4's job and is not here; the runner collects.
- **The checks.** `src/Preflight/` holds the model — `CheckId` (which also declares the
  dependency graph), `CheckOutcome` (`pass`/`warn`/`fail`/`skip`), `CheckResult`
  (summary + details + hints, kept apart so the same result can be a table row, an expanded
  failure, or section I's structured event), `PreflightReport`, `PreflightContext`, the
  `PreflightCheck` interface, and `GitProbe` — plus eight checks in `Check/`:
  - `php`: the running version against `Ralph::MINIMUM_PHP_VERSION`, with the binary path.
  - `extensions`: `Ralph::REQUIRED_EXTENSIONS`, and separately that PDO reports a `sqlite`
    driver, which is the thing the state store actually needs.
  - `git`: the executable, and its version against `Ralph::MINIMUM_GIT_VERSION`.
  - `repository`: not a repository, bare, not the repository *root*, and not writable, each
    with its own message; reports the git directory and whether it is a linked worktree.
  - `base`: `git.base` resolved to a commit, with the subject as evidence; an empty
    repository is reported as having no commits rather than as a bad setting, and an
    unresolvable reference lists up to five existing branches.
  - `identity`: `git var GIT_AUTHOR_IDENT` and `GIT_COMMITTER_IDENT`, which is the same
    strict resolution `git commit` performs, so an identity Git would only guess at counts
    as missing.
  - `agent`: the selected agent's executable, and that every environment variable its
    definition declares is set and non-blank. Names only, never values.
  - `checks`: every configured verification command's executable, and — for a Composer
    invocation — that the project defines the script it names, which is the promise
    `Defaults::checks()` already made in B1.
  `Preflight::run()` selects the agent through B2's `AgentSelector` first (an unknown agent
  or unavailable adapter is a configuration error, not a check), then runs every check even
  after a failure, skipping only those whose dependency failed.
- **`Ralph` now states the requirements**: `MINIMUM_PHP_VERSION`, `REQUIRED_EXTENSIONS` and
  `MINIMUM_GIT_VERSION` (2.24.0, chosen from the newest Git feature the design commits to —
  `--end-of-options`, used so a configured reference can never be read as an option — with
  `worktree remove` 2.17, `worktree list --porcelain` 2.7 and `worktree add` 2.5 behind it).
- **`ralph preflight`.** A new command, registered alongside `init`: it prints the project,
  the configuration source and the agent, one row per check, then expands failures (on
  stderr) and warnings (on stdout) with their details and hints, and exits 0 when a run
  could start or 78 when it could not. `--agent NAME` checks another agent. It reads the
  defaults when the project has no configuration file, executes nothing and writes nothing.
  `ralph init`'s next steps now name it before `ralph run`.

**Verification**

- `vendor/bin/phpunit` → **266 tests, 860 assertions, green** (was 257/833); the
  `installation` group passes separately (6 tests, 42 assertions).
- `vendor/bin/phpstan analyse src tests --level=max` → no errors.
- PHP-CS-Fixer with `@PER-CS` + `@PHP83Migration`, plus the two rules the existing codebase
  deviates on (`fn (` spacing, no single-line empty bodies), reports only the two
  pre-existing files it already reported (`src/Init/Templates.php`,
  `tests/Agent/AgentSelectorTest.php`); none of the new files.
- New tests: `tests/Process/ProcessRunnerTest.php` (12 — capture, no shell interpretation,
  working directory, environment add/remove, a timeout that stops the process, 512 KiB on
  both streams without deadlock, end-of-input on stdin, both start failures, two programming
  errors), `tests/Process/ProcessResultTest.php` (7), `tests/Process/ExecutableFinderTest.php`
  (10 — PATH order, the executable bit, a directory of the same name, unset and empty PATH,
  absolute and relative paths, the search-path description), `tests/Preflight/PreflightTest.php`
  (24, against real repositories), `tests/Preflight/ComposerScriptsTest.php` (23),
  `tests/Console/PreflightCommandTest.php` (8), `tests/DeclaredRequirementsTest.php` (4).
- `tests/DeclaredRequirementsTest.php` exists because the same two requirements are now
  written in three places: it asserts that `composer.json`'s `ext-*` requirements equal
  `Ralph::REQUIRED_EXTENSIONS`, that its `php` constraint matches
  `Ralph::MINIMUM_PHP_VERSION`, and that `bin/ralph`'s two literals match
  `Ralph::MINIMUM_PHP_VERSION` and `ExitCode::CONFIGURATION`.
- New test support: `tests/Support/Git.php` (real throwaway repositories, with the identity
  never configured implicitly) and `tests/Support/Environment.php` (set/remove a real
  variable and restore it), both of which sections F and J will reuse.
- **The tests are not vacuous.** Seven mutations were applied and reverted (originals copied
  to `/tmp` first and restored by `diff`-verified copy): dropping dependency skipping broke
  5 tests; ignoring the executable bit broke 2; inheriting `GIT_DIR`/`GIT_WORK_TREE` in
  `GitProbe` broke 1; `succeeded()` ignoring a timeout broke 1; the Composer script check
  always abstaining broke 5; `CheckOutcome::blocks()` returning `false` broke 4; and stopping
  the loop at the first failure broke 4. The suite was re-run green after each restore.
- Two findings came out of that exercise rather than from review. `failureReason()` rendered
  a 20-second timeout as "2 seconds" (`rtrim($seconds, '.0')` eating the trailing zero of
  `20.0`), now a `describeSeconds()` that only drops a trailing `.0`. And the first
  `blocks()` mutation survived, because `isUsable()` compared against `CheckOutcome::Failed`
  directly while `CheckResult::blocks()` had no caller at all; `failures()` now asks the
  outcome, and the two unused methods (`CheckResult::blocks()`, `PreflightReport::skipped()`)
  are gone, following B1's precedent about helpers with no caller.
- **Real environments by hand**, in throwaway directories: a prepared project (all eight
  pass, exit 0); a repository with no commits; a bare repository; a subdirectory of a
  repository (reported with the root to run from); `PATH=/nonexistent` (git fails, its three
  dependents skip, the agent at an absolute path still passes); a repository whose identity
  is hidden with `GIT_CONFIG_GLOBAL=/dev/null`; a `composer.json` without the `test` script
  the check names; `--agent claude` against the Claude Code CLI installed on this machine
  (resolved and passed); and two `git` shims on PATH — `2.17.1`, rejected as too old, and
  `git version (a vendor build)`, warned about while every later check still ran.

**Notes / deviations**

- **`ralph preflight` is not in PROJECT.md's command table.** The checks are specified as
  something `run` and `resume` do before starting work, and both are still planned commands,
  so implementing B7 without a surface would have meant shipping eight checks with no caller
  — against this project's own precedent, and unverifiable by hand. A command is also the
  right shape for the job: it is what a user runs after installing the package or setting an
  agent up, it changes nothing, and `run` and `resume` will call the same `Preflight` object
  and stop on `isUsable() === false`. Its help says "will perform", not "perform", because
  in this build they do not exist yet.
- **No agent CLI version check.** E9 owns "incompatible CLI versions", and it needs the
  per-agent knowledge of what each CLI's version output looks like, which only the adapters
  have. B7 covers availability: the executable resolves, and the declared variables are set.
- **An agent authenticated by interactive login cannot be verified.** Both built-in agents
  declare no environment variables (B1's reasoning: demanding an API key would fail preflight
  for a working `codex login` setup), so for the default configuration the agent check
  proves the CLI is installed and nothing about whether it is signed in. That is a real limit
  of checking without executing, and the check says what it did rather than implying more.
- **The checklist is not checked here.** Whether `.ralph/tasks.md` exists, parses, or has
  pending work is section C's, and a check about it would have to duplicate the parser. B7's
  list does not include it; a project with no checklist currently learns that from `run`.
- **Uncommitted changes are not inspected.** F1 owns rejecting unrelated uncommitted work,
  and doing it here would mean two places deciding what "unrelated" means.
- **`GIT_DIR` and `GIT_WORK_TREE` are dropped** from every command `GitProbe` issues: Ralph
  PHP locates the repository from the project directory it was given, so a redirection left
  in the shell cannot make preflight answer about another repository. F should keep that
  convention when it starts creating worktrees.
- Verification commands are resolved against the controlling checkout, not a task worktree.
  A command that works here can still fail in a fresh worktree that has no `vendor/`; that
  is F5's item, and the class says so.
- `ProcessRunner` stops one process, not a process tree. Stopping an agent's children on
  Ctrl+C is H4's, and the method that terminates says where that belongs.
- Still no PHP-CS-Fixer or PHPStan configuration file; both are still run ad hoc, and the
  fixer invocation above had to reconstruct the project's effective rules. A committed
  configuration for both belongs with CI (J8).
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-28 — C1: Reading pending and checked items from the checklist

**Task:** Implement parsing of top-level `- [ ]` pending tasks and `- [x]` or `- [X]` checked
tasks from `.ralph/tasks.md`. *(PROJECT.md section C, item 1)*

**What was done**

- `src/Tasks/ChecklistParser.php`: turns Markdown into items, with **no I/O**, so it is
  testable against strings and the same parser serves a file, a template and (later) a
  checklist read out of a worktree. A task is a line that starts **in column one** with a
  bullet, a checkbox and a description. All three CommonMark bullet characters are accepted
  (`-`, `*`, `+` are the same construct, and a user who writes one of the other two means a
  task); an ordered list is not, since it is a different construct that neither the README's
  syntax nor the generated checklist suggests. Whitespace is required after both the bullet
  and the checkbox, so `-[ ] x` and `- [ ]x` are prose, and the description must start with a
  non-space character, so a bare `- [ ]` is not an item either.
- **Fenced code blocks are not tasks.** Both fence characters are tracked, with CommonMark's
  rules for the ones that matter here: up to three leading spaces open a fence, a closing
  fence uses the same character and is at least as long, and an unclosed fence runs to the
  end of the document — the safer reading, since an unterminated example is then treated as
  the code it looks like rather than as work. A line opening with backticks whose info string
  contains another backtick is prose holding a code span, not a fence. This is what lets the
  checklist `ralph init` writes carry a worked example of the syntax without that example
  becoming the project's first task, which is now asserted directly against `Templates`.
- **The parser is line-based on purpose, not a Markdown implementation.** The file is a
  document a human edits and the runner later rewrites exactly one marker of, so what matters
  is that the line the user sees as a task is the line that is read and written back — not
  that every CommonMark subtlety is honoured.
- `src/Tasks/ChecklistItem.php`: description, checked, line number and **the line's source
  bytes**. `checked` is deliberately recorded as *the marker in the file*, not a verdict that
  anything was verified; an item the user ticked by hand is indistinguishable here, and that
  distinction belongs to the state store (section C, item 5). `source()` is the line exactly
  as read — trailing spaces and all, where `description()` is trimmed — so the checkbox
  writer of section C, item 6 can confirm the line it is about to rewrite is still the line
  that was parsed before it touches the file.
- `src/Tasks/Checklist.php`: the items in file order, plus the path they came from. **An
  empty checklist is a valid result, not an error** — a freshly initialised project is
  exactly that — so whether "nothing pending" means finished or means empty is left to the
  runner, which is the only thing that can tell.
- `src/Tasks/ChecklistReader.php`: the one place that turns the configured `tasks` path into
  a `Checklist`, so every command reports a missing file, a directory in its place or an
  unreadable file the same way. Reading never writes: the checkbox updates are a separate
  writer, which is what keeps `status` and `logs` structurally unable to alter the user's
  checklist.
- `src/Exception/ChecklistException.php`: kept apart from `ConfigurationException` because
  the settings may be perfectly valid while the file they point at is the problem, and the
  user edits a different file to fix it. It carries hints like the other readable failures
  and reports `ExitCode::CONFIGURATION`, since nothing has been executed when a caller sees
  it.
- **Contents that are not valid UTF-8 are refused** rather than parsed. Task descriptions
  become branch names, commit messages, log entries and structured events, so an invalid byte
  accepted here resurfaces as a confusing failure far from the file that caused it. A
  byte-order mark is stripped instead, because it would otherwise sit in front of the first
  bullet and hide a task that looks perfectly ordinary in an editor. All three line endings
  are recognised, so a checklist written on Windows reads the same and no carriage return can
  end up inside a description.

**Verification**

- `composer test` → `OK (323 tests, 965 assertions)`; `vendor/bin/phpstan analyse src bin
  tests --level=9` → `[OK] No errors`; `composer validate --strict` → valid;
  `composer dump-autoload --optimize --strict-psr` → no PSR-4 violations; `php -l` clean on
  all seven new files; `php-cs-fixer --dry-run --rules=@PSR12` reports no diff on any of them.
- `tests/Tasks/ChecklistParserTest.php` (49 tests) is written as the documents a user
  plausibly produces rather than as unit cases: pending and checked items read in file order;
  line number and source bytes; eleven recognised spellings (three bullets, tabs and runs of
  spaces in both positions, inner Markdown, a literal `[x]` inside a description, accents);
  eighteen lines that are *not* items (headings, prose that mentions the syntax, a plain
  bullet, missing spaces, `[]`, `[?]`, `[xx]`, an empty and a whitespace-only description,
  indented and tab-indented items, an ordered list item, a blockquoted item, a checkbox later
  in the line); six fence spellings each hiding what they enclose; a fence closed only by its
  own character and length; an unclosed fence; a leading code span that is not a fence; the
  three line endings; a byte-order mark; an empty document; invalid UTF-8; and the `ralph
  init` template yielding nothing.
- `tests/Tasks/ChecklistReaderTest.php` (8 tests, real temporary projects): the configured
  checklist read with its absolute path kept; a checklist configured at `docs/backlog.md`
  read from there; an empty one accepted; a missing one reported with its path and the hint
  to run `ralph init`; a directory in its place reported as one; an unreadable file reported
  rather than silently read as empty (skipped for a user who can read it anyway); and a
  non-UTF-8 file refused with its path.
- **The tests are not vacuous.** Eight mutations were applied and reverted (originals copied
  to `/tmp` first and restored by `diff`-verified copy): allowing leading whitespace before
  the bullet failed 3 tests; ignoring fences entirely failed 10; treating `[X]` as unchecked
  failed 1; dropping the byte-order-mark strip failed 1; splitting on `\n` alone failed 1;
  accepting an empty description failed 1; letting any fence close any other failed 1; and
  dropping the reader's directory check failed 1. The suite was re-run green after each
  restore.
- **By hand, on real files.** In a throwaway `git init` project, `ralph init`'s checklist
  parses to **0 items**, confirming the template's own claim that its fenced example is not
  picked up. A hand-written `sprint.md` with **CRLF endings**, a heading, prose mentioning
  the syntax, a `- [X]` item, an item with indented detail bullets, an indented `- [ ]`
  pretending to be a subtask, a plain bullet, a `*` item with accents, a fenced example and a
  `+` item with trailing spaces yielded exactly the four real tasks, at lines 5, 6, 10 and
  16, with the right checked states. Every item's `source()` came back byte-identical to its
  line in the file, trailing spaces included, while `description()` was trimmed. A missing
  path produced the message and both hints.

**Notes / deviations**

- **Nothing calls the parser yet, and that is the layering, not an oversight.** `ralph
  status` is the command that lists tasks, and it is still a registered `PlannedCommand`
  pointing at section I, item 1, which implements it "against the real runner and state
  store" — neither of which exists before section D. B7 added `ralph preflight` because eight
  environment checks could not otherwise be exercised by hand; a parser has no such problem,
  and the hand-runs above read real files through the real reader. Building a `status` here
  would be section I's work done early and with nothing to report.
- **Indented bullets under an item are ignored, not attached.** That is section C, item 2,
  and the parser says where it stops.
- **Near-misses are skipped silently, for now.** An empty description and an indented
  checkbox pretending to be a nested subtask are both specified as *readable errors* in
  section C, item 3; here they simply are not items. Doing it now would mean writing the
  error message twice, since item 3 also has to decide what counts as "unsupported nested
  checkable subtask".
- **No task IDs yet.** `description()` deliberately returns the text as written, with inner
  Markdown untouched: normalising it is what generates stable IDs, and that is section C,
  item 4, which also has to reject duplicate normalised bodies.
- **An ordered list is not a checklist.** `1. [ ] Work` is read as prose. If a user asks for
  it later it is a one-line change to the pattern, but accepting it now would mean the
  checkbox writer has to handle renumbering, which nothing requires.
- The fence handling covers the constructs the checklist actually contains. A checkbox inside
  an HTML block, or one inside an indented (four-space) code block that is *not* fenced, is
  not recognised as code — the first is not a task either way because it would be indented or
  prose, and the second is already excluded by the column-one anchor.
- `ChecklistException` reuses `ExitCode::CONFIGURATION`. A separate code would say more, but
  section I, item 5 is where the exit codes are defined and documented as a set, and
  inventing one here risks having to change it there.
- Still no PHP-CS-Fixer or PHPStan configuration file; both continue to be run ad hoc, and a
  committed configuration for both belongs with CI (J8).
- Still no Git remote on this machine, so this commit is local only (see A1 notes).

## 2026-10-30 — C2: Attaching details to a task

**Task:** Attach indented ordinary bullets and paragraphs to the preceding task as optional
implementation details or acceptance criteria. *(PROJECT.md section C, item 2)*

**What was done**

- `src/Tasks/ChecklistParser.php`: an item is now held back until the lines below it have been
  read, so what is indented under a task becomes that task's **details** — the implementation
  notes or acceptance criteria that will be handed to the agent alongside the description.
  The parse loop keeps one open item plus its collected lines and flushes it when the next
  task, a line in column one, or the end of the document arrives.
- **Any indentation counts, and blank lines do not break the block.** A line that starts with
  a space or a tab belongs to the task above it, whatever the amount; demanding CommonMark's
  exact continuation width would reject a one-space indent that plainly reads as a detail in
  an editor. Blank lines are collected too, so an item can carry several paragraphs ("a
  sentence, a blank line, then an acceptance-criteria list"), and the blank lines that turn
  out to be followed by something else are dropped again when the item is built. An item's
  details therefore end at its last line of *content*: `endLine()` points at that line, not
  at the empty space in front of the next task.
- **A fence opened inside details runs to its own closing fence.** This is what makes a
  documented example safe to write under a task: the enclosed lines belong to the item even
  when written in column one as tasks of their own. Two things had to be relaxed from the
  column-one rules for this — details are indented to begin with, so a fenced example nested
  under a bullet of its own sits further in than the three spaces CommonMark allows a
  top-level fence, and its closing line is indented just as far. Both the opening pattern
  (`DETAIL_FENCE`) and the closing one accept arbitrary indentation inside details, while the
  column-one behaviour from C1 is untouched. A fence left unclosed inside details still runs
  to the end of the document, as it does at the top level.
- `src/Tasks/ChecklistItem.php`: details are kept **both** as read (`detailLines()`, no line
  endings, trailing spaces intact) and as a block of Markdown (`details()`), because the
  first is what the checkbox writer and a content hash will need and the second is what goes
  into a prompt. `hasDetails()` and `endLine()` round out the accessors.
- **`details()` removes only the indentation every line genuinely shares**, compared as
  literal text rather than as a width. A bullet list written two spaces in reads as a list
  rather than as a code block, nested bullets keep their relative nesting, and a block that
  mixes tabs and spaces loses nothing, because no tab size is assumed. The text is otherwise
  untouched — it is the user's prose, and rewriting it is not the parser's business.
- **Whitespace-only lines are normalised to the empty string.** Otherwise a stray space on an
  apparently blank line is the shared prefix of the whole block and the dedent collapses to
  nothing.

**Verification**

- `composer test` — **345 tests, 1021 assertions, green**, up from 323. PHPStan at `--level
  max` over `src` and `tests` reports no errors; PHP-CS-Fixer (`@PSR12`, dry run) reports
  nothing to fix.
- `tests/Tasks/ChecklistParserTest.php` grew to 71 tests. The new ones cover: a task on its
  own having no details; indented bullets and an indented paragraph becoming details; several
  paragraphs in one block; blank lines before the next task excluded; details ending at each
  kind of column-one line (another task, a heading, a paragraph, a plain bullet, a fence);
  nesting preserved while the shared indentation is removed; tab-indented details; a block
  mixing tabs and spaces losing nothing; detail lines kept byte-for-byte with a whitespace-
  only line emptied; CRLF details; an indented checkbox read as a detail; a fenced example
  inside details kept and its checkboxes hidden; a column-one checkbox inside a detail fence;
  a fence indented six spaces closed at its own indentation and hiding a column-one task; an
  unclosed detail fence; and indented lines that follow no task being ignored.
- **The tests are not vacuous.** Nine mutations were applied and reverted (original copied to
  `/tmp` first, restoration verified by `diff`): never collecting detail lines failed 18
  tests; keeping the trailing blank lines failed 5; treating a blank line as the end of the
  details failed 2; not normalising whitespace-only lines failed 1; not tracking fences inside
  details failed 2; closing a detail fence only within three spaces failed 1; dedenting by the
  first line's indent instead of the shared prefix failed 2; and using the column-one fence
  pattern inside details failed 1. The suite was re-run green after each restore. The ninth —
  dropping the `$open !== null` guard before collecting a detail line — changed no test, and
  the guard is in fact behaviour-preserving: an indented line with no task above it is only
  ever collected into a buffer that the next task discards, and a fence it opens at four or
  more spaces is an indented code block either way. It is kept because the code reads as what
  it means.
- **By hand, on a real file.** `/tmp/handrun/tasks.md` — prose with an indented bullet above
  the list, a hand-ticked item with an indented paragraph, a task with nested detail bullets
  followed by a blank line, an "Acceptance criteria:" paragraph and an indented checkbox, a
  task whose details hold a fenced example containing a column-one `- [ ] A task`, and a bare
  task at the end — read through the real `ChecklistReader` as **4 items**, at lines 6–7,
  9–16, 18–23 and 25–25, with exactly the expected details, the indented bullet above the
  list ignored, and no task from inside the fence.

**Notes / deviations**

- **Lazy continuation is not supported.** CommonMark would attach unindented prose written
  directly below a list item to that item; here it ends the details. In a file the runner
  rewrites line by line, "indented belongs to the task above" is a rule a user can see is
  being followed, and the alternative makes a stray paragraph silently part of a task.
- **An indented checkbox is now a detail line rather than nothing.** PROJECT.md section C,
  item 3 makes an unsupported nested checkable subtask a *readable error*; until then it is
  text under its task, which at least keeps it in front of the agent instead of dropping it.
  The same holds for a `- [ ]` with an empty description.
- **A fenced example whose content sits in column one is not dedented.** It shares no
  indentation with the prose around it, so the block is handed over exactly as written —
  faithful, and it cannot break the example, which dedenting to the item's own indent could.
- A whitespace-only line *inside* a fenced example in details also loses its spaces. No
  checklist depends on trailing whitespace in an example, and the alternative is a dedent
  that silently stops working.
- `ChecklistItem::sharedIndent()` uses `strspn()` rather than a regular expression: PHPStan at
  `--level max` cannot prove `preg_match`'s match array is populated, and the fix of asserting
  it would be worse than not needing the regex.
- **Still nothing calls the parser.** Unchanged from C1 and still the layering: `ralph status`
  lists tasks and remains a `PlannedCommand` pointing at section I, item 1, which implements
  it against the runner and state store that sections D–G build. The hand-run above goes
  through the real reader.
- No stable task IDs yet, so nothing here hashes the details; C4 generates the IDs and will
  have to decide whether details take part in the normalised content (they describe the work,
  so they probably should, but the item that rejects duplicates is where that is settled).
- `loop.sh` has uncommitted local changes from the harness around this project; they are not
  part of this task and were left alone.

## 2026-10-30 — C3: Ignoring prose, and refusing the two near-tasks

**Task:** Ignore headings, unrelated prose, and checklist-like text inside fenced code
blocks; reject empty descriptions and unsupported nested checkable subtasks with readable
errors. *(PROJECT.md section C, item 3)*

**What was done**

- The *ignoring* half was already the parser's behaviour from C1 and C2 — a task is a
  column-one bullet with a well-formed checkbox, everything else is prose, and a fenced
  block hides whatever it encloses at the top level or inside a task's details. This item
  added the *refusing* half and the tests that pin the boundary between the two.
- `src/Tasks/ChecklistParser.php`: two near-tasks now stop the read with a
  `ChecklistException` instead of being skipped.
  - **A checkbox with nothing after it** (`EMPTY_ITEM`, deliberately disjoint from `ITEM`,
    which requires a non-space character after the marker). An item with no description
    cannot be handed to an agent, identified by its content, or named in a status line, and
    the user is three words from a task that works.
  - **An indented checkbox** (`NESTED_ITEM`). This release runs top-level items only
    (PROJECT.md section 2), and an indented checkbox is the one piece of detail text that
    looks exactly like work the runner has accepted. C2 read it as an ordinary detail line,
    which handed it to the agent inside *another* task's notes and never checked it off —
    indistinguishable, from the outside, from the runner having lost it.
- **Both errors name the file, the line number, and the line itself**, and carry hints with
  the ways out: finish or delete the empty one; promote the nested one to column one, demote
  it to a plain `- ` bullet, or fence it if it was only ever an example. Lines longer than 60
  characters are quoted truncated, so a long task does not bury the sentence that explains
  it. They are `ChecklistException`s, so they already exit `78` (configuration) and render
  with their hints through the existing CLI handler.
- **The checks sit below the open-fence branch and below the `ITEM` match**, which is the
  whole of their placement logic: a fenced example may show a malformed task without the
  document that shows it failing to parse, and a well-formed task is never also a mistake.
- **A line only resembling an item is still prose.** `- []`, `- [?]`, `  - [ ]Work`,
  `  -[ ] Work`, a blockquoted `> - [ ] Work`, an indented ordered-list item and a checkbox
  inside a table cell are untouched: the line has to be a bullet followed by a well-formed
  checkbox and a separator before it is anyone's mistake. The nested pattern keeps `ITEM`'s
  requirement that something separate the checkbox from what follows, which is what holds
  `  - [ ]Work` on the prose side of the line.
- **An indented checkbox under no task at all is refused too.** It is work the user wrote
  down that the runner will never run, and the fix is the same one; the sentence in the
  error says "indented checkbox", which is true in both positions.
- `src/Tasks/ChecklistParser.php`: the UTF-8 error now shares the new `subject()` helper, so
  a checklist parsed from a string says "The task checklist is not valid UTF-8." rather than
  "The task checklist contents is not valid UTF-8."
- `src/Init/Templates.php`: the generated `tasks.md` documents the two refusals in a
  paragraph next to the one about what is ignored, so a user meets the rule in the file they
  are writing rather than in an error.

**Verification**

- `composer test` — **372 tests, 1067 assertions, green**, up from 345. PHPStan at `--level
  max` over `src` and `tests` reports no errors; PHP-CS-Fixer (`@PSR12`, dry run) reports
  nothing to fix.
- `tests/Tasks/ChecklistParserTest.php` grew to 98 tests. Five cases moved out of the
  `nonItems` provider, which asserted silence, into providers that assert refusal: the two
  empty descriptions and the three indented items. New coverage: eight spellings of an empty
  description (pending, checked, uppercase, trailing space, trailing tab, each bullet
  character, padded bullet) and eight of a nested one (one space, two spaces, a tab, deeply
  indented, checked, each bullet character, no description); both full messages and both
  hint lists asserted verbatim; the long-line truncation; twelve lines that only resemble
  items staying prose; and a document carrying both mistakes inside fences — one at the top
  level, one inside a task's details — parsing to exactly one task.
- **The tests are not vacuous.** Nine mutations were applied and reverted (original copied to
  `/tmp` first, restoration confirmed by `diff` and a green re-run): deleting the
  empty-description throw failed 9 tests; deleting the nested-subtask throw failed 11;
  dropping the lookahead from `NESTED_ITEM` errored 1 (`  - [ ]Work` refused); moving both
  checks above the open-fence branch errored 4 (fenced examples refused, the `ralph init`
  template among them); refusing a nested subtask only while a task is open failed 2;
  reporting `$index` instead of `$index + 1` failed 10; never truncating failed 1; not
  trimming the excerpt failed 2; and dropping the path from `subject()` failed 4.
- **By hand, on real files.** Four documents in `/tmp/c3run` read through the real
  `ChecklistReader`: one with a fenced example containing *both* mistakes, which parsed to
  the two real tasks below it with their details; one with a trailing `- [x]`; one with an
  acceptance-criteria checkbox indented under a task; and one long nested line. The three
  bad ones reported the right line number, the quoted line (truncated at 60 characters for
  the long one), their hints, and exit code 78.

**Notes / deviations**

- **The refusal is the whole document's, not just that task's.** A malformed line stops the
  read rather than being skipped with a warning, because every caller of the parser is about
  to act on the file — and a run that starts against a checklist the user has misread is
  worse than one that does not start. If a warn-and-continue mode is ever wanted, it belongs
  with the runner's reporting, not here.
- **A blockquoted `> - [ ] Work` stays prose.** It is checklist-like text, but quoting is how
  Markdown says "this is something being shown, not something being stated", and the fence
  rule already covers the deliberate example. Refusing it would make it impossible to quote a
  task in a note.
- **`- [?] Work` and `- [] Work` are prose, not errors.** They are not checkboxes; treating a
  plausible typo as a task would mean guessing which character the user meant, and `ITEM`'s
  accepted set is exactly what the template documents.
- The 60-character excerpt limit is a readability choice with nothing behind it; it is
  measured with `mb_strlen`/`mb_substr`, so a multi-byte description is cut on a character
  rather than mid-sequence.
- **Still nothing calls the parser.** Unchanged from C1 and C2: `ralph status` remains a
  `PlannedCommand` pointing at section I, item 1. The hand-run above goes through the real
  reader.
- C4's stable task IDs will hash normalised task content. Nothing in this item constrains
  that choice; it only guarantees that every item reaching C4 has a non-empty description.
- `loop.sh` has uncommitted local changes from the harness around this project; they are not
  part of this task and were left alone.

## 2026-10-30 — C4: Stable task identifiers, and refusing duplicates

**Task:** Generate stable internal task IDs from normalized task content, excluding
checkbox state and file position; reject duplicate normalized task bodies. *(PROJECT.md
section C, item 4)*

**What was done**

- `src/Tasks/TaskBody.php`: the normalisation identity is computed from. Invisible
  characters are removed, every run of whitespace collapses to one space and is trimmed
  from both ends, and letter case is folded. The checkbox, the line number, the file and
  the item's indented details are not read at all — the first two because a task is the
  same task whether it is ticked or where it sits, the details because they are notes about
  *how* to do the work and get rewritten while the task stays the same.
- `src/Tasks/TaskId.php`: `TASK-` plus the first eight hexadecimal digits of the SHA-256 of
  the normalised body, uppercased. Also reads an identifier back from text
  (`fromString`/`isValid`), forgiving case and surrounding space, because that value is
  what a user pastes into `ralph logs` after copying it out of a status table.
- `src/Tasks/Task.php`: a `ChecklistItem` together with its identifier and body. Kept apart
  from `ChecklistItem` so the parser stays a reader of Markdown with no opinion about
  identity, and the item is kept whole rather than copied field by field because the
  checkbox writer (C6) needs the line exactly as it was read.
- `src/Tasks/TaskIdentifier.php`: identifies a whole `Checklist` in file order, checked
  items included, and refuses two items that state the same task. Also refuses the rarer
  case of two *different* bodies hashing to one identifier rather than letting two tasks
  share a branch, a log directory and a row of state.
- `src/Support/Excerpt.php` and `Checklist::describe()`/`subject()`: the 60-character
  quoting and the "The task checklist <path>" phrasing moved out of `ChecklistParser` so
  an error raised while parsing and one raised while identifying name the same document the
  same way. No message text changed.
- `src/Init/Templates.php`: the generated `tasks.md` now says that a task is identified by
  its description alone — so reordering, ticking or rewriting notes keeps its history — and
  that two items reading the same are one task and are reported as an error.

**Verification**

- `composer test` — **446 tests, 1197 assertions, green**, up from 372. PHPStan at
  `--level max` over `src` and `tests` reports no errors; PHP-CS-Fixer (`@PSR12`, dry run)
  reports nothing to fix.
- `tests/Tasks/TaskIdTest.php` (54 cases) pins the derivation to **literal identifiers**
  (`Add a --dry-run option` → `TASK-19E0214A`, and two more), asserts the `TASK-[0-9A-F]{8}`
  shape across Markdown, punctuation, accented, Arabic, emoji and paragraph-length
  descriptions, and asserts each thing identity ignores on its own: the checkbox, the line,
  the details, surrounding and doubled spaces, tabs, a trailing hard break, non-breaking and
  ideographic spaces, a zero-width space, a stray byte-order mark, and case (including in
  non-Latin scripts). The other direction too: a trailing full stop, emphasis, backticks, a
  missing word and `--dry_run` are all different tasks. Empty bodies in five spellings have
  no identifier, invalid UTF-8 is refused, and ten kinds of not-an-identifier are rejected
  by `fromString`.
- `tests/Tasks/TaskIdentifierTest.php` (26 cases) identifies documents a user plausibly
  writes, including the one this is all for: the same three tasks reordered, one ticked, a
  note indented under another and the file renamed still yield the same three identifiers.
  Duplicate refusals are asserted with full messages and hint lists — the exact repeat, the
  three that differ only in case or spacing, the ticked-plus-pending pair, the pair that
  differs only in its notes, the first duplicate winning in a file with two, the truncated
  quote for a long description, the no-path phrasing, exit code 78 — as is the real
  collision pair below.
- **The tests are not vacuous.** Eighteen mutations were applied and reverted (originals
  copied to `/tmp` first, restoration confirmed by `diff` and a green re-run): folding the
  checkbox into the body failed 5 tests; folding the details in failed 4; not folding case
  failed 14; not collapsing whitespace failed 8; keeping invisible characters failed 3;
  dropping the `/u` modifier failed 3; dropping `trim` failed 6; dropping the UTF-8 check
  failed 1; seven digits instead of eight errored 1 and failed 14; not uppercasing failed
  15; giving an empty body an identifier failed 5; not case-folding typed input errored 2;
  accepting duplicate bodies failed 7; accepting colliding identifiers failed 1; always
  adding the capitalisation hint failed 2; never adding the details hint failed 1; and
  reporting one line instead of two failed 8.
- **A real collision is tested, not hypothesised.** Eight hexadecimal digits is 32 bits, so
  a birthday search over ~24k strings found `collision probe 14565` and
  `collision probe 24048`, which both hash to `TASK-0ADF80F7`. The test asserts they collide
  *and* that a checklist containing both is refused, so the branch cannot rot.
- **By hand, on real files.** Five documents in `/tmp/c4run` read through the real
  `ChecklistReader` and identified: one well-formed (three tasks, details attached); the
  same three reordered, ticked and in a differently named file (identical identifiers); a
  repeat differing only in case and spacing; a ticked task with a pending copy below it; and
  the collision pair. Each refusal printed the right lines, the quoted description, its
  hints and exit code 78.

**Notes / deviations**

- **Case is folded, and that is a trade with a visible edge.** Two genuinely different tasks
  that differ only in capitalisation — "Rename foo to Foo" and "Rename Foo to foo" — read as
  one task here and are refused as duplicates. That refusal names both lines and one
  clarifying word resolves it, whereas the opposite choice silently orphans a running task's
  history the moment someone sentence-cases the checklist. Documented on `TaskBody`.
- **Markdown, punctuation and accents are kept.** Stripping emphasis means guessing which
  `*` is markup and which is the task (a task about glob patterns is a real task).
  "Add a flag" and "Add a flag." stay two tasks: a trailing full stop is not something a
  user adds twice by accident, and a punctuation-stripping rule has an edge nobody can
  predict. Accents are left alone because Unicode normalisation needs ext-intl, which this
  package does not require — applying it only where the extension happens to exist would
  make identifiers differ between a developer's machine and CI, which is worse than not
  applying it at all.
- **Identity is the description; the details are not part of it.** This is what lets C7
  report an edit to a task's notes as *that task changing* rather than as one task vanishing
  and another appearing, and it is why two items differing only in their notes are
  duplicates — with a hint that says so.
- **The derivation is a promise, not an implementation detail.** Identifiers go into Git
  branch names, commit messages and a SQLite database that outlive the process, so changing
  the recipe orphans every stored task. That is why the pinning test asserts literal values
  rather than recomputing the hash.
- **Eight digits is deliberate**: short enough to read out, type into `ralph logs` and put in
  a branch name, with a collision chance around one in a million for a hundred tasks — and
  the remaining case refused rather than papered over.
- **Still nothing calls this.** As with C1–C3, `ralph status` remains a `PlannedCommand`
  pointing at section I, item 1; the hand-run above goes through the real reader. C5 selects
  pending tasks from this list in file order, and D persists these identifiers alongside the
  content hash that covers the details.
- `loop.sh` has uncommitted local changes from the harness around this project; they are not
  part of this task and were left alone.

## 2026-10-30 — C5: Selecting the next task, and who finished the ticked ones

**Task:** Select pending tasks in file order and skip checked items; label imported checked
items as user-completed rather than runner-verified. *(PROJECT.md section C, item 5)*

**What was done**

- `src/Tasks/TaskList.php`: the shape the runner works from. Built from a `Checklist` (it
  identifies the items itself, so the chain is reader → parser → identifier → list), it
  answers the two questions a loop and a status table actually ask: `next()` is the first
  unticked item in the file, however many ticked ones precede it, and `completionOf()` says
  where every other task stands. Also `pending()`, `done()`, `userCompleted()`,
  `runnerVerified()`, the three counts, and `find()`/`has()` for resolving the `TASK-ID`
  argument of `logs` and `retry`.
- `src/Tasks/Completion.php`: the three answers a checkbox can have — `Pending`,
  `UserCompleted`, `RunnerVerified` — with `label()` for a status column (`pending`,
  `done (by you)`, `done (verified)`) and `describe()` for a sentence. Named apart from
  section D's lifecycle states on purpose: those track where a task is *inside* a run, this
  says where its completion came from.
- `src/Tasks/VerifiedTasks.php`: the one input needed to tell those two apart — a set of
  identifiers the runner has carried through verification itself. A small immutable value
  object rather than an interface onto the database, so selection stays pure and reading a
  checklist never needs SQLite open. `none()` is the default; `fromStrings()` reads stored
  values back and refuses anything that is not an identifier; `with()` returns a new set.
- `src/Tasks/ChecklistItem.php` and `src/Tasks/Task.php`: the docblocks that pointed
  forward to "section C, item 5" now point at `Completion` and `TaskList`. No behaviour
  changed; `isChecked()` is still the file's marker and nothing more.

**Verification**

- `composer test` — **465 tests, 1269 assertions, green**, up from 446. PHPStan at
  `--level max` over `src` and `tests` reports no errors; PHP-CS-Fixer (`@PSR12`, dry run)
  finds nothing to fix in either directory.
- `tests/Tasks/TaskListTest.php` (19 cases) is written against checklists a project really
  has: one being started with history already ticked in it, one part way through, one fully
  ticked, one reordered since the last run, and one whose ticked items are interleaved
  rather than grouped at the top. It asserts pending order, that `[x]` and `[X]` are both
  skipped wherever they sit, that a fully ticked list has nothing to do while still
  reporting four counts, and that the generated `tasks.md` is *empty* rather than finished.
- **The labelling is asserted in both directions.** A ticked item with nothing on record is
  `UserCompleted`, with the full sentence "marked done in the checklist, not verified by
  Ralph PHP"; the same item is `RunnerVerified` once the set names it, while its ticked
  neighbour stays the user's. A verified identifier the checklist no longer states is
  ignored rather than conjuring a task.
- **The reopened case is pinned now, not left to C8.** An *unticked* task whose identifier
  the state store has verified is `Pending` and is what `next()` returns: the file is the
  authority on what is being asked for. Reordering plus ticking is asserted to keep the
  same identifier and move it to `UserCompleted`.
- **The tests are not vacuous.** Twelve mutations were applied and reverted (originals
  copied to `/tmp` first, restoration confirmed by `diff` and a green re-run): labelling
  every ticked item runner-verified failed 3 tests; dropping the unticked-is-pending guard
  failed 10; `next()` ignoring completion failed 3; `pending()` returning everything failed
  3; reversing file order failed 7; answering `Pending` for an unknown identifier failed 1;
  accepting two tasks with one identifier failed 1; counting `Pending` as done failed 4; a
  verified set that contains nothing failed 2; and `with()` either returning `$this` or
  replacing the set failed 1 each. One mutation survived and was expected to —
  lower-casing a stored identifier before `TaskId::fromString()`, which uppercases it
  anyway, so the two are the same program.
- **By hand, on real files.** `/tmp/c5run/tasks.md` — prose, two ticked items, two pending,
  one with notes, one checkbox inside a fence — read through the real `ChecklistReader`.
  First reading: four tasks, two pending, both ticked ones `done (by you)`, next is the
  `--dry-run` option with its two notes attached. Second reading, with the importer in the
  verified set: that row becomes `done (verified)` and its neighbour stays `done (by you)`.
  A simulated loop then took `next()`, added it to the verified set, rewrote the checkbox
  and re-read until nothing was pending: it ran exactly the two pending tasks, in file
  order, and finished with two verified and two still imported as the user's.

**Notes / deviations**

- **A ticked item is the user's word until something can prove otherwise.** The default is
  `VerifiedTasks::none()`, so any caller without a state store under-claims rather than
  over-claims. Section D is what will supply a non-empty set; until then an imported
  checklist reads as exactly what it is — the project's history, not Ralph PHP's.
- **`isEmpty()` is not "nothing pending".** An empty checklist means there is nothing to do
  *yet*; a fully ticked one means the work is finished. Both leave `next()` null, and only
  the caller can phrase the difference, so the list reports them separately rather than
  guessing with an `isComplete()`.
- **Asking about a task that is not in the list is refused, not answered.** `completionOf()`
  throws on an unknown identifier instead of returning `Pending`, because a default of
  "pending" would have a caller run work the checklist no longer states. `find()`/`has()`
  are the nullable way to ask.
- **The list is a snapshot, deliberately.** It describes one reading of the file. The
  checkbox writer (C6) changes that file underneath it and C7 makes a mid-run edit something
  to reconcile rather than absorb, so a runner finishes a task and re-reads — which is what
  the hand-run above does — instead of mutating a list in place.
- **`of()` takes any array, not a list.** A caller that filtered or sliced its tasks keeps
  the order without having to reindex first; the list reindexes internally and still refuses
  two tasks sharing an identifier, which `TaskIdentifier` has already reported readably by
  the time anything gets here.
- **Still nothing calls this.** `ralph run` and `ralph status` remain `PlannedCommand`s
  pointing at section I, item 1; the hand-run goes through the real reader and the real
  list. C6 writes the checkbox the loop above only simulated, and D is what makes
  `RunnerVerified` reachable outside a test.
- `loop.sh` has uncommitted local changes from the harness around this project; they are not
  part of this task and were left alone.

## 2026-10-30 — C6: Ticking one box and leaving the document alone

**Task:** Implement checkbox updates that change only the selected task's marker and
preserve all unrelated text and formatting. *(PROJECT.md section C, item 6)*

**What was done**

- `src/Tasks/CheckboxEditor.php`: the substitution itself, free of I/O. Given a document
  and a `TaskId`, `tick()` and `untick()` return the document with that one task's marker
  changed and every other byte as it was. The task is located by parsing — `ChecklistParser`
  then `TaskList::find()` — rather than by searching the text, which is what keeps the
  fenced example in the generated checklist from ever being the line that gets ticked. The
  rewrite is one `preg_replace` of the state character inside `/^([-*+][ \t]+\[)[ xX](\])/`
  with both halves written back unchanged, so the bullet character, the spacing either side
  of the checkbox, the description and the line's trailing whitespace survive.
- `src/Tasks/CheckboxEdit.php`: what the update did — the document after it, the task, the
  line the marker was found on *now*, what it says, and whether anything changed. Setting a
  marker that already reads that way is not an error: it returns the document untouched with
  `isChanged()` false, which is what section G, item 8 needs from a resume that may be
  retrying a tick that actually landed before the crash.
- `src/Tasks/ChecklistWriter.php`: the only thing that writes to the user's task file.
  `tick()`/`untick()` take a `Configuration`, `tickFile()`/`untickFile()` an absolute path.
  An unchanged edit is not written at all, so the file keeps its modification time and stays
  out of a tracked checkout's `git status`.
- `src/Tasks/ChecklistFile.php`: the checklist as bytes — `read()`, lifted out of
  `ChecklistReader` so the reader and the writer report a missing, directory or unreadable
  checklist in the same words, and `replace()`, which writes a temporary file *beside* the
  original and renames it over it. The original's permissions are carried onto the
  replacement, since the rename produces a new inode and a checklist the user had made
  group-writable must not come back private because Ralph PHP ticked a box in it.
- `src/Tasks/ChecklistReader.php` now parses `ChecklistFile::read()`; its behaviour and
  messages are unchanged, which its own tests still assert. The docblocks in `TaskList`,
  `ChecklistItem` and `Task` that pointed forward to "section C, item 6" now name
  `ChecklistWriter` and `CheckboxEditor`.

**Verification**

- `composer test` — **498 tests, 1341 assertions, green**, up from 465. PHPStan at level
  max over `src` and `tests`: no errors.
- **The preservation claim is asserted as a byte comparison, not inspected piecemeal.** Most
  of the 21 editor tests build the expected document by replacing that one marker in the
  input and assert the result is identical, so anything reformatted, re-indented or
  re-ended shows up whether or not a test thought to look for it. Covered that way: CRLF
  throughout, a file that mixes all three endings, no final newline, a byte-order mark in
  front of the first task, `*` and `+` bullets with tab and multi-space gaps and trailing
  spaces, indented details and extra paragraphs under the task, and the whole generated
  checklist with a real task appended below its fenced example — which states the very same
  description, so a text search would have ticked the instructions instead.
- **The two directions and the no-ops.** `[X]` is read as ticked and normalised to `[x]`
  when it is unticked and re-ticked (the one formatting change made, and only to the marker
  it was asked to rewrite); ticking what is ticked and unticking what is open both report
  `isChanged()` false and return the input string. A task that moved is found at its new
  line; a task the file no longer states is refused, naming the identifier and the file.
- **Twelve mutations applied and reverted** (originals copied to `/tmp` first, restoration
  confirmed by `diff` and a green full run). Writing a space where `x` belongs failed 21
  tests; dropping the already-set short circuit, 4; indexing the line one off, 23; splitting
  without capturing the line endings, 18; letting the byte-order mark into the split, 1;
  answering a missing task with a no-op instead of refusing, 3; locating the task by
  stripped text instead of by parsing, 2; normalising the bullet and spacing in the
  replacement, 1; treating a failed `file_put_contents` as success, 8; not carrying the
  permissions over, 1; `copy()` instead of `rename()` (leaving the temporary file behind),
  1; writing an unchanged edit anyway, 1; swapping the writer's tick/untick branches, 7;
  and pointing the writer at a hardcoded path instead of the configured one, 2. One mutant
  survived and was expected to — `preg_replace`'s limit of 1 versus -1, which on a single
  line matched by an anchored pattern is the same program.
- **By hand, on a real CRLF file.** `/tmp/c6run/tasks.md` — prose, a table, a fenced
  example, one hand-ticked item, two pending ones with `*` and `+` bullets, trailing spaces
  on a task line, details under it, and no final newline. A loop took `next()`, ticked it
  through the real writer and re-read until nothing was pending: it ticked exactly the two
  pending tasks, `diff` of `cat -A` before and after showed exactly two changed lines and
  nothing else, the file still ends without a newline, re-ticking reported no change, an
  unknown identifier was refused, and no temporary file was left in the directory.

**Notes / deviations**

- **The task is named by identifier, not by line number.** The parsed line number is not
  trusted: identifiers come from the description alone, so a task that moved while the agent
  worked is still found, and one whose wording changed is *not* found and is refused rather
  than approximated. Pausing a run for that reconciliation instead of simply failing is C7,
  which builds on this refusal; the message already says the work is still on its branch and
  that restoring the wording records the result against it.
- **Atomic replacement landed here rather than in H8.** PROJECT.md pairs it with source
  content checks in section H, item 8, but the temporary-file-and-rename is a few lines and
  the alternative was a window where an interrupted write leaves the user's own checklist
  truncated. What H8 still owes is the other half: detecting that someone else edited the
  file between the read and the write, and refusing instead of overwriting them.
- **`[x]` lower case, deliberately.** It is what `ralph init` generates and what PROJECT.md
  writes. `[X]` is still read as ticked wherever a user wrote it and is left alone unless the
  marker is the one being rewritten.
- **Reopening is not implemented by `untick()`.** The method exists because a prematurely
  ticked box has to be able to go back to being work, but preserving a reopened task's
  history and giving it a new execution generation is C8 and needs the state store.
- **The loop test is bounded.** `the_whole_checklist_is_worked_through_one_tick_at_a_time`
  exits on `next()` returning null, so a writer that did not tick what it was asked to would
  hang the suite rather than fail it. A mutation did exactly that; the loop now asserts a
  bound each pass, and the same mutation reports 7 failures in under a second.
- **Nothing calls this yet.** `ralph run` remains a `PlannedCommand` pointing at section I,
  item 1. The hand-run above drives the real reader and the real writer; what decides a task
  has *earned* its tick — verification, the commit, any required push — is section G.
- `loop.sh` has uncommitted local changes from the harness around this project; they are not
  part of this task and were left alone.

## 2026-11-01 — C7: Noticing the checklist changed under a running task

**Task:** Detect task edits or removal during active execution and pause for reconciliation
instead of silently applying old progress to changed work. *(PROJECT.md section C, item 7)*

**What was done**

- `src/Tasks/TaskSnapshot.php`: a task exactly as it read when the runner picked it up.
  Two things are kept and they answer different questions — the `TaskId`, which comes from
  the description alone, so editing the description makes this a *different* task rather
  than a changed one; and a content hash that also covers the indented details, so an edit
  to the acceptance criteria under a task is caught even though its identity is untouched.
  The hash is the "task content hash" section D, item 4 persists, so its recipe is a promise
  and not an implementation detail: SHA-256 of the normalised description, a newline, and
  the normalised details, both halves through `TaskBody`, with the newline chosen because it
  cannot occur inside either half and so keeps `"a" + "b"` from hashing like `"a b" + ""`.
  Literal expected values are pinned by a data provider, since stored hashes outlive the
  process. The line number is recorded and deliberately not compared.
- `src/Tasks/ChecklistSnapshot.php`: every task's identity from the same reading. One
  `TaskSnapshot` would be enough to notice the running task changed but not to say anything
  useful about it — when a description is edited the old task simply stops existing, and a
  message that says so and stops there leaves the user to work out which line in front of
  them used to be it.
- `src/Tasks/TaskChange.php`: what happened — `None`, `DetailsEdited`, `Reworded`,
  `Removed`, `Ticked`, `Unticked`. Everything but `None` stops the run. `Ticked` and
  `Unticked` are kept apart so the message can say which direction the marker moved.
- `src/Tasks/ChecklistReconciler.php`: the comparison. `reconcile()` takes the snapshot, the
  running `TaskId` and a fresh `Checklist`; `reconcileAgainst()` takes an already-identified
  `TaskList`, for the runner, which holds one anyway and should not pay to identify it
  twice. The marker is examined before the details on purpose: a user who ticked the item by
  hand has said something about the whole task, and that is the finding to report even if
  they also edited the notes underneath. When the task is gone, the item whose wording reads
  closest to it — and which was *not* in the file when the run started — is offered as the
  likely rewording, above a 60% similarity threshold.
- `src/Tasks/TaskReconciliation.php`: the answer with its evidence — what the task said at
  the start, what the file says now, the replacement when there is one, a one-sentence
  `reason()` and the `hints()` for resolving it. Returned rather than thrown, because the
  runner's response is to pause and *record* the pause; `toException()` is for callers that
  have nowhere to park the finding, and refuses to turn an unchanged task into a failure.
- `src/Exception/ReconciliationException.php`: the stopping form, kept apart from
  `ChecklistException` because that one reports a checklist that cannot be read *before*
  anything has run, and this one is raised after an agent has worked and there may be a
  commit on a branch. Every hint list ends by saying the work is still on its task branch
  with its logs, which is the first thing a person wants to know when a run stops.

**Verification**

- `composer test` — **529 tests, 1430 assertions, green**, up from 498. PHPStan at level 8
  over `src` and `tests`: no errors.
- **Each test is the same shape as the run it protects**: read a checklist, snapshot it as a
  task is picked up, edit the file the way a person would while the agent works, read it
  again, ask what may be recorded. The edits are the real ones — fixing a typo in a task,
  tightening the criteria under it, deleting a task after changing their mind, ticking
  something off by hand, reordering the list. Asserted as *not* changes: a task that moved
  because the list was reordered or an item above it was ticked, respacing and recasing the
  task line, re-indenting its details, and any amount of editing to other tasks.
- **Fourteen mutations applied and reverted** (originals copied to `/tmp` first, every
  revert confirmed by a green run). Never comparing the marker, 3 failures; swapping the
  tick/untick branches, 3; ignoring an edit to the details, 2; offering an item that was
  already in the file as the rewording, 1; a similarity threshold of 0 so anything matches,
  1; of 101 so nothing does, 2; dropping the details from the hash, 6; joining the two
  halves without the separator, 2; hashing unnormalised text, 4; `statesSameWork()` checking
  only the identity, 3; adding the line number to `matches()`, 1; `pausesRun()` always
  false, 5 failures and an error; letting an unchanged task become a failure, 1; dropping
  the hints from the exception, 1. No survivors.
- Every public member of the five new classes is exercised by the two test files; nothing
  was added speculatively and left unasserted.

**Notes / deviations**

- **The replacement is a hint and never a decision.** The run stops whether or not a
  rewording can be named — the only difference is whether the message can point at a line.
  The threshold is set where a corrected typo, an added clause or a retitled task still
  matches while two unrelated tasks do not, and a near-miss is deliberately reported as a
  removal: pointing at the wrong line is worse than saying the task is gone.
- **Only the running task is reconciled.** Tasks added, deleted, reordered or edited
  elsewhere in the file are ordinary editing. The loop re-reads the checklist before it
  selects the next task, so the file's own order decides what happens next without anything
  having to be resolved.
- **`ReconciliationException` exits 78 for now**, the "correct a file and run again" status,
  which is accurate about the remedy. The dedicated blocking exit code of section I, item 5
  does not exist yet; the class adopts it when it does.
- **The pause is detected here, not recorded here.** This decides whether progress may be
  applied; persisting the pause as task state belongs to section D, and acting on it — not
  ticking the box, leaving the branch alone, reporting it — is the runner in section G.
  `ralph run` is still a `PlannedCommand`, so nothing calls this yet.
- `loop.sh` has uncommitted local changes from the harness around this project; they are not
  part of this task and were left alone.

## 2026-11-01 — C8: Reopening a finished task without landing on its old branch

**Task:** Handle reopening a previously completed item by preserving its history and
creating a new execution generation with a separately owned branch. *(PROJECT.md section C,
item 8)*

**What was done**

A `TaskId` is deliberately stable — it comes from the wording and nothing else — so a task
finished months ago and unticked today comes back with the identifier, the history and the
log directory it had before. That is exactly what makes reopening dangerous: the earlier
pass left a branch and a commit behind, and the new one must not land on top of them. So the
unit of *execution* is now the task plus a generation number, and the six classes below
decide which generation a run belongs to and what that generation may not touch.

- `src/Tasks/TaskGeneration.php`: one pass at a task — a `TaskId` and a generation number
  counted from one. `slug()` is the part that gets written down, and the whole mechanism
  behind "a separately owned branch": the first generation is named by the bare identifier,
  so in a project where nothing has been reopened every branch and log path reads plainly,
  and later generations carry `-g2`, `-g3`. Two passes at one task therefore cannot name the
  same branch, and the runner can never mistake the old pass's branch for its own. The
  spelling is pinned by a data provider, since it ends up in Git refs and SQLite. A letter
  is part of the separator on purpose: `TASK-19E0214A-2` reads like part of the identifier,
  `TASK-19E0214A-g2` reads like a second go at it. `fromSlug()` is the inverse, for callers
  that find a slug written down rather than passed to them, and reads it case-insensitively
  because a branch name gets retyped — the identifier half is canonicalised by
  `TaskId::fromString()` afterwards. Immutable: `next()` returns the following generation
  rather than advancing this one, so the record of the pass that just ended cannot change
  under a caller still reporting on it.
- `src/Tasks/RecordedGeneration.php`: what the state store remembers about one pass —
  whether it completed, the branch it owns, the commit it produced, and what the task said
  at the time. "Complete" is used in the strict sense PROJECT.md puts on ticking a box, and
  it is a property of the record rather than something re-derived from a lifecycle state,
  because it is the single fact that decides whether a new branch is created. A null commit
  deliberately covers two different things, separated by `isComplete()`: a pass that never
  got as far as committing, and a complete pass whose task was already satisfied (section G,
  item 6).
- `src/Tasks/GenerationHistory.php`: every recorded pass, by task — the state store's word,
  as a value object rather than an interface onto the database, for the same reasons
  `VerifiedTasks` is one. It also holds the invariant: a task has at most one open pass, and
  a history that breaks that is refused on construction rather than resolved by guesswork,
  since the guess decides which branch a run writes to. `verified()` derives a
  `VerifiedTasks` from it, which is the bridge back to `TaskList`.
- `src/Tasks/GenerationStart.php`: `FirstRun`, `Continued`, `Reopened`. They differ in two
  consequential ways — whether the pass owns a branch of its own, and whether attempt
  counters carry over.
- `src/Tasks/GenerationSelector.php`: the rule, read from the recorded history alone.
  Nothing recorded → generation 1. The latest pass unfinished → that same generation,
  continued, on the branch it already owns. The latest pass finished → the next generation,
  a reopening, with every earlier pass left exactly as it is.
- `src/Tasks/GenerationSelection.php`: the answer with its evidence — the generation,
  why it is that one, the earlier passes carried forward whole, `reservedBranches()` for the
  branches a new pass must not be given, `previousCommit()` for the change that was accepted
  last time, and a one-sentence `reason()`.
- `src/Tasks/TaskList.php`: the comment on reopening now points at `GenerationSelector`
  instead of forward at this item. No behaviour change — an unticked task was already
  pending whatever the state store recorded.

**Verification**

- `composer test` — **581 tests, 1586 assertions, green**, up from 529. PHPStan at level 8
  over `src` and `tests`: no errors.
- The selector tests are written as the sequence a project really goes through: run a task,
  finish it, untick it weeks later, run it again, have that pass interrupted, resume. The
  mistakes being guarded against are all sequence mistakes — the second pass landing on the
  first one's branch, a retry counted as a reopening, a completed pass dropped from the
  record once a new one starts.
- **Twenty-four mutations applied and reverted** (originals copied to `/tmp` first, every
  revert confirmed by diff and a green run). Always naming a generation by the bare
  identifier, 8 failures; always suffixing it, 3; `isReopening()` as `>=`, 2; `next()` not
  incrementing, 7 errors and 5 failures; accepting generation 0, 1; treating an open pass as
  complete and vice versa, 3 and 10; reopening with no preserved history, 4; a continuation
  preserving its own record, 2; dropping the ordering of passes, 1; dropping the duplicate
  and open-latest guards, 1 each; reading the latest pass as the first, 5; counting an open
  pass as verified, 1; a continuation starting a new branch, 1; a reopening keeping its
  attempt count, 1; `previousCommit()` reading forwards, 1; `isReserved()` always true, 1;
  accepting a first generation as a reopening, 1; continuing a complete pass, 1; ignoring
  foreign history, 1; blank refs allowed, 2; `ownsBranch()` case-insensitive, 1; an unknown
  content hash claiming the work is unchanged, 1. Two survivors were real gaps and are now
  covered: `equals()` comparing only the number (generation 2 of one task is not generation 2
  of another), and `branchesOf()`/`reservedBranches()` keeping a null branch as an empty
  name. One genuine bug was caught while writing the tests: `fromSlug()` uppercased the
  whole input, so it rejected every slug it had itself produced.

**Notes / deviations**

- **The checkbox deliberately does not decide the generation.** Whether a task is worked on
  at all is the checklist's business and has one input, the file; which pass the work belongs
  to is the selector's business and has one input, what has been recorded. Mixing them would
  mean `ralph retry` on a task someone ticked by hand either overwriting a verified commit or
  refusing to run. A test asserts the two readings agree.
- **A retry is not a reopening.** An unfinished generation is continued — same branch, same
  accumulated attempts, same failure evidence fed back in — because creating a new generation
  there would strand the branch the earlier attempt left behind. Only a *complete* pass is
  reopened. A reopening starts attempt counting at zero, or a task reopened after its attempt
  limit was reached could never be run again.
- **Preserving history is reported, not enforced here.** The selection says which branches
  are reserved and which records are carried forward; actually creating the branch, refusing
  one owned by unrelated work and storing the rows is sections F and D. Nothing in this layer
  touches Git or SQLite.
- `ralph run` is still a `PlannedCommand`, so nothing calls this yet. Section C is now
  complete.
- `loop.sh` has uncommitted local changes from the harness around this project; they are not
  part of this task and were left alone.

## 2026-11-01 — D1: The SQLite schema, and bringing a database up to date

**Task**

Section D, item 1: "Create the SQLite schema and migration/bootstrap logic for runs, tasks,
execution generations, attempts, verification results, Git references, and checklist
synchronization."

**What was built**

- `src/State/Schema.php`: the words the store is allowed to write down. Every column that
  holds one value out of a fixed set gets a `CHECK` constraint built from one of these lists,
  so a typo in application code is refused by the database rather than stored and puzzled
  over three runs later. `completions()` and `agentSources()` are derived from the existing
  `Completion` and `AgentSource` enums so the column and the enum cannot drift; the lifecycle
  states and phases are named here first because the schema needs them before the enums of
  items D2 and D3 exist, and those enums are expected to adopt these spellings. `TABLES` is
  part of the contract too — it is what bootstrap checks a file against to decide it is a
  Ralph PHP state store.
- `src/State/Migrations.php`: the schema, as the ordered steps that build it. There is no
  separate "current schema" definition to keep in step: a fresh database is one with every
  migration applied, so the two cannot disagree. Eight tables — `runs`, `tasks`,
  `generations`, `attempts`, `verification_results`, `git_references`,
  `checklist_snapshots`, `checklist_updates` — plus the indexes.
- `src/State/Migration.php` and `src/State/Migrator.php`: a numbered step, and the thing that
  applies the ones a database is missing. Creating the schema and upgrading it are the same
  operation, which is why a command can open the store without first asking whether the
  project has one.
- `src/State/Database.php`: the connection and the four pragmas that are wrong by default for
  this use — foreign keys on, `synchronous = FULL`, WAL where the filesystem allows it, a busy
  timeout — plus `transaction()`, and translation of every `PDOException` into a
  `StateException` naming the file.
- `src/State/StateBootstrap.php`, `src/State/StateStore.php`, `src/State/MigrationResult.php`:
  the entry point commands use, what it hands back, and what it did. `Defaults::STATE_FILE`
  adds the path, deliberately not a configurable setting.
- `src/Exception/StateException.php`: a state store that cannot be read or trusted. Loud on
  purpose — it is the only record of which commits belong to which task, so a run that cannot
  read it must stop rather than start over against an empty one and repeat committed work.
- `src/Init/ProjectInitializer.php`: the comment about who creates the database now points at
  `StateBootstrap` instead of forward at this item. No behaviour change.

**Two invariants moved into the schema**

Both are enforced by partial unique indexes rather than by application code, because they are
the ones whose violation destroys work.

- **One branch, one generation.** A branch name appears on at most one generation. This is the
  storage half of C8's promise: reopening a finished task cannot be handed the branch the
  earlier pass left behind, even if the branch name is computed wrongly.
- **One open pass per task.** At most one generation per task sits in a state it can be
  continued from. This is the invariant `GenerationHistory` refuses to guess at; if two rows
  could claim to be the current pass, a run would have to choose which branch to write to.
  `Schema::OPEN_STATES` and `CLOSED_STATES` are asserted to partition the seven lifecycle
  states, so no state can end up unclassified by the index.

**Verification**

- `composer test` — **633 tests, 1716 assertions, green**, up from 581. PHPStan at level 8
  over `src` and `tests`: no errors. php-cs-fixer over `src` and `tests` at `@PSR12`, the
  ruleset the committed code matches: nothing to fix.
- The schema tests write rows with raw SQL and expect the database to refuse them. The stores
  that will write them for real are later items, and a schema test routed through those would
  stop testing the schema the moment they gained a guard of their own. Each refusal names the
  constraint it relies on, because "something threw" would also pass if the row were rejected
  for an unrelated reason and would keep passing after the constraint was dropped.
- **Twenty-nine mutations applied and reverted** (originals copied to `/tmp` first, every
  revert confirmed by diff and a green run). Foreign keys left off, 4 failures; the branch
  index not unique, 1; the open-pass index built over the open states instead of the closed
  ones, 2 errors and 3 failures; `pending()` selecting by version instead of by set
  difference, 1; the newer-schema refusal removed, 1; a migration applied outside a
  transaction, 22 errors and 13 failures; `transaction()` never rolling back, 2; nested calls
  opening a second transaction, 1; `wasCreated` always true, 2; the runtime directory created
  world-readable, 1; the writability check dropped, 1 error; a directory at the database path
  accepted, 1 error; `literals()` not quoting, 44 errors; the ledger not created, 44 errors;
  generations not cascaded from tasks, 1 error; cascaded from runs instead of detached, 1;
  generation numbers not unique per task, 45 errors; the `number >= 1` check dropped, 1;
  check results not unique per position, 1; task identifiers not unique, 1; checklist updates
  not cascaded, 1 error; the open and closed state lists made to overlap, 2; a state left in
  neither list, 2; and the four column defaults each flipped, 1 failure apiece. Two survivors
  were real gaps and are now covered: `Database::value()` reading the last column of a row
  rather than the first, which no single-column query could catch, and the column defaults,
  which the test helper was supplying explicitly instead of letting the schema supply them.

**Notes / deviations**

- **Lifecycle state is recorded on the generation, not on the task.** A reopened task has a
  finished pass and a running one at the same time, and one column could not say both. A
  task's own state is its latest generation's, or `pending` when it has never been run, and
  `tasks.completion` keeps the separate question of whether a tick was the user's or earned.
  A `tasks.state` column would have been a second place for the same fact to be wrong in.
- **`git_references` is an append-only log beside the generation's own columns.** The
  generation says what is true now; this says in what order it became true, which is what the
  crash-immediately-after-a-commit case of H5 needs. It also holds the references the runner
  *refused* — a branch owned by unrelated work, a rejected push — which no current-state
  column has anywhere to keep.
- **`generations.attempts` is a counter, not a count of rows in `attempts`.** It survives a
  crash between an attempt starting and its row being written, so a task cannot escape its
  attempt limit that way.
- **Tables are not declared `STRICT`.** That keyword needs SQLite 3.37, and the package
  supports whatever libsqlite the user's PHP was built against. Affinities plus the `CHECK`
  constraints cover what matters without ruling out an older build.
- **`synchronous = FULL` is chosen over the faster `NORMAL`.** The hard case in recovery is a
  crash just after a commit: if the row recording that commit can be lost by the operating
  system after the transaction returned, the next run reasons from a database that is behind
  the repository.
- A constraint violation currently surfaces as a `StateException` with SQLite's message
  inside it. The stores of items D2–D6 are where those become domain errors — "that branch is
  already owned", "that task already has an open pass" — and the schema tests assert the
  constraint names they will key off.
- Nothing calls any of this yet: `ralph run` is still a `PlannedCommand`. `.gitignore` already
  covers `.ralph/runtime/`, so the database and its WAL files are ignored without new rules.
- `loop.sh` has uncommitted local changes from the harness around this project; they are not
  part of this task and were left alone.

## 2026-11-01 — D2: The lifecycle states

**Task:** Implement the lifecycle states `pending`, `running`, `completed`, `skipped`,
`blocked`, `failed`, and `interrupted`. *(PROJECT.md section D, item 2)*

**What was done**

- Added `State\LifecycleState`, a string-backed enum with the seven states, spelled exactly as
  D1's schema already pinned them. Three things beyond the list of words:
  - **The open/closed partition.** `isOpen()`/`isClosed()` are strict complements over the
    whole enum. A pass in an open state is the task's current pass and is continued — same
    generation, same branch, another attempt; a closed one is finished with, and work on that
    task starting again is a new generation. That is `GenerationSelector`'s decision and the
    predicate behind the one-open-pass-per-task index, so it had to be one definition rather
    than two agreeing lists.
  - **A transition table** (`successors()`, `canTransitionTo()`, `transitionTo()`). It exists
    for what it refuses: a pass reaching `completed` without ever having run, a finished pass
    being changed in place instead of superseded by a new generation, a `failed` pass being
    overwritten as though it had never failed. `transitionTo()` returns the new state rather
    than mutating, since a case is a value, and raises `StateException` naming the states a
    pass could actually move to instead.
  - **`fromStorage()`**, which refuses a value it cannot read rather than defaulting. A state
    this build does not know means a newer Ralph PHP wrote the file or someone edited it, and
    reading it as `pending` would re-run work that is already committed.
- Also on the enum, each answering a question a later section asks: `isResumable()` (only an
  interruption is what `ralph resume` picks up), `requiresHumanInput()` and `isFailure()` (I9
  has to tell "needs a person" from "needs another attempt"), `isRetryable()`, `isCompleted()`,
  and `describe()`/`nextAction()` for status output and H9's "readable reason and documented
  next action".
- Rewired the schema vocabulary: `Schema::STATES`, `OPEN_STATES` and `CLOSED_STATES` became
  `Schema::states()`, `openStates()` and `closedStates()`, built from the enum the way
  `completions()` and `agentSources()` are already built from theirs. D1's own note said these
  spellings were named there first only until the type that owns them existed. `Migrations`
  renders its `CHECK` constraints and the one-open-pass index from the methods, so the DDL and
  the enum now have a single source and cannot drift.

**Verification**

- `composer test` — **750 tests, 2028 assertions, green**, up from 633: 115 in the new
  `LifecycleStateTest` and 2 added to `SchemaTest`. PHPStan level 8 over `src` and `tests`: no
  errors. php-cs-fixer at `@PSR12`: nothing to fix.
- The transition table is written out a second time, by hand, in the test's `table()` provider,
  and every one of the 49 ordered pairs is asserted either allowed or refused. A provider that
  asked `successors()` what it allowed would have agreed with any table at all, including one
  that allowed everything.
- Two tests in `SchemaTest` close the loop on the database: every case the enum names is
  accepted by `generations.state`, and a value it does not name is refused by the `CHECK`
  constraint. Without those, the enum and the DDL could agree in PHP and disagree in SQLite.
- **Twenty-eight mutations applied and reverted** (original copied to `/tmp` first, every revert
  confirmed by diff and a green run). Caught: `skipped` made open, 1 error and 2 failures;
  `pending → completed` allowed, 2; `completed → running` allowed, 16; a state allowed to move
  to itself, 16; `transitionTo()` never refusing, 33; an unknown stored state read as `pending`,
  2; `fromStorage()` made case-insensitive, 1; `failed` reported as resumable, 1; `running`
  reported as retryable, 1; `blocked` reported as a failure, 1; `failed` reported as needing
  human input, 1; `skipped` earning a checkbox, 1; the refusal hint dropped, 33; the closed-pass
  hint reworded to drop "new generation", 14; the hint listing something other than the
  successors, 19; two cases swapped, 1; `all()` returning only the open states, 1; `initial()`
  returning `running`, 1; `interrupted → running` removed, 1; `failed → running` removed, 1;
  `running → skipped` allowed, 2; `describe()` and `nextAction()` blank, 1; `Schema::states()`
  hardcoded and missing a state, 2 errors and 1 failure; `openStates()` hardcoded wrongly, 4.
  One survivor was a real gap and is now covered: `Schema::isOpen()` rewritten as "not closed",
  which is equivalent for every valid state but reports an unreadable one as open — a pass the
  runner would try to continue without knowing what it is. It now asserts that `paused`,
  `Running` and the empty string are all not open.

**Notes / deviations**

- **`pending` can reach every state but `completed`.** The generation row is written when the
  task is selected, before a workspace exists, so Ctrl+C or a failed preparation in that gap
  has to be recordable. What it cannot do is complete: nothing is complete that never ran, and
  `running` is therefore the only route into `completed`.
- **`running` cannot be skipped.** Skipping is a decision taken before the work starts; a pass
  the runner gave up on partway through is `failed` or `blocked`, which keeps whatever evidence
  it produced.
- **`blocked → failed` and `interrupted → failed` are allowed without passing through
  `running`.** A reconciliation that finds a pass unrecoverable records that directly; making
  it claim to run the task first would put a false `running` row in the history.
- **A state cannot transition to itself.** Re-recording the state a pass is already in is not a
  transition: a second attempt at a running pass is a new row in `attempts`, and
  `generations.attempts` is the counter that moves.
- **`LifecycleState` lives in `State`, not `Tasks`.** It is what the state store writes;
  `Tasks\RecordedGeneration` deliberately keeps its own complete/open boolean so the checklist
  layer does not have to know the lifecycle, and that stays as D1 left it. The one asymmetry is
  worth writing down: `RecordedGeneration::isComplete()` means "carried all the way through",
  while `isClosed()` here covers `skipped` too, so a skipped pass is closed without being
  complete. Nothing bridges the two yet; D6's stores are where the mapping gets written, and it
  should map closed → superseded rather than complete → superseded.
- Nothing calls any of this yet — `ralph run` is still a `PlannedCommand`. The enum is used by
  `Schema` and so by the DDL; the transitions wait for the runner of section G.
- Noticed while probing SQLite's constraint messages: `Database::failure()` formats as "the
  state database an in-memory database" for a memory connection, since `describe()` returns a
  phrase rather than a path. Harmless, user-visible only in tests, and left alone as unrelated
  to this item.
- `loop.sh` still has uncommitted local changes from the harness around this project; they are
  not part of this task and were left alone.

## 2026-11-01 — D3: The active phase

**Task:** Record the active phase as `preparing`, `executing`, `verifying`, `committing`,
`publishing`, or `finalizing`. *(PROJECT.md section D, item 3)*

**What was done**

- Added `State\Phase`, the six phases as a backed enum in the order of the runner loop
  (section G, item 1). `Schema::PHASES` is gone; `Schema::phases()` returns `Phase::values()`,
  so the `CHECK` constraints on `generations.phase` and `attempts.phase` and the type now have
  one source, the way D2 did it for the states.
- **Order.** `position()`, `next()`, `isFirst()`, `isLast()`, `isBefore()`, `isAfter()`,
  `hasReached()` and `remaining()`. A pass moves forward only, and may skip: `successors()` is
  every later phase, because two phases are conditional — `publishing` happens only when
  pushing is enabled, and `committing` only when there is something to commit, since a task
  found already satisfied completes as a verified no-change outcome (section G, item 6).
  `isOptional()` names exactly those two.
- **Refusals.** `canTransitionTo()`/`transitionTo()` reject standing still and every way back,
  with a hint that names exactly the phases the pass can move on to and says that going over
  the same ground is the next attempt, which starts again at `preparing` — context and bounded
  memory are loaded per attempt (section D, item 5). A backward move is the one that would
  really cost something: a row reading `executing` for a pass whose commit exists is a resume
  that redoes it.
- **The already-true questions** a reconciling resume asks: `hasCandidate()` (from `verifying`
  on), `verificationPassed()` (from `committing` on, which `finalizing` inherits whether it
  arrived through a commit or through a no-change outcome), and `commitRecorded()` (from
  `publishing` on). Where the last is true resume must not create a second commit.
- **The resume partition.** `resumesInPlace()` / `requiresNewAttempt()`: a pass that stopped
  before the agent came back has nothing worth keeping and is begun again, one that stopped
  after is continued from the phase it recorded. This is what makes "resume failed publication
  without repeating implementation or creating a duplicate commit" (section H, item 7) something
  the stored row can actually answer. `resumeAction()` phrases it per phase, in terms of what
  will and will not be repeated; `describe()` is the status-line wording.
- **Reading rows.** `fromStorage()` refuses a phase this build cannot spell, like
  `LifecycleState` does and for a sharper reason: a phase misread as an earlier one is work done
  twice, misread as a later one is work skipped. `recorded(?string)` is the nullable-column
  reader — null is the *absence* of a phase, and an empty string is not that, so it is refused.
- Added `LifecycleState::recordsPhase()`: `running`, `blocked`, `failed` and `interrupted`
  carry a phase; `pending` has not begun and `completed`/`skipped` are not in the middle of
  anything. The nullable column is not an invitation to leave the phase out — where a pass was
  blocked or failed is what tells a verification failure apart from a rejected push.

**Verification**

- `composer test` — **951 tests, 2475 assertions, green**, up from 750: 200 in the new
  `PhaseTest` and one added to `SchemaTest`. (An interim 992 came down to this when PHPStan
  flagged the `assertTrue(true)` guards in two all-pairs tests, which became an allowed/refused
  provider split.) PHPStan level 8 over `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- The spellings *and the order* are pinned to literals, because both are written into SQLite
  and read back by later runs; reordering the cases would silently change what a stored row
  means. The three sequences a pass can take are written out by hand and walked one allowed
  transition at a time, and there is deliberately no sequence that publishes without
  committing: a pass with no commit has nothing to push.
- One new `SchemaTest` test closes the loop in SQLite — every phase the enum names is a phase
  `generations.phase` accepts. The converse was already there (a `CHECK` refusing `thinking`).
- **Twenty-eight mutations applied and reverted** — twenty-nine applications, since one hint
  mutation was re-run after the test was strengthened (originals copied to `/tmp` first, every
  revert confirmed by `diff`, and a green run afterwards). Caught: `initial()` returning `executing`,
  4 failures; `committing` renumbered, 16; `verifying` followed by `publishing`, 1; a phase
  allowed to move to itself, 18; movement allowed only backwards, 63; `transitionTo()` never
  refusing, 21; an unfinished agent run counted as a candidate, 2; `verifying` counted as
  verified, 6; `committing` counted as committed, 6; `verifying` resumed as a new attempt, 1;
  `requiresNewAttempt()` un-negated, 7; `committing` made unconditional, 1; `verifying`
  invoking the agent, 2; `fromStorage()` made case-insensitive, 1; an empty string read as no
  phase, 1; the refusal hint dropping the next-attempt sentence, 14; the finished-phase hint
  reworded, 6; `publishing` made the last phase, 17; `remaining()` dropping the phase itself, 6;
  `successors()` including it, 23; `describe()` blanked, 1; `hasReached()` made strict, 15;
  `Schema::phases()` hardcoded and short a phase, 1 error and 1 failure; `recordsPhase()` made
  false for `blocked`, 2, and true for `pending`, 2.
  One survivor was a real gap and is now covered: a refusal hint that listed *every* phase
  rather than the reachable ones passed, because the test only looked for each successor
  inside it. It now pins the exact comma-joined list, which also catches a hint naming only
  the immediate next phase.

**Notes / deviations**

- **`committing` is not treated as having a commit.** It is the one phase in which Git and the
  database can genuinely disagree — a crash between `git commit` and the row being written
  (section H, item 5) — so `commitRecorded()` is false there and the question is left to be
  answered by looking at the repository. Being wrong in the other direction would mean a commit
  silently never recorded.
- **`verifying` resumes in place.** The agent has already come back with a candidate, and the
  checks are run by the runner from the snapshotted configuration, so re-running them from the
  first one is safe and cheap. `executing` does not: a half-finished agent run is not a result,
  and carrying whatever it left in the worktree forward would put unverified changes on the way
  to a commit.
- **A phase cannot move to itself, and there is no backward transition at all.** Re-preparing
  after a failed check is the *next attempt* — a new row in `attempts` that starts again at
  `preparing` — not a phase moving backwards. That keeps the recorded phase monotone within an
  attempt, which is the property the resume partition rests on.
- **`position()` is never stored.** It is for comparing phases and for reading ("3 of 6");
  storing it would mean a later release that inserted a phase changed what old rows said.
- `isOptional()` calls `committing` conditional, which is about configuration and task state,
  not about the runner choosing: there is no path that skips it when there are changes to
  commit. The test asserts it names exactly `committing` and `publishing`.
- Nothing writes a phase yet — `ralph run` is still a `PlannedCommand`. The enum is used by
  `Schema` and so by the DDL; the transitions wait for the runner of section G and the stores
  of D6.
- `php-cs-fixer` with no path argument scaffolded a `.php-cs-fixer.dist.php` and appended to
  `.gitignore` on first run; both were reverted, and it is invoked per directory with
  `--rules=@PSR12` as before.
- `loop.sh` still has uncommitted local changes from the harness around this project; they are
  not part of this task and were left alone.

## 2026-11-01 — D4: Everything a pass records about itself

**Task:** Persist task content hashes, attempt counters, timestamps, selected agent and
non-secret settings, worktree ownership, base SHA, branch, commit SHA, push result, and
blocker details. *(PROJECT.md section D, item 4)*

**What was done**

Source for this item was already written and uncommitted when the pass started; this pass
finished it — read it against the schema, fixed two gaps it had, and gave it the tests it had
none of.

- **`Support\Clock`**, with `SystemClock` (real, UTC) and `FrozenClock` (mutable on purpose, so
  everything holding one sees the same time after it moves). An interface rather than scattered
  `time()` calls because nearly every timestamp here is evidence, and a test that cannot control
  the clock can only assert that a column holds *something* — the assertion that passes if the
  store wrote the wrong one.
- **`State\Timestamp`**, the text a column holds: `2026-11-01T09:30:00+00:00`, ISO-8601, always
  UTC, offset always present, seconds only. SQLite has no date type, so the spelling is a promise
  — it sorts chronologically under a plain string comparison, says its timezone, and matches what
  `Migrator` has been writing into the migration ledger since D1. `fromStorage()` also reads a
  `Z` suffix and a real offset (both name an unambiguous instant and are normalised), and refuses
  anything else rather than guessing: a timestamp read wrongly is a history that silently
  reorders itself.
- **Four value types for the columns that are only meaningful together.** `AgentRecord`
  (name / adapter / settings — the name is the user's label, the adapter is the implementation,
  and two agents can share one); `PushState` + `PushResult` (state / remote / error);
  `WorktreeOwnership` (path / owned); `Blocker` (reason / next action / reporter). Each refuses
  the combinations that cannot be true instead of storing them to be puzzled over later — a
  failed push with no reason, a remote on a pass that was never going to push, a worktree
  claiming ownership without naming a path.
- **`TaskStore`** + `TaskRecord`: what the checklist has said about a task, and the completion
  rule that keeps the package honest — an unticked item is `pending` even if it was
  `runner_verified` yesterday, a ticked one stays `runner_verified` only if the row already said
  so and is otherwise `user_completed`. `markVerified()` is the only thing that writes the
  stronger verdict. The three timestamps stay distinguishable: `first_seen_at` never moves,
  `last_seen_at` moves on every reading, `updated_at` moves only when something actually changed.
- **`GenerationStore`** + `GenerationRecord`: everything a pass owns. The attempt counter only
  goes up and goes up *before* the agent is invoked, so a pass killed at the wrong moment still
  spends its attempt; `started_at` is written once, `updated_at` on every write, `finished_at`
  exactly when the pass reaches a state it does not come back from; the branch, base commit and
  worktree ownership are recorded when they become true and never re-derived. `complete()`
  refuses a pass with no implementation and one whose required push is outstanding — a store
  that let those slide would make the ticked box the only place the lie showed up.

**Notes / deviations**

- **Two gaps found in the uncommitted source and fixed.** `Blocker::fromStorage()` sent a JSON
  *list* into the object branch — `is_array()` is true for `["it broke"]` — where it was refused
  for having no `reason` key; it now falls back to prose like any other JSON that is not an
  object. And `Blocker::label()`, documented as "a single short line for a status table", passed
  a multi-line reason straight through `Excerpt`, so an agent's request for human input would
  have spilled one status row over several lines; it now collapses whitespace first. The fix is
  in `label()` rather than in `Excerpt`, whose other callers all pass single lines already.
- **`Schema::PUSH_STATES` became `Schema::pushStates()`**, built from `PushState::values()`, the
  way D2 and D3 did it for states and phases — so the column's `CHECK` and the enum cannot
  drift. One stale `{@see}` left pointing at the constant was corrected.
- **`blocked` is an open state, so it writes no `finished_at`.** A test was written the other way
  round first, on the assumption that blocking finishes a pass; the lifecycle allows
  `blocked → running`, so a blocked pass is one a person can clear and it stays open. The test
  now pins that, plus the finish time being written by the retry that completes it. The
  `finishedAt() === null` guard in `write()` is unreachable through the public API today —
  `completed` and `skipped` have no successors — and is left as defence.
- **`committing` still does not imply a commit**, and this item does not change that: `no_change`
  and `commit_sha` together are what `hasImplementation()` reads, and the crash between
  `git commit` and the row being written stays a question for the repository (section H, item 5).
- Nothing calls these stores yet — `ralph run` is still a `PlannedCommand`. The runner of
  section G and the attempt/verification stores of D5 and D6 are what will.

**Verification**

- `composer test` — **1222 tests, 3102 assertions, green**, up from 951: 271 new across six test
  files (`TimestampTest`, `PushResultTest`, `AgentRecordTest`, `BlockerTest`,
  `WorktreeOwnershipTest`, `TaskStoreTest`, `GenerationStoreTest`). PHPStan level 8 over `src`
  and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- The stores are tested against a real migrated in-memory database, not a double. Part of what
  is under test is the schema's own refusals — the partial unique indexes on `generations.branch`
  and one-open-pass-per-task — and a store tested against a fake would stop testing the thing
  that actually rejects the bad row. Both indexes are confirmed to surface as readable failures
  naming what the user can do, not as SQLite constraint text naming an index.
- **Forty-two mutations applied and reverted** (originals copied to `/tmp` first, every revert
  confirmed by `md5sum -c` and `diff`, and a green run afterwards). Caught: a user's tick promoted
  to runner-verified, 3 failures; the marker ignored so ticks never register, 5; `updated_at`
  always moving, 1, and never moving, 2; `last_seen_at` not written on update, 2; `first_seen_at`
  moving on update, 3; the reading taken out of its transaction, 1; `markCompletion()` writing
  unconditionally, 1; the attempt counter never incrementing, 7; a commit silently overwritten, 1;
  `complete()` ignoring an outstanding push, 1, and a missing implementation, 1; `finished_at`
  written on every write, 2, and never, 2; a branch reassigned over its owner, 1; the lifecycle
  bypassed, 1; the phase sequence bypassed, 1; a phase recorded for a closed state, 1;
  `interrupt()` clearing the phase, 1; `beginAttempt()` keeping the old phase, 3; `no_change` left
  set after a commit, 1; no-change allowed after a commit, 1; an update against a deleted row
  going unnoticed, 1; `updated_at` dropped, 1; `explain()` passing SQLite's raw message, 3; the
  branch and open-pass hints swapped, 3; `refreshContent()` ignoring the task, 1; `start()`
  ignoring the generation's task, 1; `openFor()` returning the latest pass, 2; `reservedBranches()`
  including nulls, 1; `PushState::Failed` not counted as outstanding, 3; `not_required` labelled
  as pushed, 1; `required()` inverted, 1; a failure accepting no reason, 2; a stored
  `not_required` row keeping a stray remote, 1 error and 1 failure; truncation left unmarked, 1;
  `succeeded()` carrying the error forward, 1; an owned worktree with no path read as none, 3;
  `adopted()` recorded as owned, 4; `mustBePreserved()` un-negated, 3; `isAt()` matching loosely,
  1; the moment not converted to UTC, 1; `fromStorage()` not validating the shape, 2;
  `hasReached()` made strict, 1; `plusSeconds()` accepting negatives, 1; the format constant
  losing its offset, 9 errors and 11 failures; `Blocker::label()` not collapsing newlines, 1; the
  JSON-list fallback removed, 1 error; a missing next action blanked rather than said, 2 errors;
  the reporter defaulting to the agent, 2.
- **Two survivors were real gaps and are now covered.** Stamping each task in a reading
  individually survived, because a frozen clock makes per-task and per-reading timestamps
  identical; a test with a clock that ticks a second per read now pins it, which is the case that
  matters — `absentSince()` would otherwise report tasks still in the file as deleted. And
  dropping the second-truncation in `Timestamp::of()` survived, because the stored format cannot
  show a fraction either way; the test now also pins `moment()`, which is public, so two
  timestamps that say the same thing cannot compare unequal.

## 2026-11-01 — D5: What an attempt is told before it starts

**Task:** Load project context and bounded relevant memory when preparing each attempt.
*(PROJECT.md section D, item 5)*

**What was done**

A new `Memory` namespace, ten classes, which answers one question in one place: what does the
agent get to read. Nothing else in the package decides it, so the two adapters of section E
cannot answer it slightly differently.

- **`BoundedText`** — the ceiling. Context and memory are the two inputs whose size the package
  does not control: a `CONTEXT.md` can be any length a user cares to write, and the memory
  directory gains a note every attempt. Both are read in full on every attempt, so both need a
  bound, and the bound has to be *visible* — a prompt that silently lost the second half of a
  convention is worse than one that says it did. Cuts on a line boundary when one sits in the
  back half of the budget (so a trimmed note ends after a sentence), measures characters rather
  than bytes, and carries the notice that says how much of the source arrived.
- **`MemoryBudget`** — two kinds of bound, because they fail differently. A per-note ceiling stops
  one runaway note crowding out every other; a shared character total stops a long history
  crowding out the context file. Deliberately *not* a configuration setting: section B, item 1
  lists what `config.json` carries and these are not in it — they are a property of what an agent
  can usefully read, not of a repository.
- **`MemoryScope`** — task notes and project-wide notes are separate directories, because they
  answer different questions. A task's notes say what was already tried on *this* work; project
  notes say what an attempt at anything here should know. Task directories are named by `TaskId`,
  which is always `TASK-` + eight hex digits, so they are safe directory names and can never
  collide with `project`. `ls` on the memory directory is then a legible index, and a task's
  history is thrown away by deleting the directory named after it.
- **`MemoryStore`** + `MemoryNote` + `MemorySelection` — reading `.ralph/runtime/memory/`.
  Markdown files rather than rows in the state store, and that is the point: these are sentences
  an agent wrote, and keeping them where a user can open, correct and delete them keeps them
  visibly apart from the record of what actually ran. **Selection runs newest-first**, so what a
  bound takes away is always the oldest note — the newest one describes the failure being retried
  and is the one that must never be dropped — and the result is handed back oldest-first so an
  attempt reads the history forwards. `MemorySelection` carries how many notes the scope *has*,
  not only the ones chosen, so "bounded" is something the output can state rather than something
  that quietly happens.
- **`ContextFile`** + `ProjectContext` — the counterpart of `ChecklistFile`, and more forgiving in
  exactly one respect: a missing checklist means there is no work and is an error, while a missing
  context file means the user has not written project instructions, which is a project the loop
  can still run. The absence is carried in the result instead of thrown. Three states rather than
  two — absent, present and empty, present with text — because a template nobody filled in and a
  file nobody wrote need different words before an agent is sent off with no conventions.
- **`AttemptContext`** + `AttemptContextLoader` — assembled once per attempt, not once per run,
  because the notes an attempt needs most are the ones the previous attempt wrote. Relevance is
  the context file, the attempted task's own notes, and the project-wide notes, and nothing else:
  another task's notes describe a branch this one cannot see, which this release does not build
  on. **The task's own notes get first refusal on the shared allowance** and the project-wide
  notes take what is left — when a task has failed twice, that evidence is the most valuable text
  in the prompt and must not be squeezed out by a standing note the context file could also have
  carried. The rendering puts the standing rules first and the history second, and prefixes the
  notes with three lines saying they are observations and not results. That preamble is not
  decoration: the one failure mode worth spending it on is an attempt reading "implemented and
  tests pass" in a note and concluding there is nothing to do.

**Notes / deviations**

- **Nothing calls this yet.** `ralph run` is still a `PlannedCommand`; the runner of section G is
  what will, during the `preparing` phase. D6 is the writing half — it owns the note file names,
  which is what this reads order from.
- **Note order is a name comparison, and the writer owns the names.** Notes are sorted by file
  name with a byte comparison, oldest first, so D6's names are what say which note is newest and
  a file a user drops in by hand sorts where its name puts it. The directory is listed with
  `SCANDIR_SORT_NONE`: `scandir`'s own sort runs through `strcoll`, which follows the user's
  `LC_COLLATE`, so the same directory could otherwise be read in a different order on a different
  machine. Doing the sort ourselves also makes it load-bearing rather than redundant — see the
  survivor below.
- **An unreadable note stops the attempt; a missing memory directory does not.** Memory is
  generated, so a project that has not run, or whose runtime directory was cleaned out, simply
  recalls nothing. A note file that exists and cannot be read is fatal instead, because the note
  that cannot be read is most likely the newest one, and an attempt proceeding without it would
  repeat the failure it describes.
- **A nearly spent allowance is not spent on a fragment.** Below 200 characters the next note
  arrives as a few opening words under a heading — long enough to look like information and too
  short to be any — so selection stops and the note is reported as omitted. The floor is capped by
  the budget's *configured* total, so a deliberately tiny budget still yields its one note; and
  the loader measures the remainder against the floor of the budget as configured rather than of
  the narrowed one, which is what stops fifty characters left out of twelve thousand from looking
  like the whole of a fifty-character budget.
- **A scope the budget had nothing left for is counted, not assumed empty.** When the task's
  history spends the whole allowance the project notes are still counted on disk, so they are
  reported as omitted rather than as never having existed.

**Verification**

- `composer test` — **1281 tests, 3265 assertions, green**, up from 1222: 59 new across six test
  files (`BoundedTextTest`, `MemoryBudgetTest`, `MemoryScopeTest`, `MemoryStoreTest`,
  `ContextFileTest`, `AttemptContextLoaderTest`). PHPStan level 8 over `src` and `tests`: no
  errors. php-cs-fixer at `@PSR12`: nothing to fix in either tree.
- The store and the context reader are tested against real temporary projects, not a stubbed
  filesystem. What is under test is which files are picked up, in what order, and what a bound
  leaves out — a missing directory, a `.md.tmp` from an interrupted write, an uppercase suffix, a
  hidden file, a directory named like a note, an unreadable file — and all of that is a property
  of a filesystem rather than of a parser.
- **Thirty mutations applied and reverted** (each file copied aside first, every revert confirmed
  by comparing against the original text, and a green run afterwards). Caught: exact-budget text
  trimmed, 1 failure; no back-off to a line break, 1; back-off at any distance, 1; bytes counted
  instead of characters, 1; a cut landing mid-character, 1; a blank note counted as content, 2;
  the budget spent oldest-first, 2; notes handed back newest-first, 1; the allowance never spent,
  2; one note over the count bound, 1; a fragment of a note included, 2; an empty note given a
  heading, 1; omitted notes reported as absent, 3; hidden files read as notes, 1; an uppercase
  suffix ignored, 1; a directory read as a note, 1; a tiny budget yielding nothing, 1; a missing
  context file not reported as absent, 2; a directory read as context, 1; project notes given
  first refusal, 2; the loader's floor lowered to zero, 1; a skipped scope reported as empty, 2;
  notes listed task-first, 1; the history rendered before the rules, 1; the briefing dropped when
  one part is empty, 2; one omitted note said in the plural, 1; the notes presented without the
  warning, 1; `omitted()` counting the shown notes too, 3.
- **Two survivors were real gaps.** Removing the explicit `sort()` survived, because `scandir`
  happened to have sorted already — so the ordering promise rested on PHP's locale-dependent
  internal sort rather than on ours. The listing is now `SCANDIR_SORT_NONE` and the explicit sort
  is what orders the notes, which kills the mutant on any machine and removes the dependency on a
  collation locale that could not be reproduced here (only the `C` locale is installed). And
  dropping `min($noteCharacters, $remaining)` survived, because no test reached the case where the
  remainder is smaller than the per-note ceiling but still above the floor; a test with three
  400-character notes and a 1000-character total now pins it, and the mutant overruns the shared
  total by 200 characters.

## 2026-11-02 — D6: Keeping what the agent said apart from what happened

**Task:** Save concise attempt summaries and failure evidence without treating model-written
notes or checkboxes as verified execution state.
*(PROJECT.md section D, item 6)*

**What was done**

The other half of memory. D5 read notes; this writes them, and the whole of the item is in one
word of it — *without*. An attempt's own account is the cheapest useful thing to hand the next
attempt, and it is also the only text in the package that nothing checked. Seven types, and what
they mostly do is make that distinction impossible to lose.

- **`UnverifiedText`** — the gate every model-written sentence passes through. Two things happen
  to it. **Checkbox markers are defused:** `- [x] parser written, tests pass` becomes
  `- claimed done: parser written, tests pass`, keeping the sense the marker carried while taking
  away its syntax. In a package whose completion signal *is* a Markdown checkbox, a ticked box in
  recalled prose is the single sentence most likely to make the next attempt do nothing — and the
  shape most likely to be mistaken for the real signal. **And it is bounded**, at 800 characters,
  because a summary read on every later attempt has to stay a summary; the whole of what the agent
  wrote is in the task log, where length costs nothing. It lives in `Support` rather than `Memory`
  or `State` because the same text goes to a note *and* to the `attempts.summary` column: both
  sides need it and neither owns it. A byte-wise fallback pattern handles invalid UTF-8, which is
  reachable rather than theoretical — this text came out of another process's output.
- **`FailureEvidence`** — the counterpart the runner observed, and deliberately a different type
  from the claim rather than another string. Observations are labelled, bounded to four entries,
  and each detail is held to 360 characters *and* twelve lines: a character bound alone is not
  enough, because details are rendered indented and three hundred characters of one-character
  lines would arrive nearly three times their own length. Past the bound an observation is counted
  rather than kept — evidence arrives while a pass is going wrong, and that is the worst moment to
  raise a second problem about the size of the first. Reading is forgiving in the way `Blocker` is
  and for the same reason: this is read while reporting a failure, so anything that is not the
  object it writes is shown as prose under a generic label instead of refused.
- **`AttemptOutcome`** — what one invocation came back with: `candidate`, `blocked`, `error`,
  `timed_out`, `cancelled`. **A candidate is not a result.** Every other case settles the attempt's
  state by itself; a candidate's verdict comes from the verification commands and the commit, which
  is PROJECT.md section 4 written as a type. `Schema::ATTEMPT_OUTCOMES` was a hand-kept list
  standing in for this enum until it existed; it is now `Schema::attemptOutcomes()`, built from the
  cases, so the column cannot hold an outcome the enum lacks and the enum cannot name one the
  column would refuse.
- **`AttemptRecord`** + **`AttemptStore`** — the `attempts` table, and the four rules that make the
  item's "without" structural rather than a convention someone has to remember. A claim is written
  through `recordClaim()`, which writes `summary` and only `summary` — there is no path by which an
  agent's sentence moves the state, the outcome, the phase, or the timestamps that say the attempt
  is over. `recordEvidence()` is its counterpart. The verdict goes in through `finish()`, which
  takes enums the package computed rather than anything parsed out of worker output. An outcome may
  not be recorded in a state it does not allow. And **`completed` requires verification rows**: an
  attempt cannot be completed unless at least one verification result exists for it and none
  failed, so an agent that exits zero and says the work is done produces a `failed` or
  still-running attempt and nothing else. The attempt number comes from the generation's counter,
  not from a count of rows here, so a crash between the counter going up and the row being written
  cannot hand a task a free attempt.
- **`AttemptSummary`** — one finished attempt as the note the next one reads. The claim and the
  evidence sit under headings that say which is which — "What the agent reported", followed by the
  sentence that nothing in it has been checked, against "What Ralph PHP observed", from the
  commands it ran — and the preamble says the note is history rather than a record of what passed,
  with a pointer to where the real verdict lives. A test pins the sum of the parts against
  `MemoryBudget::NOTE_CHARACTERS`: the largest note the package can write has to survive the budget
  the reader applies on the way back in, because a note trimmed there would lose its last section,
  and the last section is the one about what went wrong.
- **`MemoryWriter`** — the names, and nothing else owns them. A note is
  `0003-20261101T093000Z-attempt-02-error.md`: sequence, the UTC moment, and what it is about. The
  reader sorts file names as bytes, so **the name is the entire ordering contract**, and the leading
  field is a counter rather than only a timestamp because a clock corrected backwards between two
  attempts would otherwise file the second note before the first. The sequence is read off the
  existing names rather than from a counter in the database, so the directory stays
  self-describing — a user who deletes the newest note, or copies a task's notes elsewhere, gets a
  directory that still makes sense. Writes go to a `.tmp` beside the destination and are renamed
  into place, so a run killed mid-write leaves nothing rather than half a note the next attempt
  would read as the truth, and the reader already ignores that suffix. Nothing is ever overwritten:
  a note records something that happened, and nothing that happened is replaced by something that
  happened later.

**Decisions worth keeping**

- **An empty attempt leaves no note.** An attempt that claimed nothing, showed nothing and blocked
  on nothing returns `null` from `record()` rather than a file. That is not a failure to record:
  the attempt is in the state store either way, and a note repeating only that it existed would
  spend part of the next attempt's reading budget saying so. `recallFor()` applies the same test on
  the database side.
- **The two halves of memory are separate classes on purpose.** Reading happens while an attempt is
  being prepared and must tolerate anything it finds; writing happens once an attempt is over and
  decides what a note is called. Splitting them is what lets the reader be forgiving without the
  writer being loose.
- **The sequence is padded to four digits, and that is a stated limit.** Past 9,999 notes in one
  scope the names stop sorting as bytes in the order they were written. A task's own scope cannot
  approach it — a task has an attempt limit in single figures — and padding wider would move the
  problem rather than remove it. Refusing to write the note would lose evidence, which is worse
  than filing it out of order.
- **Notes are the user's own.** The memory directory is created `0700` and notes `0600`: they hold
  agent output, and agent output can hold anything the agent read.

**Verification**

- `composer test` — **1409 tests, 3630 assertions, green**, up from 1281. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- **Two defects were found and fixed in the work this iteration inherited.** `MemoryWriter` had no
  test file at all — 296 lines of naming, sequencing and atomic-replacement logic with nothing
  pinning any of it — and `AttemptSummaryTest` had a failing case. The failure was the test's own
  fixture: it put bare prose in the `failure_evidence` column and expected it back as a label,
  while bare prose in that column is deliberately read under a generic label (`FailureEvidenceTest`
  pins that separately). The row now holds what the store actually writes.
- **Nineteen tests for `MemoryWriter`**, against a real directory rather than a stubbed filesystem,
  because what is under test — the name a note gets, that a note already there is never replaced,
  that nothing is left beside it — is a property of a filesystem. Including the round trip through
  `MemoryStore`, which is the only thing that proves the two halves agree: the writer owns the
  names and the reader's ordering is nothing but a comparison of them.
- **Eighteen mutations applied and reverted** (the file copied aside first, every revert confirmed
  against the original text). Fifteen killed: no zero padding; the sequence never advancing;
  existing notes ignored for numbering; the stamp leading the name; the note written without its
  closing newline; the directory and the file mode loosened; any slug accepted; an empty note
  accepted; an empty attempt still written up; the note dated by the clock instead of by the
  attempt; the note filed in the project scope; the slug ignored; an unlistable directory ignored;
  a file in the way of the notes directory not detected.
- **Three survivors, all in the same place, and all honestly untestable here.** They are the
  defensive branches of `write()` and `replace()`: the `file_exists($path)` skip, writing via a
  temporary file, and the `unlink` after a failed rename. The first is **unreachable through the
  API** — `nextSequence()` counts every entry whose name begins with digits, including dangling
  symlinks (checked), so the name it composes always carries a higher sequence than anything
  present; it guards a race between two writers, which TOCTOU does not actually close and which the
  repository lock of section H is what will prevent. The other two are only observable by killing
  the process mid-write or by injecting a filesystem fault, neither of which this suite can do. The
  contract that matters is pinned from the other side: `MemoryStoreTest` already proves a leftover
  `.md.tmp` is never read as a note. Recorded here rather than papered over with a test that would
  pass either way.

## 2026-11-02 — E1: The contract between the loop and an agent

**Task:** Define an execution adapter interface that receives the task, optional criteria,
workspace, context, previous failure evidence, and remaining execution limits.
*(PROJECT.md section E, item 1)*

**What was done**

Five classes in the `Agent` namespace: the interface, the request, the result, and the two value
objects the request needed. This is the seam section 4 describes — the runner keeps task
selection, lifecycle state, Git, verification and completion; an adapter keeps command
construction, prompt handling, output interpretation and cancellation for exactly one agent.

- **`ExecutionAdapter`** — two methods, `name()` and `execute()`. The docblock states the three
  obligations an adapter takes on, because they are what the loop cannot check for itself:
  normalise whatever the agent printed onto the result's cases, stop inside the budget, and work
  only in the given workspace with an argument array. The one distinction encoded in the signature
  is **return versus throw**: anything the agent *did* — a non-zero exit, unreadable output, a
  request for human input, a timeout — is a returned result, because the loop has bookkeeping to
  do for each and a failed attempt has to leave a recorded row behind. A `RalphException` means
  the invocation could not be started at all, which is a setup problem that would fail the same
  way on retry, and which an adapter must never resolve by running a different agent.
- **`ExecutionRequest`** — the six inputs the item lists, passed as one object. The reason it is an
  object and not six parameters is section E, item 3: a third-party adapter registered through the
  extension point keeps compiling when the runner learns to pass something more. Three invariants
  are checked at construction rather than left to the adapters — a positive attempt number, a
  generation that actually belongs to the task it was paired with, and a budget with something
  left in it.
- **`ExecutionBudget`** — what remains of the attempt and time limits. Its one real decision is
  that `seconds()` is the **lesser** of the task timeout and the run's remaining time. A
  thirty-minute task timeout means nothing when the run has four minutes of its total budget left;
  taking the longer one would let a single attempt overrun the run limit and then report the wrong
  limit as the cause. Computing it here is also what stops each adapter working it out again and
  one of them getting it wrong.
- **`Workspace`** — an absolute, existing directory, and the invariants hold at construction. A
  string would have done the job, which is the problem: the two ways an adapter goes wrong are
  both this path — the child process inherits the PHP process's own working directory because
  nobody passed one, or it is given a relative path that resolves against whatever that happens to
  be. Either one lets a task edit the controlling checkout, the one repository the package has
  promised not to touch (section F, item 1).
- **`ExecutionResult`** — the normalised outcomes, reusing `AttemptOutcome` from section D rather
  than inventing a parallel enum.

**Decisions worth keeping**

- **An adapter cannot report success.** There is no `completed` case and no boolean saying the
  task is done; the strongest thing an adapter may claim is `candidate()` — there is something in
  the workspace worth verifying. Section 4 is explicit that a zero exit code is not sufficient, and
  this is where that stops being a convention: `AttemptOutcome::settles()` returns null for a
  candidate, so the verdict can only come from the verification run, the commit and the push. A
  test walks every constructor and asserts none of them settles as `Completed`.
- **The claim passes through the gate on the way in.** `candidate('...')` takes a *string* and runs
  it through `UnverifiedText` itself rather than accepting an already-wrapped value. A model that
  reports `- [x] parser written, tests pass` therefore has that marker defused once, here, instead
  of in each adapter — and an adapter cannot skip the step by constructing the result differently.
  The claim and the runner's own evidence stay separate parameters, separate accessors and separate
  headings, as section D established.
- **A blocked result must carry its blocker; an error must carry its evidence.** Both are typed
  parameters, not optional ones, for the reason `Blocker` already gives: an attempt that stopped
  to ask a question and recorded no question, or failed and recorded nothing, leaves a halted run
  and nothing to act on. `cancelled()` is the deliberate exception — Ctrl+C means nothing was
  wrong with the attempt and nothing about it passed, so there may genuinely be nothing to record.
- **The verification commands are not in the request.** They are snapshotted at run start precisely
  so nothing during an attempt can replace them (section G, item 3), and an agent told the exact
  commands its work will be judged by is an agent that can satisfy the commands instead of the
  task. The adapter also gets no run identifier, no database handle and no checklist.
- **The briefing is rendered once, in the request.** `toMarkdown()` composes what is *known* about
  the attempt — the task, its criteria, the context and notes, then the previous failure evidence
  last because on a retry it is the most specific thing there. Same reasoning as
  `AttemptContext::toMarkdown()` in section D: two adapters composing the same briefing separately
  would answer "what was the agent actually told" two slightly different ways, and the first time
  that matters is when one agent succeeds at a task the other cannot. *How* it reaches the agent —
  an argument, standard input, a file — and whatever instruction preamble a particular CLI expects
  stay with the adapter (items 6 and 7).
- **The briefing states the workspace and the room left.** The workspace because it is the one fact
  an agent cannot discover and must not get wrong: changes made elsewhere are not part of the task
  and will not be committed. The remaining budget because an attempt that knows it is the last one
  should spend its time differently, and a reopened task should know its earlier work is not in the
  directory it is looking at.
- **A spent budget is refused by the request, not by the adapter.** `isExhausted()` is a question
  the runner asks before preparing an attempt — running out of attempts or time means the task
  stops, which is section H's decision — so an adapter should never have to recognise a
  zero-second timeout as that decision arriving sideways.
- **A workspace path is not canonicalised.** Resolving symlinks would change the text of a path the
  runner recorded next to a worktree and a branch, and the state store, the logs and
  `git worktree list` all have to keep agreeing about which directory a task owns. Trailing
  separators are removed, because those are a formatting difference rather than another directory.

**Verification**

- `composer test` — **1482 tests, 3799 assertions, green**, up from 1409. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- **Seventy-three new tests** across five files. `ExecutionAdapterTest` is the one that tests the
  contract rather than a class: two adapters with nothing in common are consumed by one caller
  that never asks which agent ran, and it reads the same two things off both — whether there is a
  candidate, and what the result settles. That is the whole of what agent-independence means here
  (section E, item 10), and it is pinned now rather than after the first adapter exists.
  `ExecutionRequestTest` builds its context through the real `AttemptContextLoader` against a
  temporary project, so what the briefing carries is what section D actually produces.
- **Twenty mutations applied and reverted** (the files copied aside first, every revert confirmed).
  **All twenty killed:** relative and missing workspace paths accepted; a trailing separator kept;
  a path climbing out of the workspace accepted; the run bound ignored when computing the process
  timeout; remaining run time rounded up instead of down; a negative attempts-remaining; a spent
  time budget not counting as exhaustion; the last attempt not announced; an error or a timeout
  accepted with no evidence; an unbounded claim; the blocker's question losing to the evidence in
  the one-line description; a negative duration; attempt zero accepted; another task's generation
  accepted; a spent budget reaching the adapter; the previous attempt's evidence discarded; the
  workspace, reopened and last-attempt lines dropped from the briefing; the evidence rendered
  first instead of last; empty criteria returned as an empty string rather than null.
- **No deterministic fixture worker yet, deliberately.** The adapters in `ExecutionAdapterTest` are
  anonymous classes that return a prepared result; they exercise the interface and nothing else.
  The fixture worker with modes for success, failure, blocking, slow execution and interruption is
  section E, item 12, and building it before the process adapter of item 2 exists would be
  building it against a shape that does not yet run anything.

## 2026-11-02 — E2: Running an agent as a process

**Task:** Implement a process adapter using argument arrays and an explicit working directory;
keep arbitrary task text out of shell command interpolation.
*(PROJECT.md section E, item 2)*

**What was done**

Two new classes and one extension to the process layer. Section E, item 1 defined what an adapter
is handed and what it may claim; this is the machinery that actually starts the agent, and the two
guarantees in the item's wording are now structural rather than conventional.

- **`AgentInvocation`** — one agent process as a program, an argument array, and optionally the
  text it reads from standard input. There is **no constructor that takes a command line** and no
  method that returns one, and that absence is the whole class. `of()` and `forAgent()` name the
  program, `with()` appends whole arguments, `withInput()` hands text over out of band, `at()`
  swaps the program for the path it resolved to while keeping everything else.
- **`ProcessAdapter`** — the abstract base both built-in adapters will extend. It resolves the
  configured executable, starts it with the invocation's array in the task's own workspace, holds
  it to the attempt's budget, and normalises a timeout. What a subclass supplies is the two
  agent-specific halves: `invocationFor()` (which flags that CLI takes, where its briefing goes)
  and `interpret()` (what its output means). `execute()` is `final`.
- **`ProcessRunner`** gained optional standard input, and **`ExecutableFinder`** gained
  `isRelativePath()`, which the adapter needs to refuse a path whose meaning depends on where it
  is resolved from.

**Decisions worth keeping**

- **`execute()` is final, and that is the point of the class.** A subclass that could override it
  could lose either guarantee the item asks for, and an adapter is exactly the place in this
  package where neither is checkable afterwards: once a shell has run a composed string, nothing
  downstream can tell that it did, and once a child has inherited the runner's own working
  directory, the edits are already in the controlling checkout. The subclass is never asked for a
  directory or a timeout, so it cannot supply a wrong one.
- **Task text goes in as an argument or on standard input, and there is no third way.** The text an
  adapter has to pass an agent is a description a user wrote, the criteria under it, the project's
  context file and the previous attempt's captured output — prose that routinely contains quotes,
  `$`, backticks, semicolons and newlines. `AgentInvocation` offers no shape that a shell could
  read, so the escaping question never arises rather than being answered correctly each time. A
  test runs an agent with the description `Escape $HOME; touch written.txt && echo * | tee
  "quoted" \`date\`` and asserts the process received exactly two arguments, the second being that
  sentence, and that no `written.txt` appeared in the workspace.
- **Standard input is spooled to a file, not written down a pipe.** The obvious implementation is
  the wrong one: the parent has to drain two output pipes while it fills the input pipe, and a
  briefing larger than the pipe buffer blocks the write until the child reads, while the child may
  be blocked writing output nobody is draining. That is a deadlock whose trigger is the size of
  the user's context file. A file cannot deadlock, and the child sees an ordinary stream ending in
  end-of-input. It goes in the system temporary directory and **never in the workspace**, where it
  would be an untracked change inside a diff the runner is about to stage (section F, item 6), and
  it is removed however the command ends — a test checks that, including after a timeout.
- **A timeout is normalised by the base class; everything else is the adapter's reading.** A killed
  process reported no exit status and printed nothing conclusive, so there is nothing
  agent-specific left to say about it — while an adapter that forgot the case would report it as
  an ordinary failure and have the user raise the wrong limit. The evidence names which bound ran
  out, because an attempt stopped after four minutes under a thirty-minute task timeout looks like
  a bug until it is clear the run's budget was what ended.
- **The executable is resolved before the process starts.** So that a missing CLI is a readable
  error naming the agent and its configuration key rather than a failed `proc_open`, and so that
  the command recorded in the log is the binary that ran rather than the name that was typed. The
  error also says that Ralph PHP does not run a different agent in its place (section E, item 9).
- **A relative executable path is refused rather than resolved.** It has two meanings here — the
  project root preflight checked it against, and the per-task worktree the process would resolve
  it in — and the second would name a different program for every task. Neither is guessed; the
  error asks for an absolute path or a name on PATH.
- **The adapter passes no environment overrides.** Credentials reach the agent by inheritance,
  which is why the configuration holds only variable *names* and why nothing secret has to appear
  in an argument, where the process table would publish it to every other user on the machine.
  A test pins the inheritance. Per-agent variables are section E, item 8.
- **`describe()` reports the briefing by size and never prints it.** It is thousands of characters,
  it is in the log already, and a log header needs to know that there was one and how long it was.
  `toArray()` keeps the arguments as an array rather than joining them, so whatever reads a
  structured log can still tell one argument from two.
- **A NUL byte is refused wherever it appears.** It is where the string ends at the system call
  boundary, so an argument containing one would reach the agent silently truncated — and the end
  of a task description quietly missing is worse than a refused invocation.
- **No streaming yet, deliberately.** This collects the output and hands it over at the end.
  Streaming to the terminal and to task logs while the agent runs is section E, item 4, and
  `ScriptedProcessAdapter` is a subclass in a test, not the deterministic fixture worker of item
  12 — that one is a program, and it is built once there is a real adapter to point it at.

**Verification**

- `composer test` — **1516 tests, 3881 assertions, green**, up from 1482. PHPStan level 8 over `src`
  and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- **Thirty-four new tests.** `ProcessAdapterTest` runs real subprocesses throughout, as
  `ProcessRunnerTest` does and for the same reason: what is under test is what the operating system
  was told — which directory the child ran in, which argument array it received, whether a shell
  saw any of it, whether a timeout stopped it — and a double would only confirm that a method was
  called. The agent is PHP itself run with `-r`, because a program that prints its own working
  directory and arguments is the only agent these assertions need; one test instead puts a shell
  script on a PATH of its own, to pin that a bare name resolves to the absolute path that ends up
  in the command.
- **Twenty-seven mutations applied and reverted** (the files copied aside first, every revert
  confirmed). **All twenty-seven killed:** the workspace replaced by the runner's own directory;
  the resolved path not substituted; resolution skipped entirely; the budget's lesser bound
  replaced by the task timeout; standard input dropped; input spooled empty; the spooled file left
  behind; the null device used in place of it; timeout normalisation skipped; the timeout's
  duration and its singular/plural dropped; the bound-naming branch inverted; the failure's exit
  code discarded; evidence preferring standard output over the error stream; evidence recorded
  without its detail; the relative-path refusal removed, both in the adapter and in
  `ExecutableFinder::isRelativePath()`; the searched PATH left out of the hint; the
  path-versus-name branch of the hints forced; NUL-byte and empty-program checks defeated; the
  program dropped from the command; `at()` losing the input; `with()` replacing the arguments
  instead of appending; `describe()` printing the briefing; `quoted()` never quoting;
  `toArray()` always reporting no input; `forAgent()` using the agent's name instead of its
  configured executable.

## 2026-11-02 — E3: Where another agent is added

**Task:** Implement an adapter registry and a documented registration interface so additional
coding agents can be added without editing the runner.
*(PROJECT.md section E, item 3)*

**What was done**

Section E, item 1 defined what an adapter is handed; item 2 built the machinery that starts one.
Neither said how the runner finds out that an adapter exists. This is that, and it is three
classes, one of which is the extension point the item asks for.

- **`AdapterProvider`** — the registration interface, and the whole of it: a provider says which
  adapter name it answers to, and builds the `ExecutionAdapter` for one configured agent. Its
  docblock carries the worked example a project copies.
- **`AdapterRegistry`** — the adapters a run can execute, and the one place that both answers
  "is there an implementation behind this name?" and builds it. It implements the existing
  `AdapterCatalog` that agent selection already asks, so selection and construction cannot
  disagree. `default()` is the package's own adapters; `register()` is everyone else's.
- **`AdapterRegistrationFile`** — a project's `.ralph/adapters.php`, optional, returning an array
  of providers. How a registration reaches the registry when Ralph PHP is the program being run
  rather than a library being called.
- **`BuiltInAdapters::providers()`**, **`Defaults::ADAPTERS_FILE`**, and
  **`AgentDefinition::NAME_PATTERN`** — the shipped providers, the fixed path, and the name shape
  the configuration validator already enforced, now shared with the registry.

**Decisions and why**

- **The catalog and the factory are deliberately the same object.** Agent selection checks an
  adapter name before a run starts; the runner builds it later. Two objects could disagree, and
  the failure would be a run that passed selection and then could not construct its agent, after
  a worktree and a branch already existed. One registry answers both, so that state does not
  exist.
- **Nothing in the runner names an adapter class.** The built-in adapters arrive through
  `BuiltInAdapters::providers()` as `AdapterProvider` instances, down the same path a project's
  own provider takes — no privileged route around the extension point, which is what makes it the
  real one. A test configures an agent for an adapter the package has never heard of, selects it
  and builds it, with nothing in `src` naming it.
- **A registration is never replaced.** Registering a taken name, or one the package ships, is
  refused rather than treated as an override. An adapter name is what attempts, logs and commits
  are attributed to, so a project that could quietly put its own code behind `codex` would produce
  a run history saying Codex did work Codex never did. Section E, item 9's rule that no agent is
  silently substituted, read from the other end.
- **A provider must build an adapter answering to the registered name.** Checked at construction,
  because the alternative is attempts filed under one name and logs under another, noticed much
  later in a log rather than before the first task.
- **Registration is a PHP file, not a class name in `config.json`.** A provider may need
  constructing, and the project's classes have to be loadable — something only the project knows
  how to arrange, since a globally installed Ralph PHP runs under its own autoloader. The file
  loads the project's autoloader itself; the hint on a class-not-found error says so, because that
  is the one failure whose cause is not in the file.
- **Its path is fixed rather than configurable.** For the reason the state file's is: this is code
  the package executes in its own process, and a setting that says which code a run loads is worth
  more to an attacker than any other setting in the file. `ralph init` does not write one either —
  a project that does not extend the package should not acquire an executed PHP file by default,
  and the default ignore rules leave it tracked, because it is reviewed like the rest of the
  repository.
- **The file's value is remembered per process.** `require` executes a file every time, so a
  second load of one declaring a class is a fatal redeclaration — and `require_once` cannot be
  used instead, because its second call returns `true` rather than the file's value and there
  would be nothing left to validate.
- **Readability is checked before loading, not caught after.** A `require` that cannot open its
  file is a fatal compile error no `catch` reaches. A path that exists but is a directory, or
  cannot be read, is also a different mistake from having no registration file at all, and each
  gets its own report.
- **Everything the file can do wrong is a configuration failure.** A wrong return type, an entry
  that is not a provider (named by index or key), a parse error, an unautoloadable class, a
  constructor that throws — all caught as `Throwable` and reported against one named path, because
  reporting the user's file as a Ralph PHP crash points at the wrong code.
- **`BuiltInAdapters::providers()` is still empty, deliberately.** The Codex and Claude Code
  adapters are items 6 and 7. A registry's answers are its registrations and it does not claim an
  implementation exists before one does, so a default registry currently provides nothing while
  the declared name list keeps agent selection rejecting unsupported adapters. A test holds every
  shipped provider to a declared name, so the two cannot drift when the adapters land.

**Verification**

- `composer test` — **1548 tests, 3970 assertions, green**, up from 1530. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- **Eighteen new tests** in `AdapterRegistrationFileTest`, on real files in a temporary project,
  because that is what the behaviour is: absent, wrong return, unparseable, unreadable and a
  directory are five different reports, and a double would only confirm that `require` was called.
  The registry's own twenty-one tests and the shared `NamedAdapterProvider` arrived with this item
  too; the registry test's anonymous providers were folded into that one class.
- **Fifteen mutations applied and reverted** (the file copied aside first, the revert confirmed
  byte-for-byte). **All fifteen killed:** a missing file loaded anyway; the readability check
  dropped; the directory hint dropped; the returned type and the entry type not reported; non-
  provider entries accepted; the index-versus-key branch inverted; only `ConfigurationException`
  caught instead of `Throwable`; the loader error's detail and the autoloader hint dropped; the
  shape hints dropped; registered names not returned; providers not registered at all; and both
  halves of the per-process cache — the two of those killed by the fatal redeclaration the cache
  exists to prevent, which is the clearest possible evidence that it does.

## 2026-11-02 — E4: Watching an agent work, and keeping what it said

**Task:** Stream worker output to the terminal and task-specific logs while capturing exit code,
timing, and a structured result.
*(PROJECT.md section E, item 4)*

**What was done**

Item 2 ran an agent and handed over everything it printed once the process was over. That is
unusable for the thing this package actually does: a coding agent can be quiet for minutes, and a
loop showing nothing until exit is indistinguishable from one that has hung — the single failure a
user cannot diagnose and can only kill. So output now goes somewhere while it is being produced,
and the somewhere is two places at once.

A new `Log` namespace, seven classes, and the streaming parameter that connects it to the process
runner.

- **`OutputSink`** — somewhere a worker's output goes while the worker is still running: chunks
  with the stream they came off, plus notes the package writes about the invocation, plus a close.
- **`OutputStream`** — which descriptor a chunk arrived on, carried rather than merged away.
- **`Sinks`** — several sinks written to as one, so "the terminal and the log" is a fact about how
  a run was assembled rather than a branch inside the runner. `Sinks::none()` is the null case and
  is a real sink.
- **`TerminalSink`** — the worker's bytes on the user's terminal, unbuffered and unchanged, each
  stream keeping its destination.
- **`LogFile`** — the same output on disk as it arrives, both streams interleaved in arrival order
  with a marker whenever the active stream changes.
- **`LogDirectory`** — where a task's logs live and what they are called:
  `.ralph/runtime/logs/<generation>/attempt-02-agent.log`.
- **`AttemptLog`** — the two destinations composed, opened per attempt, with the file's failures
  reportable and the terminal's deliberately not.
- **`ProcessRunner::run()`** takes an optional sink and forwards every chunk the moment it is
  read; **`ExecutionRequest`** carries the sink for the attempt; **`ProcessAdapter`** passes it
  through and writes the three notes around every invocation. **`Output`** gained `text()` and
  `diagnosticText()`, which write without inventing a line ending.

**Decisions and why**

- **One runner, not a streaming one and a collecting one.** The second implementation would have
  duplicated the pipe draining, the timeout and the termination, and the copy that ran agents
  would have been the one tested least. A sink is a parameter, and preflight's `git --version`
  takes the same path a twenty-minute attempt does.
- **Streamed *and* collected.** The sink is for the user and the log; the `ProcessResult` is what
  an adapter reads to decide what the agent did, and an adapter cannot read a chunk that has
  already been streamed past. The cost — the whole output held in memory for the length of the
  command — is stated in the runner's docblock rather than discovered later.
- **A sink must not throw, and the runner assumes it will anyway.** It is written to from inside
  the loop draining a live child's pipes, so an exception out of a write would skip the pipe close
  and the `proc_close` and leave a coding agent running with nothing waiting for it. That is the
  orphan this package must not create, so the runner catches, drops the sink, and keeps draining.
  A sink that cannot write absorbs it instead and reports afterwards: losing a diagnostic copy is
  a far smaller harm than losing the process.
- **An unwritable log is found before the worker starts; a log that breaks later is not fatal.**
  `AttemptLog::open()` throws, because a permissions problem has a readable cause and should not be
  discovered twenty minutes into an attempt. A full disk half-way through is remembered on
  `failure()` for the run to report at the end.
- **Both streams in one file.** A file each loses the thing a reader needs — that the error
  appeared *after* the line about rewriting the test. Which stream a line came from survives as a
  marker written on each change-over.
- **The one place the log is not a byte-exact copy** is a newline inserted when a stream changes
  mid-line, so the marker starts on its own line instead of being buried inside the worker's
  sentence. Nothing else is added, removed or re-encoded: a worker may print terminal escapes and
  invalid UTF-8, and a log is a copy rather than a rendering.
- **Nothing is added on the terminal at all.** No prefix (a per-chunk prefix lands mid-line, since
  a read ends wherever the worker happened to be) and no buffering to find line boundaries (which
  is what stops a progress indicator from ever appearing). Running under Ralph PHP should look like
  running the agent by hand.
- **Notes are marked as the package's own.** `--- scripted exited with status 3 after 1.2s` is an
  observation; the output around it is an agent's and may say anything about itself. The prefix
  does not make forgery impossible and is not treated as evidence anywhere — the exit status the
  runner acts on comes from the process, and the state store is what later commands read. Same
  separation as the claim and the evidence everywhere else.
- **The sink reaches the adapter through the request**, because adapters are stateless across
  attempts while a log belongs to one attempt. It is the one member of `ExecutionRequest` the agent
  is never told about, and the briefing is byte-identical with and without it.
- **A subclass does nothing to get this.** An adapter that had to remember to stream is an adapter
  whose agent goes silently dark the first time someone forgets, so `ProcessAdapter::execute()` —
  already final — does it.
- **The runner never closes the sink.** An attempt's log spans the agent and then section G's
  verification commands; whoever opened it closes it. `LogFile` appends rather than truncates for
  the same reason.
- **A directory per generation, a file per attempt.** The generation slug is what a reopened task's
  second pass is named after everywhere else, so section C, item 8's preserved history includes its
  earlier logs. The kind is a suffix so section G's `attempt-02-check-tests.log` can sit beside the
  agent's rather than be found by elimination.
- **The log path is derived, not configured**, like the state file and the memory directory: it is
  generated data inside the directory cleanup owns. Both it and the file are kept to their owner,
  because a log holds whatever the agent printed.

**Verification**

- `composer test` — **1618 tests, 4115 assertions, green**, up from 1548. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- **Seventy new tests.** Real files and real subprocesses throughout: what is under test is that a
  chunk is on disk before the process exits, that a second open appends, that the bytes are the
  worker's own, and that the first line reached the sink *before* the second was written — which a
  double over a string cannot tell apart from collecting.
- **Thirty-four mutations applied and reverted** (each file copied aside first, every revert
  confirmed byte-for-byte). **All thirty-four killed**, three of them only after the tests were
  improved, and each of those three was a real gap rather than a weak mutant:
  - *Dropping the forward in the timeout drain* survived, because a worker that prints and then
    sleeps has its output read by the ordinary loop. Fixed with a sink deliberately slower than the
    worker, which keeps the pipe buffer full so there is certainly unread output at the deadline;
    the assertion is that the sink saw exactly as many bytes as the result kept. An attempt killed
    mid-sentence is precisely the one whose tail is being read for.
  - *Throwing instead of remembering a failed write* survived, because the test broke the log by
    closing its handle and so returned at an earlier guard. Fixed by swapping in a read-only handle
    to the same file, which fails at the system call and lets the test assert on the reported cause
    — and the closed-handle path got a test of its own, since a handle that went away and a log a
    caller closed deliberately must not be reported the same way.
  - *Replacing the default empty sink with one on `STDOUT`/`STDERR`* survived, which pointed at a
    missing assertion rather than a missing feature: Ralph PHP is a library as well as a command,
    and an attempt prepared by a host application must write nowhere when nobody passed a sink.

## 2026-11-02 — E5: Telling what the agent did from what it says it did

**Task:** Distinguish a candidate implementation, a request for human input, and an execution
error; reject absent or malformed result output.
*(PROJECT.md section E, item 5)*

**What was done**

Items 1 to 4 gave the loop a request to hand an agent, a process to run it in, a registry to find
it by, and a log of everything it printed. What was still missing is the answer: which of the
three things did this attempt do. Until now `ProcessAdapter::interpret()` was abstract and each
adapter would have decided that for itself, which is how two agents end up reported two different
ways and how a zero exit code quietly becomes a commit.

So the agent is asked for the answer in a format, and the format is read strictly. Four new
classes, one new method on `ProcessAdapter`, and one new section in the briefing.

- **`ResultProtocol`** — the format itself, declared in one place because both halves of it are
  written in different files: `instructions()` goes into every briefing, and `ReportReader` parses
  what comes back. One fenced ` ```ralph-result ` block holding one JSON object with an `outcome`
  of `candidate`, `blocked` or `error`, a `summary`, and a `next_action` for the one case that
  needs it. `outcomes()` and `example()` are generated from the same constants the reader looks
  for.
- **`AgentReport`** — a report that was read successfully: the declared outcome, the agent's
  summary as `UnverifiedText`, and the next action where there is one. `blocker($agent)` turns a
  question into a recordable `Blocker` attributed to the agent that asked it.
- **`ReportReading`** — what came back from reading the output: a report, nothing at all, or a
  block that could not be read, with the reason and the offending text. Three answers rather than
  two, because "never reported" and "reported something broken" are different things to tell a
  user.
- **`ReportReader`** — finds the last complete block, decodes it, and refuses everything else with
  a reason written in terms of the format: not JSON, a JSON array where an object belongs, no
  `outcome` key, an `outcome` that names nothing, a question with no `next_action`.
- **`ProcessAdapter::reported()`** — the normalised reading every adapter can share, measuring
  what the agent declared against what the package observed, and noting the result in the log.
- **`ExecutionRequest::toMarkdown()`** gained a `# Reporting back` section, after the acceptance
  criteria and before the context.

**Why it is this way**

- **The agent is asked for a token, not read for intent.** Section 4 is explicit that a zero exit
  code does not finish a task, and a loop inferring "I've implemented the feature!" from prose
  would be guessing about the one thing it must not get wrong: whether to verify and commit, stop
  and ask a person, or try again. Everything the agent writes about itself still travels — as the
  claim, defused and bounded — but it is not what the branch is taken on.
- **JSON, because malformed has to be decidable.** `json_decode` either reads an object or it does
  not. A line-oriented format has a dozen shapes that are nearly right, and every one of them is
  an invitation to salvage.
- **A fenced block, because the report has to be findable.** Agent output is prose, progress and
  diffs. A fence with a package-specific info string is something every coding agent already emits
  correctly, and nothing else in a transcript collides with `ralph-result` — a block of `json` or
  an unmarked one is not a report, which is tested.
- **Nothing is salvaged, and the asymmetry is the argument.** Refusing a good attempt costs one
  attempt; accepting a broken report commits work nobody checked. So a block that does not decode
  is an execution error, never a candidate, and the refusal carries the block itself — "could not
  be read" without the thing that could not be read is not evidence a user can act on.
- **Silence is an error, which is section 4's rule in the only place it can be enforced.** A zero
  exit with no block leaves the package with nothing claimed about the workspace, and the
  alternative is verifying and committing on the strength of an exit status. The evidence for that
  case carries a second observation saying what to print, because the next attempt is handed this
  evidence in its briefing and a retry told only "no result" produces the same no result.
- **The observation outranks the declaration, except for a question.** A declared candidate from a
  process that exited non-zero is an error: the status is something the package watched happen,
  the declaration is an account of itself, and where they disagree the status wins — with the claim
  kept, so a user can see what was claimed. A declared blocker is honoured whatever the status,
  because a question does not stop being a question when the process that asked it then failed,
  and retrying an attempt that needs a decision spends an attempt to be asked the same thing
  again.
- **An agent may not declare a timeout or a cancellation.** `ResultProtocol` offers three of
  `AttemptOutcome`'s five cases. The other two are things the package watched happen to a process,
  and an agent that could declare them could report its own killing as an orderly stop.
- **What *is* normalised: capitals and whitespace on the outcome token, and nothing else.**
  `Candidate` is `candidate` written by a model that capitalises sentences. Unknown keys are
  ignored, so an agent that adds `files_changed` is not failed for being helpful; a missing summary
  is allowed for a candidate, where which-of-the-three is the part the loop acts on, and required
  for the two cases that exist to tell a person something.
- **The last block wins.** The briefing shows the format, so an agent may quote it, explain it, or
  report and then keep working. The instructions say the report comes last, and how many blocks
  there were is kept for the log, since two is also what an agent trying two answers looks like.
- **Standard output first, then error output, and a broken block does not fall through.** Plenty
  of CLIs write their whole transcript to standard error. But the first stream holding *any* block
  is the one answered from: an agent that reported twice in two places has a problem a user should
  see, and quietly preferring the readable one hides it. The stream is recorded either way — it is
  the first thing to check when an agent reports correctly and the package disagrees.
- **No `u` modifier on the fence pattern.** An agent may print invalid UTF-8; a truncated
  multi-byte character at a buffer boundary is enough. A Unicode-mode match fails outright on it,
  which would turn a report that is on disk and perfectly readable into "printed no result block".
  Broken bytes *inside* the block are a different matter and are refused by `json_decode`.
- **The protocol is stated in the common briefing, not in each adapter's preamble.** Item 10 wants
  both adapters normalising onto the same outcomes; two adapters wording this their own way would
  be two agents reporting in two formats the reader does not accept. The flags that make a
  particular CLI print anything at all remain each adapter's business (items 6 and 7).
- **`interpret()` stays abstract.** An adapter whose CLI reports some of this natively should read
  that first, so the method still forces a decision — but an adapter with nothing else to go on
  has one line to write, and `ProcessAdapter::reported()` is that line.
- **An unreadable report is noted, like the three notes around the invocation.** The package's own
  reading of the output, marked as its own, so a user can see why an attempt was classified the
  way it was without opening the database.

**Verification**

- `composer test` — **1687 tests, 4355 assertions, green**, up from 1618. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- **Sixty-nine new tests.** `ReportReaderTest` is half refusals, driven by a provider of twenty
  near-misses — an empty block, prose, half-written JSON, a JSON array, a string, a number, a
  boolean, null, a missing `outcome`, an `outcome` that is a number, null, an object or an invented
  word, a `summary` that is a list, a `next_action` that is a number, a question with no
  `next_action`, with an empty one, or with nothing asked, and an error that says nothing — because
  every one of them has an obvious tolerant reading and taking any of them means committing work
  nobody could parse. `ProcessAdapterTest` exercises the classification through real subprocesses,
  as the rest of that file does: a declared candidate, a declared candidate from a process that
  exited 1, a question from a process that exited 2, a self-reported failure, a clean exit with
  nothing printed, and a block naming an outcome that does not exist.
- **The protocol's two halves are pinned to each other.** `ResultProtocolTest` reads the example
  out of the instructions with `ReportReader` and asserts it comes back as a report, for every
  declarable outcome. An example the reader would refuse is an instruction to fail, and nothing
  else in the package would notice: every attempt would come back an execution error for reporting
  in the form it was asked to report in. `ProcessAdapterTest` makes the same round trip through the
  real briefing.
- **Twenty-one mutations applied and reverted** (each file copied aside first, every revert
  confirmed byte-for-byte). **All twenty-one killed**, one of them only after the tests were
  improved:
  - *Relabelling the "no result to act on" observation* survived, because the test asserted the
    reminder's detail and the evidence's shape but not its label — and a label is what
    `ralph status` shows of a piece of evidence. Asserted now, which also kills dropping the
    observation altogether.
  - The others covered the parts where a tolerant reading is one character away: the first block
    winning instead of the last, a case-sensitive outcome match, broken JSON read as a candidate,
    `isset()` instead of `array_key_exists()` on the `outcome` key (which reports a null outcome as
    a missing one), the error stream searched first, a broken block falling through to the other
    stream, `u` on the fence pattern, `json_decode`'s default depth, a claimed candidate outranking
    a failed process, a question from a failed process read as an error, silence on a clean exit
    read as a candidate, a blocker attributed to the runner instead of the agent, the briefing not
    asking for a report, and a timeout becoming declarable.
- **One existing test changed.** `the_briefing_can_be_handed_over_whole_on_standard_input` echoed
  the briefing back and compared it to the claim; the briefing is now longer than
  `UnverifiedText::LIMIT`, so that comparison had become a comparison of its first 800 characters.
  It now compares an `md5` of what arrived on standard input against the briefing — every byte,
  untrimmed, which is the stronger assertion anyway.

## 2026-11-02 — E6: Running Codex

**Task:** Implement the Codex adapter, including tested CLI invocation, prompt/context handling,
workspace selection, streamed output, normalized result handling, and cancellation.
*(PROJECT.md section E, item 6)*

**What was done**

Items 1 to 5 built everything an adapter stands on: the request handed to an attempt, the process
it runs in, the registry it is found by, the streamed and captured log, and the strict reading of
the result block. This is the first adapter that uses all of it, and it is deliberately small —
two new classes, each saying only what is true of this particular CLI.

- **`CodexAdapter`** — a `ProcessAdapter` whose invocation is
  `codex exec --cd WORKSPACE --sandbox workspace-write [--model M] [extra…] -`, with the prompt on
  standard input. `interpret()` is one line: `reported()`.
- **`CodexAdapterProvider`** — registers it under `BuiltInAdapters::CODEX`, through the same
  `AdapterProvider` interface a project's own adapter uses.
- **`BuiltInAdapters::providers()`** — no longer empty; `AdapterRegistry::default()` now holds the
  Codex adapter. `claude` stays a declared name selection accepts, with item 7 still to build it.
- **`AdapterRegistry::register()`** — the reserved-name check moved ahead of the
  already-registered check (see below).

**Why it is this way**

- **`codex exec`, not `codex`.** Plain `codex` is the interactive terminal UI: it draws a screen,
  waits for a person, and asks before it runs anything. The loop has nobody to approve anything —
  `ProcessRunner` gives every child a standard input that ends, by design — so an adapter that
  started the interactive command would hang until the task timeout killed it and report a timeout
  for a setup mistake. A test pins the subcommand as the first argument for exactly that reason.
- **The briefing goes over a stream, with `-` to say so.** It is the task, its criteria, the
  project context, the notes from earlier work and the previous attempt's captured output: tens of
  kilobytes of prose a user wrote. As an argument it would still be *one* argument — `AgentInvocation`
  has no shell form — but argument lists have a size limit a stream does not, and that limit is
  the kind that is hit by the one project whose context file is large. The `-` is what makes the
  stream the prompt instead of ignored input.
- **`--cd` is passed although the process already starts in the workspace.** Codex decides what it
  may write from its own notion of a working root, and this states it rather than leaving it
  inferred. The two always agree — `ProcessAdapter` starts the process in
  `ExecutionRequest::directory()` and a subclass cannot change that — and stating it is what makes
  the sandbox mean *the task's worktree* rather than whatever directory a later change leaves the
  process in. The test reads the recorded arguments out of a file the stub wrote **in its own
  working directory**, so the two are asserted against each other rather than separately.
- **`--sandbox workspace-write`.** The runner stages the task worktree's diff after verification
  (section F, item 6), so an edit made anywhere else is work that is never committed and never
  reviewed — in a checkout the package has promised not to touch. Under this mode Codex may read
  the machine, write inside its working root and run commands without asking; it may not write
  outside it, and it has no network access unless the project turns it back on. That last part is
  a real restriction worth knowing: an attempt that needs to install a dependency will fail, while
  the verification commands of section G run outside the sandbox and are unaffected.
- **The project's own arguments come last, so they win.** Nothing the adapter chose is a setting in
  disguise: `agents.*.arguments` is appended after the adapter's flags and before the prompt, and a
  repeated flag's last occurrence is the one the CLI keeps — so a project that needs
  `danger-full-access`, network access or any other Codex setting configures it and gets it. The
  model is placed by the adapter because where a `--model` goes is something only this CLI knows
  (section E, item 8).
- **`--json` is not passed.** Its event stream is experimental and it would wrap the agent's
  message in another envelope to unwrap; the result block is findable in the plain transcript, and
  `ReportReader` already looks on both streams.
- **`interpret()` is one line, and that is the point.** Codex's exit status says a session ended,
  not whether a task was done — section 4's rule, which holds for every agent — and the cases a
  status could be read as are the ones `reported()` already weighs the declaration against. Item
  10 (both adapters normalising onto the same outcomes) then holds by construction rather than by
  two adapters agreeing with each other.
- **The preamble says only what is true of being run this way.** Three facts, composed from the
  same constants that build the invocation so the prompt cannot describe a sandbox the adapter did
  not ask for: nobody will answer a question, the sandbox is `workspace-write` rooted at this
  path, and a decision only a person can make should be reported rather than guessed. What the
  task is, what finishing it means and how to report it stay in the common briefing — two adapters
  wording the shared half their own way is how two agents end up answering two slightly different
  questions.
- **Cancellation is the base class's, and nothing of Codex's needs saving.** The timeout is
  `ExecutionRequest::seconds()`; the process is asked to stop, killed if it will not, and the
  attempt is recorded as a timeout with whatever was printed first. An abandoned session is not
  state the next attempt reads, because each attempt is given its briefing afresh. Stopping the
  child processes an agent itself spawned remains section H, item 4.
- **The reserved-name check now runs before the already-registered check.** Registering `codex`
  from a project's `.ralph/adapters.php` used to be refused as reserved; with the provider now in
  a default registry it was being refused as already registered instead. Two messages for the same
  mistake, decided by how far the package has got through section E, so the one that explains the
  attribution problem goes first. An existing test caught the change; a new assertion pins the
  order.

**What was deliberately left out**

- **No "Ralph PHP owns the Git actions" instruction.** An agent that commits, branches or pushes
  inside its workspace is a real problem, but it is agent-independent, so saying it in *one
  adapter's* preamble is how the two adapters drift. It belongs in the common briefing, alongside
  section F, item 9's detection of worker-created commits, and is left to that item.
- **No version check.** Refusing a CLI too old to have `exec` is section E, item 9, together with
  the missing-executable and unavailable-authentication errors.

**Verification**

- `composer test` — **1704 tests, 4425 assertions, green**, up from 1687. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- **The Codex CLI is not installed on this machine, and the suite must pass without model
  credentials (section J, item 8).** So what the seventeen new tests run is a program named `codex`
  — installed at the path the configuration names, reached through the same `ExecutableFinder` a
  real CLI is — that records the argument array and the standard input it was handed and then
  behaves as the test says: printing a report, printing prose and exiting 0, failing with a stream
  error, or sleeping past the budget. That covers the half of the invocation this package is
  responsible for. **It is not evidence that the real CLI accepts these flags**; that is section J,
  item 11's real run, which records the agent version it was tested against. The invocation targets
  `codex exec` as it stands in the 0.4x releases.
- **The stub records into its own working directory**, so every argument assertion is also an
  assertion that Codex ran in the task's workspace.
- **Ten mutations applied and reverted** (each file copied aside first, every revert confirmed
  byte-for-byte). **All ten killed**: dropping the `exec` subcommand, dropping `--cd`, a
  `read-only` sandbox, the project's arguments placed before the adapter's, the prompt passed as an
  argument instead of on standard input, a `--model` passed when none is configured, a clean exit
  read as a candidate instead of read for a report, the preamble dropped, the provider registering
  another name, and the registry's reserved check moved back behind its duplicate check.

## 2026-11-03 — E7: the Claude Code adapter

`ClaudeAdapter`, `ClaudeAdapterProvider`, registered in `BuiltInAdapters::providers()`, with
`tests/Agent/ClaudeAdapterTest.php`. The second built-in agent, and the first chance to find out
whether the split made for the first one was the right split: everything shared stayed in
`ProcessAdapter`, and this class is 40 lines of code under its description — which flag runs it
without a terminal, where its prompt goes, what it is allowed to do, and what its output is read
for.

**The invocation, and why each part of it**

`claude --print --output-format text --permission-mode bypassPermissions [--model M] [extra…]`,
with the briefing on standard input and **no positional argument at all**.

- **`--print`.** Plain `claude` starts the interactive session: it draws a terminal UI and waits
  for a person. An adapter that started it would hang until the task timeout killed it and report
  a timeout for a setup mistake — the same trap `codex` without `exec` sets, which is the one way
  the two CLIs are alike.
- **The prompt goes on standard input, and the absence of a positional is what sends it there.**
  `claude --print` takes its prompt as a positional *or* from the stream when none is given. The
  briefing is the task, its criteria, the context file and the previous attempt's captured output:
  tens of kilobytes of user prose. An argument list has a size limit a stream does not, and it is
  the kind of limit reached by the one task whose context file is large.
- **There is no `--cd`, because this CLI has none.** Its project root *is* the working directory
  it was started in, which `ProcessAdapter` guarantees is `ExecutionRequest::directory()` and a
  subclass cannot change. Where Codex needs its root stated, this adapter needs nothing said —
  and `--add-dir` is pointedly not passed, because every directory it added would be one more
  place an attempt's changes could land and never be committed.
- **`--permission-mode bypassPermissions`.** In print mode nobody can approve a tool call, so a
  call needing approval is denied: under the default mode or `acceptEdits` the attempt could edit
  files but could not run the project's test suite, and an agent that cannot run anything cannot
  check its own work before claiming a candidate.
- **`--output-format text` although it is already the default.** The reading is of a plain
  transcript, so the format is a dependency rather than an accident. Stating it puts the
  dependency in the command line and the log, and leaves a project the usual last word over it.

**The asymmetry with Codex, which is documented rather than hidden**

Codex gets `--sandbox workspace-write` and a write outside the workspace *fails*. Claude Code has
no equivalent, so this adapter **cannot make such a write fail** — it can only make sure the agent
knows the write is never staged (section F, item 6) and is left behind in a checkout the package
promised not to touch. The preamble says exactly that, in those terms. A project wanting real
confinement runs the loop in its own container; a project that would rather give up running
commands puts `--permission-mode acceptEdits` in `agents.claude.arguments`, where its occurrence
is the one the CLI keeps. Discovering this by having an attempt quietly edit the wrong tree is
worse than reading it here.

**What it does not do**

- **No `--output-format stream-json`.** It would wrap every one of the agent's words in an
  envelope to unwrap, and the result block is findable in the plain output. The cost is recorded
  instead: in print mode the CLI prints its *final message*, not a running account of its tool
  calls, so the attempt's log holds the result and whatever the agent chose to say — which is why
  the preamble insists the report go in the last message. A report printed anywhere earlier is a
  report nothing captured.
- **No `--continue` or `--resume`.** Each attempt is given its briefing afresh, so a stopped
  session is not state the next attempt reads — which is precisely what makes abandoning one on
  timeout safe. A test asserts all four flags are absent even on attempt 3.
- **`interpret()` is one line, same as Codex's.** The exit status says a session ended, not
  whether a task was done (section 4), and the cases a status could be read as are the ones
  `reported()` already weighs the declaration against. **Section E, item 10 therefore holds by
  construction rather than by two adapters agreeing with each other** — which was the point of
  putting the reading in the base class, and is now demonstrated rather than asserted.
- **No version check.** Refusing a CLI too old for these flags is section E, item 9, with the
  missing-executable and unavailable-authentication errors.

**Documentation corrected while here**

`BuiltInAdapters`, `AdapterRegistry` and one inline comment each described Claude Code as "once
item 7 lands" and a default registry as providing "the Codex adapter alone". Both are now wrong,
so both were rewritten; the registry's reserved-before-duplicate comment no longer names Codex and
Claude as its example, since the distinction it draws is now about any third adapter that is
declared but not yet registered.

**Verification**

- `composer test` — **1726 tests, 4517 assertions, green**, up from 1704. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- **The flags were read off the installed CLI, not recalled.** `claude --help` at **2.1.226**
  confirms `-p, --print`, `--output-format` taking `text` (its default), `--permission-mode`
  taking `bypassPermissions` among its choices, `--model`, the absence of any `--cd`, and
  `--input-format` documented as working *only* with `--print` — which is the CLI's own statement
  that print mode is the one that reads a prompt from a stream.
- **No real session was run, and the suite must not need one** (section J, item 8): a real
  `claude --print` would need an account, spend money and answer differently every time. So what
  the 22 new tests run is a program named `claude` — installed at the path the configuration
  names, reached through the same `ExecutableFinder` a real CLI is — that records the argument
  array and the standard input it was handed and then behaves as the test says: printing a report,
  printing prose and exiting 0, failing with an invalid-API-key message, or sleeping past the
  budget. **That is evidence about the half of the invocation this package owns, not evidence that
  the real CLI does the work**; the latter is section J, item 11's recorded real run. (A live
  one-line smoke test of the exact argument array was attempted and refused by this machine's
  sandbox, so it is not being claimed.)
- **The stub records into its own working directory**, so every argument assertion is also an
  assertion that Claude Code ran in the task's workspace — which for this CLI is the *whole* of
  how its project root gets chosen, there being no flag to state it with. One test reads `getcwd()`
  back directly for that reason.
- **Eleven mutations applied and reverted** (each file copied aside first, every revert confirmed
  byte-for-byte). **All eleven killed**: dropping `--print`, `acceptEdits` in place of
  `bypassPermissions`, the prompt passed as an argument instead of on standard input, the
  project's arguments reordered ahead of the adapter's, a `--model` passed when none is
  configured, a clean exit read as a candidate instead of read for a report, the preamble dropped,
  `stream-json` as the output format, the adapter answering to `claude-code`, the provider never
  registered, and an `--add-dir` smuggled into the invocation.

## 2026-11-03 — E8: Per-agent settings, and a credential that stays out of the log

**Task:** Support per-agent executable paths, optional models, extra argument arrays, and
environment-based authentication without printing or persisting credentials. *(PROJECT.md
section E, item 8)*

**What was already there, and what was actually missing**

Three of the item's four settings were in place and tested before this task. The configured
`executable` is what `ProcessAdapter::resolve()` looks up and runs — a bare name searched on
PATH exactly as a shell would, a relative path refused because it would mean a different
program per worktree, an absolute path used as given. The optional `model` and the extra
`arguments` array are each adapter's to place, because where a `--model` goes is something
only that CLI knows, and both adapters append the project's own arguments *after* their own so
the project wins; `CodexAdapterTest` and `ClaudeAdapterTest` already pinned the flag, its
absence when no model is configured, and the ordering.

What was missing was the fourth: **environment-based authentication was declared but never
applied.** `AgentDefinition` carried the variable *names*, `ConfigurationValidator` rejected a
`NAME=value` written where a name belongs, and `AgentCheck` reported an unset one as a failed
preflight check — but `ProcessAdapter::execute()` passed `[]` as the child's environment, so
nothing read the declaration at the moment an agent was started.

**What was added**

`AgentEnvironment` resolves an agent's declared variables against the process environment and
is the only place in the package a credential value lives. Its surface is deliberately
lopsided: `names()`, `missing()`, `isComplete()`, `isDeclared()`, `describe()` and `toArray()`
all answer in variable *names*, and one method — `forProcess()`, named after its only caller —
returns the override array `proc_open` is given. There is no `value()`, no `all()` and no
`__toString()`, because a credential that can be asked for is a credential that eventually
gets interpolated into a message by someone who did not think about it.

`ProcessAdapter::authenticate()` resolves it before the agent starts and refuses the attempt
when a declared variable is unset or empty — thrown, not returned, for the reason a missing
executable is: a retry hits it identically, and no other agent is run in its place. The
message names the variables and the hints say to export them; neither can echo a value,
because the only object holding one will not hand it over. When variables *are* declared and
present, one note goes into the attempt's log naming them. When none are declared — the common
case, since both built-in CLIs can be authenticated by an interactive login — nothing is noted,
so an ordinary attempt's log is unchanged.

**The mutation that made this honest**

The first version passed `$environment->forProcess()` to the runner and claimed that was how
the agent received its key. Replacing it with `[]` **did not break a single test**, and the
reason is that `ProcessRunner` starts every child with `getenv()` merged under the overrides:
the agent inherits the variable either way, so "the declared variable arrived" is not evidence
that the adapter passed it. The explicit pass was decorative.

It is now load-bearing, and for a reason worth having rather than for the sake of the test:
**`forProcess()` trims each value.** `export ANTHROPIC_API_KEY=$(cat key.txt)` keeps the
file's newline, a key pasted into a CI secret brings one, a `.env` line has a trailing space —
and the agent then reports a *correct* key as invalid, which is about the least diagnosable
form this failure takes. So the copy the child is handed differs from the inherited one in
exactly that way, which is also what the test now asserts. The judgement being made is narrow
and stated in the class: API keys and tokens are not formats where surrounding whitespace
carries meaning, which is the same assumption the pre-existing blank-is-absent rule already
made, and it applies **only** to variables an agent declares — a test checks that an
undeclared variable is inherited byte-for-byte, padding included.

**Verification**

- `composer test` — **1750 tests, 4577 assertions, green**, up from 1726. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- The authentication tests run **real child processes** that print what they were given, in the
  style of the rest of `ProcessAdapterTest`: the package is not trusted about what reached the
  agent's environment, the agent reports it. `tests/Support/Environment` sets real variables and
  restores them, and `AgentEnvironmentTest` resolves through an injected reader where it must
  not depend on the variables the suite happens to run with.
- The leak assertions use one recognisable literal secret, so a test that fails because the
  value escaped says so unmistakably. One of them is a property of the type's *surface* rather
  than of today's call sites: every zero-argument public method is invoked by reflection and
  only `forProcess()` is allowed to return the secret, so a future `all()` or `value()` fails
  the suite. A mutation adding exactly that accessor was killed by that test and named it.
- **Eight mutations applied and reverted** (each file copied aside first, every revert confirmed
  byte-for-byte). Seven killed: not passing the resolved variables (killed only after trimming
  made the pass observable — see above), treating a blank value as present, not trimming,
  `describe()` printing values, `toArray()` carrying values, dropping the missing-variable
  refusal, noting authentication when nothing is declared, and adding a value-returning
  accessor. The eighth is recorded here rather than claimed as a kill: an earlier run of the
  "don't pass the variables" mutation **survived**, which is what prompted the change above.
- No credential was used and none is needed: everything here is about names, presence and
  whitespace, so the suite still runs without live model credentials (section J, item 8).

**Notes / deviations**

- Refusing an unset variable at execution time overlaps `AgentCheck`, which reports the same
  condition before a run starts. Both are kept: preflight is not guaranteed to have run by the
  time an adapter is reached, and an agent started without its key spends the whole attempt
  budget to fail with "invalid API key" in prose that `reported()` would then have to interpret.
- **Still open from the item's neighbourhood:** incompatible CLI versions and the structured
  preflight errors for unsupported adapters are section E, item 9, deliberately left there.
  Redacting secrets that appear in a *worker's own* output — as opposed to in package-generated
  diagnostics, which is what this work covers — is section I, item 6.

## 2026-11-03 — E9: Refusing an agent that cannot work, and never substituting another

**Task:** Add preflight errors for missing executables, unavailable authentication,
unsupported adapters, and incompatible CLI versions; never silently fall back to a different
agent. *(PROJECT.md section E, item 9)*

**What the item already had, and the one part nothing could answer**

Three of the four conditions were already reported before a run starts. `AgentCheck` resolves
the configured executable and fails when it is absent, and since E8 it fails when a declared
authentication variable is unset or blank. An adapter registered nowhere is refused by
`AgentSelector` with the configuration file in hand. What none of them could reach is the
fourth: whether the `codex` on PATH is the release whose command line the adapter actually
spells. A missing program is visible from the filesystem and an unset credential from the
environment, but a *version* can only be answered by the program itself — and left unanswered
it is the most expensive failure in the package. An incompatible CLI starts, prints its own
usage, exits non-zero, and does it once per attempt against the task's attempt limit, with
nothing in the log that names the cause.

**What was added**

`VersionRequirement` holds the three things only an adapter can state: what its CLI is called
when a person is told about it, which arguments make that CLI print a version, and the oldest
release the invocation targets. Nothing assumes `--version` means anything in particular —
section 4 is explicit that two agents' CLIs have nothing in common, and a version query is not
an exception. `VersionedAdapter` is the optional interface that declares one, kept separate
from `ExecutionAdapter` because a project's own HTTP-backed or wrapper-script adapter may have
no version to speak of, and is then reported as having nothing to check rather than guessed at.

`AgentVersionCheck` runs the query. It is the stated exception to `Preflight`'s promise that no
agent is executed, and the distinction it rests on is that a version query starts no session,
reads no task, writes nothing and spends nothing: bounded to 30 seconds, the project root as
its working directory, no input, and only the arguments the adapter named. `Preflight` now
loads the project's own `.ralph/adapters.php` before selecting, so an agent pointed at a
project's adapter is *checked* rather than called unsupported.

**A minimum is a floor, and the floor is where the bug was**

Both shipped adapters declare one: Claude Code 2.0.0, because `--permission-mode
bypassPermissions` is not how the 1.x line spelled an unattended session, and Codex 0.40.0,
because `exec` taking its prompt from standard input with `--cd` and `--sandbox
workspace-write` are the 0.4x spellings. A floor rather than the tested version, so an
operator running something newer is never refused for being ahead; the exact release each
adapter was exercised at stays in its own class documentation where it cannot be mistaken for
a requirement.

`CodexAdapter::MINIMUM_VERSION` was left at `0.1.0` — a value that contradicted its own
documentation two lines above it ("the floor of the 0.4x series", "a 0.3x installation is
refused") and accepted every release the invocation is not written for. It was the cause of
both failing tests in the tree, and it is the whole of what this pass changed in behaviour.

**Only a version that was read and is too old refuses a run**

A query that failed, timed out, or answered in something with no version number is a *warning*,
not a refusal. This is deliberately weaker than `GitCheck`, which fails on a `git --version`
that does not work, and the asymmetry is the point: Git is one program the package requires,
while an agent executable may be a wrapper somebody wrote, and refusing to start because that
script answers `--version` oddly would be a worse failure than the one it prevents. Version
parsing is forgiving about everything but the number — only the first `X.Y` or `X.Y.Z` in the
first line that has one — because `version_compare()` reads a trailing `-nightly` or
`.windows.1` as a pre-release marker and would judge a released CLI older than the floor.

Every failure and every warning here ends with the same sentence, and so does `AgentCheck`'s:
**Ralph PHP does not run a different agent in its place.** "No agent found" is the one moment a
tool could plausibly be expected to reach for whatever else is installed, and a run whose
commits were made by an agent the user did not choose is worse than no run.

**Verification**

- `composer test` — **1780 tests, 4665 assertions, green**, up from 1750. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- The preflight tests run **real programs** that print real version strings, in the style of the
  rest of the suite: the package is not trusted about what the CLI said, a script says it.
- **Eight mutations applied and reverted** (each file copied aside first, every revert confirmed
  byte-for-byte, each mutant syntax-checked so a kill could not be a parse error). Seven killed:
  the floor back at `0.1.0`, an unestablished version refusing instead of warning, the
  no-fallback sentence removed, the `agent-version → agent` dependency dropped, version parsing
  keeping the build suffix, the resolved executable ignored in favour of a second PATH search,
  and an adapter stating no requirement refusing the run.
- **One mutation survived and is recorded rather than papered over.** Moving `AgentCheck`'s
  `remember()` above its failure return broke no test, because the `CheckId` dependency skips
  the version check outright when the agent check failed, so the remembered value is never read.
  The inline comment claimed the placement was what kept the version query from starting an
  unusable agent; that was overstated — `AgentVersionCheck` falls back to the configured
  executable when nothing was remembered, so the placement could not have prevented it. The
  comment now attributes the guarantee to the dependency (which *is* tested) and says the
  placement is only about not leaving a half-true fact behind. Behaviour unchanged.

**Notes / deviations**

- The two floors are judgements about release history, not measurements. Each is pinned as
  behaviour in `VersionRequirementTest` — the generation that must be refused and the one that
  must pass — so a future correction changes a constant and shows up as a named failure rather
  than silently widening what is accepted.
- `Preflight` now executes the project's adapter registration file, with the trust
  `AdapterRegistrationFile` documents. That is new for a command previously advertised as
  running nothing of the user's, and the help text was updated to match.
- Section E, item 10 — normalising both adapters into the same outcomes — is next and is
  already true by construction rather than by agreement: both read results through
  `ProcessAdapter::reported()`. It remains to be tested as a property of the pair.

## 2026-11-04 — E10: Two adapters, one reading

**Task:** Normalize both adapters into the same candidate-implementation, blocked, and
execution-error outcomes so verification and Git behavior remain agent-independent.
*(PROJECT.md section E, item 10)*

**The code for this item was already written; the property was not established**

Both shipped adapters implement `interpret()` as one line — `return $this->reported($request,
$result)` — so the reading of an agent's output happens once, in `ProcessAdapter`, and the
normalisation holds by construction rather than by two adapters agreeing. E6 and E7 each left
a note saying so. Nothing in this pass changed that, and nothing needed to.

What the shared method does not establish is the property the runner actually depends on: that
for the *same* thing an agent did, every adapter comes back with a result identical in
everything verification, committing and pushing act on. The per-adapter tests assert "Codex
reports a candidate" and "Claude reports a candidate" separately, and both would keep passing
while the pair disagreed about the claim, the exit status, the recorded evidence, or whether
the attempt may be retried — which are the fields the loop reads. An item whose wording is
about two things being *the same* is not tested by two tests that never compare them.

**What was added**

`tests/Agent/AdapterNormalizationTest` runs one scripted agent session through every adapter
`BuiltInAdapters::providers()` lists and compares the results to each other field by field:
the outcome and the three questions asked of it (`hasCandidate`, `requiresHumanInput`,
`isRetryable`, `settles`), the claim, the blocker and the evidence in their stored form, and
the exit status. Elapsed seconds are deliberately excluded — two runs of the same stub take
two different times and nothing downstream decides anything from them.

Twelve sessions, each a case the loop behaves differently about: a report on standard output
and the same report on the error stream; a candidate from a session that then exited non-zero
(the observation outranking the declaration); a question, and the same question from a session
that then failed (honoured either way, because a question does not stop being one); a declared
error; a clean exit with prose but no report; a failure with no report; a session that printed
nothing at all; an unreadable block; a block declaring an outcome no agent may declare; and two
blocks of which the last is read. Plus a session stopped by a one-second task timeout, which is
the one outcome no adapter interprets — `ProcessAdapter` records it before `interpret()` is
reached — and which therefore needs saying that every adapter records it identically.

**Three decisions in the test worth stating**

*Driven from the catalogue, not from a list of two.* The loop is over
`BuiltInAdapters::providers()`, so a third shipped adapter is held to the property by existing
rather than by somebody remembering to edit this file. A project's own adapter is outside what
this can reach — it may legitimately read its agent's native reporting, which item 5 allows —
and `ExecutionAdapterTest` is where that contract stays.

*The expected outcome is asserted as well as the agreement.* Agreement alone is satisfied by a
pair that is identically wrong, and the mutation below proves the point: a change inside the
shared reading moves both adapters together, so only the declared-outcome column catches it.

*One agent name over both adapters.* Evidence labels and log notes name the configured agent,
and `agents` entries are user-chosen labels that several adapters may share, so the two are
configured as the same agent pointed at the same stub. An adapter comparison that let the names
differ would be comparing two spellings instead of two readings.

**Verification**

- `composer test` — **1795 tests, 4738 assertions, green**, up from 1780. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- The stub is a PHP program that prints what the row says and exits with the status the row
  says. No agent CLI, no credentials, no network (section J, item 8).
- **Six mutations applied and reverted** (each file copied aside first, every revert confirmed
  byte-for-byte, each mutant syntax-checked so a kill could not be a parse error). All six
  killed: Claude dropping the claim when a failed session had declared a candidate (caught on
  the evidence and the claim, three rows); Claude reading a silent clean exit as a candidate
  (four rows); the shared reading refusing a question from a session that failed — which both
  adapters do together, so *only* the expected-outcome assertion catches it; Codex discarding a
  candidate's claim; the package shipping one adapter, which the guard against a vacuous
  comparison catches; and a fourth declarable outcome added to `ResultProtocol`, which the
  guard on table coverage catches.

**Notes / deviations**

- One sentence was added to `ProcessAdapter::interpret()`'s documentation, saying that the
  shipped adapters' agreement is compared rather than inspected. No behaviour changed in `src`
  for this item, which is the honest summary of it: the implementation was already shared, and
  what was missing was the evidence that sharing it is enough.
- The timeout row costs about two seconds of suite time — one second per adapter — and is kept
  anyway: cancellation is where an adapter overriding the common path would be least visible.
  
## 2026-11-05 — E11: An agent a test writes in advance

**Item:** section E, item 12 — a deterministic fixture worker for automated tests, with modes for
success, failure, blocking, slow execution and interruption. Section E is now complete, and this
is the last thing section F can be built without: section J, item 5 names the worker as what
branches, worktrees, verification failures, commits, interruptions and unrelated-user-change
protection are tested with.

**What was added**

Five classes under `tests/Fixture/`, plus their test.

`FixtureWorkerProgram` is the agent itself — the thing that runs in the child process. An adapter
starts it, hands it a briefing on standard input, streams its output and reads a `ResultProtocol`
block out of it, exactly as it would with `codex` or `claude`; what differs is that the work is
written down in advance by a test instead of decided by a model. It is a *program* and not a test
double on purpose: nearly everything the loop has to get right about an attempt is a fact about a
process — the directory it was started in, the briefing it read, the status it exited with, that
it was asked to stop and then killed, that its half-finished edits are still in the worktree
afterwards — and an object standing in for an adapter skips precisely the code in `ProcessRunner`
and `ProcessAdapter` where those guarantees live.

`FixtureWorker` is the handle a test holds: it writes the plan, installs an executable with a
shebang and the execute bit, and afterwards answers what the package did to the worker — how many
attempts there were, which workspace each got, what briefing it was given, whether it was asked
to stop. `FixtureStep` is one scripted run, `FixtureMode` the five modes, `FixtureRecord` one line
of the worker's journal.

**Decisions worth keeping**

*Slow and interruption are two modes, not one with a flag.* A slow step installs no handlers: an
ordinary program that is merely too slow, which is what the attempt timeout is enforced against,
and a termination signal kills it where it stands. An interruption step records the signal that
reached it and — with `ignoringTermination()` — refuses to act on it, which is what makes the
escalation from a signal to a kill observable. Section H's "release ownership only after
termination is confirmed" cannot be checked against a worker that leaves no trace of having been
asked, and the mutation that gave the slow mode a handler is killed by the slow test asserting it
noticed nothing.

*A plan is a list of steps, and the last one repeats.* "Fails, then succeeds" is
`[FixtureStep::failure(), FixtureStep::success([...])]`, which is what section G, item 4 (failure
evidence feeding the next attempt) and section H, item 2 (the attempt limit) are about. Which step
runs is decided by counting the journal's attempts — nothing ambient — and clamped to the last, so
a third attempt gets the last step again rather than the plan running out.

*The journal lives beside the executable, never in the workspace.* A file written inside a task
worktree would appear in the diff a run stages (section F, item 6), so a fixture living there would
corrupt the thing it exists to test. The success test asserts the workspace afterwards holds
*exactly* the scripted change and nothing else.

*An attempt is recorded before the step runs.* A worker killed mid-step still leaves the evidence
that it ran, which is what section H's "confirm that an old worker has stopped" needs; the record
carries the pid, and `FixtureWorker::isRunning()` is how a test asks whether it is gone.

*A version query is answered and does not spend a step.* Preflight asks an executable for its
version before a run (section E, item 9), so a fixture agent that could not answer would make
preflight the subject of every test that used one. The default is deliberately higher than any
adapter's floor; a test about an unsupported version installs a worker answering an old one.

*The report is built from `ResultProtocol`'s own constants*, so the fixture cannot drift into
printing a format nothing asks for, and the plan's field names are declared once in `FixtureStep`
— the program reads its plan through the same class that wrote it.

**Verification**

- `composer test` — **1807 tests, 4808 assertions, green**, up from 1795. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `FixtureWorkerTest` drives the worker through the real `CodexAdapter` and holds one contract per
  mode: a success leaves the scripted change in the task's workspace and nothing else; a success
  with no edits is the already-satisfied case of section G, item 6; a failure is an error carrying
  what the worker said; a step reporting nothing is an error whatever its exit status, with its
  unclaimed edits still in the workspace; a block is a question with both halves; a slow step is
  stopped by a one-second budget and notices nothing; an interrupted step records SIGTERM and is
  gone afterwards; a stubborn one is killed after the grace period; successive runs take successive
  steps and repeat the last; the same step twice produces byte-identical output; and the modes are
  the five the item names.
- **Six mutations applied and reverted** (each file copied aside first, every revert confirmed
  byte-for-byte, each mutant syntax-checked). All six killed: step selection losing its clamp,
  edits landing outside the workspace, a slow step handling signals, a version query recorded as an
  attempt, a silent step reporting anyway, and an interruptible step never acting on the signal.
- No agent CLI, no credentials, no network (section J, item 8). The two signal tests skip without
  ext-pcntl, which the package does not require; the worker still waits and still dies when it is
  stopped, only the record of having been asked is missing.

**Notes / deviations**

- The waiting tests cost about five seconds of suite time — a one-second timeout each, plus the
  two-second grace the stubborn worker spends being killed. Kept: that grace is where "the worker
  has stopped" stops being an assumption.
- Nothing in `src` changed. One stale forward reference did: `ScriptedProcessAdapter`'s
  description said the fixture worker "is built once there is a real adapter to point it at", and
  now points at it.
- `FixtureStep` has knobs the current tests use and no others. Edits may escape the workspace with
  `..` deliberately — section F, item 6 stages the worktree and nothing else, and a worker that
  could not write outside it could not demonstrate that — but an absolute edit path is refused.
  Whatever section F needs beyond this (a worker that makes its own commit, for item 9) is a knob
  added when a test asks for it, not now.

## 2026-11-05 — F1: Reading the user's checkout, and leaving it alone

**Item:** section F, item 1 — inspect the user's checkout before a run and reject unrelated
uncommitted changes without stashing, discarding or committing them. The first item of section F,
and the first time the package looks at the repository it was started in rather than at its own
files.

**What was added**

A new `src/Git/` namespace with five classes, one new exception, and three test files.

`Repository` is the handle every Git operation in section F will go through: an absolute working
tree that exists, the `git` executable it was given rather than whichever one the search path
offers at the moment of the call, and `GIT_DIR`/`GIT_WORK_TREE` removed from the environment.
`GitProbe` now builds one of these per call instead of assembling the same three decisions itself,
so there is one place where "which repository, run by which git, with what redirected away" is
decided.

`WorkingTreeStatus` reads `status --porcelain=v1 -z --untracked-files=normal` and
`WorkingTreeChange` is one entry of it. `ManagedPaths` says which paths belong to the package.
`CheckoutInspection` sorts a reading into the project's changes and the package's and carries the
message, the evidence and the hints. `ControllingCheckout` is what a run calls: `inspect()` to
read, `requireRunnable()` to read and refuse, raising `CheckoutException`.

**Decisions worth keeping**

*The refusal is the feature, so the test is about what did not happen.* Stashing, discarding and
committing are the three convenient things to do with someone else's uncommitted work, and each
can lose it. "It does not do them" is a claim about absence, so it is asserted over the whole
repository rather than inferred from an uncalled method: a checkout carrying an unstaged edit, a
staged addition, a deletion and an untracked file is snapshotted — HEAD, porcelain status, staged
diff, unstaged diff, stash list, reflog — and the snapshot must be identical after the refusal,
with the file contents still readable and the stash still empty. Structurally there is nothing to
go wrong, either: both commands the inspection issues are `Repository::query()`, and nothing in
`CheckoutInspection` can express a change to a repository.

*"Unrelated" is decided by which files the run reads from this checkout.* The hazard is specific:
a task is implemented in a worktree created from the configured base commit, so work that exists
only in the controlling checkout is invisible to the agent and absent from the task's commit, and
the two can then diverge where nobody has looked. That is false for the package's own files —
`.ralph/`, the configured checklist, the configured context file, and the `.bak` copies
`init --force` leaves — because those are read *here*, not in the worktree. So they do not stop a
run, which is also what PROJECT.md section 4 requires: the runner must tell its own checkbox
changes from unrelated edits, and must never commit the ones it finds here. A rename counts as the
package's only when both of its paths do; the test that matters is the reverse direction, a source
file moved *into* `.ralph/`, which judged by its destination alone would let a run start as though
the project had not lost a file.

*Untracked files count.* A stray scratch file is irritating to be stopped by, and it is still
work the agent will not see. The remedies are named instead: commit it, stash it yourself, or
ignore it — and ignored paths are not reported here at all, which is why the default ignore rules
`ralph init` writes make an initialised project clean.

*A checkout that cannot be read is refused on the same grounds.* A failed `status` is not an empty
answer. The reasons it can fail — no longer a repository, a corrupt one, a filesystem that went
away — all mean nothing is known about what is uncommitted, and starting then would be starting on
an assumption.

*Three details of the command are load-bearing, and each has a test.* `--untracked-files=normal`
is passed explicitly because `status.showUntrackedFiles=no` is a real setting that would otherwise
report a checkout full of new files as clean. `-z` is what makes a filename containing a space, a
quote or a non-ASCII character arrive as the path it is instead of as Git's quoting of it.
`--no-optional-locks` keeps a plain inspection from writing a refreshed index, so a run cannot
fail the `git add` of a user who has the repository open in an editor; the test backdates a tracked
file's timestamp to make the difference observable, asserts the index is byte-identical after
inspecting, and then asserts that the same command *without* the option does rewrite it.

*Paths are compared exactly as Git spells them.* An earlier version of `ManagedPaths::covers()`
normalised separators and trimmed whitespace, and a mutation showed the normalisation was
unreachable. It was worse than redundant: Git reports forward slashes on every platform, so
translating a backslash would let a file called `.ralph\notes.md` in the project root be claimed as
the package's, and trimming would do the same to a name with a leading space. Both are legal
filenames, and both are now tested as the project's.

*Not refusals:* a detached HEAD and an interrupted merge or rebase. Neither affects a worktree
created from a configured base commit, and a conflicted file in such a checkout is reported anyway
because it is an uncommitted change. What the base reference resolves to stays `BaseReferenceCheck`'s
question.

**Verification**

- `composer test` — **1857 tests, 4996 assertions, green**, up from 1807. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `ControllingCheckoutTest` drives real repositories: a clean checkout starts; an unstaged edit, a
  staged edit, an untracked file, an untracked directory, a real rename and an unresolved merge
  conflict each stop a run and are named in words; a ticked checkbox in a tracked checklist does
  not, nor does runtime data, nor a checklist configured at `docs/backlog.md` — which the default
  configuration reports as the user's work, from the same repository; ignored files are not
  reported; thirteen changes are counted exactly and listed ten at a time; `inspect()` reports what
  `requireRunnable()` refuses; a directory that is not a repository is refused with Git's own
  reason; an inherited `GIT_DIR` cannot point the inspection elsewhere; and a `git` shim proves the
  executable given is the one run.
- `WorkingTreeStatusTest` holds the parser against hand-built porcelain — every status character as
  a word, both paths of a rename, the three shapes of conflict, five awkward filenames, and a
  record Git could not have written, which is refused rather than skipped: a record whose path
  cannot be read is a path that would otherwise be reported as clean.
- **Sixteen mutations applied and reverted** (each file copied aside first, every revert confirmed
  byte-for-byte, each mutant syntax-checked). Fourteen killed on the first pass. Two survived and
  both were findings rather than missing assertions: judging a rename by its destination alone,
  which produced the moved-into-`.ralph/` test above, and the redundant path normalisation, which
  was removed along with its dead empty-path branch.
- No agent CLI, no credentials, no network (section J, item 8). Every test skips without `git`.

**Notes / deviations**

- Nothing calls `requireRunnable()` yet: `ralph run` is section G, item 1, and this is the
  component it starts with. It was deliberately not added to preflight — PROJECT.md section B,
  item 7 lists the checks, and a dirty checkout is not among them; preflight is also safe to run
  mid-run, which a refusal would not be.
- `GitProbe` changed and preflight did not: same executable, same working directory, same removed
  variables, same 20-second timeout, now expressed once. Its tests are unchanged and green.
- No override. PROJECT.md gives no way to run anyway, and a `--force` that proceeds past the
  user's uncommitted work would be the first version of exactly what this item forbids.

## 2026-11-05 — F2: One commit, and a room of its own per task

**Item:** section F, item 2 — resolve the configured base reference to a recorded commit SHA, and
create an isolated linked worktree for each task.

**What this adds**

`BaseCommit` is the pair the rest of a run carries: the reference the user configured and the commit
it resolved to. The pair exists because `git.base` defaults to `HEAD` and is routinely a branch
name, and both move. A user who commits to `main` while a run is in progress has changed what `main`
means, not what the run is building on, so the SHA is resolved once and travels as a value —
`BaseResolver::resolve()` is the only thing that asks Git, and nothing downstream can ask again.
The SHA is validated as a *full* object name rather than merely non-empty: an abbreviation means a
different commit as soon as the repository grows one sharing its prefix, and this value is written
into SQLite and compared against `git worktree list` output months later. Both hash algorithms are
accepted, because a repository created with `--object-format=sha256` reports 64 characters and
refusing them would refuse the whole repository for no reason of the package's own.

`TaskWorktrees::prepare()` gives a generation its workspace, and the whole class is the decision
between three outcomes:

- **Created.** Nothing is at the path and Git has never heard of it. `git worktree add --detach` from
  the recorded SHA. Linked rather than copied: it shares the object database, so it costs a checkout
  instead of a clone, and the commit a task produces is already in the repository the user is sitting
  in.
- **Adopted.** A worktree is registered there and the directory is present — almost always what an
  interrupted run left, and continuing in it is how resume reaches work already done instead of
  repeating it. `TaskWorktree::ownership()` records that it is *not* this run's to remove, which is
  the distinction `WorktreeOwnership` was written for and which no later inspection of the directory
  could recover.
- **Refused.** Something else occupies the path. `WorktreeException`, and nothing is cleared: the
  convenient things to do with a directory the package did not create are the ones section F, item 10
  forbids.

The one case cleaned up automatically is a registration whose directory has gone — an `rm -rf` of a
task workspace, which leaves Git's record behind and makes it refuse the path outright. That is the
package's own leftover, at the package's own path, with nothing in it, so `--force` recovers it. A
missing directory cannot contain work, which is what makes this the narrowest possible use of that
flag.

`WorktreeLayout` settles where a workspace goes: `.ralph/runtime/worktrees/<slug>`, named by
`TaskGeneration::slug()` and nothing else. That is what lets an interrupted pass be found again by
asking Git about *one path* rather than by searching for a directory that looks right, and it is why
reopening a finished task cannot land in the old pass's directory. `generationAt()` reads a path back
and is deliberately strict — a name that is not a slug is attributed to nobody, because the only use
for the answer is deciding what may be removed.

`WorktreeList`/`RegisteredWorktree` read `worktree list --porcelain`. Unknown labels are **ignored**,
which is the opposite of `WorkingTreeStatus`'s stance and deliberately so: Git has added lines to this
format more than once (`locked` and `prunable` are both newer than the supported floor), and here
silence is the safe direction. That is also the answer to the format's one real flaw — paths are
printed unquoted, so a path containing a newline is read as a record plus a line this ignores, which
can only make the list *shorter* than the truth. A paragraph naming no path is still refused, because
that is not a format change Git could make. The authority on whether a path is free is `git worktree
add` itself, which refuses a path it has registered; this list decides whether to adopt or to create.

`Repository::runWithin()` is new, and small: the class docblock already promised that commands which
can legitimately take longer would pass their own timeout, and `git worktree add` writes a whole tree
and runs whatever `post-checkout` hook the repository configures. Five minutes for a checkout, twenty
seconds still for a `rev-parse`, so a `status` on an unresponsive mount fails as quickly as before.

**Verification**

- `composer test` — **1930 tests, 5260 assertions, green**, up from 1857. PHPStan level 8 over `src`
  and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `TaskWorktreesTest` drives real repositories: a workspace is linked (`.git` is a file, no object
  database of its own), detached at the recorded commit, holding the base commit's files, and creates
  no branch; asking twice creates once and adopts after; an interrupted pass's uncommitted work
  survives adoption; a deleted directory's registration is recovered; a directory holding someone
  else's files stops the run with those files untouched and is summarised five names at a time; a
  file where the workspace goes stops the run; an empty directory does not; two generations of one
  task get separate directories; a base commit the repository does not have is reported as Git's
  refusal with nothing half-created left behind; the workspaces root is created 0700; and the
  controlling checkout's HEAD, branch, status, contents and refs are byte-identical afterwards.
- `BaseResolverTest` asks the actual `git`: `HEAD` and a branch resolve to a tip rather than a name,
  and a value already in a caller's hands still names the old commit after the branch moves; an
  annotated tag peels to the commit it marks; an abbreviation is recorded in full; a missing reference
  names the file to edit; a tree is refused; an empty repository says it has no commits rather than
  blaming the default; a directory that is not a repository reports Git's own reason instead of a
  missing branch.
- `WorktreeListTest` holds the parser against hand-built porcelain, which is where a bare repository,
  a lock without a reason and a label from a newer Git than the one installed can actually be
  produced.
- **Twenty-three mutations applied and reverted** (each file copied aside, every revert confirmed
  byte-for-byte, each mutant syntax-checked). Twenty killed on the first pass. Of three survivors,
  one was a real gap and two are equivalent mutants:
  - *Gap, now covered:* letting a repeated porcelain label overwrite the first. Without the guard, a
    paragraph corrupted by a newline in a neighbour's path can be **moved onto another path** — which
    is how a directory the runner is about to create gets reported as already taken. A test now pins
    that the first `worktree` line wins.
  - *Equivalent:* dropping `--detach`. `git worktree add <path> <sha>` detaches anyway when the
    commit-ish is not a branch; verified directly. The flag is kept as a statement of intent, and the
    outcome is asserted rather than the flag.
  - *Equivalent:* dropping `--end-of-options`. The `^{commit}` suffix already makes any dash-leading
    value an invalid option, and `--quiet` turns that into the same exit 1 as a missing reference.
    Kept, because it is the same defence `BaseReferenceCheck` uses and because the suffix is not
    guaranteed to be there forever — `--all` *without* the suffix exits zero and prints every ref.
- No agent CLI, no credentials, no network (section J, item 8). Every test skips without `git`.

**Notes / deviations**

- **Branches are section F, item 3.** The worktree is created detached, which is a complete and
  committable state. Creating a branch here would mean naming one before anything can be recorded
  about who owns it, and ownership is the whole substance of the next two items.
- `BaseResolver` duplicates `BaseReferenceCheck`'s question deliberately. Preflight is optional, and
  the window between a preflight that passed and a run that starts is long enough for a branch to be
  deleted. What the two must not disagree about is *which references count*, so they share the way
  they ask: `--end-of-options`, the `^{commit}` peel, the empty-repository message.
- `tests/Support/Git::initialise()` now pins `core.autocrlf=false` per repository. The machine this
  ran on has it set globally — common in WSL — and Git applies it whenever it checks a file out,
  which is exactly what creating a task worktree does. Without the pin, "the worktree holds the base
  commit's files" was a claim about the machine.
- Nothing wires this into a run yet: `ralph run` is section G, item 1. The integration points are
  already the shapes the state store accepts — `GenerationStore::start()` takes the base reference and
  SHA, and `recordWorktree()` takes `TaskWorktree::ownership()` — so no adapter layer is needed when
  the loop lands.
- `git worktree prune` is never run. It would clear stale records for *every* worktree in the
  repository, including the user's own, and the surgical alternative — `--force` at a path the package
  owns whose directory is gone — covers the only case a run creates.

## 2026-11-05 — F3: A branch a pass can be given back

**Item:** section F, item 3 — generate valid task branch names from the configured prefix, task ID
and description, and retain branch ownership for resume.

**What this adds**

`BranchNamer::nameFor()` produces `<prefix><generation slug>-<description>`, for example
`ralph/TASK-C6AED8D9-generate-valid-task-branch-names-from-the`. The order of the three parts is the
design:

- **The prefix** first, so every branch the package creates sorts together, is matched by one
  `git branch --list "ralph/*"`, and can be excluded from a glob by anyone who wants to.
- **The generation slug** next, because it carries identity: the task ID and the `-g2` suffix a
  reopened task gets. Two passes at one task therefore cannot name the same branch, which is the
  mechanism behind "a separately owned branch" in section C, item 8. It sits before the description
  so that it survives every shortening.
- **The description** last, as a courtesy to whoever reads `git branch`. Nothing depends on it.

`BranchName` is the value that says Git will accept a name, and it is checked *before* a worktree is
prepared or an agent is invoked rather than by `git branch` afterwards, when the work has been done
and the commit has nowhere to go. The rules are Git's own and are pinned by a test that asks the
installed `git` the same questions for every candidate — which is how the two rules
`git check-ref-format` does not report were found:

- **`HEAD`** is a valid *reference* name and not a valid *branch* name. `git check-ref-format
  refs/heads/HEAD` succeeds while `git branch HEAD` refuses. Only the whole name: `ralph/HEAD` is
  accepted by both, and the type matches that exactly.
- **A leading `-`** is refused by `git branch` even after `--`.

**A recorded branch is never regenerated.** `BranchNamer::resolve()` returns what the generation's
row holds whenever it holds anything, without comparing it to what the configuration would produce
now. This is the half of the item that is about resume rather than naming: `git.branch_prefix` is a
setting a user may edit between two runs, and a resumed pass that recomputed its name would start a
second branch while the first one still held its commit — the work would not be lost, but nothing
would be able to find it. `TaskBranch` carries that distinction the way `TaskWorktree` carries
created/adopted: `generated` means this run named it, `retained` means it came back from the row.

The same reasoning is why a branch name is never read *back* as an identity. `ralph/TASK-19E0214A-g2-…`
is genuinely ambiguous — a first-generation task whose description begins "g2" produces the same
shape — so which pass owns a branch stays a question for the state store, which recorded it.
`BranchName::hasPrefix()` is as far as spelling is trusted, and it is the weakest half of what
section F, item 4 will need: a branch outside the configured prefix was certainly not generated here,
while one inside it may still belong to another pass or to a person who likes the same prefix.

**Verification**

- `composer test` — **1987 tests, 5425 assertions, green**, up from 1984. PHPStan level 8 over `src`
  and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `BranchNameTest` holds twenty-eight candidate names against the rules *and* against the installed
  `git branch` in a throwaway repository, requiring the same verdict from both. It skips without
  `git`.
- `BranchNamerTest` pins the generated shape literally, because a name goes into Git refs and a
  database that outlive the process: the word-boundary cut, a description that fills the budget
  exactly keeping its last word, a single long word cut at the budget, punctuation and casing
  reducing to single dashes, a description that reduces to nothing leaving a name that is still
  complete, a prefix long enough to leave no room still keeping the identity intact, and reopening
  producing a different branch from the first pass's.
- Retention is tested in the three forms it occurs in: a recorded branch under a *different* prefix
  is kept, a recorded branch is kept after the task has been reworded, and a blank column is the same
  as none. A recorded name Git could not hold is reported as a `StateException` naming the pass and
  the spelling, rather than quietly replaced with a generated one — the recorded branch may hold the
  commit.
- **Thirty-three mutations applied and reverted** (each file copied aside, every revert confirmed
  byte-for-byte, each mutant syntax-checked). Thirty-two killed on the first pass. The one survivor
  was a real gap: dropping the guard that refuses to shorten a description down to its first word
  when that word is under four characters. Without it, a description of "Add" followed by one very
  long word produces a branch ending `-add`; with it, the same width is spent on the word itself.
  A test now pins that case, and the second run of the mutation killed it.
- No agent CLI, no credentials, no network (section J, item 8).

**Notes / deviations**

- **The readable half is reduced to `a-z`, `0-9` and `-`.** Accents and non-Latin scripts are legal
  in a Git reference, and `git branch ünïcode` works, but this package cannot apply Unicode
  normalisation on every machine — see the note in `TaskBody` about not requiring ext-intl — so a
  name containing them could differ byte-for-byte between a developer's checkout and CI while looking
  identical. A description that reduces to nothing simply produces a name without a readable half,
  which is complete and unambiguous on its own: `ralph/TASK-C6AED8D9` is a branch. The cost is
  honest and visible — "Créer un module" becomes `cr-er-un-module` — and it falls only on the part of
  the name nothing depends on.
- **Length is bounded by the namer, not by the name.** The real limit is the filesystem's: a loose
  ref is a file, and its name plus the `.lock` Git writes beside it must fit a directory entry, so a
  300-character branch fails at creation with "File name too long" (confirmed against the installed
  `git`). That depends on the ref's last segment, on whether refs are packed, and on the machine, so
  `BranchName` validates Git's rules and nothing else, while `BranchNamer` keeps what it generates to
  120 characters with at most 48 spent on the description. Refusing a long name a repository already
  holds would be the wrong behaviour anyway: the only thing to do with such a branch is report it as
  it is spelled.
- **The prefix rules in `ConfigurationValidator` stay where they are.** A prefix ends in a separator —
  `ralph/` is the default — and a whole branch name may not, so asking `BranchName` about a prefix
  would reject the ordinary case. What the two must not do is disagree about which names count, so
  the one rule the validator was missing, `@{`, was added to it, and `BranchNamer` reports any
  remaining difference against `git.branch_prefix` with the same remedy the validator would have
  given rather than letting it surface as a Git error mid-run.
- **No branch is created yet, and that is deliberate.** A task worktree is still checked out detached
  at the recorded base commit, which is a complete and committable state. The branch is created when
  there is a commit to put on it, and refusing a branch owned by unrelated work is section F, item 4 —
  which is the half of ownership that needs to ask the repository rather than only the row.
  `GenerationStore::assignBranch()` and `reservedBranches()` have been waiting for this since section
  D; `BranchNamer::forRecord()` takes a `GenerationRecord` so the runner's call in section G is one
  line.

## 2026-11-05 — F4: A branch nobody else is standing on

**Item:** section F, item 4 — reject branches owned by unrelated work instead of resetting or
overwriting them.

**What this adds**

`TaskBranches` asks who holds the branch a pass is named after and answers with a
`BranchOwnership` reading carrying one of three `BranchClaim`s. `TaskBranches::require()` turns an
unusable reading into a `BranchException`; `inspect()` returns the same reading without judging it,
for `status` and the diagnostics.

The question exists only because the name is generated. `ralph/TASK-C6AED8D9-add-a-greeting` comes
out of the task, the generation and the configured prefix, so the same name can be produced twice
without anybody having made a mistake — by a pass being resumed, by a person who also names branches
`ralph/…`, or by a prefix edited between two runs. A branch that already exists is therefore not
evidence that it is the pass's, and "create it if it is missing, use it if it is there" is the rule
that quietly commits onto somebody else's history.

Ownership is decided from three sources, in this order, because they fail independently:

1. **The other passes' rows.** A name another generation records is taken before Git has a branch
   under it — that pass's commit is going there. Asked first, since it needs no repository at all.
   A row belonging to the pass itself is skipped, which is what makes a resumed pass's own branch
   its own.
2. **The repository's references.** A branch nothing recorded is a person's, whatever it is called.
   `refs/heads/<name>` is asked for in full: a branch `main` and a tag `main` are both `main` to
   `git rev-parse`, and asking about the wrong one is how a run decides a branch is free because a
   tag was deleted.
3. **The pass's own row, against the branch's history.** A branch the pass records is the pass's
   only while it still contains what the row says is on it. A tip that no longer contains the pass's
   commit — or, before it has one, does not descend from the base commit the run resolved — is a
   different branch wearing the same name. `git branch -f` is all it takes to produce that, and
   continuing on it would put the task's commit on a stranger's history.

A branch another worktree has checked out is refused too. Git allows a branch in one worktree at a
time, so this would otherwise surface as a failed checkout with the work already done; refused up
front it costs nothing and names the directory holding it. The pass's *own* workspace holding its
own branch is the ordinary case, which is what the recorded worktree path in the generation's row is
for.

**None of the three convenient responses is reachable from this code.** `git branch --force` moves
someone's branch off its commit, `git reset --hard` discards the tree it pointed at, `git branch -D`
removes the only reference to it; each destroys work a person has and the run has never seen. So
`BranchOwnership` is a reading that cannot express any of them, the same division
`CheckoutInspection` and `ControllingCheckout` make, and every command the class issues is a
`Repository::query()` — read-only, and unable to take the index lock of a repository the user has
open in an editor.

A question Git does not answer is a refusal rather than an empty one. A `rev-parse` that fails with
anything but "no such reference", or a `merge-base --is-ancestor` on a commit the repository no
longer has, raises instead of being read as "missing" or as "not ours": a run that cannot establish
who owns a branch must neither take it nor commit to it.

**Verification**

- `composer test` — **2003 tests, 5492 assertions, green**, up from 1987. PHPStan level 8 over `src`
  and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `TaskBranchesTest` creates real repositories and asks the installed `git`, as section J, item 5
  requires — `git branch -f`, `git worktree add` and the ancestry Git computes are the subject, and
  none of them can be faked usefully. It skips without `git`. Every case is a different way for one
  name to be produced twice: a person's branch created first, a pass resuming onto the branch its row
  records, a branch still carrying the pass's commit, a branch that has moved one commit past it, a
  branch reset off it, a recorded branch whose history misses the base, a recorded branch that has
  gone, a name another pass reserved before any branch exists, a branch checked out in another
  worktree, and the same branch checked out in the pass's own workspace.
- The recurring assertion is that nothing happened: `refs/heads/ralph/*` and their object names are
  identical before and after every refusal, and a whole-checkout snapshot — HEAD, current branch,
  `status --porcelain`, every ref — is unchanged by deciding ownership at all.
- Both unanswerable cases are tested rather than assumed: a repository Git cannot be asked about
  stops the run, and a recorded commit the repository no longer has is reported instead of being
  read as a branch that is not the pass's.
- **Thirteen mutations applied and reverted** (each file copied aside, every revert confirmed
  byte-for-byte, each mutant syntax-checked). All thirteen were killed on the first pass, including
  the two that make a refusal into a guess — treating an unanswerable `rev-parse` as a missing branch
  and an unanswerable ancestry check as "not an ancestor" — and the one that stops the pass's own row
  being skipped, which would make every resume report its own branch as another pass's.
- No agent CLI, no credentials, no network (section J, item 8).

**Notes / deviations**

- **A recorded branch that has gone is `Available`, not a refusal.** Nothing owns the name, so the
  pass may create it again; what the vanished branch means for a commit the row records is
  reconciliation's question, not ownership's — section F, item 9 and section H, item 5. The reading
  says plainly that Git has no such branch, so the later item has something to act on rather than a
  verdict to undo.
- **A branch one commit past the pass's own commit is still the pass's.** The row says which commit
  is on the branch and the tip still contains it, which is all ownership asks. An *unexpected* commit
  on a pass's own branch is section F, item 9, and answering it here would mean refusing the ordinary
  case where a run was interrupted between committing and writing the row.
- **The worktree list is asked for here rather than taken from `TaskWorktrees`.** The two ask the
  same question, but the task's own workspace is created between them, so a list from before that is
  already out of date about the one worktree this most needs to recognise.
- **Nothing calls it yet, and that is the same boundary as items 2 and 3.** A task workspace is still
  checked out detached at the recorded base commit; the branch is created when there is a commit to
  put on it, which is item 7. `TaskBranches::require()` takes the `GenerationRecord` and the
  `GenerationHistory` the runner will already be holding, so the call in section G is one line, and
  `GenerationStore::assignBranch()` remains the only thing that writes ownership down.
- **The recorded-owner check is defence in depth rather than a case the namer can reach today.** Two
  passes at one task cannot generate the same name — the generation slug separates them — so this
  fires on a name that arrived another way: a hand-edited database, a copied state file, or the
  explicit branch option section F does not have yet. It costs one pass over rows already loaded and
  it refuses *before* the repository is touched, which is the right order for a claim that is true
  before the branch exists.

## 2026-11-05 — F5: A worktree the project can actually be built in

**Item:** section F, item 5 — document and implement the example project's workspace preparation,
including dependencies and test configuration that do not automatically exist in a new worktree.

**What this adds**

A `workspace` section in the configuration, read by `WorkspaceSettings`, and a `WorkspacePreparer`
that applies it to the worktree `TaskWorktrees` just created. It returns a `WorkspacePreparation`
naming what it did — the files copied in, the files found and left alone, and a `PreparationStep`
per command — or raises a `PreparationException` naming what to fix. A `WorkspaceCheck` asks the
answerable part of the same question before a run starts.

The gap it closes is what a linked worktree *is*: a checkout of the repository's tracked files and
nothing else. For a PHP project that is not a working project. There is no `vendor/`, because
dependencies are installed rather than committed; there is no git-ignored `phpunit.xml` beside the
committed `phpunit.xml.dist`, no `.env`, no `auth.json`. An agent sent in before that is fixed finds
the project's own suite failing for reasons that have nothing to do with its task, and the failure
is then recorded against the task, where the next attempt reads it as evidence about work nobody has
done yet.

The absent things divide in two, so the setting does:

- **`workspace.prepare`** — argument-array commands run inside the new workspace, in order, for
  everything reproducible from committed files. `composer install --no-interaction` is the default,
  for the same reason `composer test` is the default check.
- **`workspace.copy`** — files taken from the controlling checkout, for what is reproducible from
  nothing: a local configuration file or a credential that exists on this machine and in no commit.
  Empty by default, because only the project knows whether it has any.

Copies run before the commands, since an install may need an `auth.json` or a `.env` to work at all.

Three rules separate this from a shell script, and each is a test:

1. **It only ever writes inside the workspace.** A path that is the project root is refused rather
   than prepared — the promise `ControllingCheckout` makes about the directory the user is sitting
   in extends to not installing into it.
2. **It never overwrites a file that is already there.** An interrupted run's workspace, which
   resume continues in, may hold a copied file the agent has since edited; re-copying would discard
   that edit silently. A file found in place is recorded as `alreadyPresent` and left alone — and a
   broken symlink counts as present, since `file_exists()` follows the link and would call it
   absent.
3. **A failure stops the task before the agent starts.** There is no partially prepared result to
   carry on with: either a `WorkspacePreparation` describing a usable workspace or an exception, and
   nothing has been implemented when it is raised.

Copies are regular files only. A directory is refused rather than copied recursively — the
directory a project would reach for is `vendor/`, which an install answers reproducibly, and a
recursive copy would additionally have to decide about symlinks, sockets and half-written files. A
symlink is refused rather than resolved, because what it points at may be outside the project. The
source's mode is copied after its contents, so a 0600 `.env` arrives as a 0600 `.env`: preparation
must not be the thing that makes a credential readable to the rest of the machine.

`WorkspacePreparation` keeps the copied paths apart from the ones already present because section F,
item 6 needs exactly that list: those files are untracked, local, and must stay out of the task's
commit. The successful steps are kept for a different reason — a task that later fails verification
reads differently depending on whether `composer install` ran in this workspace two minutes ago.

**Verification**

- `composer test` — **2037 tests, 5633 assertions, green**, up from 2032. PHPStan level 8 over `src`
  and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `WorkspacePreparerTest` uses real repositories, real worktrees and real programs written to disk:
  a command's working directory, the order commands run in, copies happening before them, a 0600
  file arriving as 0600, a failing command naming the setting it came from, a command killed at its
  timeout, the three refused copies, the project directory refused, a workspace that has gone, and
  streamed output arriving as it is produced. The checkout the user is sitting in is snapshotted —
  HEAD, `status --porcelain`, the entries in its root — and asserted unchanged by a preparation.
- `PreflightTest` gained the `workspace` cases: a preparation command whose program is missing, the
  default install measured against a project with and without a `composer.json`, a configured file
  that is absent, a directory and a symlink, a project that configures nothing, and the assertion
  that preflight *resolves* the commands without running them.
- **Seven mutations applied and reverted** (each file copied aside, every revert confirmed
  byte-for-byte). All seven were killed: an empty `workspace` treated as configured, an executable
  that always resolves, a directory accepted as copyable, an existing file overwritten, a failed
  command accepted, the project root accepted as a workspace, and a copied file's mode dropped.
- No agent CLI, no credentials, no network (section J, item 8).

**Notes / deviations**

- **The documentation is the generated `CONTEXT.md` and the generated `config.json`, not a README.**
  `ralph init` writes the full default configuration, so the `workspace` section appears in every
  initialised project; the context template now explains what a worktree lacks and which of the two
  settings closes it. A README is section J, item 9 and will gather this with everything else.
- **There is no example project yet, and this item does not add one.** That is section J, item 11.
  What this implements is the mechanism the example will be configured through — and the default is
  already what a PHP package needs, so the example's preparation is expected to be the default one.
- **Commands run on every preparation, including in an adopted workspace, so they must be safe to
  run twice.** `composer install` is, and the contract is stated where the setting is documented.
  The alternative is worse: a workspace an interrupted run created but never finished installing
  into would be handed to an agent as though it were ready.
- **`prepare` replaces the default wholesale rather than merging with it,** for the reason `checks`
  does: a project that lists one command means that one. An explicit `[]` is therefore how a project
  whose plain checkout is already usable opts out, and the preflight check says so rather than
  staying silent.
- **Preflight resolves and never executes.** Running an install there would do minutes of network
  work in the one directory preparation must not write to, and for a worktree that does not exist
  yet. The limitation is documented rather than worked around: a command that resolves is not a
  command that will succeed, and an install that fails over a private repository or a platform
  requirement is reported by preparation, against the command that produced it.
- **Nothing calls the preparer yet** — the same boundary items 2, 3 and 4 stop at.
  `WorkspacePreparer::forProject()` takes the configuration and a `TaskWorktree`, so the call in
  section G's loop sits immediately after the worktree is created and before the adapter is invoked.

## 2026-11-06 — F6: Deciding what a commit contains

**Task:** Stage only task-workspace changes after successful verification, excluding runtime
data and credentials, and record the staged diff summary. *(PROJECT.md section F, item 6)*

**What was done**

- `Git\WorkspaceStager` stages a verified task's work in its own worktree and returns the
  summary of what the commit will contain. Five steps, in this order and for reasons that
  are each a failure it prevents: refuse an unmerged path, clear the index to HEAD, read the
  workspace file by file, partition the changes, stage the survivors, read the index back.
- The exclusions come from the two places that already know. `ManagedPaths` — reused from
  section F, item 1 — answers "is this Ralph PHP's own", and the project's `workspace.copy`
  setting answers "is this a file that exists on this machine and in no commit". The
  *setting* is consulted rather than what preparation did this run: in an adopted workspace
  a copied file is reported as *already present* rather than copied, and an exclusion that
  depended on the copy would quietly stop excluding it on exactly the run — a resumed one —
  where there is most to lose.
- `WorkingTreeStatus::COMMAND_ALL_UNTRACKED` reads a workspace with `--untracked-files=all`.
  The existing `COMMAND` accepts Git's summary of a wholly untracked directory, which is
  enough to tell a user that something of theirs is in the way but cannot answer a question
  asked file by file: a directory reported as `config/` may hold both the agent's work and a
  copied `config/local.env`, and staging that entry whole commits the credential.
- `Git\StagedChanges` is the recorded summary — per file a status (score digits included),
  the added and removed lines, and `null` counts for a binary file rather than zeroes. Read
  from `diff --cached --name-status -z` and `diff --cached --numstat -z`, joined by path.
  `Git\StagedFile` is one entry; `Git\ExcludedChange` and `Git\ExclusionReason` are the other
  half, which is the half that cannot be reconstructed afterwards: once a commit exists, a
  file that was never changed and one that was changed and deliberately left out look
  identical.
- `Exception\StagingException` for the failures. It is the first refusal in section F that
  happens *after* the work is done, so every message says where the implementation is rather
  than what to fix before trying again.
- The context template now states what reaches a commit: a `workspace.copy` file does not, nor
  do the package's own files, nor anything `.gitignore` covers — and therefore a build
  directory that is *not* ignored does.

**Verification**

- `composer test` — **2076 tests, 5788 assertions, green**, up from 2037. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `WorkspaceStagerTest` uses real repositories and real linked worktrees, and reads the index
  back from Git with `diff --cached --name-only --no-renames` rather than trusting the
  returned summary: a summary is a claim, the index is what becomes a commit. It covers the
  task's own work staged with its line counts, the checklist and `.ralph/` excluded, a copied
  `.env` excluded on both a fresh and a resumed run, an ignored path never seen, a binary
  file, a rename, a workspace the agent left alone, 250 files across more than one `git add`,
  a conflict refused with the conflict left unresolved, the project directory refused, a
  workspace that has gone, and the controlling checkout — HEAD, working tree, *and its own
  index* — asserted unchanged by all of it.
- `StagedChangesTest` covers what Git is not supposed to produce and a repository cannot be
  made to produce: a status with no path, a rename missing half of itself, a line count that
  is not a number, a staged path absent from the other output, and a path with a newline in
  it surviving `-z`.
- **Thirteen mutations applied and reverted** (each file copied aside, every revert confirmed
  byte-for-byte). All thirteen were killed. Two survived on the first pass and both were
  informative rather than noise — see below.
- No agent CLI, no credentials, no network (section J, item 8).

**Notes / deviations**

- **The index is cleared before staging, and that is a deliberate write.** An interrupted run
  may have staged and been killed before committing, and an agent is free to run `git add`
  itself — so without it a commit could contain something nobody here chose. A mixed reset
  moves no files and no references, so nothing can be lost: every change is still in the
  working tree, and the paths that ought to be staged are staged again a moment later by
  rules that can be read. A test force-adds an ignored file and a credential the way an agent
  could, and neither reaches the index.
- **`--literal-pathspecs` turned out to be load-bearing, which the mutation testing is what
  found.** Removing it survived at first. Git prefers an exact match when the named file
  exists — which is almost always, since every path here came from Git — but a *deleted* path
  has no file to match, and there Git falls back to reading the name as a glob. A task that
  deleted a file named `.en?` would stage the `.env` beside it. Confirmed against real Git
  2.43 before writing the test that now kills it.
- **`--all` on the `git add` is redundant and kept anyway.** The second surviving mutation:
  `git add` has staged removals by default since Git 2.0, well below the supported 2.24, so
  removing the flag changes nothing. It stays because the intention then lives in the command
  rather than in a note about which version changed its mind — and the misleading comment
  claiming it was what made deletions work has been corrected.
- **A move is not followed, and this is documented rather than worked around.** Git pairs a
  rename only in the index, and staging clears the index first, so a move arrives as a
  deletion plus an untracked file. A file moved *out of* an excluded path is therefore staged
  under its new name. A test pins that behaviour so it is known rather than discovered. The
  pairing logic is still there for a rename Git does report, and no path-based rule can stop
  an agent that copies a secret into a file of its own — what stops that is the user's review
  of the commit, which section 6 of the PRD already says the package does not replace.
- **A project that does not ignore what its preparation commands produce will have that
  output committed.** There is nothing here to tell a generated directory from a written one.
  Stated in the class note and in the generated context file, with the staged file list named
  as where it becomes visible.
- **Nothing is persisted to the database yet.** `git_references` has no `stage` kind and no
  store writes to it; a staged summary belongs with the commit that it describes, which is
  item 7. For now the record is the returned `StagedChanges` — serialisable via `toArray()` —
  and the lines written to the attempt's log sink.
- **Nothing calls the stager yet**, the same boundary items 2 to 5 stop at.
  `WorkspaceStager::forProject()` takes the configuration; the call sits in section G's loop
  between the verification commands passing and the commit being made.

## 2026-11-06 — F7: A commit that says what it is, and a SHA that outlives the run

**Task:** Create task commits with the task ID and run/task identifiers, then persist the
resulting SHA. *(PROJECT.md section F, item 7)*

**What was done**

- `Git\TaskCommitter` turns the staged index of a verified task into a commit on the pass's
  own branch and records the SHA. It is the first step in section F that writes to two places
  which can disagree — the repository and the pass's row — so the order of operations is the
  design: **claim the branch in the row, then create it in Git, then commit, then persist the
  SHA.** A branch reference nothing records is read by `TaskBranches` as unrelated work and
  refused forever; a row naming a branch that does not exist is simply a branch to create.
  Only the harmless direction is reachable from a crash.
- `Git\CommitMessage` is what the commit says about itself: subject `TASK-xxxxxxxx: <the task
  as the checklist words it>`, and a trailer block — `Ralph-Task`, `Ralph-Generation`,
  `Ralph-Run`, `Ralph-Attempt`, `Ralph-Base`, `Ralph-Agent`. Git's own shape rather than an
  invented one, so the identifiers are queryable with the tools a reviewer already has:
  `git log --grep='Ralph-Task: TASK-19E0214A'` finds every pass at one task, and
  `git interpret-trailers --parse` reads them with no parser of ours.
- `Git\TaskCommit` is the result: the SHA, the parent the workspace was on, the branch and
  whether this commit created it, the message, the staged summary, and the row as it stands
  with the commit on it. `Exception\CommitException` is the refusal, and like
  `StagingException` every message says where the work is rather than what to fix first.
- Attaching is done here because a task workspace is checked out `--detach` (section F,
  item 2): `git switch --create` makes the branch at the commit HEAD already points at, which
  moves no file, discards nothing and leaves the index exactly as staging left it.

**Verification**

- `composer test` — **2108 tests, 5910 assertions, green**, up from 2076. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `TaskCommitterTest` uses real repositories, real linked worktrees and a real state store,
  and reads every commit back out of Git rather than from the value that claims it. It covers
  the commit on a created branch with its parent and its attached HEAD, the SHA read back from
  the store, the trailers found by `git log --grep`, a commit containing only what staging
  chose, a pass that has committed refused a second time, an empty index refused, a staged
  summary taken of another directory refused, the project directory refused, an unrelated
  branch refused with its tip unmoved, a row naming another branch refused, a workspace
  already attached to the pass's branch continued on rather than re-created, a branch and a
  workspace that have diverged refused, a workspace on somebody else's branch refused, a
  `pre-commit` hook that says no leaving the work staged and nothing recorded, the sink notes,
  and `commit.cleanup=strip` unable to edit the message. The controlling checkout — HEAD,
  working tree, index — is asserted unchanged.
- `CommitMessageTest` covers the subject, the trailers, the layout, an unknown identifier left
  out rather than written as a zero, a long description shortened in the subject and kept in
  the body, a wrapped description flattened, a description that tries to forge a `Ralph-Task:`
  of its own, control characters removed, and the refusals.
- **Fourteen mutations applied and reverted** (each file copied aside, every revert confirmed
  byte-for-byte). All fourteen were killed: the missing `--cleanup` pin, the missing
  already-committed guard, an unclaimed branch, the missing diverged refusal, `--no-verify`,
  an empty index committed, a SHA never persisted, the project directory allowed, an unusable
  branch allowed, a foreign branch allowed, another workspace's summary allowed, a row/branch
  mismatch allowed, and both halves of the description flattening.
- No agent CLI, no credentials, no network (section J, item 8).

**Notes / deviations**

- **The project's hooks and signing configuration are honoured, not bypassed.** `git commit`
  runs without `--no-verify` and without `--no-gpg-sign`. Skipping a `pre-commit` hook would
  commit what the project has said must not be committed, and the hook is usually the
  project's own verification; the cost is a failure that arrives *after* the checks have
  passed, so the message names the workspace the implementation is still staged in. A signing
  key that needs a passphrase cannot work here at all — nothing this package runs has a
  terminal to ask on — and the hints say so.
- **`--cleanup=whitespace` is pinned**, for the reason `StagedChanges` pins `--find-renames`:
  a project configured for `strip` drops every line beginning with a hash, and a task worded
  `#dry-run: …` would lose it. A trailer block a local setting can edit is not an identifier.
  A test sets `commit.cleanup=strip` and the mutation that drops the flag dies on it.
- **A diverged branch is refused rather than reconciled.** A detached workspace whose branch
  exists at some other commit is refused: nothing here can tell an earlier attempt's commit
  from a worker's or a person's, and forcing the branch, resetting the workspace or committing
  onto a mismatched history are all destructive. That is section F, item 9 and section H,
  item 5's decision, and `TaskCommit::parent()` records what HEAD was so item 9 has something
  to compare against.
- **The SHA lands on the generation's row and nowhere else yet.** `GenerationStore::recordCommit()`
  is the durable record and it refuses to replace one, so a second commit cannot be quietly
  accepted. The `git_references` table still has no writer — the log of branch, commit and push
  events belongs with the runner that performs them in sequence, so it is handed to section G,
  item 1 along with the push of item 8.
- **A pass that arrives with a commit recorded is refused before anything is written**, which
  is acceptance criterion 6 at this layer: resume retries the push or the checklist write,
  never the implementation.
- **Nothing calls the committer yet**, the same boundary items 2 to 6 stop at.
  `TaskCommitter::forProject()` takes the configuration and the generation store; the call sits
  in section G's loop immediately after staging.

## 2026-11-06 — F8: A push that is optional, and a failure that keeps the commit

**Task:** Implement optional ordinary pushes to the configured remote; retain local commits
and publication status when a push fails. *(PROJECT.md section F, item 8)*

**What was done**

- `Git\TaskPublisher` publishes a pass's recorded commit to the configured remote. Almost all
  of it is the half of the item after the semicolon: **a remote that refuses the push is not
  an exception.** It is recorded on the pass as a failed publication with the reason Git gave,
  the local commit is left exactly where it was, and the task stays unchecked because
  `GenerationRecord::isFinalizable()` will not call an outstanding publication settled. That
  recorded reason is what lets section H, item 7 retry the push and nothing before it;
  throwing would lose it.
- **The row decides whether to push, not the live configuration.** A pass records what
  publication it owes when it starts — `TaskPublisher::expectationFor()`, handed to
  `GenerationStore::start()` — and the publisher follows that record. Reading `git.push` again
  at publication time would let a configuration edited mid-run, by a worker even, change what
  a committed pass is judged by; it is the snapshot reasoning section G, item 3 applies to the
  verification commands.
- **The order is the opposite of the committer's, on purpose.** `TaskCommitter` records its
  branch before creating it; publication pushes first and writes the row after. A crash
  between the two leaves a commit on the remote and a row saying `pending`, and the retry is a
  second push of a commit the remote already has — `Everything up-to-date`, exit 0. There is
  no duplicate-commit equivalent to be made here, so the idempotent operation is the one
  allowed to happen twice.
- **What is pushed is the recorded commit, by its object name.** The refspec is
  `<sha>:refs/heads/<branch>`, and the branch's tip is checked against the recorded SHA before
  anything leaves the machine. A branch that has moved on since — a worker's extra commit, an
  amend — is refused, because what this must not do is put a commit nobody here has seen onto
  a remote other people fetch.
- An **ordinary** push: no `--force`, no `--force-with-lease`, no `--set-upstream`, no
  `--no-verify`. A rejection is reported rather than won, a project's `pre-push` hook is the
  project's decision to keep, and an upstream is `branch.<name>.*` configuration in the user's
  repository that this was not asked to write.
- `Git\Repository::runRemote()` is the new command path for anything that talks to a remote:
  `GIT_TERMINAL_PROMPT=0` and an empty `GIT_ASKPASS` turn an unanswerable credential prompt
  into an immediate, explainable failure instead of a ten-minute wait, and it is the one Git
  call here that takes an `OutputSink`, because a push is the one that can legitimately run
  for minutes and is worth watching.
- `Git\TaskPublication` is the result — the row as it now reads, the SHA and branch concerned,
  and whether *this call* pushed — and `Exception\PublicationException` is kept for the cases
  where a push must not be attempted at all: no commit, no branch, a branch that is gone, a
  branch that has diverged.
- A pass that owes a push but recorded a **no-change** result has its obligation discharged
  rather than left owing: there is no commit a remote could be given (section G, item 6), and
  left as it arrived it would never finalise.

**Verification**

- `composer test` — **2127 tests, 6008 assertions, green**, up from 2108. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `TaskPublisherTest` pushes between a real repository and a **real local bare remote** — the
  whole of section J, item 6's apparatus, with no network and no credentials (section J,
  item 8). Every failing case asserts three things rather than one: the commit is still on its
  branch at the recorded SHA, the row says the publication failed and why, and the pass is not
  finalisable. It covers the commit read back out of the remote, the row recording
  `published`, a pass owing no push never touching the remote, an already-published pass not
  pushed again (asserted by removing the remote first, so a second push *could not* succeed),
  a non-fast-forward rejection recorded with the local commit and the remote tip both
  unmoved, an unconfigured remote with the hints that name it, the whole multi-line reason
  Git gave kept on the row, no `branch.*` configuration written, a no-change pass settled, the
  four refusals, the sink notes and the streamed push output, the user's checkout untouched,
  and the expectation drawn from configuration.
- **Fourteen mutations applied and reverted** (each file copied aside, every revert confirmed
  byte-for-byte). All fourteen were killed: both early returns, the no-change settlement and
  the state it writes, the branch-at-commit check, both refusals of a row with half an
  implementation, `--force`, the exit code deciding success, a refusal thrown instead of
  recorded, the reason truncated to its first line, the unconfigured-remote hint, the push
  output not streamed, and `isPublished()` weakened to "settled".

**Notes / deviations**

- **The push runs in the controlling checkout, not in the task's worktree.** Pushing touches
  no working tree and no index — it reads a ref and writes remote-tracking refs, which linked
  worktrees share anyway — and this is what makes publication possible when the workspace is
  gone, which is the state a resumed publication-only retry can be in. A test asserts the
  user's HEAD, working tree and index are unchanged by a push.
- **A credential cannot be asked for, and an SSH passphrase cannot be caught.** `ssh` reads
  from `/dev/tty` rather than from standard input, so neither `GIT_TERMINAL_PROMPT` nor
  `GIT_ASKPASS` stops it; the 600-second timeout is what bounds it. A run that pushes over SSH
  needs an agent or a passphraseless key, and the hints for a rejected credential say so.
- **The SHA refspec and the tip check overlap deliberately.** With the tip check in place, a
  mutation changing the refspec to `refs/heads/<branch>` on both sides is not observable, so it
  is not claimed as killed above — it is the belt to the check's braces, and the thing it
  guards is the gap between reading the tip and pushing.
- **Nothing calls the publisher yet**, the same boundary items 2 to 7 stop at.
  `TaskPublisher::forProject()` takes the configuration and the generation store; the call sits
  in section G's loop immediately after the commit, and `expectationFor()` is read once at run
  start. The `git_references` table still has no writer — the log of branch, commit and push
  events belongs with the runner that performs them in sequence.
- Section F, items 9 and 10 are untouched and own what this refuses: a branch that has moved
  past its recorded commit is reported here and reconciled there.

## 2026-11-06 — F9: Noticing what the worker did to the repository

**Task:** Detect unexpected worker-created commits or branch changes and reconcile or block
instead of silently accepting them. *(PROJECT.md section F, item 9)*

**What was done**

- `Git\WorkspaceAuditor` is the component, and the thing worth stating first is **what silent
  acceptance would actually have cost**. Section F hands an agent a detached workspace and
  expects its work in uncommitted files. A worker that commits instead does not produce an
  error anywhere: staging finds nothing, the pass is recorded as a verified *no-change* result
  (section G, item 6), the box is ticked, and the implementation sits on a reference nothing
  recorded. The failure mode is a green run with the work lost, which is why this is a check
  before verification rather than a diagnostic after it.
- **It is a comparison, not an inspection.** `observe()` reads the workspace immediately
  before the agent is invoked and again after it returns, and `audit()` compares the two.
  There is no single reading that could do this job: a workspace sitting on a commit that is
  not the base is the ordinary state of a resumed pass, and a branch whose tip is not the base
  is most branches in any repository. Only the pair says what *this worker* did.
- `Git\WorkspaceState` is one reading: HEAD, what HEAD is attached to, **every local branch of
  the whole repository**, and whether an operation is half-finished. The repository-wide branch
  map is not scope creep but the only way a commit made *outside* the workspace is visible —
  references are shared by every worktree, so a worker that commits onto `main` in the
  controlling checkout shows up as a branch that moved.
- **Exactly two findings are reconcilable, and `DriftKind` is where that is decided once.** A
  worker's commits on top of the commit the workspace was handed go back with
  `git reset --soft`: one reference is written, no file is restored, nothing is unstaged, and
  the commits stay reachable through the reflog. The pass's own branch name appearing is the
  other — that name was this pass's anyway, so recording the claim is the whole repair.
  Everything else blocks, because every repair for it is `git branch --force`, `git reset
  --hard` or `git branch -D` on work somebody may have and this run has never seen.
- The blocking list is the interesting half: HEAD on a commit that does not descend from the
  one the workspace started at, HEAD on a branch that is not the pass's, a workspace detached
  off the pass's branch, a branch created, a branch moved, a branch deleted, and a rebase,
  merge, cherry-pick, revert or bisect left unfinished. **A branch that was deleted is
  reported with the SHA it was at and never re-created**: restoring it would reverse somebody's
  deliberate act, and the SHA is what lets them do it themselves.
- **One blocking finding blocks the whole reading**, beside any number of reconcilable ones.
  Repairing half a divergence would leave a state neither the user nor the next run could
  reason about, and the reconcilable half is still reported so the message holds the full
  picture.
- The repair order is the committer's: **the recorded branch claim first, the reference
  second.** A reference nothing records is read as unrelated work by the next run
  (`TaskBranches`), while a record naming a branch that is where the pass expects it is exactly
  what ownership wants to find. Afterwards the state is read back out of Git and checked
  against the four invariants the commit step depends on, rather than assumed.
- `Git\WorkspaceAudit` holds both states and the findings and composes the message;
  `Git\WorkspaceDrift` is one finding, carrying **full SHAs and the `git log` that shows them**,
  because a blocked pass leaves commits whose only remaining name may be in that message;
  `Git\WorkspaceRepair` is what a repair did, so that reconciling is a reported event rather
  than a silent correction — a soft reset nobody is told about would be this item's forbidden
  behaviour with an extra step. `Exception\DriftException` is the block, and carries the audit.
- `require()` joins the two halves — audit, then put back or stop — and is the call a run
  makes. They stay separately callable so a diagnostic can look without acting, and are joined
  here so a runner cannot look *without* acting.

**Verification**

- `composer test` — **2151 tests, 6135 assertions, green**, up from 2127. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `WorkspaceAuditorTest` works against real repositories, real linked worktrees and a real
  state store, with the worker played by real `git` commands. The reconciled cases assert the
  file's **bytes** are still on disk, the index still holds the change, and the worker's commit
  is still an object (`cat-file -e`) — "nothing is lost" is the only thing that makes repairing
  legitimate. The blocking cases assert the repository was **not** tidied up: the stray commit
  still on `main`, the created branch still there, the deleted branch still gone, nothing
  claimed on the row. One case runs the whole sequence end to end — a worker's commit put back,
  then staged and committed by the pass, with the pass's own commit on the base as its parent.
- Covered: a worker that only edits files, one commit, two commits counted, the pass's branch
  created and switched to, the pass's branch created without switching, the pass's branch
  created *somewhere else* (blocked, not claimed), a commit onto the user's `main`, a workspace
  switched to a stranger's branch, a branch invented, a branch deleted, HEAD moved off its
  history, a half-finished merge, a merge left unfinished in the *user's* checkout not being
  attributed to the workspace, a workspace detached off the pass's branch, a reconcilable
  finding beside a blocking one, a row that already names another branch, a reading taken of
  another workspace, the project directory refused as a workspace, three mismatched passes, a
  workspace that has gone, and a repository whose references cannot be read.
- **Nineteen mutations applied and reverted** (each file copied aside, every revert confirmed
  byte-for-byte). Eighteen were killed: `--soft` weakened to `--mixed` and to `--hard`, the
  blocking refusal removed, a non-descendant HEAD treated as a worker's commit, the branch
  comparison skipped entirely, the pass's branch always said to follow HEAD, the claim never
  recorded, HEAD never restored, a deletion ignored, the pass's branch claimable anywhere, any
  attached branch treated as the pass's, `HeadMoved`/`BranchMoved` declared reconcilable, a
  clean reading called reconcilable, a row re-pointed to another branch, the unfinished-
  operation finding dropped, the markers read from the shared Git directory instead of the
  worktree's, operations never detected, attachment never compared, the project directory
  accepted, and three mismatched passes accepted.

**Notes / deviations**

- **The one mutation that survived** is the guard on an unreadable `for-each-ref`. A corrupted
  `packed-refs` does make Git fail, and there is a test for it, but `rev-parse HEAD` notices
  first, so the branch-list refusal is reached by no test. `ProcessRunner` is final and nothing
  in the suite injects a fake one, so this would need a seam that does not exist yet; it is a
  guard rather than a path.
- **Detection is bounded, and the boundary is written into the classes rather than implied.**
  Tags, notes, remote-tracking refs, stash entries, reflog rewrites, `git gc`, a submodule's
  own repository, and anything a worker did and then undid are all invisible here. What is
  covered is the state the rest of section F reads. A worker determined to hide what it did
  still can, and the review a user gives the commit remains what catches the rest.
- **`git reset --soft` is the only write this can make**, besides the one state-store claim. No
  path through the class reaches `--hard`, `--mixed`, `branch --force` or `branch -D`, which is
  the same refusal `TaskBranches` makes about a branch it does not own, applied to the moment
  after a worker has run.
- A workspace that was **already** mid-operation when the worker was handed it is refused too,
  even though the worker did not cause it. Staging and committing in a repository with
  `MERGE_HEAD` set is unsafe however it got that way, and the message says what is in progress
  rather than blaming anybody.
- **Nothing calls the auditor yet**, the same boundary items 1 to 8 stop at. The call sits in
  section G's loop immediately after the adapter returns and before verification:
  `observe()` before the invocation, `require()` after. Section F, item 10 — preserving and
  cleaning up failed and interrupted worktrees — is next, and owns what a blocked pass leaves
  behind.

## 2026-11-06 — F10: A workspace kept because somebody may need to look at it

**Task:** Preserve failed and interrupted worktrees for inspection; provide documented cleanup
behavior that protects uncommitted work and completed branches. *(PROJECT.md section F,
item 10)*

**What was done**

- `Git\WorktreeCleaner` is the component, and the item asks for **two things that pull against
  each other**. A failed or interrupted pass's workspace is evidence — the half-written
  implementation, the index as the worker left it, the file that explains the verification
  failure — and it is what `ralph logs` points at and what a retry continues in, so a run
  moving on to the next task must not take it. At the same time a package that never removes
  anything leaves a full checkout per task under `.ralph/runtime/worktrees/`, and the person who
  finds out is the one who runs out of disk.
- So removal is **conjunctive and narrow**. A workspace goes only when this pass *created* it,
  the pass is *closed*, the path is one `WorktreeLayout` hands out, Git has it *registered* and
  *unlocked*, there is *nothing uncommitted* in it, its HEAD is the commit the row records, and
  that commit is *held by the pass's branch*. Seven conditions, any one of which keeps it.
- **The branch check is what "protects completed branches" actually means.** Once the workspace
  is gone the only thing keeping a task's commit reachable is the branch pointing at it — so the
  branch is *asked*, not assumed, to exist and to contain the recorded SHA before the worktree
  that also referred to it is removed. A commit held by nothing but a worktree HEAD is one
  `git gc` may collect after that worktree goes, and this is the check that stops the package
  from being the thing that arranged it. Nothing here ever creates, moves or deletes a
  reference: cleanup's whole duty towards a finished pass's branch is to leave it where it is.
- `Git\RetentionReason` is the ten reasons to keep one, and the enum is deliberately **asymmetric
  — there is no case that argues for removal**. Three are about ownership (adopted, not our
  path, not registered), three about work that would be lost (uncommitted changes, a commit the
  row does not name, a commit no branch holds), one is the item's headline (the pass has not
  finished), and three are Git declining or not answering. Each carries `describe()` and
  `hint()`, so a preserved directory a user does not recognise comes with both why it is there
  and what to do about it.
- `Git\WorkspaceRetention` is the decision and `Git\WorktreeCleanup` the event, and they are
  separate because they can disagree in the one direction that matters: a workspace judged
  removable that Git then declines is reported as kept, with Git's words, where a caller holding
  only the decision would have claimed a directory had gone. `Git\RetentionVerdict` keeps
  *removable*, *preserved* and *absent* apart — "do not remove it" and "there is nothing to
  remove" are different facts, and collapsing them would make a tidied run look like it had left
  work behind.
- **`assess()` writes nothing and raises nothing.** Every command is a `Repository::query()`, so
  a diagnostic can report where each preserved workspace is without taking the index lock of a
  repository the user has open, and every unanswerable question becomes a reason to keep rather
  than an exception — silence read in the only safe direction.
- **Cleanup never fails a pass.** It runs after verification, the commit and any push, so nothing
  it finds is grounds to withdraw work already earned: a refusal, an unreadable workspace, a
  directory that will not go away each become a line in the log. The single exception is a state
  store that will not record a removal already carried out, which is a real inconsistency.
- The only write is **`git worktree remove` without `--force`**, issued from the project
  directory rather than from inside the directory being deleted. Keeping Git's own refusal is a
  second opinion on the decision just made rather than an obstacle to it — the flag that
  overrides it is the flag that deletes somebody's files. The one case acted on without reading
  the workspace first is a **registration whose directory has already gone**: nothing in it can
  be lost, and the leftover record is what makes `git worktree add` refuse the path next time.
  That is reported as a cleared registration, not as a removal, so a run tidying up after
  somebody else's `rm -rf` does not claim to have deleted their files.

**Verification**

- `composer test` — **2193 tests, 6316 assertions, green**, up from 2151. PHPStan level 8 over
  `src` and `tests`: no errors. php-cs-fixer at `@PSR12`: nothing to fix.
- `WorktreeCleanerTest` works against real repositories and real linked worktrees, with a real
  state store, and every case is one of two assertions. Where a workspace is kept, the test
  asserts the **bytes are still on disk** and the registration is still Git's — a preserved
  workspace whose contents went anyway is not preserved. Where one is removed, it asserts the
  **branch is still at the same SHA, the commit is still an object, and the row still names
  both**: taking the deliverable with the workspace is the one unrecoverable mistake available
  here.
- Covered: a completed pass removed with its branch left alone, a verified no-change pass, a
  skipped pass, a stale registration cleared without deleting anything, ignored files going with
  the workspace, a workspace that becomes removable once the reason it was kept is gone, a failed
  pass kept with the agent's bytes intact, an interrupted pass kept with its *index* intact, a
  blocked pass, a running pass, an adopted workspace, a row naming the project directory, a
  directory Git has never heard of, an untracked file, a modified tracked file, seven changes
  counted and summarised, a commit the row does not name, a branch that was never created, a
  branch that does not contain the commit, a commit with no branch at all, a worktree locked with
  and without a reason, a repository whose references cannot be read, a pass that never had a
  workspace, a path that has entirely gone, a missing directory of an *unfinished* pass keeping
  its registration, a sweep that removes one and keeps another, and the refusals each value type
  makes about a decision it cannot honestly represent.
- **Sixteen mutations applied and reverted** (each file restored and confirmed byte-for-byte),
  and **all sixteen were killed**: each of the seven conditions dropped in turn, a recorded
  branch assumed to exist, a commit with no branch accepted, the row left pointing at a removed
  directory, a stale registration cleared regardless of the reasons to keep it, HEAD compared
  against the commit but never the base, an unreadable repository reported as nothing to clean
  up, the reasons no longer written to the log, and `--force` added to the one command.

**Notes / deviations**

- **`--force` needed a different kind of test.** It survived as an ordinary mutation and would
  always have: `assess()` already refuses every workspace Git itself would refuse, so no
  situation any test can construct gets as far as the command unless Git would have agreed
  anyway. The flag is therefore unobservable from outside and still the most dangerous thing
  that could be added, so it is pinned against the class's **own source** — one test reads
  `WorktreeCleaner.php` with its comments stripped and asserts that `--force`, `-f`, `branch`,
  `reset`, `prune`, `checkout` and `clean` appear in no Git argument. `DeclaredRequirementsTest`
  is the precedent for checking a promise the runtime cannot be made to break.
- **`git worktree remove` deletes what it can before reporting a failure.** Confirmed against
  real Git: a removal blocked on an unwritable parent wiped the workspace's contents, dropped the
  registration, *and* exited non-zero. So `RemovalRefused`'s hint sends a person to look at the
  path rather than promising the workspace is as it was, and says plainly that the pass, its
  commit and its branch are untouched either way.
- **Ignored files go with the workspace, and that is a decision rather than an oversight.**
  `git worktree remove` deletes untracked-but-ignored files silently — a prepared `vendor/`, a
  build cache, a `workspace.copy`'d `.env`. Dependencies are reinstalled by preparation and a
  copied credential's original is still at the project root, which is what makes it acceptable;
  it would not be acceptable for anything a person put there by hand, which is why an
  *untracked* file is enough to keep a workspace. There is a test asserting the `vendor/` goes.
- **Registered worktrees under the package's root that no generation names are never touched.**
  An unrecorded directory is one nothing here can reason about, and `git worktree list` is where
  a user sees it. `sweep()` takes the generations a caller gives it and decides each
  independently, so a kept workspace never stops the next from being removed.
- **The `isMain()` guard is reached by no test**, because the layout check refuses the main
  working tree's path first. It is kept because of what it guards: of every directory in the
  repository, that is the one whose removal takes the user's own work with it.
- **Documentation is the class docblocks, as it was for F5.** The generated `CONTEXT.md` is the
  agent's brief and gets replaced by the user's own prose, so package behaviour does not belong
  in it; the user-facing half of "documented cleanup behaviour" is what `RetentionReason::hint()`
  prints at the moment it applies. The README gathers all of it — section J, item 9.
- **Nothing calls the cleaner yet**, the boundary every item in section F has stopped at. The
  call sits in section G's loop at the end of each task, after finalisation: `clean()` with the
  pass's final record, whose verdict is "removed" for a completed task and "kept for inspection"
  for everything else. Section F is complete; section G, item 1 — the sequential loop itself —
  is next, and is the first item that joins all of this together.

## 2026-11-06 — G1: The loop, joining up everything built so far

**Task:** Implement the sequential loop that selects a pending task, prepares its workspace and
context, invokes the adapter, verifies results, commits, optionally pushes, and finalizes
progress. *(PROJECT.md section G, item 1)*

**What was done**

- This is the first item that **writes almost no new behaviour**. Sections C to F each ended at a
  boundary, deliberately: a parser that reads a checklist nothing selected from, a worktree
  nothing created, a committer nothing called, a cleaner the previous entry closed by noting that
  "nothing calls the cleaner yet". This item is the call sites. What it owns is the *order*, the
  state written between steps, and the decisions — and `Run\Runner` and `Run\TaskPass` between
  them implement Git, run a check and talk to an agent exactly nowhere.
- **The split is between-tasks and within-a-task.** `Run\Runner` settles everything that must
  hold still before the first task is looked at (checkout inspected, agent resolved, base
  resolved to a SHA, checklist read and recorded, checks frozen, run row opened) and then owns the
  order, the iteration count and the decision to carry on. `Run\TaskPass` is one task from an
  empty workspace to a ticked box, as the six phases of section D, item 3 in sequence —
  preparing, executing, verifying, committing, publishing, finalizing.
- **The seven verbs are a sequence of conditions, not a sequence of steps.** A zero exit code from
  the worker buys the work a chance at verification and nothing else: the box is ticked only once
  the frozen checks passed, a commit or a justified no-change result is recorded, and any required
  push succeeded. `TaskResult::wasChecked()` is kept deliberately separate from the pass being
  complete, because ticking the box is a write to a file the user may own — so the loop can report
  "done, but the checklist still says otherwise" instead of implying one from the other.
- **A failed task does not end the run; a blocked one does.** Failing checks leave a retriable
  task and the tasks after it are independent work (section 2's assumption), but a task that
  stopped to ask a person a question is waiting on that person, and handing the next task to the
  same agent would be answering it by ignoring it.
- **Every exit records what it found.** The pass body sits in a `try` whose `catch` records the
  refusal with the phase it happened in and a blocker naming the next action, and whose `finally`
  writes the attempt up and sweeps the workspace. `Runner::run()` closes the run row with the
  reason before any failure goes on to the caller: a run left recorded as `running` by a process
  that has gone is precisely the state resume cannot tell from a live one.
- `Run\Journal` groups the five stores over one connection and one clock, and
  `Run\Workbench` assembles the ten Git/checklist/context components once per run from the
  configuration the run froze — so a worker editing `.ralph/config.json` mid-run cannot change the
  base reference, the branch prefix, the push remote or what counts as uncommittable for any task
  the run has left.
- New state: `State\RunRecord` and `State\RunStore` (one row per invocation of the loop, closed
  once — the first stop reason is the true one and a `finally` must not be able to replace it with
  something blander), and `State\VerificationStore`, which is what
  `AttemptStore::finish()` already reads to refuse a `completed` attempt no passing check
  supports. The sequence is stored whole in one transaction, because a half-written one reads as
  "check 2 failed, check 3 passed" and no reader could tell it from a sequence that really did.
- `Verification\Verifier` is introduced here as an **interface**, with
  `VerificationReport`, `CheckRun` and `CheckOutcome` as its values. The checks are *passed in*
  rather than read from configuration, which is what makes section G, item 3's freeze real. A
  failing check is a returned report; an exception from a verifier means the checks could not be
  run at all, which the loop treats as a failed task rather than a passing one.
- `Schema::CHECK_OUTCOMES` became `Schema::checkOutcomes()`, built from the new `CheckOutcome`
  enum, joining the six vocabularies already derived from their enums so the column and the type
  cannot drift.

**Checks**

- `vendor/bin/phpunit`: **2208 tests, 6450 assertions, all passing**, 40 of them the new
  `RunnerTest`. PHPStan at level 8 over the new files: no errors.
- `RunnerTest` runs the loop against a **real repository with real linked worktrees, the fixture
  worker started through the Codex adapter as an actual executable, the real SQLite store and a
  real Markdown checklist on disk**. The one substitution is the verifier, because a check that
  really ran would make every case depend on a test suite being installed inside a temporary
  worktree. No agent CLI, no credentials, no network (section J, item 8).
- Four assertions recur, each standing for a way the loop could do real harm: the checklist is
  **read back off disk**, because a box ticked for work that did not pass is the one lie a user
  cannot catch; the repository is read with **`git log`**, because a result object claiming a
  commit is not a commit; the **controlling checkout** is asserted against a before-snapshot; and
  the **state store is read back** rather than the returned report, because the report dies with
  the process and the rows are what `status`, `logs` and `resume` will believe.
- Covered: the whole happy path end to end, checks running against the workspace the agent
  actually changed, file order with checked items skipped, a failing check leaving the task
  unchecked and the work kept, a second run continuing the pass and being told what failed, an
  agent asking for human input blocking the task and stopping the run, a failed task leaving the
  loop free to take the next one, an already-satisfied task completing with no commit, the
  iteration limit, an empty checklist, unrelated uncommitted changes refusing the run before it
  starts, checks that cannot be run failing the task rather than passing it, the run row recording
  the agent and base and frozen checks, a completed workspace removed while a failed one is kept,
  and each attempt leaving a memory note behind.

**Notes / deviations**

- **One test asserts a relaxed checkout snapshot, and the relaxation is the point.** The run does
  make exactly one change to the user's checkout — the checkbox edit — and the test now asserts
  HEAD and the branch are where they were and `.ralph/tasks.md` is the only dirty path. Section 4
  requires the runner never to *commit* the controlling checkout's progress edits; leaving the
  edit uncommitted in the working tree is the correct outcome, and that test commits `tasks.md`
  as the user's own tracked file precisely to exercise it.
- **A test helper named `run()` could not be named that.** `PHPUnit\Framework\TestCase::run()` is
  final, and overriding it is a fatal error at class-load time rather than a failure — the suite
  did not run at all. It is `loopOver()` now.
- **The real verifier is not here.** Section G, item 2 — running the configured commands
  independently of the worker, capturing each command, its output, exit code and timeout — is the
  next item, and it is the implementation of this interface. Until it lands, nothing in the
  package constructs a `Verifier`, so `ralph run` is still a `PlannedCommand`: wiring the CLI
  commands to the real runner is section I, item 1.
- **Attempt limits, the run-time budget, the repository lock and Ctrl+C are section H**, and the
  loop enforces only the iteration limit so far. `Runner::loop()` already counts iterations and
  accumulates active seconds on the run row, which is where those limits will read from.

## 2026-11-06 — G2: The checks, run by something that is not the worker

**Task**

PROJECT.md section G, item 2: run the configured verification commands
independently of the worker, capturing each command, output, exit code, and
timeout. The previous iteration left `Verification\Verifier` as an interface
with nothing in the package implementing it; this is the implementation, and the
thing a task's completion is now actually decided by.

**What it does**

- `Verification\CommandVerifier` runs the frozen checks through the existing
  `Process\ProcessRunner` — argument arrays, no shell, explicit working
  directory — one after another in the task's worktree, and turns each one into
  a `CheckRun`: the command as executed, where it ran, the exit code, the
  duration, the timeout it was held to, started/finished timestamps from the
  injected `Clock`, and an excerpt of its output.
- The sequence stops at the first failure and the checks behind it are recorded
  as `not_run` rather than silently dropped. The test proves this is *not
  started* rather than *run and ignored*: the skipped check would have written a
  file into the workspace, and the file is not there.
- A timeout is its own outcome, not a failure with a missing exit code. A check
  killed at its limit keeps whatever it printed first, which is usually the
  explanation, and its `exitCode()` is null because it never reported one.
- Output goes two places at once, which is what `ProcessRunner`'s sink parameter
  was built for: streamed to the caller's `OutputSink` as it arrives — for a run
  that is the attempt's log and the terminal — and collected for the bounded
  excerpt the state store keeps and the next attempt is told about. Each check is
  announced and summarised through `note()`, so what the package says stays
  distinguishable from what a command printed.
- `Exception\VerificationException` is the new one, and the distinction it exists
  for is the point of the whole item: **a failing check is a returned report, a
  check that could not be run is raised.** A missing `phpunit` reported as a
  failed check would accuse work nobody examined, send the agent back to fix a
  suite that never ran, and burn the task's attempts. So the executable is
  resolved with the existing `ExecutableFinder` *before* the process is started —
  against the workspace, not the project root, because `vendor/bin/phpunit` is
  exactly the case where those two answers come apart — and an unstartable
  command, a missing workspace, or an empty check list all refuse instead.

**Checks**

- `vendor/bin/phpunit`: **2226 tests, 6516 assertions, all passing**, 18 of them
  the new `CommandVerifierTest`. PHPStan level 8 over all of `src`: no errors.
- The tests use real subprocesses throughout, as `ProcessRunnerTest` does and for
  the same reason: a doubled process would only assert that this class hands its
  own values back to itself. Every command is a short program run through
  `PHP_BINARY`, so the suite still needs nothing installed that it is not already
  running under, and no credentials or network (section J, item 8).
- Covered: every check run in order, the command/exit code/timeout/directory
  captured, the checks reading what the agent left in the workspace, a non-zero
  exit as a failure, the stop-at-first-failure sequence with the rest unreached,
  a real 1-second timeout killing a 30-second command, both streams in the
  excerpt, a 12,000-character output keeping the assertion at its end, streaming
  and notes into a sink the verifier does not close, timestamps from a frozen
  clock, the failing output arriving in `FailureEvidence`, a missing program and
  a missing relative program refused, a workspace-provided `vendor/bin` program
  run, no checks refused, a missing workspace refused, and the terminal lines for
  a passing and a failing sequence.

**Notes / deviations**

- **No per-check log file.** `LogDirectory` anticipated `attempt-02-check-tests.log`
  and `CheckRun` carries a `log_path`, but the `Verifier` interface is handed the
  checks, a directory and a sink — not a generation and an attempt number — so it
  cannot name one, and widening the interface to pass a naming collaborator
  through the loop would buy a second path pointing at a file that already exists.
  The full output goes to the attempt's log through the sink, and the attempt row
  already records that path. `log_path` stays null and stays available.
- **The checks inherit the environment.** No overrides are applied: PATH, HOME
  and whatever a project's suite needs are where a user's own invocation finds
  them, and inventing a different environment here would make a check that passes
  by hand fail under the runner for reasons neither run would explain. Standard
  input is the null device, so a check that stops to ask a question sees
  end-of-input instead of holding the run open until its timeout.
- **Serial, deliberately.** The first failure is supposed to stop the sequence,
  and two suites writing to one workspace's caches at once is a flake nobody can
  reproduce.
- **Still nothing constructs it.** `Runner` takes a `Verifier` as a required
  argument and `ralph run` is still a `PlannedCommand`; wiring the CLI commands to
  the real runner and this verifier is section I, item 1. Next is section G,
  item 3 — snapshotting the verification configuration at run start — which
  `Runner::loop()` already does in memory for the pass and which now needs the
  frozen set recorded on the run row.
- **`php-cs-fixer` has no committed configuration in this repo**, and running it
  without one makes it generate a `.php-cs-fixer.dist.php` using `@auto` plus a
  `.gitignore` edit. Its rules disagree with the style of every existing file
  (`fn (` spacing), so the generated config and the ignore edit were removed
  rather than committed; the new files follow the surrounding code.

## 2026-11-06 — G3: The checks a run is held to, frozen where a worker cannot reach them

**Task**

PROJECT.md section G, item 3: snapshot the verification configuration at run
start so worker edits cannot silently replace the required checks. `Runner` was
already handing one in-memory list of checks to every pass, and the run row was
already storing them as JSON; what was missing is that the stored set was never
read back, so nothing in the package actually depended on the snapshot, and an
edit to `.ralph/config.json` went unmentioned.

**What it does**

- `Config\VerificationSnapshot` is the frozen set: taken from a `Configuration`
  at the run's start, written to the run's row, and read back from the row by
  everything that runs or reports a check. It keeps the configured order,
  because order is behaviour — the first failure stops the sequence — and it
  refuses two things outright: an empty set, which would let a run tick a box
  for anything, and two checks sharing a name, which is how a check is referred
  to in status output, in logs and in a drift message.
- `RunStore::open()` now freezes through the snapshot, which makes that INSERT
  the single place a run's checks are decided. `RunRecord::verification()` reads
  them back, and `Runner::loop()` takes its checks from there rather than from
  the `Configuration` the process was handed. `TaskPass` takes a
  `VerificationSnapshot` instead of a `list<VerificationCommand>`, so the type
  itself now says these are the run's frozen checks and not any list someone
  assembled.
- `VerificationCommand::fromStorage()` reads a stored check back through the
  same `RawConfiguration` reading the configuration file uses, so a round trip
  preserves argument boundaries and timeouts and a stored row that does not
  describe a command is **refused**. Carrying on with a different set — or with
  none — is the exact failure the item exists to prevent, so this raises rather
  than falling back. It is built on demand rather than in `RunRecord::fromRow()`
  so that an unreadable row refuses only the caller about to verify something
  with it; `status` can still list the run.
- `Config\VerificationDrift` is the other half: freezing makes a worker's edit
  unable to *replace* the checks, and this makes it unable to do so *silently*.
  After each task, `Runner` re-reads the configuration file and compares it
  against the frozen set, reporting removals, additions, per-name edits, and a
  reordering — reported only when the set is otherwise identical, since a
  removal already moves everything behind it and saying so twice would describe
  one edit as two. The notice names the checks still in force, is printed once
  rather than after every task behind the edit, and reaches `RunReport`
  (`verificationDrift()`, `details()`, `toArray()`) so it outlives the terminal.
- A configuration that no longer parses is drift too, not a failure. A worker
  that writes broken JSON over the file has changed what the next run will read,
  and reporting that as "no change" because the comparison could not be made
  would be the silence this item is about — but the checks this run is held to
  were frozen before the file was touched, so ending the run would throw away
  verified work over a file it is no longer reading.

**Checks**

- `vendor/bin/phpunit`: **2245 tests, 6591 assertions, all passing** (19 new).
  PHPStan level 8 over all of `src`: no errors.
- `tests/Config/VerificationSnapshotTest.php` covers the freezing and the
  comparison: order preserved, the storage round trip exact (including an
  argument with a space in it and a non-default timeout), the digest responding
  to a renamed check, a changed command and a changed timeout, an empty set and
  a duplicated name refused, a stored check with no command and one with an
  unknown setting refused, and every drift shape — unchanged, replaced,
  edited, shortened timeout, reordered, a removal that is not also a reordering,
  unreadable, and the prose and the data forms of each.
- `tests/Run/RunnerTest.php` adds the two cases that matter end to end, both
  with a real repository, a real worktree and the fixture worker writing
  `../../../../.ralph/config.json` — the project's own configuration, four
  directories above a task workspace. A worker that replaces the suite with
  `true` mid-run: both tasks are still verified against the frozen set
  (including the one taken on after the file was rewritten), the row still names
  it, the edit is printed once with the checks in force, and the file does now
  say what the worker wrote — which is what the *next* run will be held to. And
  a worker that leaves the file unparseable: the task still completes, the run
  still ends `completed`, and the drift is reported as unreadable.
- These cases are configured from a committed `.ralph/config.json` loaded with
  `ConfigurationLoader::load()` rather than from the in-memory settings the rest
  of the file uses, because a worker's edit is only a worker's edit if there is a
  real file to edit.

**Notes / deviations**

- **The drift is reported, not recorded.** It reaches the terminal and the
  `RunReport`, but no column holds it: adding one is a schema migration, and the
  durable half of "the checks changed" belongs with section G, item 5 (blocking
  when detected changes weaken existing verification) and section I, item 3
  (`status --json` and structured events), which are the next items that would
  read it. What *is* durable is the part that decides anything: the frozen set
  on the run's row.
- **An edit already reported stays reported**, even if the file is put back
  before the run ends. A file restored after the fact does not unmake the edit,
  and the report is a record of what happened rather than a last look at the
  file.
- **The snapshot lives in `Config`, not `Verification`.** It is a frozen
  configuration value with no I/O and no state, and `State` and `Verification`
  both need to name it — `State` to freeze and read it back, `Verification` to
  be handed it. Putting it beside `VerificationCommand` keeps the dependency
  direction the package already has (`Config` ← `State`, `Config` ←
  `Verification`) instead of introducing a cycle between the last two.
- **Nothing is compared by digest.** `digest()` exists for status lines and logs
  and is documented as display-only; `compareTo()` is what answers whether a set
  changed, so a shortened hash never decides anything.
- **Still nothing constructs the runner from the CLI.** `ralph run` remains a
  `PlannedCommand`; wiring the commands to the real runner, the verifier and
  this snapshot is section I, item 1. Next is section G, item 4 — feeding
  bounded verification-failure evidence into the next attempt — which
  `TaskPass` already does through `FailureEvidence` and which that item should
  hold to a stated bound.

## 2026-11-06 — G4: Trying again on a failing check, with the failure as evidence

**Task**

PROJECT.md section G, item 4: feed bounded verification-failure evidence into
the next attempt, and leave the task unchecked while verification fails. Both
halves had machinery behind them and neither was finished. The evidence was
stored, rendered and read — but "the next attempt" never arrived, because a
pass made exactly one attempt and the loop moved on, so `attempts_per_task`
could only be reached by a user running `ralph run` three times. And the
evidence that *was* fed forward came from the newest attempt row alone, which
drops a failing check the moment one intervening attempt records nothing.

**What it does**

- **`TaskPass::execute()` is now a loop over attempts.** While the configured
  checks decide against the work and the task's budget holds, the pass opens a
  new attempt log, makes another attempt, and hands it what the last one ran
  into. The box is not ticked by any of them, which needed no code: ticking is
  what `finalize()` does, and a failing verification returns before reaching it.
- **One outcome is retried and the rest are not**, which is
  `TaskResult::invitesAnotherAttempt()`: a candidate implementation the checks
  decided against, with no commit to its name. It is the only outcome where the
  runner itself holds evidence of what is wrong — a command, its status, its
  output — which is what makes a second attempt a different attempt rather than
  the same one again. An agent that asked a question is waiting on a person; an
  execution error, a refused branch or drift the auditor would not put back left
  nothing to act on; a commit the remote would not take is already implemented,
  and repeating the implementation is the one thing resume must not do.
- **`State\EvidenceTrail` is the bound, stated in one place.** At most two
  attempts contribute, newest first, looking past up to six rows so that an
  attempt which observed nothing does not spend the budget on having happened;
  at most `FailureEvidence::ENTRIES` observations survive in total, each under
  its existing label and detail bounds; and everything left out is counted,
  including what the bound on the stored column had already dropped
  (`FailureEvidence::alsoOmitted()`). Observations are attributed to the attempt
  that saw them only when more than one attempt is quoted — with a single source
  the briefing's heading already says whose they are.
- **Retrying reuses the workspace the failed attempt left.** That is what
  section F, item 10 kept it for, so the agent fixing a failing check starts from
  the work that failed it rather than from an empty checkout, and whatever
  survives into the final attempt is part of the commit.
- `TaskPass::ownershipOf()` keeps the pass's own answer about that workspace.
  Preparation can only report having *adopted* a directory that is already
  there, and recording that would hand the pass's own workspace to nobody:
  cleanup removes only what a pass created, so a task that passed on its second
  attempt would leave a checkout behind where one that passed on its first did
  not. Not a guess about the directory — it is what this pass wrote when it made
  it.
- **The run's time budget is now read from the run's row**, not from the
  `RunRecord` the pass was constructed with. That copy is taken before the first
  task and never changes, so every pass of a long run was being offered the
  whole budget again; the row is what the loop has been adding each task's time
  to. Within a pass, time since the row was last written is measured with the
  run's `Clock` and added, because no row holds it yet and it is the only thing
  keeping the run's bound true between two attempts.
- `TaskPass::failing()` replaces two `isOpen()` guards. `failed` is an open
  state — a failed task can be attempted again — but it is not one a pass can
  move to from itself, so a task passed over for a spent budget *after* a failing
  attempt used to raise `A task that is failed cannot become failed`. It was
  unreachable before this change and is the ordinary end of a retried task now.
  The row keeps its first blocker, which is the true one: what the checks said,
  not that the runner then declined to try again.
- A line is printed between attempts naming the failure and the attempt that
  follows. A second worker starting with nothing to explain it reads as the loop
  having lost its place.

**Checks**

- `vendor/bin/phpunit`: **2257 tests, 6647 assertions, all passing** (12 new).
  PHPStan level 8 over all of `src`: no errors.
- `tests/State/EvidenceTrailTest.php` covers the bound and the reason for it:
  one attempt quoted unattributed, several attributed newest first, an attempt
  that observed nothing read past rather than treated as the end of the trail,
  attempts older than the bound not read at all (and not counted as dropped
  observations, which they are not), the total held to `FailureEvidence::ENTRIES`
  with the remainder announced in the Markdown, and a stored set's own omissions
  surviving the fold.
- `tests/Run/RunnerTest.php` adds the two end-to-end cases, both with a real
  repository, real worktrees and the fixture worker. A check that fails once and
  then passes: two attempts in one run, one commit, the box ticked at the end of
  it, the second briefing containing the first's failure under "Observed by
  Ralph PHP itself", the retry line on the terminal, both attempts' files in the
  commit, and the workspace removed — the ownership case, which fails without
  `ownershipOf()`. And a check that never passes: three attempts, three failed
  attempt rows each carrying the failure, four verifier calls across two tasks,
  the box still `- [ ]`, no commit for that task, the blocker still naming the
  check rather than the limit, and the task after it taken on and finished.
- `tests/Run/RunnerTest.php`'s shared settings now state
  `attempts_per_task => 1`. Left at the default of three, every failing case in
  that file would quietly have become three cases, and how many attempts a case
  makes is something its assertions depend on and should say out loud.
- `a_second_run_continues_the_pass_and_tells_the_agent_what_failed` now raises
  the limit between the two runs. Within one run the retrying above spends the
  budget, so the lever for that case has to be the configuration: what it is
  about is the counter and the evidence outliving a process, not the loop.

**Notes / deviations**

- **Only a failing check is retried.** An execution error is not, although a
  Ralph loop plausibly should retry one. The item names verification, the runner
  has nothing observed to hand a second attempt after an error, and deciding
  what an error deserves belongs with section H, items 2 and 9 — exhausted
  attempts and unresolved decisions — where the stopping conditions are written.
- **The attempt limit is not reported as the reason a task stopped.** When the
  budget runs out between attempts the last verification failure is kept as the
  outcome, because what the task failed on is more useful than the fact that the
  runner stopped trying. The limit is in the attempt count on the row and in
  `budget->describe()` in each attempt's log; a line of its own in the result
  belongs with section I, item 2.
- **The retry is not announced as a structured event.** It reaches the terminal
  and the attempt logs only; `RunReport` still carries one result per task.
  Events with a sequence and a schema version are section I, item 3, and an
  event emitted now would have to be re-emitted there.
- **Nothing resumes mid-retry.** A run interrupted between two attempts leaves
  the pass as the last attempt recorded it, which `resume` will have to
  reconcile — section H, item 5. The counters it will need are already correct,
  since every attempt is a row before the agent is invoked.
- **Still nothing constructs the runner from the CLI.** `ralph run` remains a
  `PlannedCommand`; that is section I, item 1. Next is section G, item 5 —
  blocking when detected changes weaken existing verification — which is the
  other half of the frozen-checks work and the first item that has to look at a
  diff rather than at a verdict.

## 2026-11-07 — G5: Refusing a green run whose diff deleted the test

**Task**

PROJECT.md section G, item 5: block when detected changes weaken existing
verification, preserve the diff for review, and document that comprehensive
detection is not guaranteed. The other half of item 3. Freezing the checks
stops a worker choosing *which* commands judge its work; nothing stopped it
choosing what those commands then find. Delete the test that would have failed
and the suite is green, the run is honest, and the task is checked off having
proved nothing.

**What it does**

- **`ChecksAuditor` reads the staged diff between staging and the commit**, and
  `TaskPass` stops the task when it finds something. The position is the whole
  design: after the checks, because a diff that failed them is already refused;
  after staging, because a test file the stager excluded is not in the commit
  and has weakened nothing; before the commit, because the only useful answer
  is one given while not committing is still an option.
- **Six findings, in three groups.** The tests themselves — a test file deleted
  or renamed out of the suite, fewer test cases, fewer assertions, a
  `markTestSkipped` added. What runs them — an `<exclude>` added to
  `phpunit.xml`, a path removed from `phpstan.neon`. And the command itself — a
  diff that rewrites the `bin/check` a frozen check executes, or the
  `composer.json` script it invokes.
- **Blocked, not failed.** A failing check is evidence about the work and the
  next attempt is given it; this is not — the checks passed. Whether a diff
  that deletes a test is the change the task asked for is not a question more
  attempts answer, so the pass blocks, the run stops, and
  `TaskResult::invitesAnotherAttempt()` needed no change to leave it alone.
- **The patch is kept at `attempt-NN-staged.diff`**, beside the attempt's own
  logs, because they are read together. `LogDirectory::stagedDiff()` is the
  path; `.diff` rather than `.log` so an editor highlights it and `git apply`
  takes it. Written only when there is something to review — a run that kept a
  patch per task would fill the directory with diffs nobody asked for.
- **`StagedPatch` and `PatchedFile` are the reading.** One `git diff --cached`
  with its interpretation pinned the way `StagedChanges` pins its commands:
  `--find-renames` so a repository with `diff.renames=false` does not report
  every rename as a deleted test, `--no-ext-diff`/`--no-textconv` so a diff
  driver a worker configured cannot decide what the package reads,
  `core.quotePath=false` so a path matches the one the staged summary named.

**Why it is shaped this way**

- **The bias is toward reporting, and it is stated out loud.** The reading has
  no parser, does not run the old suite against the new code, and measures no
  coverage; it matches the shapes test code has in a diff's added and removed
  lines. So it misses an assertion inverted rather than removed, a fixture
  emptied, a mock that now returns whatever it is asked — and it reports a task
  that was *asked* to delete an obsolete test. A finding costs a person a patch
  to read; a miss costs the guarantee the item exists for.
- **The documentation requirement is met where a user meets the limit.**
  `ChecksAudit::hints()` ends with it in plain words, so the sentence is printed
  with every refusal and stored in the blocker's next action. A limitation
  stated only in a docblock is stated to nobody.
- **"Existing" verification is taken literally, and that is what makes the
  rules usable.** Removals count wherever they happen and additions count
  toward the same total, so moving a case between two files nets to zero. A
  disabling marker is only counted in a file that existed before the diff: a
  new test file carrying `markTestSkipped('Git is not installed')` has made the
  suite larger, not weaker — this package's own tests are full of such guards,
  and counting them would have blocked every task that added one.
- **The manifest rule is separate from the configuration rule because an
  ordinary task edits one of them.** Tool configuration is reported when it
  loses lines or gains a narrowing directive. `composer.json` is reported only
  when the declaration of a script a frozen check *runs* changes — because
  adding a dependency to JSON removes the line above the new one to put a comma
  on it, and a rule about lost lines there would block half the tasks a loop is
  for. `scriptsIn()` reads `composer test`, `composer run test`, `npm run test`
  and `make test` down to the script name.
- **Nothing is reconcilable and nothing is edited.** Unlike `DriftKind`, where
  putting a repository back is sometimes mechanical, there is nothing here for a
  runner to put back: re-adding a deleted test, or dropping part of a diff,
  would be the package deciding what the project's suite should contain.
- **A diff that cannot be read refuses.** `StagingException`, the same answer
  `WorkspaceStager` gives an index it cannot read: a commit this package could
  not examine is not one it makes, and a clean audit by default would make every
  unreadable workspace a silent bypass of the item. The work is kept either way.
- **The patch parser is deliberately thin.** Sections split on `diff --git` at
  column zero, lines taken from inside hunks with the marker stripped. Inside a
  hunk every line is prefixed, so a fixture file that *is* a patch cannot be
  mistaken for a boundary — that one property is all the structure needed. A
  section it cannot read contributes no lines, which can only make a finding
  less likely, so an unfamiliar patch shape cannot fail a verified task.

**Checks**

- `vendor/bin/phpunit`: **2289 tests, 6810 assertions, all passing** (32 new).
  PHPStan level 8 over all of `src`: no errors.
- `tests/Verification/ChecksAuditorTest.php` stages real files in real linked
  worktrees and audits what `WorkspaceStager` decided the commit would contain,
  because the item is about a commit and not a workspace. The cases come in
  pairs wherever a rule has an edge, and the second of each pair is the one that
  matters: a deleted test blocks and a test renamed within the suite does not; a
  removed case blocks and a case moved to a new file does not; a skip added to
  an existing test blocks and one in a new test file does not; an `<exclude>`
  added to `phpunit.xml` blocks and a second test suite does not; a rewritten
  `composer test` script blocks and an added dependency does not. Plus: a test
  file the stager excluded weakens nothing, an empty index is not audited at
  all, four findings arrive together as three evidence entries and one omitted,
  and a workspace that cannot be read refuses rather than passing.
- `tests/Git/StagedPatchTest.php` reads diffs Git actually printed — a
  modification, a deletion, an addition, a rename with and without a content
  change, a path with a space in it (Git marks where such a name ends with a
  trailing tab, and trimming whitespace instead of that one tab attributes the
  section to the wrong file), a repository holding a directory called `b`, and a
  binary file whose only statement of its path is the section header. Three
  cases hand `parse()` text directly, for shapes that are awkward to produce: a
  file whose contents are a patch, a section naming no readable path, CRLF.
- `tests/Run/RunnerTest.php` adds the end-to-end case with a passing verifier —
  which is the point, since a case where the checks failed would prove nothing
  about this. A worker deletes `tests/ImportTest.php`, and the task is blocked
  on `verifying` with no commit, the box still `- [ ]`, the second task not
  taken on, the workspace kept with the diff still staged, the patch on disk
  with the deleted case in it, `a test file removed` in the attempt's evidence,
  and the controlling checkout byte-identical.
- Run against this change's own staged diff as a sanity check: clean, with the
  four modified and added test files examined.

**Notes / deviations**

- **No configuration for it.** What counts as a test file, what configures a
  check, and what counts as narrowing are conventions in the auditor rather than
  settings. The item asks for detection and a block, not for a policy language,
  and a project that disagreed would need a setting that *weakens* the guard —
  which is the thing being guarded against. If real use shows the rules are
  wrong for a project, the fix is better rules.
- **A blocked task has no "accept it" command.** The documented next action is
  to read the preserved patch and either commit it from the workspace and tick
  the box by hand, or reword the task. A one-command override belongs with
  `ralph retry`'s flags in section I, item 1, where the CLI is built.
- **No structured event.** The finding reaches the terminal, the attempt log,
  the attempt's evidence and the generation's blocker. Events with a sequence
  and a schema version are section I, item 3.
- **`ralph status` does not show it yet** beyond the blocker it already reads,
  and the preserved diff is not listed by `ralph logs` — both are section I,
  item 1, where those commands stop being `PlannedCommand`s.
- **Still nothing constructs the runner from the CLI.** Next is section G,
  item 6 — an already-satisfied task as a verified no-change outcome with
  supporting evidence — which `TaskPass` already records as `recordNoChange()`
  and which needs the evidence half built.

## 2026-11-07 — G6: A task that was already done, and the evidence for saying so

**Task**

PROJECT.md section G, item 6: support an already-satisfied task as a verified
no-change outcome with supporting evidence rather than creating an empty
commit. The outcome itself was already there — `TaskPass` recorded
`recordNoChange()` when staging found nothing, and `GenerationStore` refused to
complete a pass without either a commit or that flag. What was missing is the
clause the item spends half its words on: *with supporting evidence*. "Nothing
was staged" is not evidence of anything on its own. It is equally what an agent
that misread the task, edited the wrong directory, or ticked the checklist in
its own workspace leaves behind, and every one of those would have been
completed and checked off on the strength of a green run and an empty diff.

**What it does**

- **`Satisfaction` is the case for the box, and the reading of it.** Collected
  at the one moment the question exists — after the checks passed, after
  staging reported an empty index, before anything is completed — out of the
  checks that ran, what staging decided, where HEAD is, and what the agent
  claimed. Either every fact holds and `isJustified()` is true, or
  `objections()` says what does not and the pass stops.
- **Five objections, worst first.** Checks that did not pass; an index with
  something in it after all; a workspace whose only change was to Ralph PHP's
  own files; a HEAD that is no longer the base commit; a half-finished rebase
  or merge. The first two cannot be reached from the loop and are refused
  anyway, because a no-change result is called *verified* on account of the
  checks and a caller that asked the question without them should not get a
  ticked box.
- **The agent that ticks its own box is now blocked rather than completed.** A
  worker that edits `.ralph/tasks.md` in its workspace and changes nothing else
  satisfies everything a green run can say. Completing it would make a
  model-written checkbox into verified execution state, which section D, item 6
  forbids in as many words; `ExclusionReason::Runtime` is what names it, and the
  user's own checklist stays `- [ ]`.
- **`attempt-NN-satisfied.md`, beside the attempt's logs.** A commit is its own
  evidence and a reviewer reads it with `git show`; a no-change outcome has no
  such artefact, so `SatisfactionAuditor` writes one — the facts, the checks by
  name and command, the agent's words marked as a claim, the objections where
  there are any, and what the whole reading is still not. `LogDirectory` owns
  the path, as it does for the preserved patch.
- **Blocked, not failed, and the run stops.** The same shape as item 5's
  refusal and for the same reason: the checks passed, so there is nothing a
  further attempt would be told that it does not already know. Whether a task
  with an empty diff is done is a question for a person, and
  `TaskResult::invitesAnotherAttempt()` needed no change to leave it alone.
- **`TaskResult::satisfaction()` carries it out of the pass** for both answers,
  so a later `status --json` or event has the verdict and what it was made on
  without re-reading a workspace that may have been cleaned up.

**Why it is shaped this way**

- **Ralph PHP's own files are an objection and local ones are not.** A `.env` or
  a machine-specific `phpunit.xml` is untracked in the workspace because
  `WorkspacePreparer` put it there so the project could be built at all;
  objecting to an excluded local file would block every genuine no-change task
  on every project that configures `workspace.copy`. Those are recorded in the
  evidence and not held against the outcome. An edit to `.ralph/` is the
  opposite: nothing puts it there but the agent.
- **HEAD is read again rather than inferred.** `WorkspaceAuditor::require()`
  already reconciled the worker's commits before verification, so the reading
  taken then is nearly always still true — but the checks themselves run in
  that workspace, and the one statement this record must be able to make is
  that the files the checks passed against are the base commit's. One
  `observe()` is what makes it a fact rather than an inference from a reading
  taken two steps earlier.
- **A record that could not be written does not fail a verified task.** The
  durable evidence is the verification rows and the attempt's row; the file is a
  copy of it made for a reader. A full disk is a fact about the filesystem, so
  the write is noted on the log and the outcome stands — the same tolerance
  `ChecksAuditor` has when it keeps a patch. A *contradicted* outcome blocks
  whether or not the file was written.
- **No new column, and no new table.** The record's path is derived from the
  generation and the attempt, exactly as the preserved diff's is, so a row that
  knows only those can find it. The objections go in the attempt's
  `failure_evidence`, which is what that column is for on a refusal; a
  justified outcome writes nothing there, because it is not a failure and the
  column is read as one.
- **The limit is stated where a user meets it.** The checks passing says the
  project is green at the base commit. It does not say any configured check
  tests what *this task* asked for, and no reading of an empty diff could — so a
  requirement nothing covers reads as already satisfied whenever an agent
  changes nothing. That sentence is in the record file and in the hints a
  refusal prints, not only in a docblock. Git's ignore rules are the other gap:
  a workspace whose only changes are to ignored paths is reported here as
  unchanged, because `git status` does not mention them.

**Checks**

- `vendor/bin/phpunit`: **2309 tests, 6940 assertions, all passing** (20 new).
  PHPStan level 8 over all of `src`: no errors.
- `tests/Verification/SatisfactionTest.php` takes the positive case and replaces
  each of its facts with the thing that contradicts it: the base commit swapped
  for another SHA, a rebase left half-finished, a failing report, an index with
  a file in it, and the two kinds of exclusion side by side — `.ralph/tasks.md`
  refused, `.env` recorded and allowed. Plus: all four objections at once in
  order, eight excluded paths named in part and counted in full, the record's
  sections for both verdicts, a silent agent contributing no claim section, the
  auditor writing the file and noting it, a path it cannot write to, and no path
  at all.
- `tests/Run/RunnerTest.php` carries both outcomes end to end against a real
  repository. The satisfied case asserts no commit beyond the base, the box
  ticked, `attempt-01-satisfied.md` on disk naming the check that ran and
  quoting the worker, and the base SHA on the result. The refused case gives the
  worker one edit — the checklist inside its workspace, its own box ticked — and
  asserts the task blocked on `verifying` with no commit, the user's file still
  `- [ ]`, the second task not taken on, the workspace kept, the objection in the
  blocker and in the attempt's evidence, the record saying why, and the
  controlling checkout byte-identical.
- `tests/Log/LogDirectoryTest.php` pins the new path beside the attempt's logs
  and apart from the staged patch.

**Notes / deviations**

- **Nothing is configurable about it.** What counts as an objection is a rule in
  `Satisfaction` rather than a setting, for the reason item 5's rules are: a
  project that disagreed would need a setting that *weakens* the guard, which is
  the thing being guarded against.
- **A refused task has no "accept it" command.** The documented next action is to
  read the record and either tick the box by hand or reword the task. A
  one-command override belongs with `ralph retry`'s flags in section I, item 1.
- **No structured event, and `ralph status` does not show it yet.** The verdict
  reaches the terminal, the attempt log, the attempt's evidence, the
  generation's blocker and `TaskResult::toArray()`. Events with a sequence and a
  schema version are section I, item 3, and the record is not yet listed by
  `ralph logs` — section I, item 1, where those commands stop being
  `PlannedCommand`s.
- **Still nothing constructs the runner from the CLI.** Next is section G,
  item 7 — check a task off only after verification, Git requirements and
  optional publication succeed, recording the completion evidence together —
  which `finalize()` already sequences and which now has two kinds of evidence
  to record as one.

## 2026-11-07 — G7: The conditions on a ticked box, and the evidence for it

**Task**

PROJECT.md section G, item 7: check off a task only after verification, Git
requirements and optional publication succeed; record completion evidence
together. Two halves, and the sequence in `TaskPass::finalize()` already
satisfied the first one implicitly — a pass only reaches the tick with its
checks passed, a commit or an accepted no-change result recorded, and any
required push settled. What was missing is that nothing *asked*. The
permission to write to a file the user owns was a property of how the code
happened to be ordered, recoverable only by reading the sixty lines above it.
The second half was missing outright: by the end of a pass the facts behind a
completion are spread across `verification_results`, three columns on the
generation, three more for the push, the attempt's claim and a line in a
Markdown file, and none of them answers the question a reviewer arrives with.

**What it does**

- **`CompletionEvidence::unmet()` is the condition, written down.** Every
  condition on ticking a box, read off one value, worst first: checks that did
  not pass, no implementation recorded, a no-change result with no collected
  case or a contradicted one, a publication still owed. Empty means the box may
  be written. It deliberately re-reads what the sequence has already
  established, because a box ticked in a user's own file is the package's one
  outward claim and a later edit that moves the tick above one of its
  conditions must not pass unnoticed. `GenerationStore::complete()` refuses an
  unearned pass for the same reason and is not enough alone: it runs *after*
  the write, and never sees the verification report.
- **The conditions are read before the reconciliation.** Whether the task moved
  under the pass is not a question worth asking about work that did not earn a
  tick in the first place, so `unearned()` comes first and `movedUnderUs()`
  second.
- **`attempt-NN-completed.md`, beside the satisfied record and the preserved
  patch.** The same document for both answers — the conditions each with this
  pass's verdict, the checks by name and command, the base, the branch, the
  commit and what is in it, the publication, the checklist line that changed,
  the agent by name, and the agent's words marked as a claim. A refusal gets
  one too, and it is the case most worth being able to read afterwards.
- **A refusal is blocked, not failed.** The third reading that stops a green
  run, after item 5's weakened checks and item 6's unjustified no-change, and
  blocked for the reason those are: nothing here is a failing check, so a
  further attempt would be told nothing it does not already know. Each unmet
  condition is stored as its own finding in the attempt's `failure_evidence`,
  not as a count of findings.
- **`tick()` now returns the `CheckboxEdit` it made.** The line number it
  changed is the one fact in the record that exists nowhere else — not in a
  row, not in the commit.
- **A record that cannot be written does not un-complete a task.** The checks
  passed, the commit exists and the box is ticked before `CompletionRecorder`
  runs; a filesystem that refuses the copy is a fact about the filesystem. The
  same tolerance `SatisfactionAuditor` has.

**Design notes**

- **No new column and no new table.** The record's path is derived from the
  generation and the attempt, as the satisfied record's and the preserved
  patch's are, so a row holding only those can find it. Everything in the
  document is a copy of state the store already holds durably; what the store
  does not hold is the five layers as one answer.
- **"Did any check run at all" is not among the conditions.** It was, and the
  branch was unreachable: `VerificationReport::of()` refuses the empty set
  outright, because `passed()` would be vacuously true for it, and a report is
  required to reach `finalize()`. The guarantee is better where it already is —
  at construction, one layer earlier — than as a reading here that a later edit
  could drop. The test now pins the constructor's refusal instead, and the dead
  branch is gone.
- **The agent is named in the record.** "Which agent produced this" is the first
  thing asked of a commit nobody wrote by hand, and `AgentRecord` holds no
  credentials to leak.
- **The limit is stated where a reader meets it.** The checks passing says the
  project is green with this work applied. It does not say any configured check
  tests what *this task* asked for, and a ticked box is not a substitute for
  reading the commit. That sentence is in the record file, not only in a
  docblock.

**Checks**

- `vendor/bin/phpunit`: **2331 tests, 7067 assertions, all passing** (22 new).
  PHPStan level 8 over all of `src`: no errors.
- `tests/Run/CompletionEvidenceTest.php` takes the complete positive case —
  passing checks, a commit, nothing owed to a remote — and replaces each fact
  with the thing that withdraws the tick: failing checks, no implementation, a
  no-change result with no evidence and one the facts contradict, a push
  pending and a push failed. Plus: both settled push states, all conditions
  failing at once in order, a commit recorded alongside a no-change flag, the
  tick reported only once it has landed and the original value untouched, a
  diff longer than the record names counted rather than listed, the structured
  form, and the recorder with a path, with a path it cannot write to, and with
  none.
- `tests/Run/RunnerTest.php` asserts the record against a real repository and a
  real run: the path beside the attempt's logs, the three conditions ticked,
  the check with its command, the commit SHA read back out of Git, the staged
  path, the agent, the checklist line, the agent's words marked as a claim, and
  the limit.
- Three pre-existing PHPStan findings in `tests/Run/RunnerTest.php`
  (`assertStringStartsWith` on a `string`, two `?->` on non-nullable values) are
  untouched and unrelated; the project's level-8 gate is `src`.

**Notes / deviations**

- **The refusal path has no end-to-end test, by construction.** Reaching
  `unearned()` from a real run would mean contriving a state the sequence above
  it prevents — which is the whole point of the guard. It is a defensive
  re-read, and it is covered at the unit level for all four conditions.
- **The publication condition is unit-tested only.** Driving a required push to
  `pending` or `failed` through a real run needs a local bare remote, which is
  section J, item 6's job; `PushResult`'s states are exercised directly here.
- **No structured event yet.** The verdict reaches the terminal, the attempt
  log, the attempt's evidence, the generation's blocker, `TaskResult::toArray()`
  and the record file. Events with a sequence and a schema version are section
  I, item 3, and `ralph logs` does not list the record yet — section I, item 1.
- **Still nothing constructs the runner from the CLI.** Next is section G,
  item 8: a checkbox write that fails as pending finalization, so a resume
  retries only the checklist update and never the implementation. `tick()`
  already returns a blocker rather than losing the pass, and the evidence it now
  collects is most of what that item needs to recognise the case.

## 2026-11-07 — G8: A refused checkbox write leaves the pass owing only the box

**Task**

PROJECT.md section G, item 8: handle checkbox-write failure as pending
finalization, preserving the successful implementation and commit so resume
only retries the checklist update. The write to the user's checklist is the
last thing a pass does and the only one that can fail with everything else
already durable — the checks passed, the commit exists, the push is settled.
Item 7 left that case a `Blocker` with good words in it, and words are not a
state: a pass blocked on an unwritable file and a pass blocked because the
agent asked a question are the same row in `generations`, and only one of them
is finished by running `chmod`. Worse, the next run would take the task on as
pending work again, prepare a workspace and invoke the agent against an
implementation that was already committed.

**What it does**

- **The write is recorded before the file is touched.** `checklist_updates` has
  been in the schema since section D, item 1 and had no writer; it has one now
  (`ChecklistStore`). `TaskPass::finalize()` plans the write — which pass, which
  file, which marker, the file's content hash and the task's as they read at
  that moment — and only then calls the checkbox writer. The row is what makes
  the case recognisable afterwards without parsing a blocker's prose.
- **`pending` and `failed` are different answers.** A `FilesystemException` is
  the file saying no, so the row stays `pending` with the reason and the count:
  that is what pending finalization *is*, and retrying the write unchanged is
  exactly right. A `ChecklistException` means what the plan was made against
  has changed, so it is closed as `failed` — retrying would tick a line nobody
  asked about, and the next action is to reconcile rather than to retry.
- **A pass found owing a write is finished, not attempted.** `execute()` asks
  `owedChecklistWrite()` before a budget is measured or a workspace is made: an
  outstanding plan plus `GenerationRecord::isFinalizable()` means the work is
  recorded and the box is not written. `finishTheChecklist()` then reconciles
  the task, writes the box, marks the task runner-verified and completes the
  generation. No workspace, no agent, no checks, no second commit.
- **No attempt is spent on a file write.** `GenerationStore::resumeFinalizing()`
  moves a pass back to `running` at `finalizing` with the counter untouched, and
  refuses a pass that is not finalizable. The write's own attempts are counted
  on its row instead, so a read-only checklist cannot exhaust the budget of work
  that has already passed.
- **The retry goes through the same plan-then-write path.** `plan()` reuses the
  outstanding row rather than adding to it, refreshing the path, the run and the
  hashes to the attempt about to happen. One plan with two attempts behind it,
  not two claims on the same line.
- **The attempt's log is reopened, not replaced.** `LogFile::appendTo()` already
  appends, so the resumed finalization reads as the end of the attempt that
  earned it rather than as a new one with no agent output in it.
- **`ChecklistMarker` and `ChecklistUpdateState` own their spellings.**
  `Schema::checklistMarkers()` and `Schema::checklistUpdateStates()` now build
  the `CHECK` constraints from the enums, as the other seven vocabularies do, so
  the column and the type cannot drift.

**Design notes**

- **Not an eighth lifecycle state.** Section D, item 2 fixes the seven, and
  "pending finalization" is not a peer of `blocked` — it is a *reading*: a
  blocked pass at phase `finalizing`, finalizable, with an outstanding plan. The
  three facts live where they already belong, and the plan is the one that was
  missing.
- **The row carries two hashes it does not yet compare.** `expected_hash` and
  `task_hash` are what section H, item 8's source-content check will read before
  it replaces the file. Writing them now costs nothing and means the recovery
  path does not have to guess what the write was planned against;
  `ChecklistFile::hash()` is of the bytes, deliberately unlike
  `TaskSnapshot::hash()`, because a byte the package did not write is exactly
  what is being looked for.
- **The completion record is not rewritten on the retry.** The document
  `CompletionEvidence` wrote beside the blocked attempt already states all three
  conditions as met and says the box was not written; rebuilding it on the retry
  would mean reconstructing a `StagedChanges` that no longer exists anywhere.
  What the retry records instead is the `checklist_updates` row — applied, with
  the resulting file hash and the moment it landed. Amending the record belongs
  with `ralph logs` (section I, item 1), which will read both.
- **`movedUnderUs()` on the retry path is a defensive re-read.** Rewording a
  task changes its identity, so the task the plan was made for is simply not in
  the checklist the next run reads — the loop takes the new line on as new work
  and never reaches the old plan, which stays outstanding for a recovery path.
  Reaching the reconciliation here needs an edit landing inside the write
  window, which is the same race `finalize()` guards and worth guarding twice.
- **`supersede()` has no caller in the runner yet, by construction.** An
  outstanding plan always belongs to an open generation — `blocked` is open — and
  an open generation is continued rather than superseded, so the runner cannot
  reach it today. It is the operation that writes the state the column already
  allows, it is what a reopening will call, and `settle()` refuses a superseded
  row so that an applied tick can never be claimed for work that no longer owns
  the line.

**Checks**

- `vendor/bin/phpunit`: **2365 tests, 7291 assertions, all passing** (34 new).
  PHPStan level 8 over all of `src`: no errors.
- `tests/State/ChecklistStoreTest.php` holds the row to being unambiguous: the
  plan before the write, what it was planned against, a retry reusing the row
  and refreshing it, a second plan after one has landed, the opposite marker as
  a different write, an applied write with its resulting hash and moment, a
  refusal that stays owed with its reason and count, two refusals then a write
  that lands as one plan with two attempts, an abandoned plan closed rather than
  owed, superseding across generations and tasks, a superseded row that cannot
  be settled, and the readable failures for a pass or a row that is gone.
- `tests/Run/RunnerTest.php` runs the case for real, with the checklist's
  directory read-only: the commit exists on its branch, the box is as the user
  left it, the pass is blocked at `finalizing` and finalizable, the row is
  pending with one attempt and the filesystem's reason, and the task is nobody's
  completion. Then the second run with the permission restored — the same
  generation, the same attempt, the same commit, the worker invoked exactly once
  in total, the box ticked, the row applied against the file it produced, the
  checks not run again. And a task reworded while its write was owed: nothing
  ticked, the plan still outstanding, and one row for a recovery path to find.
- `tests/State/GenerationStoreTest.php` pins `resumeFinalizing()`: a blocked
  pass with its work recorded comes back at `finalizing` with its counter
  untouched and can then be completed; a pass with nothing recorded and one that
  still owes a push are both refused.
- `tests/State/ChecklistUpdateStateTest.php` pins the stored spellings, the
  schema building its constraints from them, the outstanding/settled partition,
  and the pending state's next action naming the box and not the work.

**Notes / deviations**

- **Nothing constructs the runner from the CLI yet.** `ralph run` and
  `ralph resume` are section I, item 1; everything above is reached through the
  loop in tests. The resume path exists and is exercised — it is a second `run`
  over the same state store, which is what `resume` will do with a reconciliation
  in front of it.
- **Section G is complete.** Next is section H, item 1: a repository-level lock
  so two runners cannot overlap, and confirming an old worker has stopped before
  a resume is allowed. Item 2's limits are already enforced per run and per task
  (`ExecutionBudget`); what H adds is them surviving a resume and a new run being
  identifiable without losing completed history.

## 2026-11-07 — H1: One runner per repository, and a resume that confirms the old one stopped

**Task**

PROJECT.md section H, item 1: add a repository-level lock to prevent
overlapping runners, and confirm that an old worker has stopped before allowing
resume. Everything built so far assumes it is the only loop in the repository.
Two runners would share one state database, one set of task branches, one
worktree directory and the user's one checklist file: they would select the
same pending task and hand it to two agents, claim the same branch name, and
tick a box each for work only one of them did. None of that is detectable
afterwards from the rows either of them wrote, so it has to be prevented before
anything is read.

**What it does**

- **The lock is an `flock`, and that is the whole design.** `RepositoryLock`
  takes `LOCK_EX | LOCK_NB` on `.ralph/runtime/run.lock` as the first thing
  `Runner::run()` does, before the checkout inspection, the agent, the base
  reference and the state store. The kernel releases it when the holding
  process exits however it exits — a clean finish, a `kill -9`, a closed
  terminal — so "nobody is running" needs no cleanup step a dying process might
  not reach, and there is no lease to renew and no timeout to tune. A lock that
  is free is a runner that has stopped.
- **The file is never deleted, only rewritten.** A released lock is a record
  that says it was released. Unlinking it would detach the inode the `flock`
  lives on, so a runner still holding the old inode and a runner that has just
  created a new file at the same path would both believe they hold the lock —
  precisely the state the lock exists to prevent.
- **The record is what makes the refusal actionable.** `LockHolder` is the
  file's contents: the command that took it, the host, the pid, the run once
  there is one, and when it was taken and released. "Another Ralph PHP run holds
  this repository" is not a sentence anyone can act on; naming the run and the
  process is. `HeldLock::record()` writes the run id in after the row is opened,
  because the lock has to be held before opening a row is allowed.
- **Taking a lock over is the confirmation the item asks for.** A record that
  claims to be held, over a lock that is free, describes exactly one thing: a
  runner that stopped without releasing. That is the state a resume has to
  recognise, and recognising it is what lets resume continue interrupted work
  instead of refusing forever. Two cases are refused instead: a record written
  on **another machine**, whose processes this one cannot see and over whose
  filesystem a free lock proves nothing; and a record naming a **process the
  probe finds running**, which contradicts the free lock. The honest readings of
  that contradiction are a recycled process id and a filesystem whose locks do
  nothing, they are indistinguishable from here, and both refusals name the next
  action (section H, item 9).
- **A third answer about processes, deliberately.** `ProcessProbe` returns
  true, false or null — running, gone, or "this environment cannot tell".
  `SystemProbe` asks `/proc/PID` first (the supported platforms are Linux and
  WSL, and a directory test needs no extension and no permission over the
  process it asks about) and falls back to `posix_kill($pid, 0)`, where a
  refused signal means the process exists and belongs to somebody else. Where
  neither is available the answer is null and the takeover proceeds: the free
  `flock` is already the proof that no live local process holds the lock, and
  the probe only adds the contradictory case.
- **A record this process wrote itself is not an overlap.** The only runner a
  process can overlap with is itself, so a lock its own pid left held is taken
  back rather than refused — otherwise a long-lived process that failed once
  would be wedged out of its own repository.
- **Both commands pass the same gate.** `run` and `resume` differ only in the
  purpose recorded in the file, so a refusal can say what it is refusing beside.
  The question "has the previous runner stopped" has one answer and one safe way
  to get it, and a fresh run started over a live runner's worktrees would do the
  same damage as a resume.

**Design notes**

- **Why not a row in the state database.** SQLite's own locks last for a
  statement, not a run, and a `runs` row that says `running` is exactly the
  claim that cannot be trusted — it is what a process that died wrote and never
  corrected. `RunStore::running()` has said so in its docblock since section D,
  and `runs.host` and `runs.pid` were put in the schema for this item. The lock
  is what turns their claim into a fact.
- **An unreadable record is treated as no record.** The contents are generated
  and exist only to explain a refusal. A project that could not be run again
  because a full disk truncated its lock file would be worse than a takeover
  that says less about what it displaced. The `flock` is unaffected either way.
- **A failure to write the release record does not fail the command.** The lock
  is released by closing the handle whatever happens to the file, and a run must
  not end with a filesystem complaint about a bookkeeping line in place of
  whatever actually happened to it.
- **`LockException` is its own exception, sharing `CONFIGURATION` for now.** A
  state failure says the project needs fixing; this says the project is in use,
  and the next action is usually to wait or to look rather than to change
  anything. The documented exit-code set, and a status of its own for a lock
  conflict, belong with section I, item 5.
- **`FixtureWorker::isRunning()` now delegates to `SystemProbe`.** It had its
  own copy of the same `/proc`-then-`posix_kill` logic, and "has the old worker
  stopped" should be answered in tests the same way the lock answers it.

**Checks**

- `vendor/bin/phpunit`: **2391 tests, 7409 assertions, all passing** (26 new).
  PHPStan level 8 over all of `src`: no errors.
- `tests/Run/RepositoryLockTest.php` holds the lock to its promises against a
  real `flock` on a real file — two handles on one file conflict whether or not
  they belong to the same process, so the overlap is created exactly as it would
  occur between two terminals: the record written when it is taken, a second
  runner refused with the holder named, a released lock retaken without being
  reported as a takeover, an idempotent release, a run named after the fact
  without losing when the lock was taken, a takeover of an abandoned record
  whose process is gone, refusals for a live process and for another machine,
  a lock its own process left held taken back, a takeover where the system
  cannot answer, an unreadable record, `holder()` reporting without locking, and
  the readable `describe()` for a person to act on.
- `tests/Run/RunnerTest.php` runs it through the loop: a run refused while the
  lock is held, asserted by what did *not* happen — no state database, no
  worktree directory, no worker started, the checklist byte-for-byte as it was,
  no commits; a finished run leaving the record released and the lock actually
  retakeable; an abandoned lock taken over, the run completing and the terminal
  naming what it displaced; and a refusal to take over a lock whose process is
  still running, with nothing started.
- `tests/Process/SystemProbeTest.php` pins the probe: this process found, a
  non-positive id refused as a record that never named a process, an unused id
  reported gone, and the admission that an environment with neither `/proc` nor
  the POSIX extension cannot tell.

**Notes / deviations**

- **The lock confirms the *runner* has stopped, not an orphaned worker.** If a
  runner is killed with `SIGKILL`, its agent child can survive and be reparented
  while the lock goes free, and nothing in the state store records that child's
  pid to go looking for it. Stopping the managed process tree is section H,
  item 4, which is where recording the worker's own pid per attempt belongs;
  until then the confirmation this item makes is about the process that held the
  lock. Worth stating plainly rather than implying the stronger guarantee.
- **Nothing calls it for `resume` yet, because `resume` is not wired up.**
  `ralph run` and `ralph resume` are still `PlannedCommand` until section I,
  item 1; the gate they will both pass through exists and is exercised through
  `Runner::run()`. The purpose string is the only difference between them.
- **Next is section H, item 2**: carrying the configured iteration, attempt,
  task-timeout and run-time-budget limits across a resume. The limits are
  already enforced within a run (`ExecutionBudget`, and the iteration count on
  the run's row); what item 2 adds is the counters surviving a resume and a
  deliberately new run being identifiable without losing completed history.

## 2026-11-07 — H2: The limits a run stops at, and the ones a task stops at

**Task**

PROJECT.md section H, item 2: enforce the configured run iteration limit,
per-task attempt limit, task execution timeout, and total active run-time
budget. Three of the four were already in force, built where the code that
needed them was: the attempt limit inside `TaskPass`, the task timeout as the
seconds the adapter gives the process it starts, and the iteration count on the
run's row. The fourth was not. A run whose **total time budget** was spent kept
going: `TaskPass` noticed the empty budget per task and returned a "spent"
result for each one, so every remaining task got a generation row, was marked
`failed`, and acquired a blocker — a trail of failures in `status` for work no
agent was ever asked to do, over tasks whose truthful state was still pending.
A limit should end a run; it should not fail the checklist.

**What it does**

- **`RunBudget` is the run's half of the limits, and it is read before a task is
  taken on.** `Runner::loop()` now asks it at the top of each iteration, built
  from the configured limits and the run's row, and breaks when it refuses. The
  before/after distinction is the entire fix: the run's limits are reasons not
  to **start** the next task, not things to discover once a pass has already
  opened a row for it. A run that stops this way leaves the tasks it did not
  reach untouched — no generation, no branch, no blocker, still `- [ ]` on disk
  and still pending in the store.
- **It pairs with `ExecutionBudget` rather than extending it.** The two limits
  that belong to a run — how much of the checklist it works through, and how
  long it keeps the repository — are measured between tasks; the two that belong
  to a task — attempts, and the timeout one attempt is given — are measured
  inside the pass. One class for both would have had to be asked two different
  questions at two different moments, and the answer "exhausted" would have
  meant "pass this task over" in one place and "stop the run" in the other.
- **Both figures come off the run's row, not off the loop's own counting.** The
  iteration check was `count($results) >= $limit` on a local array; it is now
  `$run->iterations()`, the persisted counter `RunStore::countIteration()`
  writes, and the time comes from `active_seconds`, which the loop adds to after
  each task. A limit measured from a local variable is a limit a resumed run
  would start again from zero. Item 3 is what makes the counters survive, and
  this is the half of it that belongs here: the enforcement already reads the
  durable figure, so nothing about it has to change when resume starts reusing
  a row.
- **The refusal names the limit and what it left undone.** `the run's time
  budget of 90 seconds was spent with 1 task still pending`, or
  `the iteration limit of 3 was reached with 7 tasks still pending`. The limit
  alone is not a decision a user can make — a run that stopped one task short
  and a run that stopped nine short want different answers — and neither is
  visible from the limit on its own. When both are gone the iteration limit is
  named, because that is the one a user who wanted more of the checklist done
  would raise.
- **Overspending is reported as nothing left, not as a deficit.** A run that
  went 900 seconds past a 60-second budget has `0` remaining, not `-840`. The
  figure is read by a person deciding what to do next, and a negative remainder
  invites the question of how a limit came to be exceeded by an amount — which
  is answered by the task timeout being allowed to finish the task it started,
  not by arithmetic.
- **One second is still enough to try.** `allowsAnotherTask()` is satisfied by a
  single remaining second, because one second is the smallest timeout a process
  can be given and `ExecutionRequest` already refuses a zero-second budget. A
  minimum of the loop's own invention would stop runs the configuration allows.

**Checks**

- `vendor/bin/phpunit`: **2402 tests, 7458 assertions, all passing** (11 new).
  PHPStan level 8 over all of `src`: no errors.
- `tests/Run/RunBudgetTest.php` holds the arithmetic and the wording: a fresh
  run with its configured limits, both figures reduced by what was spent, the
  last iteration and the last second still allowed, each limit refusing on its
  own and naming itself, overspending reported as zero rather than negative, the
  iteration limit named first when both are gone, negative inputs refused, and
  the plain-data form.
- `tests/Run/RunnerTest::the_run_stops_when_its_time_budget_is_spent` is the
  item through the real loop, and most of what it asserts is an **absence**: the
  second task has no generation row, the worker was started once, the checklist
  still reads `- [ ] `, the task is still pending in the store, and there is one
  commit in the repository. The first task is asserted *completed* — a spent
  budget does not cut short the task that spent it — and the run's row is read
  back for the iteration count, the seconds and a `completed` state.
- Time is made observable with a clock that jumps two minutes every time it is
  read. The run's budget cannot be tested against the wall clock: a case that
  really spent its budget would really take that long, and a budget small enough
  to spend quickly would also be the timeout the agent's process is given.
  Reading the clock is what the loop does to measure a task, so a clock that
  moves when it is read is the smallest substitution that makes the arithmetic
  visible.
- `the_run_stops_at_the_iteration_limit` gained the same absence assertion — the
  task the limit kept the run from reaching has no generation of its own. The
  iteration limit was already correct in this respect; it was untested, and it
  is the assertion that would have caught the time budget.
- The other two limits are held where they are enforced:
  `a_task_whose_checks_keep_failing_stops_at_its_attempt_limit` for the attempt
  limit, and `ProcessAdapterTest`'s
  `an_agent_that_overran_the_task_timeout_is_a_timeout_the_adapter_never_sees`
  and its run-bounded sibling for the task timeout.

**Notes / deviations**

- **The budget counts time spent on tasks, not the run's setup.** `active_seconds`
  is written per task, so inspecting the user's checkout, resolving the base
  reference and reading the checklist are not charged to it. They happen once,
  cost seconds, and cannot run away; charging them would make the limit harder
  to reason about for no protection. "Active run time" is read as the time the
  run spends working.
- **No attempt can overrun the run's budget and leave it for the boundary to
  notice.** `ExecutionBudget::seconds()` is already the lesser of the task
  timeout and the run's remaining time, so the per-attempt ceiling keeps the run
  bound true from the inside. The boundary check is what stops the *next* task
  from starting; the two are not redundant.
- **The verification commands are bounded by their own timeouts, not by the
  run's.** A check that overruns is caught by its own `timeout` and the overrun
  shows up at the next task boundary. Threading the run's remaining time through
  the verifier would mean cancelling a check mid-run and recording a failure
  that is the clock's rather than the code's — the wrong evidence to leave on a
  task.
- **Next is section H, item 3**: preserving these counters across a resume, and
  making a deliberately new run identifiable without losing completed task
  history. The enforcement here already reads the row rather than a loop
  variable, so what item 3 has to settle is which row a resume continues on and
  how `resumed_from` distinguishes a continuation from a fresh start.

## 2026-11-07 — H3: Counters that survive a resume, and a new run that says it is one

**Task**

PROJECT.md section H, item 3: preserve counters across resume; make a
deliberately new run identifiable without losing completed task history.

H2 left the enforcement in the right place — `RunBudget` is measured from
`iterations_used` and `active_seconds` on the run's row rather than from a loop
variable — so what was left to settle was which row a continuation writes to.
`RunStore::open()` took a `?RunRecord $resumedFrom` argument, wrote it to
`resumed_from`, and then inserted `0, 0` for the counters regardless. Nothing
called it with a predecessor yet, so nothing was broken in practice; what was
wrong was the arithmetic waiting to happen. A run interrupted three tasks into
a limit of five would have resumed with five tasks left, and interrupting it
again would have bought five more. An interruption must not be a way to buy
budget.

**What it does**

- **`RunStore::resume()` is the second way to open a run, and it carries the
  counters.** The new row starts at the iteration count and the active seconds
  its predecessor had reached, so the limits of H2 bound the *work* rather than
  the process that happened to be holding it. `open()` lost its
  `$resumedFrom` parameter and is now what it says it is: a deliberately new
  run, counters at zero. Both go through one private `insert()`, so the two
  cases cannot drift on everything else a run freezes.
- **The carried figure is a total, not a step.** Each resume copies its
  predecessor's totals, so one row holds what the whole chain has spent and
  `RunBudget::of($limits, $run->iterations(), $run->activeSeconds())` is still
  one row's two columns. Summing a lineage at read time would have been the
  alternative; it would have put a query behind a figure the loop reads at every
  task boundary, and made the budget wrong whenever the chain was read
  incompletely.
- **What a run did itself is still legible.** Run rows are read with a left join
  onto the run they continue (`RunStore::SELECT`), so a `RunRecord` knows the
  predecessor's identifier and the predecessor's counters without a second
  query. `carriedIterations()` / `carriedSeconds()` are what was inherited,
  `ownIterations()` / `ownActiveSeconds()` the difference. Without that
  distinction a resume that did two tasks on top of three would have reported
  five, and "this run did five tasks" would have been false in exactly the case
  where a person is most likely to be reading it.
- **A fresh start is identifiable, and says so in both places that matter.**
  `isFreshStart()` is the recorded fact; `describe()` prints `2 tasks
  continuing run-… (5 in all)` for a continuation and plain `3 tasks` for a new
  run; `toArray()` gains `resumed_from` (the predecessor's identifier or null),
  `iterations_here` and `active_seconds_here` beside the totals, which is what
  `status --json` of section I will carry.
- **Resuming a completed run is refused.** A run that ended in the ordinary way
  has nothing left to continue, and continuing it would be a new run wearing a
  finished run's spent budget — a user whose run stopped at the iteration limit
  would "resume" into a budget with nothing in it. The refusal names the stop
  reason, points at `ralph run`, and says that completed tasks stay completed.
  An `interrupted` run, a `failed` one and one still claiming to be `running`
  (the crashed runner that never closed its row — the commonest thing there is
  to resume) are all accepted; whether the old worker has really stopped is the
  lock's question, settled in H1, not the store's.
- **`lineage()` and `predecessor()` read a continued effort as one history.** A
  fresh start is one row; each resume adds one in front of it. The walk is
  bounded by the ids already seen, because a command that reads a hand-edited
  database should not hang.
- **Starting afresh loses nothing.** No change was needed for this half and
  that is the finding worth recording: a task's identity, its completion and its
  generations hang off the task row, not the run that worked on it, so a new run
  reads the same verified history and takes on only what is still pending. It is
  now asserted rather than assumed.

**Checks**

- `vendor/bin/phpunit`: **2417 tests, 7543 assertions, all passing** (15 new).
  PHPStan level 8 over all of `src`: no errors.
- `tests/State/RunStoreTest.php` is new, and holds the arithmetic: a fresh run
  with nothing spent and nothing to continue, a resume beginning at the
  counters the interrupted run reached, the same budget read through
  `RunBudget` before and after the resume (**2 iterations left either side** —
  the assertion the item exists for), three successive resumes that still total
  five tasks, carried-versus-own on one row, a completed run's refusal and its
  hints, a crashed `running` row and a `failed` row both resumable, the base
  commit inherited, a new run after a completed one starting at zero with its
  predecessor still readable, the lineage of a chain of three, and the counters
  going on from where the resume began — read back through `get()`, `find()`,
  `running()` and `latest()`, because the join is in all four and a test that
  only used one would pass against three broken queries.
- `RunnerTest::a_second_run_starts_its_limits_afresh_without_losing_what_the_first_completed`
  is the other half through the real loop: two tasks, an iteration limit of 1,
  two runs. The second run takes on the task the first did not reach and only
  that one — the worker was handed work **twice in all**, the completed task has
  **one** generation and it belongs to the first run, both boxes are ticked on
  disk and there are two commits. The new run's row is asserted a fresh start
  with its own count of 1 and nothing carried.

**Notes / deviations**

- **Nothing calls `resume()` yet.** `ralph run` opens a new run, which is
  correct; the caller is `ralph resume`, which is H items 4–7 (reconciling
  worktree and commit state, refusing an implicit change of agent, resuming a
  failed publication) and I item 1. This item is the state those will be built
  on, and it is tested as such rather than through a command that does not
  exist.
- **The agent is passed to `resume()` rather than taken from the predecessor.**
  Refusing an implicit change of agent is H item 6's job and belongs to the
  command: the store would have no way to tell "resume with the recorded agent"
  from "resume with whatever was configured today", and silently substituting
  the recorded one would make the refusal impossible to write later.
- **`resumed_from` is a row id and the predecessor's identifier is read through
  the join**, rather than denormalised into a column of its own. It avoids a
  second migration for a value that is already one `LEFT JOIN` away, and the
  join is in the one constant every run read goes through.
- **Only `completed` is refused.** `failed` and `interrupted` are both things a
  person fixes and continues, and a row still claiming to be `running` is the
  crash resume exists for. Drawing the line anywhere tighter would have made the
  store the arbiter of resume policy, which is the command's to decide with the
  lock and the reconciliation in front of it.
- **Next is section H, item 4**: Ctrl+C — stopping the managed process tree,
  recording the interruption, preserving files, and releasing ownership only
  once termination is confirmed. The `interrupt()` path on the run row and the
  lock's ownership from H1 are in place; what it adds is the signal handler and
  the proof that the worker is gone.

## 2026-11-07 — H4: Ctrl+C stops the worker, writes the run up, and only then lets go

**Task**

PROJECT.md section H, item 4: handle Ctrl+C by stopping the managed
worker/process tree where supported, recording interruption, preserving files,
and releasing ownership only after termination is confirmed.

Four requirements that have to happen in that order, and the order is the whole
difficulty. H1 put the repository lock and its takeover check in place and H3
made an interrupted run's counters continuable, so the pieces either side of
this item existed; what was missing was the middle. A signal arrives at an
arbitrary moment — typically twenty minutes into an agent's attempt, inside a
blocking read — and the four things owed cannot be done in the handler that
learns about it.

**What it does**

- **The handler does nothing but remember.** `Interruptions` is process-wide
  state holding one flag, because there is one process, one terminal and one
  Ctrl+C; a per-object flag would be invisible to the `ProcessRunner` an adapter
  built for itself. Killing a process tree, writing to SQLite and closing a lock
  file are not things to do in a signal handler, where the interpreter may be
  halfway through any of them. `requested()` dispatches and reads the flag from
  ordinary control flow, inside a loop already waiting on `stream_select`, so
  noticing costs one call per chunk of agent output.
- **Asked, then killed, deepest first.** `Terminator` is the only place the
  package signals a process it started, and it has two callers that want
  identical behaviour: a timeout and an interruption. A timeout that left an
  agent's test runner behind would be the same bug as an interruption that did,
  and keeping one path means the one with a test is the one that stays fixed.
  SIGTERM with a two-second grace, SIGKILL for what ignores it. `ProcessTree`
  supplies the order: a shell signalled before the command it waits on would
  orphan that command, and a supervisor signalled before its children may start
  replacements.
- **"Confirmed stopped" means somebody looked.** `Termination` has three
  answers, not two — confirmed, survivors, and unknowable — and the third is
  treated as unconfirmed. Where the system offers no way to look a process up,
  the honest answer is that termination was *requested*. The cost of erring that
  way is a lock a user clears by hand; the cost of erring the other way is two
  agents in one worktree.
- **Ownership is retained, not released, when it cannot be.** `HeldLock::retain()`
  is the second way to give up the lock: the handle closes because the process is
  ending and the kernel would drop the `flock` regardless, but the record is
  written with the survivors named and *no release time*, so H1's takeover check
  refuses to start here while any of them is alive. `Runner::relinquish()` picks
  between that and an ordinary release in the outermost `finally`, and the
  handlers are put back after the lock — a second Ctrl+C during that is still
  this package's to notice rather than PHP's to die of mid-write.
- **The run row says `interrupted`, wherever the stop surfaced.** A pass gives
  way at its next phase boundary, the loop then takes no further task on, and
  the row is closed with the signal as its reason. An `InterruptionException`
  escaping from *between* tasks is caught in `close()` and recorded the same
  way rather than as a failure, because `interrupted` is what resume looks for
  and what keeps the counters continuable.
- **Without ext-pcntl there is nothing to install, and the run says so once.**
  `ralph` behaves exactly as before and Ctrl+C ends PHP where it stands,
  leaving an interrupted run to be reconciled on resume instead of recorded on
  the way out. That is the item's "where supported". The extension is suggested
  in `composer.json` and reported by preflight, not required: requiring it would
  make the package uninstallable on a build where everything except this one
  courtesy works.

**Checks**

- `vendor/bin/phpunit`: **2470 tests, 8315 assertions, all passing** (53 new).
  PHPStan level 8 over all of `src`: no errors.
- Three new test files carry the mechanism. `TerminatorTest` stops a real
  waiting process and confirms it, stops the children a worker started, kills
  one that ignores SIGTERM, finds nothing to do for one that has already
  exited, and reports only the worker itself where there is no process
  filesystem. `ProcessTreeTest` reads children, grandchildren and their order,
  puts the deepest first, excludes non-descendants, and — the two that matter
  for a parser reading `/proc` — does not shift its fields on a command name
  containing spaces and brackets, and does not hang on a table claiming a
  process is its own ancestor. `InterruptionsTest` covers a real keyboard
  interrupt, a second signal not replacing the first, insisting restoring the
  default disposition, and a worker that has since gone no longer being owed.
- `RunnerTest::a_run_stopped_with_ctrl_c_stops_the_worker_and_records_the_interruption`
  is the item end to end: the worker really received SIGTERM rather than being
  waited out for its scripted 30 seconds, the run is `interrupted` with
  **2 tasks still pending**, the generation stopped in `Phase::Executing` with
  its attempt `Cancelled` and its single attempt still counted, the worktree
  still holds the half-written file the agent was stopped mid-way through, and
  the user's checklist and checkout are untouched with **no commits**.
- The other three guard the edges: a stop before setup never starts, a stop
  between two tasks leaves the next untouched, and an ordinary run is still
  recorded `completed`. On the lock side, a run refuses to start over a worker
  an interrupted run left behind, a worker that has since stopped no longer
  holds the repository, a retained lock names the worker and does not claim to
  be released, and retaining nothing is an ordinary release.

**Notes / deviations**

- **The grace period is two seconds, not thirty.** A user who has pressed
  Ctrl+C is watching the terminal. An agent gets long enough to finish the file
  it is writing and clear its own temporary state, and no longer.
- **The direct child is stopped through its handle, descendants through
  signals.** `proc_terminate` needs no extension and is the one way to stop the
  worker on a build without ext-posix; only `posix_kill` reaches further. So
  such a build still stops the agent and *reports* that it could not account
  for what the agent started, rather than stopping one process and calling the
  tree dealt with.
- **`Interruptions::request()` exists as a test seam**, in the way
  `FrozenClock` is the clock's. A test that had to send itself a real signal to
  cover a branch could not run on a build without ext-pcntl and would be at the
  mercy of whatever else the suite had installed a handler for. It is also the
  one remaining way a library host with its own signal handling can stop a run.
- **Unconfirmed survivors are re-read rather than trusted.** A process that had
  not died when it was given up on may well have died in the seconds since, and
  the question is only asked at the end of a run. One that has gone is dropped,
  so a lock is not held over a worker that stopped a moment late.
- **Next is section H, item 5**: reconciling worktree, branch and recorded
  commit state on resume, including a crash immediately after a commit but
  before the database update. This item leaves exactly the state that work
  reads — a resumable generation with its phase, a retained-or-released lock,
  and a preserved worktree — but nothing yet calls `RunStore::resume()`; the
  caller is `ralph resume`, which is H items 5–7 and I item 1.

## 2026-11-08 — H5: Finding the commit a crash never recorded

**Task**

PROJECT.md section H, item 5: reconcile worktree, branch, and recorded commit
state on resume, including a crash immediately after a commit but before the
database update.

A pass owns three things and each of them is recorded a moment after it happens
rather than at the same instant, so a row is always a copy. The named case is
the gap that matters: `TaskCommitter` makes the commit, and the process is
killed before `recordCommit` writes the SHA. The repository holds real,
verified, committed work and the state database holds nothing pointing at it —
so a resume reading only the row would hand the task back to the agent and
commit it a second time, which is the duplicate acceptance criterion 6 rules
out.

**What it does**

- **The commit says whose it is, and that is what finds it.** `CommitIdentity`
  reads a message back into trailers: task, generation, run, attempt, base,
  agent. It was always going to be possible because `TaskCommitter` writes those
  identifiers into the commit and claims the branch in the row *before* creating
  it — the row names the branch, the branch names the commit, the commit names
  the pass. Only the last paragraph is read and only when every line of it is a
  trailer, which is Git's own rule and what stops a task description shaped like
  `Ralph-Task: TASK-FAKE0001` from forging one: a description reaches a message
  as its body, and a body is never the last paragraph of a message that carries
  trailers.
- **`CommitReader` asks the three questions recovery has.** What a reference
  says (peeled with `^{commit}`, so a tag of a branch's name is not mistaken for
  it), whether one commit is still in another's history, and how far a branch
  has gone past the base. Every command is a `Repository::query()`, and a
  question Git could not answer is raised rather than read as a no — these
  answers decide whether work is adopted or abandoned.
- **`PassRecovery` decides, and the decision is narrow.** A commit is adopted
  only when its own trailers name this pass, it sits exactly one commit past the
  base the row records, and the base it claims is that base. Then `recordCommit`
  writes the SHA and the pass's next step is a push or a checklist write rather
  than an agent. Everything else blocks with the next action named: a branch
  holding a commit no pass recorded, a commit of another generation of the same
  task, two commits past the base, a recorded commit the repository no longer
  has, a branch that has moved past it, a no-change outcome whose branch has
  acquired a commit, a workspace recorded somewhere this project does not put
  workspaces.
- **Blocking changes nothing at all.** No commit created, none adopted, no
  branch moved, no directory removed. The convenient answers to each ambiguity —
  adopt a stranger's commit, commit again over work already there, force a
  branch, delete a directory that cannot be accounted for — each destroy
  something a person may want, and `ralph retry` and a human's own inspection
  both depend on the evidence still being there.
- **The one repair besides adoption is a reference.** `BranchRestorer` creates
  a deleted branch again at the commit the row already records: `git branch`
  with no `--force`, so Git itself refuses a name something holds. It is a
  separate class because `TaskBranches` reads ownership and writes no
  reference, which is what makes that class safe to call anywhere.
- **The workspace is read first and on its own.** The later answers do not
  depend on it: a pass with a commit owes its workspace nothing, and a pass
  without one needs a workspace it can be *given* rather than the one it had. A
  registration without its directory will be checked out again, a directory
  without its registration stops a pass that still needs it (`git worktree add`
  refuses a path holding files it did not create, and this package will not
  delete one it cannot account for), and a path the configuration no longer
  places there stops the pass outright.
- **The verdict is returned, not acted on.** `Recovery` carries the row as it
  now stands, what was changed, what was merely observed, and the blocker.
  Moving a pass to `blocked` or closing a run is the caller's decision: the same
  reading means "stop here" to `ralph resume` and "this is what you are retrying
  over" to `ralph retry`.

**Checks**

- `vendor/bin/phpunit`: **2516 tests, 8502 assertions, all passing** (46 new).
  PHPStan level 8 over all of `src`: no errors.
- `PassRecoveryTest` produces the named crash the way it really happens: the
  commit is made by `TaskCommitter`, then the column it would have written is
  cleared with `UPDATE generations SET commit_sha = NULL`. The commit is found
  and adopted, the row names it, **one commit is still on the branch**, and the
  pass is left owing what follows a commit. Eleven further cases are the
  refusals and the repairs, each asserting the repository was left exactly as it
  was: a stranger's commit, generation 2's commit under generation 1, a worker's
  commit under the pass's, a commit claiming another base, a branch that moved
  on, a recorded commit the repository does not have, a commit with no branch, a
  branch deleted from under a recorded commit (re-created, tip read back out of
  Git), a workspace that has gone, a workspace Git no longer lists with and
  without an implementation, and a completed pass refused outright.
- `CommitIdentityTest` is the forgery and the shape: a real `CommitMessage` is
  recognised as the pass's, a description written as a trailer block is not, a
  message of only trailers is read as having none, one identifier alone is not
  adoptable, and `Ralph-Generation: two`, `0` and `-1` are treated as absent
  rather than guessed into a `0` that compares equal to something.
- `CommitReaderTest` and `BranchRestorerTest` run against real repositories: a
  message read back verbatim, a tag peeled to its commit, a tree refused, a
  commit as its own ancestor, counts either way across a range, an unanswerable
  question raised rather than answered "no", and a branch name something else
  holds reported with the held reference unmoved.

**Notes / deviations**

- **One commit past the base, exactly.** Two commits with the pass's on top is
  blocked rather than adopted. The trailers identify the tip and say nothing
  about what is underneath it, and the commit underneath may be a worker's, an
  earlier attempt's or a person's — so publishing the branch would publish work
  no check of this run ran against.
- **A row's own commit is not re-identified.** Where `commit_sha` is set, the
  commit is read back only to confirm the repository has it; its trailers are
  not checked against the pass. That column is this package's own write and is
  the authority on which commit is the pass's, and refusing a commit made by an
  older version over a trailer it did not write would abandon real work.
- **The restore path trusts the schema.** `generations_branch` is a unique
  index, so a branch name this row holds is one no other row can hold, and a
  reference under it would have been read as the tip rather than as nothing.
  What is left is a reference appearing between the reading and the write, and
  `BranchRestorer` raises Git's refusal rather than describing it as a
  recovery: an ownership conflict appearing *inside* a recovery is not a state
  the recovery can account for.
- **Nothing calls it yet.** `Workbench::recovery()` assembles it for the run,
  and the caller is `ralph resume` — H items 6 and 7 and I item 1. This item
  leaves the reading those need: the row put back in step with the repository,
  the verdict, and `Phase::resumeAction()` already saying what will and will not
  be repeated.
- **Next is section H, item 6**: resuming with the originally recorded agent
  and non-secret settings, rejecting implicit agent changes. `RunStore::resume()`
  and `AgentRecord` already hold what that needs; what is missing is the
  comparison and the refusal.

## 2026-11-08 — H6: Resuming with the agent that started the work

**Task**

PROJECT.md section H, item 6: resume with the originally recorded agent and
non-secret settings; reject implicit agent changes and document how to
explicitly begin a new run with a different agent.

Everything needed was already written down — `AgentRecord` holds the agent, its
adapter and its non-secret settings on the run, every generation and every
attempt — and nothing read it back. A resume that re-selected its agent from
`.ralph/config.json` would finish a task with whatever the file says *now*: a
model swapped since the interruption, a binary reinstalled elsewhere, a default
moved on to another agent entirely. The commit would still carry the trailer of
the agent the row names, so the evidence would be attributing work to a tool
that did not do half of it.

**What it does**

- **`AgentContinuity` is `AgentSelector`'s opposite number, and a separate
  class because the question is the reverse.** A new run asks the configuration
  what to use. A resume asks the run it is continuing, and the configuration
  gets no vote. One method, `continuation()`, which returns what to resume with
  or raises.
- **The invocation comes off the row.** `AgentRecord::definition()` rebuilds an
  `AgentDefinition` from the three stored columns: name, adapter, and the
  executable, model, argument array and authentication-variable names inside
  `agent_settings`. The resumed run's `AgentSelection` is built from that, so a
  task interrupted part-way through is finished by the binary that started it,
  with the arguments it was started with.
- **A third `AgentSource`.** `resumed_run` — "the run being continued" — rather
  than copying the predecessor's source forward. A continuation row claiming
  `project_default` would read as a fresh decision taken under settings that
  may since have changed, which is the thing this item exists to prevent. The
  schema's `CHECK` is generated from the enum's cases, so the vocabulary
  followed the case.
- **An implicit change is reported, not obeyed.** Four kinds of drift are each
  a line naming the recorded value as the one in use: the project default has
  moved to another agent, the `agents` entry for this one is gone, a setting now
  says something else, the adapter under the same name now says something else.
  A resume that quietly used the recorded settings would leave a user reading
  the file and watching something else happen.
- **An explicit change is refused with what to type instead.** `--agent` naming
  the recorded agent is a confirmation and is printed as one. Naming anything
  else raises, and the hints are the documentation: omit the option to continue;
  reconcile this run's work first, `ralph status` shows what is outstanding;
  then `ralph run --agent NAME`; and what that costs, which is nothing — the
  limits come back whole, completed tasks stay completed, and commits and
  attempt history stay attributed to the agent that earned them.
- **The same refusal in the store.** `RunStore::resume()` now checks the
  selection against `$previous->agent()` and raises when they are different
  agents. Its docblock used to explain that the agent was the caller's to
  refuse; the caller's job is to *explain* the refusal, and the invariant — a
  continuation row cannot name another agent — belongs where the column is
  written, beside the already-completed-run check. Settings are not compared,
  only the agent and its adapter: `isSameAgent()` was written that way, and a
  moved binary is a legitimate edit rather than a different agent.
- **No fallback for a missing adapter.** A recorded adapter this build cannot
  execute is refused the way an unavailable adapter is refused at selection
  time, with a suggestion when the name is close, the available names, and how
  to register it again. Resuming half-finished work through a substitute adapter
  would be the silent switch PROJECT.md rules out, one crash later.
- **A row that cannot say how to invoke the agent stops the resume.** No
  executable, or an `arguments`/`environment` setting that is not a list of
  strings, raises rather than being worked around: an invocation assembled from
  part of a recorded array is a different invocation, and a dropped
  authentication variable fails the agent for a reason no message would name.
- **`ralph help resume` is the documentation half.** It now states that the
  recorded agent and settings are continued with, that a configuration edit
  since is reported and not applied, what `--agent` is for, and the two steps to
  a new run with a different agent — where a user would look for it, rather than
  in a message they have to provoke first.

**Checks**

- `vendor/bin/phpunit`: **2534 tests, 8570 assertions, all passing** (18 new).
  PHPStan level 8 over all of `src`: no errors.
- `AgentContinuityTest` works against real run rows written by `RunStore` and
  real configuration files read by `ConfigurationLoader`, because both halves of
  the item are about a disagreement between the two. The recorded executable,
  model, argument array and variable names survive a configuration that states
  four different ones; a default moved to `claude`, an `agents` entry deleted, a
  changed adapter under the same name and three changed settings are each
  reported with the recorded value named; `--agent claude` over a `codex` run is
  refused with the `ralph run --agent claude` alternative asserted verbatim;
  `--agent codex` over a `codex` run is a confirmation; an unavailable adapter is
  refused and a project-registered one is resumed; a row with no executable and
  a row with `["--full-auto", 7]` are both refused; `RunStore::resume()` refuses
  a continuation naming another agent and writes no row; and the row a resume
  does open is asserted to hold the recorded agent, the recorded executable and
  model, and `resumed_run` as its source.
- The continuation is asserted reportable: `toArray()` with a real environment
  variable set to a secret contains the variable's name and not its value.

**Notes / deviations**

- **Drift is not a failure.** A user who moved a binary between the
  interruption and the resume made a legitimate edit. Refusing the resume over
  it would strand the interrupted work; applying it would change the invocation
  mid-task. Reporting it and using the recorded value is the only option that
  does neither, and the lines say which value won so the choice is visible.
- **An unknown stored setting is compared but not resumed with.** The
  comparison covers any extra key a row written by a later version holds, since
  a changed setting is worth saying either way. `definition()` reads back only
  the five an invocation is built from, which is the narrower question, and
  `settings()` keeps the whole row for `status` and `logs`.
- **`--agent` on a resume is only ever a confirmation.** It is accepted when it
  agrees and refused when it does not, rather than being rejected outright: a
  user scripting `ralph resume --agent codex` is asserting something true, and
  an error would teach them to drop the assertion rather than that it holds.
- **Nothing calls it yet.** `ralph resume` is section I, item 1, and this item
  leaves it the two things it needs: `AgentContinuity::continuation()` for the
  agent, and `PassRecovery::reconcile()` from H5 for the repository. The help
  text for the planned command now describes what both will do.
- **Next is section H, item 7**: resuming a failed publication without
  repeating the implementation or creating a duplicate commit. `PushResult`,
  `PushState` and `TaskPublisher` already hold the publication state, and
  `Phase::Publishing`'s resume action already says what must not be repeated.

## 2026-11-08 — H7: Retrying a push without implementing the task again

**Task**

PROJECT.md section H, item 7: resume failed publication without repeating
implementation or creating a duplicate commit.

The pieces were all in place and nothing joined them up. `TaskPublisher` already
recorded a refused push as an outcome rather than throwing, `PushState` already
told `pending` apart from `failed`, `GenerationRecord::awaitsPublication()`
already answered the question — and no caller anywhere read it. A failed push
leaves the generation `failed`, which `LifecycleState` counts as open, so
`GenerationSelector` continues that pass rather than reopening the task. The
pass arrived at `TaskPass::execute()` with its commit recorded, fell through the
checklist-write check (which requires publication to be *settled*), and went
into the attempt loop — the agent invoked again over work already in the
repository, and a second commit on the same branch. That is the duplicate
acceptance criterion 6 rules out, reached by a different route than the crash of
item 5.

**What it does**

- **`execute()` now has two ways to finish a pass without attempting it.** The
  checklist-write check from item G8 was the first; `awaitsPublication()` is the
  second, read in the same place and before a budget is measured or a workspace
  made. The two are mutually exclusive by construction — `isFinalizable()` wants
  the push settled, `awaitsPublication()` wants it outstanding — so the order
  between them is a readability choice rather than a precedence rule.
- **`GenerationStore::resumePublishing()` is `resumeFinalizing()`'s opposite
  number**, and refuses the cases that are not it: a pass with nothing committed
  has work to redo, and a pass whose push already succeeded owes the box
  instead. Neither counts an attempt. A remote that is down has nothing to say
  about how many tries the task itself deserves, which is the same argument that
  keeps a read-only checklist from eating a budget.
- **The blocker is cleared on both resumptions.** Both reopen a pass whose
  recorded reason for stopping is the thing now being done again, so leaving it
  would have `ralph status` report a refused push against a task that went on to
  publish and complete. A retry that stops again records its own. This is the
  one behaviour change outside item 7's own path; `resumeFinalizing()` shares
  the new `reopenAt()` and so shares the fix.
- **A successful retry carries straight on to the box.** The first run stopped
  at `publishing`, before it ever planned a checklist write, so returning after
  the push would leave the task unticked and a second resume to notice. The
  plan-then-write tail is now `writeTheBox()`, shared by both resumptions, so
  the one write this package makes to a file the user owns happens in a single
  place whichever thing the pass was owing — and the task is reconciled against
  the file first either way, because a pass can sit owing a push for as long as
  it takes someone to fix their remote.
- **A refusal the second time is recorded the second time.** `pushRefused()` is
  one wording for the first failure and every retry of it, because the situation
  and the next action do not change: the commit is kept, the box stays unticked,
  the remote has to be put right. `unpublished()` now uses it too.
- **A branch that moved under the pending publication blocks instead.**
  `TaskPublisher` refuses to push anything but the commit the row records, and
  that refusal is an exception rather than an outcome. Caught here and turned
  into a blocker with the publisher's own hints kept, since they name the commit
  and the command that puts the reference back. A retry would refuse identically
  for as long as the reference says what it says, so this is a decision rather
  than a failure — and going back to the agent instead would be exactly the
  duplicate the item forbids.
- **The resume help says what is not repeated.** Where a user who has just
  fixed their remote would look: a pass whose push was refused retries the push
  alone, one whose box could not be written writes only the box, and neither
  invokes the agent again or creates a second commit.

**Checks**

- `vendor/bin/phpunit`: **2542 tests, 8643 assertions, all passing** (8 new).
  PHPStan level 8 over all of `src`: no errors.
- Four new `RunnerTest` cases run the whole loop against a real repository, the
  fixture worker through the Codex adapter, and a real local bare remote
  (section J, item 6 — no network, no credentials). The remote is deliberately
  absent on the first run: `origin` is in the configuration and not in the
  repository, which is the mistake a user actually makes when they turn pushing
  on.
  - A refused push leaves the pass `failed` at `publishing`, `awaitsPublication`
    and not `isFinalizable`, with the reason on the row, the commit on its
    branch at the recorded SHA, the box as the user left it, and no checklist
    write ever planned.
  - A second run after the remote is added completes the task: same generation,
    same attempt count, same commit SHA, **one** commit beyond base, the worker
    invoked **once**, the commit read back out of the bare remote, the box
    ticked, the push `published` with no error and no blocker left on the row,
    one verification report rather than two, and one checklist plan.
  - A remote still missing on the second run is refused again with the commit,
    the attempt count and the agent-invocation count all unchanged.
  - A commit made by hand in the preserved worktree moves the branch past the
    recorded SHA; the resume blocks, nothing reaches the remote, and the
    recorded commit is still resolvable.
- Four new `GenerationStoreTest` cases cover `resumePublishing()` on a failed
  and on an interrupted pass, and its two refusals, including that a published
  pass is `resumeFinalizing()`'s case instead.

**Notes / deviations**

- **The branch-moved case is handled rather than left to propagate.**
  `PublicationException` is a `RalphException`, and letting it out of
  `execute()` would abort the whole run over one task's reference. `TaskPass`
  returns rather than throws for everything a pass can record, and an ownership
  conflict is recordable — so it is, as a blocked pass with the publisher's
  hints. This is section F, item 9 and section H, item 9 territory that item 7
  walks through; what it is not is a reason to attempt the task again.
- **The attempt row is not touched by a retry.** The attempt that earned the
  commit was finished by the run that failed its push, and a push is not part of
  an attempt. The reason for this retry's refusal goes on the generation's
  `push_error`, which is where `status` and the next resume read it.
- **The log is reopened, not replaced.** Same as the G8 path: the retry appends
  to the log of the attempt that produced the commit, so it reads as the end of
  that attempt instead of as an attempt of its own.
- **Nothing here is `ralph resume`.** That command is section I, item 1. The
  path is reached today by running `ralph run` again, which is how the item's
  tests exercise it and how the G8 path has worked since it was built.
- **Next is section H, item 8**: protecting checkbox writes with source-content
  checks and atomic replacement, preserving detected concurrent edits and
  blocking conflicting writes. `ChecklistStore` already records an expected
  checklist hash and a task hash with every planned write, and
  `CheckboxEditor` and `ChecklistWriter` are where the replacement happens.

## 2026-11-09 — H8: Ticking a box without overwriting the file it is in

**Task**

PROJECT.md section H, item 8: protect checkbox writes with source-content
checks and atomic replacement; preserve detected concurrent edits and block
conflicting writes.

The replacement was already atomic — `ChecklistFile::replace()` has written a
sibling and renamed it since section C — so the half that was missing was the
condition. Ticking a box is a read-modify-write of a document the package does
not own: the file is read, one marker in those bytes is changed, and the whole
result goes back. Every byte Ralph PHP did not mean to touch therefore comes
out of the read, so a save arriving in between was silently undone by the
rename. `ChecklistUpdate` had recorded `expected_hash` and `task_hash` with
every planned write since section G and nothing compared them.

**What it does**

- **The replacement is a compare-and-swap.** `ChecklistFile::replace()` takes
  the content hash the document was derived from and re-reads the file to check
  it still holds those bytes. The comparison happens as late as it can — after
  the replacement is written, immediately before the rename — so the window in
  which an edit goes unnoticed is one `rename` call wide. The stat cache is
  cleared first, because within one request PHP will answer `is_file()` from a
  stat taken before a concurrent editor replaced the file, and a stale answer
  is the exact thing the reading exists to rule out. A file that cannot be read
  back at all — deleted, or swapped for a directory — is a conflict too rather
  than a missing file: the caller has a document in hand it must not write.
- **The caller may also name what it decided on.** `ChecklistWriter::tick()`
  accepts the hash the write was planned against and refuses before anything is
  parsed. This answers a different question from the one above — whether the
  file is still the one the write was *decided* on, which may have been minutes
  and a failed write ago — and it is checked before the marker is looked at, on
  purpose: a run resuming a write it turns out to have already made must still
  be told the file moved, or what it records about the checklist's contents
  describes a document that is not on disk.
- **A conflict is a new exception, not a reused one.**
  `ChecklistConflictException` is kept apart from `ChecklistException`, which
  means the plan no longer makes sense because the task was reworded, and from
  `FilesystemException`, which means the environment refused a write the package
  was entitled to make and the remedy is `chmod`. Here the write could have been
  made and should not have been, nothing was written, and nothing is undone.
- **It is pending finalization, like an unwritable file.** `TaskPass` records
  the plan as still outstanding and blocks with a different next action, because
  there is nothing for the user to fix: somebody saved the file, their save is
  intact, and running again writes the box into it. Said explicitly, since a
  user who reads that a checkbox write was refused will otherwise go looking for
  what they lost. The refusal is deliberately not retried in a loop — a
  checklist being edited while a box is ticked is a person at a keyboard, and
  reading-and-writing until it lands would race against them.
- **The second check is the task's own content, and only a resume needs it.**
  `TaskPass::editedWhileOwed()` compares the plan's `task_hash` against the task
  as it now reads. Rewording a task changes its identity, so section C already
  catches that as different work — but the box's own line can be left alone
  while the acceptance criteria indented under it are rewritten, and the task
  keeps its ID. A write made from the plan would then record a runner-verified
  completion against criteria nothing was ever checked against. This one blocks
  and closes the plan rather than retrying it: the implementation stays on its
  branch and is not repeated, and what has to happen is a person deciding
  whether it still satisfies the task as it now reads.
- **The run's own reading cannot answer that question**, which is why the plan
  is consulted at all. On a resume the snapshot is this run's reading of the
  file, so an edit made between the runs is already in it and compares equal;
  the plan holds what the task said when the checks passed against it. The two
  are reconciled in `writeTheBox()`, so both resumptions get both checks.
- **An edit elsewhere in the document is not a refusal.** When a write that was
  already owed finds its file moved, `plan()` notes it in the attempt log and
  refreshes the row: the user's edit is preserved by writing the box against the
  file as it now reads, which is the whole point of re-reading rather than
  replaying. Refusing there would make an unrelated save in the checklist into a
  blocked task. What would be wrong is to say nothing and leave the row claiming
  a reading of the file nobody would recognise.

**Checks**

- `vendor/bin/phpunit`: **2552 tests, 8699 assertions, all passing** (10 new).
  PHPStan level 8 over all of `src`: no errors.
- Six new `ChecklistWriterTest` cases against real temporary projects, which is
  where the mechanism lives: a checklist edited since the write was decided on
  is refused with its path and both hashes on the exception, and the edit is
  found afterwards exactly as it was made; a file that still reads as planned is
  ticked; an already-ticked task is still refused when the file has moved;
  `replace()` abandons its replacement when the bytes moved while it was being
  written, cleans the temporary file up rather than leaving it beside the
  original, and reports `found()` as null when the file was deleted instead of
  recreating it from a stale document; and a `replace()` with no expected
  content still writes unconditionally, for the callers that are not rewriting
  existing content.
  - Calling `ChecklistFile::replace()` directly is how the instant before the
    rename is reached deterministically. It is the same code path a real
    concurrent save takes; interleaving a second writer inside one PHP process
    is not something a test can do honestly.
- Three new `ChecklistStoreTest` cases: a plan knows the reading of the file it
  was made against and the task it was earned by, a refused write keeps both
  while it waits, and the next run's `plan()` refreshes the row in place rather
  than adding a second claim on the same line.
- One new `RunnerTest` case runs the whole loop twice over a real repository
  with the fixture worker: the first run's checkbox write is refused by a
  read-only directory, the criteria indented under the task are then rewritten,
  and the resume blocks at `finalizing` with `states it differently now` and a
  `ralph retry` next action — the agent invoked once, one attempt, one commit
  beyond base at the recorded SHA, no `- [x]` in the file, the plan `Failed`
  with no `applied_at`, and the task neither user-completed nor runner-verified.
  Mutating the new guard out makes that test tick the box, which is the
  behaviour it exists to pin.

**Notes / deviations**

- **`TaskPass`'s conflict branch is not covered by a test, and cannot honestly
  be.** `plan()` re-reads the file immediately before `apply()` writes it, so
  the caller-level hash it passes is always fresh and the only thing that can
  refuse it is a genuine race inside `replace()`. `ChecklistWriter` is `final`
  and built inside `Workbench::forProject()`, so forcing the exception through
  the runner would mean adding an injection seam to production code for one
  four-line branch. The mechanism and the message are tested where they live;
  the glue that turns the exception into a pending-finalization blocker is read
  rather than exercised. Worth revisiting if section J, item 7 wants it.
- **A concurrent edit cannot be ruled out altogether**, and the docblocks say so
  rather than implying a guarantee. Closing the window completely needs a lock
  every editor of the file would have to take, and a Markdown file the user
  opens in their own tools has no such discipline. What the check does promise
  is that an edit made at any point up to that last instant is seen and the
  write refused.
- **The conflict reports `ExitCode::CONFIGURATION`** — the status for "look at a
  file and run the command again" — until section I, item 5 defines the blocking
  status. Noted there as a known placeholder, as with the other exceptions.
- **A stray space before `InitCommand.php`'s opening tag** was left in the
  working tree by the interrupted run that started this item, and is removed
  here. It would have emitted a byte before any output the command produced.
- **Next is section H, item 9**: stopping on unresolved decisions, exhausted
  attempts, or ambiguous ownership with a readable reason and a documented next
  action. Most of the stopping already happens — `Blocker` carries a reason and a
  next action throughout — so the work is likely to be auditing that every stop
  has both and that the reasons name what the user has to do.

## 2026-11-09 — H9: Every ending says what to do next

**Task**

PROJECT.md section H, item 9: stop on unresolved decisions, exhausted attempts,
or ambiguous ownership with a readable reason and documented next action.

Per task, both halves were already there: `Blocker` has carried a reason and a
next action since section D, and every one of the twenty-four places that raise
one supplies both. The run had only half. `Runner` built its stop reason as
prose — `'TASK-19E0214A needs human input'`, `'the iteration limit of 1 was
reached with 2 tasks still pending'` — printed it on the last line, and wrote it
to `runs.stop_reason`. A user reading that is left to work out whether to raise a
setting, run the command again, answer a question, or go looking for a problem.

Auditing the per-task actions then turned up one that was worse than missing:
the action for a task whose attempts had run out said `ralph retry TASK-…`, and
a retry continues the same generation. `GenerationStart::Continued` keeps the
accumulated attempt count, so following that advice stops the pass again against
the same spent budget without invoking the agent at all.

**What it does**

- **`RunStop` is the run's `Blocker`.** A kind, a reason and a next action,
  neither half constructible as empty, with a factory per ending: a decision, a
  task given up on, a spent limit, a stop asked for, a failure, and the three
  ways of running out of work. `StopKind` lives in `State` beside
  `LifecycleState` and `Phase`, since it is a stored vocabulary; `Schema`
  renders it into the column's `CHECK` so the enum and the database cannot
  disagree about what a stop can be.
- **The kind is not a second spelling of the run's state.** A run that ended
  `completed` may have worked through its checklist, reached a limit, or left a
  task that ran out of attempts, and those want different words — and, once
  section I, item 5 defines them, different exit codes.
  `StopKind::needsAttention()` is exactly the three situations this item names.
- **Both halves are persisted.** Migration 2 adds `runs.stop_action` and
  `runs.stop_kind`, because the person who needs the next action is usually not
  the one who was watching the terminal: they are reading `status` the next
  morning. Appended rather than folded into migration 1, so a project already
  carrying runtime state is upgraded instead of left recording a version that
  promises columns it has not got. Nullable with no backfill — a run closed by
  an earlier build stopped for a reason nobody wrote an action for, and
  inventing one now would be putting words into a finished run's mouth.
- **The run quotes the pass rather than describing it again.** A blocked task's
  stop takes its reason and action straight off the pass's blocker, and so does
  the end-of-checklist stop for whichever task it names. The pass is where the
  judgement was made; a paraphrase at run level is one more thing that can
  disagree with the row, and it would also have to be written to cover
  situations the run cannot tell apart — a spent attempt limit wants the limit
  raised, a branch unrelated work owns wants the branch dealt with.
- **"Exhausted attempts" is reported as the stop it is.** A failed task still
  does not end the run — the tasks after it are independent work, which is
  `TaskResult::stopsTheRun()` and section 2 — so what was missing was that a run
  reaching the end of its checklist having given up on something read as an
  ordinary finish. It is now `StopKind::Attempts`, with the task named and the
  count said.
- **The advice for a spent attempt limit is advice that works.**
  `TaskPass::nothingLeftToTry()` says where the work is kept, that nothing was
  checked off, and that another pass needs either a higher
  `limits.attempts_per_task` or the task restated — restating it is new work
  with a generation and an attempt budget of its own. `unverified()` keys on
  `ExecutionBudget::isLastAttempt()`, so while attempts remain the action is
  still the retry it has always been; the wording changes only where the retry
  would do nothing. `spent()` tells the attempt limit from the run's time budget
  running out mid-pass, which wants `ralph run` again and nothing else.
- **A failure's own hints become the row's next action.** Ambiguous ownership
  found before a task can be taken on — a branch the runner will not touch, a
  checkout holding changes that are not the run's — arrives as a `RalphException`
  that already documents what to do about itself. `Runner::close()` copies those
  hints onto the row instead of leaving them to the one terminal that saw them.
- **`spent()`'s reason was a sentence fragment.** It quoted
  `ExecutionBudget::exhausted()`, which returns "the attempt limit of 3 per
  task" — written to sit inside another sentence. It now does.
- **`RunBudget::spentSetting()`** names the configuration key behind a spent
  limit in dotted notation, which is the half of the next action that prose
  cannot be parsed for.

**Verification**

- `vendor/bin/phpunit`: **2582 tests, 8834 assertions, all passing** (30 new).
  PHPStan level 8 over all of `src`: no errors. `php-cs-fixer --dry-run
  --rules=@PSR12` over every changed file: nothing to fix.
- `RunStopTest` covers all six endings: both halves required and a long one
  shortened rather than refused; a blocked pass quoted verbatim and the fallback
  when it recorded nothing; a spent attempt limit named in the reason with the
  pass's own action passed on; a refusal the pass recorded passed on in place of
  a retry that would meet the same branch again; each limit naming its own
  setting, and a limit that left nothing undone saying so instead of inviting a
  pointless run; an interruption sending the user to `resume`, with and without
  a pending count; and a failure carrying its exception's hints.
- `StopKindTest` pins the stored spellings, the attention partition, and that
  the column accepts exactly the enum's cases.
- `RunStoreTest` reads the two new columns back off the row, including a close
  with a blank action recording none rather than an empty string, and a run
  still going having neither.
- Three new `RunnerTest` cases over real repositories and the fixture worker: a
  run that gave up on a task after two attempts carries on to the next task,
  ends `attempts`, and has both halves on its row with the action naming
  `limits.attempts_per_task` and not naming a retry; a blocked run reports the
  pass's own action unchanged; and a run that could not read its checklist
  mid-loop closes `failed` with the exception's hints as its action. The
  existing limit and Ctrl+C cases gained assertions on the recorded action.

**Notes / deviations**

- **The loop still does not stop the run when one task's attempts run out**, and
  this item is read as not asking it to. Section 2 makes tasks independent and
  `TaskResult::stopsTheRun()` already decided it: a blocked pass stops the run
  because the agent is waiting for an answer, a failed one does not because the
  next task is unrelated work. What item 9 is owed is that the giving-up is
  reported as a stop with both halves, which it now is at the pass and at the
  run.
- **The `attempts` kind is decided by `attempts() >= limits.attempts_per_task`**,
  read from the configuration rather than from the result. A task that failed
  non-retriably with attempts left is therefore reported as an ordinary
  unfinished task, which is what it is — and either way the action quoted is the
  pass's own.
- **Lowering `limits.attempts_per_task` between runs** would make an earlier
  failure read as attempts-exhausted. The reason is written from the limit the
  run was measured against, so it is true of that run; nothing reads it back.
- **No `ralph status` to show the two new columns yet.** They are written and
  read back by `RunRecord`, and the commands of section I are what will print
  them. `RunReport::stoppedForAttention()` and `nextAction()` are there for
  those commands, and are covered by tests rather than by a caller.
- **Migration 2 is the first second migration**, which means the multi-version
  path in `Migrator` now runs on every project rather than only under tests with
  injected fakes. Three `StateBootstrapTest` cases invented a version 2 of their
  own and silently stopped exercising their migrations when the real one
  appeared — they now ask for `Migrations::latestVersion() + 1`, so the next
  version added cannot blunt them the same way.
- **Next is section I, item 1**: `ralph run`, `status`, `logs`, `resume` and
  `retry` wired to the real runner and state store. `resume` and `retry` are
  named throughout the next actions this item wrote; until they exist, those
  sentences describe commands a user cannot yet run.

## 2026-11-09 — I1: The five commands, wired to the real runner

**Task**

PROJECT.md section I, item 1: implement `ralph run`, `ralph status`,
`ralph logs TASK-ID`, `ralph resume` and `ralph retry TASK-ID` against the real
runner and state store.

Until now the four commands after `init` and `preflight` were `PlannedCommand`
registrations: `ralph help` listed them, and running one said the
implementation was pending. The loop itself has been real since section G, and
sections F and H left `Recovery`, `AgentContinuity`, `RunStore::resume()` and
`GenerationStart::Continued` sitting there with no caller — every next action
the package prints has been telling users to run commands that did not exist.

**What it does**

- **`RunIntent` is the difference between the three loop commands, and the only
  one.** `run`, `resume` and `retry` are one loop with three openings: which
  agent, whether a run row is opened or continued, which tasks are taken on, and
  what the lock records. `Runner::start(RunIntent)` is the single path; the lock,
  the checkout inspection, the frozen checks, the per-task sequence and all six
  endings are shared, so a resume cannot drift away from a run in any of them.
  `Runner::run()`, `resume()` and `retry()` remain as named entry points over it.
- **A resume reconciles everything before it works on anything.** Every pass the
  interrupted run left `running` or `interrupted` goes through
  `Workbench::recovery()` up front (section H, item 5), the readings are printed
  with their next actions, and a reading that needs a person is written to the
  pass as its blocker and stops the resume through the new
  `RunStop::unreconciled()`. Done before the first task because a resume that
  invoked an agent on some other task first would have to be stopped again
  afterwards.
- **A resume keeps the base its predecessor recorded.** `git.base` defaults to
  `HEAD` and routinely moves; a pass whose worktree and branch were made from one
  commit must not be finished against another. A predecessor with no recorded
  base resolves the reference afresh.
- **A retry grants the attempt allowance again, and nothing else.**
  `TaskPass`'s new `$forgivenAttempts` subtracts the attempts already recorded
  when the budget is measured, so every message still quotes the configured
  `limits.attempts_per_task` rather than a number the pass invented. The pass is
  continued, not replaced: same generation, same branch, attempts counted on,
  and the recorded failure handed to the next attempt as evidence.
- **A retry's four refusals each say what to do instead**: a task the checklist
  does not state, a task checked off (untick it and run `ralph run`, which is a
  new generation with its own branch), a task never run (`ralph run`), and a
  task interrupted rather than failed (`ralph resume`, which finishes the pass
  instead of starting an attempt over the top of it).
- **`ProjectCommand` and `LoopCommand` hold what the commands share.** The
  current directory as the project, configuration loading, rejection of
  unrecognised options and arguments, `--agent`'s value, and `TASK-ID` parsing
  are in one place, so a mistyped option is refused identically by all five and
  no command can quietly ignore one. `LoopCommand` adds the runner, a real
  `CommandVerifier` and the exit status; the three loop commands differ by their
  `intent()` and their help.
- **`status` reads and never writes.** The state store is opened only if it is
  already there, so asking about a project that has never run does not create a
  database in it; configuration falls back to the defaults, and a checklist that
  cannot be read is reported rather than fatal. Tasks are listed in checklist
  order with state, identifier, attempts, commit and branch, and a box the user
  ticked is labelled `done (by you)` against the runner's `done (verified)`.
- **`logs` joins the worker's output to the checks' verdicts.** Newest pass
  first, each attempt in order, the agent's log printed verbatim as bytes with
  its size, the agent's own summary labelled unverified, each check's recorded
  command, exit code and excerpt out of `verification_results`, and the staged
  diff, no-change evidence and completion record named rather than printed.
- **`ExitCode::ATTENTION` (75) is the status for a run that stopped short.**
  `StopKind::needsAttention()` is the single decision behind it: a decision, a
  task given up on and a run that could not carry on exit 75; a checklist worked
  through, a configured limit and a Ctrl+C exit 0, since each of those did what
  it was asked and printed its next action.

**Verification**

- `vendor/bin/phpunit`: **2621 tests, 9067 assertions, all passing** (39 new).
  PHPStan level 8 over `src` and over every new test file: no errors.
  `php-cs-fixer --dry-run --rules=@PSR12` over all changed files: nothing to fix.
- `tests/Support/LoopProject.php` is the harness: a real repository with a
  commit, a checklist, a configuration naming the fixture worker, `php -v` as a
  check that really runs, and the binary invoked as a subprocess with the project
  as its working directory — because the directory the user stands in is how
  every command finds the project, and an exit status does not exist in-process.
- `RunCommandTest` carries a pending task through to a ticked box, a commit on
  one branch and a `tasks.verified` row; holds a failed task to exit 75 with the
  box unticked and no branch; proves `--agent` overrides a project default that
  would otherwise fail preflight; and refuses an unknown agent, a project with no
  configuration, an argument and a mistyped option.
- `StatusCommandTest` covers a project that has never run (no database written),
  a verified task (the run's heading, agent, checks, iterations, reason and next
  action, and the commit the repository really has on the branch), a failed task
  (exit 75, `stopped while waiting for the agent`, the pass's next action), a
  hand-ticked item reported as the user's, and a project that was never
  initialised being answered on the defaults instead of refused.
- `LogsCommandTest` asserts the worker log is reproduced byte for byte with its
  size, both attempts of a pass tried twice appear in order with both verdicts,
  and the three refusals — nothing recorded, no state store, not an identifier —
  are told apart.
- `ResumeCommandTest` interrupts a real run with a real SIGINT and resumes it:
  one commit for the task and attempts of 2 on the first generation, the
  inherited iteration and time counters printed and recorded, `--agent claude`
  refused with what to type instead, `--agent fixture` accepted as confirmation,
  and a completed run and a project that never ran both told there is nothing to
  continue.
- `RetryCommandTest` continues the pass of a task that ran out of attempts
  (attempts 1 → 2, same generation, one branch, one commit), proves the
  allowance starts again, proves only the named task is taken on while the other
  pending task's box, state and attempt count stay where the run left them, and
  covers all four refusals.
- `RunIntentTest` pins the three openings' answers to the four questions.

**Notes / deviations**

- **A refused retry no longer opens a run row.** The implementation as written
  announced the run before selecting the task, so `ralph retry TASK-11111111`
  left a `failed` run recorded with a typo as its reason — and that row became
  the project's latest run, which is what `ralph resume` continues
  (`RunStore::resume()` refuses only a `completed` predecessor). A mistyped
  identifier would therefore have handed back the iteration and time counters
  the interrupted run had spent, which is exactly what section H, item 3
  forbids. A retry now reads the checklist and checks its task before the row
  is opened; `loop()` takes the already-read `TaskList` so the file is not read
  or recorded twice. An unreadable checklist on a `run` still fails inside the
  loop with the row open, which is what `RunnerTest` holds it to.
- **`status` describes a run by its stop kind, not its lifecycle state.**
  `LifecycleState::describe()` says what a *task* in that state is, so a run that
  worked through a checklist was headed "verified, committed and checked off"
  even when the task in it had failed. A pass's phase is now printed as
  `stopped while waiting for the agent` unless the pass is actually running, and
  the generation label is dropped from the first generation, where it only
  repeated the identifier in the next column.
- **An interrupted run exits 0, and `ralph status` afterwards exits 75.** The
  help text written with the commands claimed 75 for a limit and an
  interruption, which contradicted the committed `StopKind::needsAttention()`.
  The help and `ExitCode::ATTENTION`'s own documentation now describe what the
  code does rather than the reverse, with the asymmetry deliberate: at the
  moment of Ctrl+C the person is present, and `status` is what tells them later
  that something is outstanding. Whether an interruption deserves a status of
  its own is section I, item 5's to settle.
- **`PlannedCommand` is gone**, with the `(not implemented yet)` suffix in
  `ralph help`. Nothing in this build is registered that cannot be run.
- **`StateException` still exits 78**, so "nothing recorded for this task" and
  "this configuration is invalid" share a status. Item 5 is where the six
  documented codes are decided; nothing here invented a seventh in the meantime.
- **Still to come in this section**: `status --json` and the JSONL event stream
  (item 3), completion events and stable event ids (item 4), the exit-code table
  (item 5), and secret redaction with the documented limits of raw worker logs
  (item 6). `logs` prints a whole log with no paging or filtering, which is why
  it reports the size first.
- **Next is section I, item 2**: the selected agent, actual task phases,
  completed/remaining counts, attempt counts, branch names and check results.
  Most of it is already printed by `status` and `logs`; what that item is really
  owed is the counts and the check results in `status`, which currently names the
  frozen checks but not how they came out.

## 2026-11-09 — I2: What a status report is actually asked for

**Task**: Section I, item 2 — display the selected agent, actual task phases,
completed/remaining counts, attempt counts, branch names, and check results.

Four of the six were already printed by `ralph status` when item 1 wired it to
the real state store: the agent appears twice and deliberately — the project
default in the header, because that is what a run started here would use, and
the run's own recorded agent in the run section, because that is what the last
one did use — and the phases, attempt counts and branch names are the task
table's columns and notes. What the item was owed was the other two.

**Completed and remaining counts.** A `progress` line under the agent, which
says `1 of 3 completed, 2 remaining` and then, when the two kinds of done are
both present, `— 1 verified here, 1 ticked by hand`. The checklist line above it
already carried `3 tasks, 1 checked, 2 pending`, which is the file's own
arithmetic; this is the question a person asks instead, phrased as they ask it.

The split is the part worth arguing for. A count on its own is a number that
can be entirely true and entirely misleading: a checklist whose every box was
ticked by hand reports 100% completed and has had no work done on it at all.
`status` already refuses to pass a hand-ticked box off as the runner's in the
task table (section C, item 5), so the summary count cannot quietly do the
opposite one line higher up. The clause is dropped when there is nothing to
split, so a project only ever run by Ralph PHP reads as the plain sentence it
is, and one only ever ticked by hand says so outright.

**Check results.** Read from `verification_results` for the latest attempt at
each pass, and reported under the task's row as `checks: 1 of 1 checks passed in
0.4s`, or `checks: tests failed; 1 of 2 checks passed` when they decided against
it. When the pass has more than one check, each one's own verdict follows on its
own line, so `lint passed in 0.1s` and `tests failed (exit 3)` are both there;
with a single check that would only repeat the headline, so it is left out.

Two decisions behind that. It reads the store rather than the configured check
list, because the frozen names the run section prints say what was *meant* to
run and these rows say what was *decided* — a check that was configured and
never reached is not a check that passed, and collapsing the two is how a status
report starts lying. And it reads the last attempt only: a task that failed
twice and then passed is passing, and the earlier verdicts are what `ralph logs
TASK-ID` exists for.

A pass whose latest attempt has no rows at all is reported as `checks: none ran
against attempt 1` rather than having the line left out. That is the ordinary
shape of a failed pass — the worker never produced anything to check — and an
absent verdict that looks identical to a passing one would send a reader hunting
for the failing check that explains the unticked box.

**Tests** (`StatusCommandTest`, 7 cases, all green; the full suite is 2622 tests)

- The never-run project shows `0 of 2 completed, 2 remaining` before anything
  has been recorded, since a diagnostic that only works after a run is not one.
- The verified task shows `1 of 1 completed, 0 remaining` with no "ticked by
  hand" clause anywhere in the output, and `checks: 1 of 1 checks passed in`.
- A new case configures two checks, the second of which exits 3, and holds the
  report to the headline naming the check that stopped the sequence plus a line
  each for `lint passed in` and `tests failed (exit 3)` — and to the box staying
  unticked at `0 of 1 completed, 1 remaining`.
- The failed-worker case gains `checks: none ran against attempt 1`.
- The hand-ticked case holds the whole sentence, clause included: `1 of 2
  completed, 1 remaining — all ticked by hand, none verified here`.

**Notes / deviations**

- **The run section still names the frozen checks without verdicts.** It is one
  run over many tasks and many attempts, so "how did `tests` come out" has no
  single answer at that level; the verdicts live where they were decided. What
  the run line is for is the other half of the same question — which checks this
  run was *required* to satisfy, snapshotted at its start so a worker's edit to
  the configuration could not swap them (section G, item 3).
- **The progress line is suppressed for an empty checklist**, where `No tasks.`
  below it already says everything `0 of 0 completed` would.
- **Next is section I, item 3**: `status --json` and the structured JSONL event
  stream, with a schema version, sequence, timestamp, run id, task id and
  outcome. `status --json` is currently refused as an unknown option, which
  `StatusCommandTest` pins, so that test changes when the option arrives.

## 2026-11-09 — I3: The same run, written for a program

**Task**: Section I, item 3 — add `status --json` and structured JSONL events
with a schema version, sequence, timestamp, run ID, task ID, and outcome.

Two outputs, one reason for both. Everything this package has printed so far is
prose: it abbreviates a commit to twelve characters, cuts a long description to
the table's width, and gets reworded whenever a clearer wording turns up. That
is right for a person and useless to a wrapper script, a CI job or an editor
extension, which needs the field under a stable name and the whole of the value.
So the same facts go out a second way, with a version number on them — and the
version number is the real deliverable, because it is what lets a consumer stop
rather than guess when it meets a document it does not know.

**`ralph status --json`** prints one indented JSON document on standard output:
the project and its configured source, the progress counts, the latest run's row,
and every task in checklist order with its state, phase, attempts, branch, whole
commit SHA, push state, blocker and verification verdicts. Problems still go to
standard error, so `ralph status --json | jq` works on the project someone is
most likely to be asking a script about — one whose checklist is missing — and
the checklist's problem is a field in the document as well, for the reader who
is not watching the terminal.

Two fields are worth naming. `completion` is always present and says `pending`,
`user_completed` or `runner_verified`: the table makes that distinction in a
sentence, and a consumer must not have to parse English to find out whether this
package actually stands behind a ticked box (section C, item 5). And
`needs_attention` is computed from the same arithmetic the exit status uses, so
the field and the status cannot disagree.

`state` is null for a task with no recorded pass, deliberately: there is no
lifecycle state for work that was never taken on, and `pending` there would be a
seventh state invented for the document. `completion` is the field that always
has an answer.

**The event stream** is `.ralph/runtime/events.jsonl` — one JSON object per
line, appended to across runs, flushed per line so a `tail -f` sees an event
when it happened rather than when PHP's buffer filled. Four kinds: `run.started`,
`task.started`, `task.finished`, `run.finished`. Every line carries the whole
envelope the item asks for, and `task` is present and null on a run-level event
rather than left out, so a consumer can read the field without first asking what
kind of line it has.

Decisions behind the shape:

- **The sequence is the order, and the timestamp is not.** Timestamps are
  written to the second, and all four events of a short run routinely share one
  — which `EventStreamTest` pins rather than works around. The sequence starts
  at 1, has no gaps, and is numbered *per run*, not per file: numbering from the
  file would mean reading all of it back before a run's first line could be
  written, and a truncated file would silently renumber. `(run, seq)` identifies
  an event.
- **One file per project, not one per run**, so a supervising process can be
  told one stable path instead of discovering a new file and racing the runner
  to open it. It is already covered by the ignore rules section B, item 6
  wrote, since it lives under `.ralph/runtime/`.
- **The outcomes are words this package already has**: a finished task's is its
  `LifecycleState`, a finished run's is its `StopKind`, and the two "something
  began" events say `started`. Inventing a parallel vocabulary is how an event
  starts disagreeing with the row `ralph status` reads back.
- **`data` is advisory.** It holds the pass's or the run's own `toArray()`, to
  save a consumer a database read. A field may appear in it without the schema
  moving, and nothing in it is a promise. The envelope is the interface.
- **A stream that cannot be written does not stop the run.** The first failure
  is remembered and mentioned on the terminal — once, at the start if the file
  could not be opened and at the end if it stopped part-way — and nothing is
  written after it, because a stream with a hole in the middle is worse than one
  that stops: a consumer cannot see the hole. Abandoning a running agent because
  a report could not be filed would be the tool doing real harm over a courtesy.
- **The closing line is written however the run ends**, including the failure
  path in `Runner::close()`. A consumer that saw `run.started` and nothing after
  it cannot tell a crashed run from one still going — the exact state that method
  already exists to keep the database out of.

**`Support\Json`** is where both outputs are encoded, so the escaping is decided
once. Slashes and non-ASCII are left unescaped, because `.ralph/runtime/logs` is
a path a person reads in both outputs and `\/` is noise in it. The part that
matters is that nothing it is handed can make it throw: almost every string in
either output came out of another process — a check's output, an agent's account
of itself, a Git error — and `json_encode` refuses invalid UTF-8 outright. A
diagnostic that fails on the one run whose output was unusual is a diagnostic
that fails when it is needed, so invalid sequences are dropped on the way out
and the floats JSON cannot spell become null. The exact bytes are not lost: the
task's log is the copy meant to be exact, and this is a report about it.

**Tests** (19 new; the suite is 2641, all green)

- `EventStreamTest` (10): the whole envelope asserted field by field; the
  gapless sequence across a shared second; a line on disk, whole, before the
  stream is closed; a second run appending to the same file and numbering from
  one; an unopenable stream staying silent rather than fatal; the refusals for
  an envelope a consumer could not use — a task result naming no task, a run
  event naming one, an outcome that cannot be switched on; and a payload holding
  invalid UTF-8 still producing one usable line.
- `StatusCommandTest` (3 new, 1 changed): the document's fields against the
  repository and the store — the whole commit SHA matched against `git`, the
  `runner_verified` completion, the verification verdicts; the document surviving
  a checklist that cannot be read; and the hand-ticked counts kept apart. The
  case that pinned `--json` as an unknown option now pins `--jason`, so a
  mistyped option is still a refusal.
- `RunCommandTest` (1 new): a real `ralph run` leaves the four lines at the path
  a supervising process would be told to watch, correlated by the run id the
  store recorded.
- `JsonTest` (5): one object on one line with no line ending of its own; text
  carrying its own newlines staying one record; invalid UTF-8 dropped rather
  than refused; `NAN`/`INF` becoming null, including nested; and a document
  indented with paths and non-ASCII left alone.
- `tests/Support/JsonDocument.php` is new: a decoded document read by field with
  its type required, so that `string('project.root')` asserts the field exists
  and is a string where it is read, with the path in the failure message. These
  tests are pinning a contract, and `$decoded['a']['b']` states none of it — a
  renamed field would surface as a null somewhere downstream rather than as the
  broken promise it is.

**Verification**

- `vendor/bin/phpunit` → `OK (2641 tests, 9167 assertions)`.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, which is
  exactly the count at `HEAD` before this change: it adds none. (The 43 are
  pre-existing `mixed` findings that newer PHPStan and PHP 8.5 surfaced in code
  earlier entries recorded as clean; they are not this item's to fix, and
  cleaning them up belongs with section J.)
- `ralph help status` and `ralph status --json` run by hand in a fresh
  repository, including the never-initialised case, which prints the document
  and exits 0 with the checklist problem on standard error.
- `git check-ignore .ralph/runtime/events.jsonl` → ignored by the rule section
  B, item 6 already writes.

**Notes / deviations**

- **The stream is not configurable, and not under `logs/`.** The logs directory
  holds copies of what other processes printed, organised by task; this is one
  file, written by this package only, that a supervisor follows for the whole
  project. Making it opt-in would mean a consumer could not rely on it being
  there, and an option to move it would be one more thing for a wrapper script
  to have to discover.
- **The two schema versions are separate numbers.** `status --json` and the
  event envelope are different documents read by different code, and tying them
  together would make every change to one announce a change to the other. Both
  are at 1.
- **No event per phase.** The phase a pass is in is already a column
  (`generations.phase`) and `ralph status` reads it back; an event per phase
  would be a second, less reliable copy of it. The four kinds are the questions
  a supervising process actually has.
- **Next is section I, item 4**: emitting completion events only after durable
  finalization, and stable event IDs for deduplication. `task.finished` is
  already emitted after the pass has written its rows, so the first half has a
  foundation; what item 4 adds is the identity a consumer deduplicates on, which
  the current `(run, seq)` gives only for a stream that was never re-read.

## 2026-11-09 — I4: A line worth acting on, and worth acting on once

**Task**: Section I, item 4 — emit completion events only after durable
finalization; retain stable event IDs for deduplication.

Item 3 gave another program a stream to follow. This item is about the two
promises that stream has to keep before anything automated can be hung off it,
because a consumer does not read `task.finished` — it *acts* on it: merges a
branch, reports a task done, moves a card. The first promise is that the word it
acts on is true of the database and not only of the object the loop happened to
be holding. The second is that meeting the same line twice is not the same thing
as the event having happened twice.

**The outcome is read back out of the store** (`src/Run/DurableOutcome.php`).
The loop already emitted its finishing lines in the right place, but "in the
right place" was a property of the order the lines of `Runner` happen to be in,
and nothing about it would have noticed being reordered. So the outcome is no
longer taken off the `TaskResult`; `DurableOutcome::ofTask()` re-reads the
generation row the pass has just written and the word on the line is that row's
`state`. That makes the ordering structural rather than incidental: moved
earlier, the read would report the row as it stood earlier, which is `running`
and a phase — not a completion. `ofRun()` does the same for the run's row and
its stop kind.

The reading is published rather than merely consulted, under `data.durable`: the
state, the phase, the commit, whether a checklist write is still owed, and when
the row was closed. A consumer that needs more than the single word gets it
without opening the database, and nobody has to take the object's word for the
gate having been applied. The two unconfirmable cases are reported as what they
are rather than dressed up — a generation row that cannot be read back, and a
run row the caller has not managed to close — with `confirmed: false`, a reason,
and the caller's own claim under `claimed`. The claim is also kept when it
*disagrees* with the row, because a stream that silently substituted one word
for another would leave nothing to notice the substitution by.

**Every line names the occurrence it reports** (`src/Event/EventId.php`).
`(run, seq)` orders a run's events and tells two lines apart, which is not the
question a consumer is asking: it names a *line of the stream* rather than an
occurrence, so one ending reported twice arrives under two sequences and reads
as two endings. `id` is a `sha256` prefix over four facts and nothing else — the
run, the kind, the task, and one discriminator saying which occurrence of that
kind this is (`iteration:3` for a task taken on, `generation:1` for a pass that
ended). Not the sequence, not the timestamp, not the payload: all three differ
between two reports of one occurrence, and including any of them would give a
key that only ever matches itself. The run is in it deliberately — a pass that
earned its tick and could not write it finishes one run `blocked` and a later
run `completed`, two genuinely different events that must not collapse. The
parts are length-prefixed before hashing so no value can be arranged to read as
another arrangement.

`EventStream` also remembers the identities it has used for the run and refuses
a repeat, raised like the other envelope mistakes `Event::of()` rejects. A
repeat is not a user's mistake and not a filesystem's; it is this package
reporting one occurrence twice, and swallowing it would hide the bug that
produced it. The refusal is a within-process guard only, and the stream is
honest about that: a second process re-reporting a run cannot be caught here,
which is exactly why the identity is derived from the occurrence — both lines
carry one `id`, so a consumer that acted on the first drops the second on sight.

**Tests**

- `DurableOutcomeTest` (8, new): the row's word announced over the loop's, with
  the disagreement kept under `claimed` and absent when the two agree; the
  commit, the finished timestamp and an owed checklist write published; a
  generation row that cannot be read back reported as unconfirmed with no
  invented `state`; a run row still open saying so while still being announced;
  and a run closed as `interrupted` refusing to announce the `failed` the caller
  decided on. A real in-memory database throughout — the behaviour under test
  *is* the read back out of the store, so a double standing in for the store
  would confirm only that the class can be told what to say, which is the thing
  it exists not to do.
- `EventIdTest` (4, new): the same four facts giving the same name however often
  they are asked about; one fixed-width opaque `ev_`-prefixed column; each of
  the four facts varied one at a time to tell two occurrences apart; and the
  length-prefixing, via a run `a` with discriminator `bc` against a run `ab`
  with `c`.
- `EventStreamTest` (3 new, 1 changed): one occurrence refused a second line,
  with the sequence the first went out on in the message and nothing added to
  the file; two goes at one task written as two occurrences; and the case the
  deduplication is actually for — two processes, one run, two lines that cannot
  be refused and carry one `id` while both being their own run's `seq` 1. The
  whole-envelope case now pins `id` against `EventId::of(...)` rather than a
  literal, so it states where the value comes from.
- `RunCommandTest` (1 new): a real `ralph run`'s finishing lines, read off disk
  — `data.durable.confirmed` true, the state matching the store, the commit
  matching `git rev-parse` of the task branch, no checklist write owed, and the
  four lines carrying four distinct identities.

**Verification**

- `vendor/bin/phpunit` → `OK (2657 tests, 9224 assertions)`.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count as at `HEAD` before this change, and none of them in the files it
  touches. (The 43 are the pre-existing `mixed` findings earlier entries
  recorded; cleaning them up belongs with section J.)
- A stream driven by hand: one `task.finished` line carrying
  `{"schema":1,"id":"ev_b9421628bf1203c3b40c0ea49fb5c01a","seq":1,…,"data":{"durable":{"confirmed":true,…}}}`,
  and the same occurrence appended a second time raising rather than leaving two
  lines in the file.

**Notes / deviations**

- **The identifier is opaque, and that is the interface.** A readable composite
  would invite consumers to parse it, and then the separator and the order of
  the parts would be interface too — while every field it carries is already its
  own field in the envelope. 128 bits of hex is far more than a project-sized
  stream needs; it is chosen to be short enough to read in a terminal and long
  enough that nobody has to think about it.
- **The discriminator is the caller's, not the stream's.** Only the loop knows
  whether a second `task.started` is a second go at the task or a re-report of
  the first, so the stream cannot derive it. It defaults to empty, which is
  right for the two events a run has exactly one of.
- **No schema bump.** Adding a field is what the version was documented not to
  move for; a consumer reading `schema: 1` keeps every field it already reads.
- **The repeat guard cannot be stronger without reading the file back.** Loading
  every identity a project's stream has ever held in order to refuse a
  cross-process repeat would make opening the stream cost the whole file, and
  the identity makes the repeat harmless to a consumer anyway. The limit is
  documented on `EventStream` rather than papered over.
- **Next is section I, item 5**: defining and documenting consistent exit codes
  for success, configuration failure, execution failure, limit exhaustion,
  blocking, and interruption. `ExitCode` already exists and the commands already
  return from it, so the work is pinning the mapping as a contract and
  documenting it, rather than inventing it.

## 2026-11-09 — I5: Nine statuses, and what each one tells a caller to do

**Task**: Section I, item 5 — define and document consistent exit codes for
success, configuration failure, execution failure, limit exhaustion, blocking,
and interruption.

`ExitCode` existed and the commands already returned from it, so this looked
like documentation work. It was not. The set was provisional in three places at
once, and each one was a lie a script could act on: one `ATTENTION` status stood
in for three different endings, a run that spent its iteration budget and a run
somebody pressed Ctrl+C on both exited 0, and twelve exception classes carried a
docblock promising to adopt "the dedicated blocking status of section I, item 5"
once it existed. Settling the set meant deciding what each of those is, not
tabulating what they already were.

**The set** (`src/Console/ExitCode.php`). Nine statuses, every one documented on
its own constant and listed by `ralph help`:

| | |
| --- | --- |
| `0` SUCCESS | the command did what it was asked |
| `1` EXECUTION | work was attempted and did not succeed |
| `64` USAGE | the command line was wrong; nothing ran |
| `69` UNAVAILABLE | the command is not implemented in this build |
| `70` SOFTWARE | an unexpected internal error, which is a bug |
| `75` BLOCKED | stopped with something waiting for a person; work preserved |
| `78` CONFIGURATION | the project or its environment is not in a state to run in |
| `124` LIMIT | a configured limit stopped the run before the work ran out |
| `130` INTERRUPTED | the run was stopped from outside; `ralph resume` continues it |

The values follow conventions a caller already reads rather than counting from
one: 64–78 is BSD sysexits, 124 is what `timeout` exits with for a command that
outlived its budget, 130 is 128 + SIGINT and what a shell reports for Ctrl+C
whether or not this package is involved. 1 is where every other tool puts
ordinary failure. Nothing is in 2–63 and nothing above 130, which
`ExitCodeTest` pins, so a status in either range is this package failing in a
way it does not recognise.

**The line that decides every one of them**: has an agent run, and is there work
sitting somewhere as a result? If yes, the status is `BLOCKED` — the work is
preserved, nothing is undone, and what is missing is a person's judgement. If
no, it is `CONFIGURATION` — nothing happened, nothing is waiting, put it right
and run the command again. That is why `DriftException`,
`ReconciliationException`, `ChecklistConflictException`, `StagingException`,
`CommitException` and `PublicationException` are now 75 (each had promised as
much), while `CheckoutException`, `WorktreeException`, `BranchException`,
`PreparationException` and `LockException` are settled at 78 with the "for now"
removed from their docblocks and the reason stated. `InterruptionException` is
130. Ambiguous ownership splits along the same line and is the clearest case
for it: found before a pass starts it is a configuration refusal, found while
one is running it is a block.

**One mapping, read in one direction and written in the other.**
`ExitCode::forStop()` turns a `StopKind` into a status, and `LoopCommand` is now
one line of it — `run`, `resume` and `retry` cannot drift apart, and the status
comes off the ending the run recorded rather than off a count of task results,
because a run can finish every task it took on and still have stopped short.
`ExitCode::stopKindFor()` is the other direction, and it exists so the row and
the process cannot contradict each other: a refusal that escapes the loop is
recorded with the ending its own status implies, so a blocking refusal closes
the run as `decision` rather than `failed` and `ralph status` afterwards says
what the run said. `PassRecovery`'s `CommitException` is the live path for that
— recovery runs up front, outside `TaskPass`'s catch — and the invariant is
pinned as a round trip in `ExitCodeTest` rather than by a test that corrupts a
repository mid-recovery.

**`status` reports the present, not the past.** The one place two commands
disagree about the same project, and it is deliberate: a run that spent its
budget exits 124 because *that run* stopped short of the checklist, while
`ralph status` afterwards exits 0, because what the project is left holding is
pending tasks — indistinguishable from the pending tasks of a project nobody has
run. Reporting the limit again would make an untouched project's status depend
on a run that is over. The rule is documented on `ExitCode` and asserted as a
pair in `RunCommandTest`.

While wiring `status` to the finer codes, the old reading turned out to be wrong
in a way the single `ATTENTION` had hidden: it treated `blocker() !== null` as
"blocked", and a blocker is recorded for every pass that stopped with something
to say, including one whose checks merely failed. So a pass that ran out of
attempts would have reported itself as waiting on a decision. The state is what
says which it is now; a blocker on a pass in any other open state falls through
to `EXECUTION`. The outstanding set is unchanged — only which code each gets.

**Documentation.** `ralph help` prints the whole table, generated from
`ExitCode::documented()` so it cannot drift from the constants. Each command's
help names the subset it can produce — `run` lists all five of its endings
— and `status --json` now carries `exit_code` and `exit_meaning` beside
`needs_attention`, for a consumer reading the report out of a file rather than
off a pipe. No schema bump: the document's version is documented as moving only
when a field is removed or changes meaning.

**Tests**

- `ExitCodeTest` (9, new): the six categories of the item being six distinct
  numbers; every `StopKind` mapping to its documented status; only the two kinds
  of execution failure sharing one, enumerated from `StopKind::cases()` so a
  seventh ending cannot be added without this failing; the `forStop`/
  `stopKindFor` round trip; everything else recording as a run that could not
  carry on, including a bug; the documentation and the constants describing the
  same set, read by reflection; the values staying inside the ranges a caller
  can interpret; and an undocumented status being called undocumented.
- `RunCommandTest` (3 new, 1 changed): a limit stopping a run exiting 124 while
  `status` afterwards exits 0 and reports nothing waiting — the two halves of
  the present-not-past rule in one case; a task that asks a question exiting 75
  with the pass `blocked`; and a worker that rewords the task in the project's
  own checklist while working on it, which exits 75, leaves the branch and
  commit in place, records `decision` on the run's row, and is reported as 75
  by `status` afterwards (three different pieces of code arriving at the same
  answer). The task given up on moved from `ATTENTION` to `EXECUTION`.
- `StatusCommandTest` (1 new, 2 changed): an interrupted pass exiting 130 rather
  than any failure status, with `ralph resume` in the output; and `exit_code`/
  `exit_meaning` pinned on the JSON document.
- `ResumeCommandTest`, `RetryCommandTest`, `ChecklistReconcilerTest` and four
  Git suites: the assertions that encoded the provisional statuses now state the
  settled ones.

**Verification**

- `vendor/bin/phpunit` → `OK (2670 tests, 9283 assertions)`.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count as at `HEAD` before this change, none in the files it touches. (The 43
  are the pre-existing `mixed` findings in tests that earlier entries recorded;
  cleaning them up belongs with section J.)
- `php bin/ralph help` prints the nine statuses; `php bin/ralph help run` prints
  the five a run can end with.

**Notes / deviations**

- **A lock conflict gets no status of its own**, though its docblock had
  wondered whether it should. Nothing a caller would do differs from the other
  "nothing ran, resolve this and try again" refusals: the lock is released by
  the other runner finishing or by the user dealing with it, and the command has
  to be run again either way. A tenth code would be a distinction with no
  consequence.
- **Exhausted attempts and a run that could not carry on share `1`**, which is
  the only pair that shares anything. Both are work that was attempted and did
  not succeed, and the response to either is to read the logs and attempt it
  again; what separates them is a sentence, and the sentence is on the run's
  row. The test enumerates the kinds rather than listing the pairs, so this
  stays the *only* collision by construction.
- **`run` exiting non-zero on a limit or a Ctrl+C is a behaviour change**, and
  the point of the item. Both previously exited 0 on the grounds that the run
  did what it was asked, which is true and useless to `ralph run && deploy`:
  neither ending means the checklist is done.
- **Next is section I, item 6**: redacting known configured secrets from
  package-generated diagnostics, and documenting the storage and limitations of
  raw worker logs. That finishes section I and leaves section J — the test,
  documentation and release work — as the whole of what remains.

## 2026-11-11 — I6: Taking a credential out of everything the package writes

**Task**: Section I, item 6 — redact known configured secrets from
package-generated diagnostics, and document the storage and limitations of raw
worker logs.

The item asks for two things that pull in opposite directions, and most of the
work was deciding where the line between them falls. Redaction wants to rewrite
text; a log read as evidence wants to be a byte-for-byte copy of what the agent
printed. Doing both to the same artefact would mean a log whose length no
longer matches what the attempt recorded, and whose contents nobody could cite.

**What can be redacted at all** (`src/Support/Secrets.php`, new). Only what the
project named. `agents.*.environment` lists the *names* of the variables an
agent authenticates with, those names are resolved against the environment when
a run starts, and that set is the whole of what redaction can act on. A token
inside a Git remote URL, a key an agent read out of a file, a password a failing
test prints — none were declared, so nothing can recognise them, and pattern
matching for them would be lucky rather than honest. `Secrets` takes values in
through one constructor called from one place and hands none back out:
`redact()` consumes them, `names()` answers in variable names. The same rule
`AgentEnvironment` already followed — a value that cannot be asked for cannot be
interpolated into a message by a caller who did not think about it — and
`AgentEnvironment::secrets()` is now its second value-touching accessor, named
after what it is for.

Replacement is one `strtr`, so the longest candidate wins at each position and a
placeholder is never redacted again by a value occurring inside it. The
placeholder is `[redacted VARNAME]`: it names the actionable half, and it holds
no quote, backslash or newline, so substituting it into a JSON string cannot
turn a valid document into an invalid one.

**A short value is refused, out loud.** Below `MINIMUM_LENGTH` (8 bytes) a
declared value is not redacted — replacing every occurrence of two characters
would corrupt every path, SHA prefix and sentence the package writes while
telling a reader nothing. `unredactable()` reports those names and
`ProcessAdapter` writes them into the attempt's log as a note, because a user
who declared a variable expecting it to be treated as a credential should find
that out there rather than in a log.

**Where it is applied.** Resolved once per run in `Runner::loop()` and handed
down, so no later caller has to remember: `Output` (every composed line,
including `status --json`), `LogFile` (its own `--- ` notes), `EventStream`
(payloads redacted before the envelope is built, not on the encoded line),
`CommandVerifier` (the excerpt a check is recorded with, which reaches the state
store, the next attempt's briefing and `ralph logs`), and
`ProcessResult::redactedWith()`, applied in `ProcessAdapter` to the agent's
captured output before anything is interpreted — the one process this package
hands a credential to is the one whose output becomes evidence, claims and
blockers. `status`, `logs` and `preflight` tell their output the project's
secrets as soon as they have a configuration; `Output::redact()` is additive and
mutates the object a command was handed rather than returning a new one,
because that is also the object `Application::report()` prints a failure
through.

**What is deliberately not redacted**: `Output::text()`,
`Output::diagnosticText()` and the worker's own bytes in a `LogFile`. These are
pass-throughs of another process's output. A chunk off a pipe can end in the
middle of a value, so a replacement there would catch some occurrences and not
others; and a terminal disagreeing with the log it is a copy of would be worse
than either. So an agent that prints its own key prints it into the log, which
is why those files are created 0600 inside the Git-ignored runtime directory.

**The documented half** (`ralph logs --help`). Four paragraphs stating where
logs live and for how long (`.ralph/runtime/logs/TASK-ID[-N]/`, one file per
attempt, readable only by the user, never trimmed or rotated, ignored by Git),
that a log is a verbatim copy holding whatever the agent chose to print, that
declared values *are* replaced in what the package writes about a run and are
*not* removed from the raw worker output, and that a secret the package was
never told about cannot be redacted anywhere. The class docblock that previously
deferred all of this to "section I, item 6" now states the decision instead.

**Tests**

- `SecretsTest` (16, new): replacement including inside a word and at every
  depth of a payload; redacting twice changing nothing; a value matched
  literally rather than as a pattern; the threshold read from the constant and
  the behaviour either side of it; the longer of two overlapping values winning;
  two names for one value being one replacement; merge adding values and keeping
  the first name; a project's secrets being every configured agent's, not just
  the selected one.
- `OutputTest` (5, new): composed lines redacted, pass-through text not.
- `RunCommandTest` (3 new): the case worth the most — after a real run whose
  agent printed its own credential, the *whole* set of files under
  `.ralph/runtime/` is searched for the value and asserted to be exactly
  `[the attempt's agent log]`. The state database, event stream, memory notes,
  completion record, staged diff and lock are all read, so a future diagnostic
  that quoted an agent's output without going through redaction fails this test
  by appearing in the list. The log is asserted to *still* hold the key, because
  that is the documented decision rather than a gap in it.
- `AgentEnvironmentTest` (+), `EventStreamTest` (+), `LogFileTest` (+),
  `CommandVerifierTest` (+), `ProcessAdapterTest` (+): the per-seam cases, plus
  `LoopProject::runtimeFilesContaining()` as the helper the run-level case needs.

**Verification**

- `vendor/bin/phpunit` → `OK (2698 tests, 9353 assertions)`.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count and the same set as at `HEAD` before this change. Two were new while
  the work was in progress: a redundant `array_values()` around a value already
  typed `list<string>` (removed), and one that turned out to be a pre-existing
  `LogFileTest` finding whose line number had shifted by five.
- `php bin/ralph logs --help` prints the four documentation paragraphs.

**Notes / deviations**

- **Every configured agent's secrets, not the selected one's.** A credential
  exported for the agent that was not chosen is still in this process's
  environment and would still be a credential in a log if something printed it.
  Which agent is running does not change what must not be written down.
- **A found bug, fixed on the way**: the new `RunCommandTest` case called
  `TaskRecord::taskId()`, which does not exist — the accessor is `id()`. It was
  the one failing test when this entry's work was picked up.
- **Section I is now complete.** What remains is the whole of section J: the
  test, CI, documentation, example-project and release items.

## 2026-11-11 — J1: The user's Markdown file, through two runs

**Task**: Section J, item 1 — test checklist parsing, stable IDs, reordering,
duplicate descriptions, completed-item skipping, reopened work, and
formatting-preserving checkbox updates.

The first item of the verification section, and the first one whose honest
answer began with reading what is already there. Every facet it names was built
with a unit suite beside it, and those suites are not thin: 208 cases across
`tests/Tasks/` — 40 on parsing alone, 21 on the checkbox write, 25 on
generations, 17 on identifiers. Adding a fifth case about a fenced code block
would have been work that looked like this item and tested nothing new.

What those suites cannot say is whether any of it survives the loop. Each one
holds a single class to its contract with the file or the parse tree in front of
it. But a stable identifier is only worth something if the *second* run agrees
with the first about which task is which; a formatting-preserving write is only
worth something if the bytes that reach `.ralph/tasks.md` after a real pass —
through the parser, the editor, the atomic replacement, the state store and
Git — are still the user's document. Those are statements about the seams, and
nothing was asserting them.

**`tests/Tasks/ChecklistThroughTheLoopTest.php`** (5 cases, new). Each one is a
real `ralph run` as a subprocess, against a real Git repository, with the
fixture worker as its agent and the real verification command. The assertions
are deliberately limited to the two things a user can see: the bytes of the
checklist, and whether work was done twice — branches and commits read out of
Git, generations read out of the state store.

- **A hand-written checklist comes back with only its markers changed.** One
  document holding everything a person's checklist has in it that a run could
  damage: a byte-order mark, Windows line endings, prose above and below the
  tasks, acceptance criteria indented under a task, a worked example in a fence
  whose own `- [ ]` lines are not work, an item ticked by hand, and no final
  newline. The run works through it to the end and the assertion is an exact
  string — the file it started as, with two markers flipped. A rewritten line
  ending, a lost byte-order mark, a normalised fence, a newline appended at the
  end, or the fenced example mistaken for a third task all fail it.
- **A checklist reordered between runs does not redo finished work.** Two tasks
  completed, then the user rewrites the file: a task that was never there at the
  top, the two finished ones below it in the other order, different prose, and
  one marker in upper case with a `*` bullet and doubled spacing. The second run
  does the one new task. The finished task keeps its identifier (compared
  against `TaskId::fromDescription()`, which is where it came from), its single
  generation, its branch and its commit, and its branch tip is read back out of
  Git. Three tasks, three branches, one commit each.
- **Unticking a finished task runs it again without disturbing the first pass.**
  Section C, item 8 as a user actually performs it: there is no command for
  reopening work, there is only the box. A second generation with its own branch
  and commit and its own attempt count, while generation 1 stays completed with
  its commit still at the tip of the branch somebody is reviewing.
- **One task written twice is refused before any work starts.** Exit code 78, no
  branch, and the file as the user left it — a duplicate is not a task that
  fails, because two identical descriptions have one identifier and the run can
  neither tell which line to tick nor tell a second pass from a repeat.
- **A task the user ticked between runs is left untouched.** The ordinary way a
  checklist stops having work in it. The next run has nothing to do, writes
  nothing, and adds no branch for an item it did not implement.

**`tests/Support/LoopProject.php`**: two methods the cross-run cases needed.
`withChecklist()` writes a checklist verbatim, for the documents `withTasks()`
cannot produce. `editChecklist()` rewrites it between two runs without
committing — on purpose, because the checklist is a managed path and a run has
already left its own tick in the file by that point, which is the state the next
run really meets.

**Verification**

- `vendor/bin/phpunit` → `OK (2703 tests, 9403 assertions)`.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count and the same set as at `HEAD`; none in either changed file.

**Notes / deviations**

- **No new unit cases.** The gap this item had was at the seams, and that is
  where the five cases sit. The unit suites it names were read rather than
  extended, and the new file's docblock points at each of them by name so the
  next reader can see what is covered where instead of re-deriving it.
- **One case was wrong before it was right.** The reordering case first ran with
  `limits.iterations` set to 1 to stop the first run early, and the second run
  then inherited the same limit and exited 124. Letting both runs use the
  project's own limits made the case stronger as well as correct: two finished
  tasks move rather than one, so the reorder the second run meets is the whole
  file rather than a line of it.
- **The user-completed/runner-verified distinction** is named by section J, item
  2, so these cases assert only that a hand-ticked item is skipped and its line
  left alone. What the package *calls* such an item is item 2's subject.

## 2026-11-11 — J2: The gate, the states, the limits, and whose box it is

**Task**: Section J, item 2 — test configuration validation, task-state
transitions, limits, and the distinction between user-completed and
runner-verified tasks.

Four facets, and the same shape of answer as J1: each of them is already held to
its contract by a unit suite, and none of those suites can say what a user meets.
`ConfigurationValidatorTest` has forty-odd cases against the loader;
`LifecycleStateTest` checks every pair in the transition table;
`RunBudgetTest` and `ExecutionBudgetTest` do the arithmetic of the limits;
`TaskListTest` and `TaskStoreTest` cover the two kinds of completion; and
`RunnerTest` drives most of it through the loop in process, with a clock it
controls and a scripted verifier.

What none of them asserts is the part the user is on the other side of:

- A rejected setting is only a *gate* if every command is behind it, and only
  safe if the rejection costs nothing.
- A transition table is only worth something if a real run writes states out of
  it, and if the task the run never reached has no state at all.
- A limit is only a limit if the process exits with the status that says so and
  the store afterwards agrees about what was spent — and for the time budget,
  if the seconds counted are seconds that really passed.
- The two completions are only distinguishable if `status` says which is which,
  to a person and to a program.

**`tests/Run/StateAndLimitsThroughTheLoopTest.php`** (8 cases, new). Every one is
a real `ralph` subprocess against a real repository with the fixture worker as
its agent; assertions are exit statuses, the checklist on disk, Git, the state
store, and what `status` prints. No agent CLI, no credentials, no network.

- **A refused setting stops every command before it touches the project.** One
  unusable line — `limits.iterations: 0` — put to all seven invocations a user
  has (`run`, `run --agent`, `status`, `status --json`, `resume`, `retry`,
  `logs`). Each exits 78, each names the file and the setting *on standard
  error*, and each prints nothing on standard output, so a `status --json`
  being piped is never a document with a complaint where the data should be.
  Then the inert half: no state store file, no branch, checklist unchanged.
- **A setting broken between two runs is refused without losing the finished
  work.** The one position where "reject it" could plausibly mean "lose it":
  the refusal arrives with a task already completed, committed and ticked.
  A blank argument in the check command, the second run refused, and the
  finished pass, its branch, its commit tip and the file all unmoved; put the
  setting back and the next run carries on with the second task. No run row and
  no generation was written against the configuration that was never accepted.
- **One run leaves each task in the state its own pass earned.** Four tasks and
  a worker that succeeds, then errors, then asks a question: `completed`,
  `failed`, `blocked`, and — for the task the block stopped the run short of —
  no generation row at all, because "never taken on" is not one of the seven
  states. The three terminal rows coexist, are read back out of SQLite by a
  separate `status --json` process, and the run exits 75 rather than 1 because
  what is waiting is an answer.
- **An agent that outlives the task timeout fails rather than being waited
  for.** The one limit nothing can demonstrate in process: the fixture really
  waits thirty seconds against a budget of two, and the case is over in two —
  which is itself the assertion. The pass is `failed` (nothing is waiting on a
  decision; the agent was too slow), its phase is still `executing`, it has no
  commit, its half-written worktree is kept for inspection, and its box is
  empty.
- **A task is handed back to the agent until its allowance is spent.** Three
  attempts where the allowance is three, one generation holding all three
  rather than three passes, every attempt row `failed`, no commit on the branch
  and the task still `Completion::Pending`. The failure is a failing check and
  not a failing agent on purpose — see the note below.
- **A time budget spent on the clock stops the run before the next task.** A
  check that sleeps for longer than the whole run was given, so the budget is
  gone when the loop comes back for the second task: exit 124 naming
  `limits.run_timeout`, the first task completed and committed anyway (a budget
  is a reason not to start another task, never a reason to abandon the one in
  hand), the second with no pass, and `active_seconds` on the run row at least
  the two that elapsed.
- **A spent iteration limit stops the run with the status and the count that
  say so.** Exit 124 naming the setting, the run row at one iteration and a
  fresh start, the unreached task pending rather than failed — and then
  `ralph status` exiting 0, because a spent budget is yesterday's event and what
  is left is indistinguishable from the pending task of a project nobody has
  run. The second run is identifiably new (nothing carried, nothing resumed from)
  and takes on only what was left: two tasks, two branches, one commit each.
- **A box the user ticked is not reported as a box this run earned.** After the
  run the two items are the same two characters in the file, which is the whole
  problem. Everything that tells them apart comes from the store: the imported
  item has no generation, no attempt, no branch and no commit; the one the run
  did has all four. Asserted for both readers — the sentence a person reads
  (`2 of 2 completed, 0 remaining — 1 verified here, 1 ticked by hand`) and the
  document a script reads (`verified_here`/`ticked_by_hand` as separate numbers,
  `completion` as `runner_verified` against `user_completed`).

**`tests/Support/LoopProject.php`**: `withConfiguration()`'s defaults moved into
a private `settings()`, and `editConfiguration()` added beside
`editChecklist()` — the settings rewritten between two runs and left
uncommitted, which is legitimate because `.ralph/` is a managed path, and which
is the only way to state "the user changed a setting and ran again", including
changing it to something the validator will not accept.

**Verification**

- `vendor/bin/phpunit` → `OK (2711 tests, 9523 assertions)`.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count and the same set as at `HEAD`; none in either changed file.

**Notes / deviations**

- **No new unit cases, again.** All four facets are thoroughly covered as units.
  Everything here is at a seam, and the class docblock points at the suite that
  owns each facet so the next reader can see what is tested where.
- **The attempt-allowance case uses a failing check, not a failing agent.** An
  agent that reports an execution error settles its pass as `failed` at one
  attempt (`AttemptOutcome::settles()`), so it cannot show an allowance being
  spent at all. Only verification failure loops.
- **An inconsistency found and left alone.** `ExitCode::SUCCESS`'s docblock says
  a task that "failed without spending its attempts leaves the loop with work to
  do and is reported as `EXECUTION`", and that success is "a promise that
  nothing is waiting for a person". `RunStop::unfinished()` does the opposite:
  with no task having spent its allowance it returns `StopKind::Finished`, so a
  run whose agent errored on every task exits 0 while `ralph status` afterwards
  exits 1. `RunStopTest::a_refusal_the_pass_recorded_is_what_the_run_passes_on()`
  asserts that behaviour deliberately, so one of the two is wrong and the choice
  belongs to section I, item 5 rather than to a test item. Left as found, and
  the cases here avoid the ambiguous path: each failure asserted exits 1 because
  an allowance really was spent.

## 2026-11-11 — J3: Which agent did the work, and which one was never started

**Task**: Section J, item 3 — test default-agent selection, command-line
overrides, adapter registration, missing-agent errors, and persisted-agent
behaviour on resume.

All five facets already have unit suites: `AgentSelectorTest` for the
resolution and its refusals, `AdapterRegistryTest` and
`AdapterRegistrationFileTest` for registration, `AgentContinuityTest` for the
recorded agent a resume reads, and `RunCommandTest`/`ResumeCommandTest` for the
option and its refusal at the command line. Every one of them answers the same
question — *which selection was produced* — and none answers the question a
user is actually asking, which is *which program ran*. A run that started the
wrong agent would record the right name just as happily.

So each case here installs **two or more usable agents**, each a separate
executable with a journal of its own, and asserts what the agents were asked to
do rather than what the loop decided to ask. That is also what makes section E,
item 9's "never silently fall back" assertable for the first time: a broken
agent always has a working one sitting next to it, so a run that quietly
reached for something installed would succeed and the refusal cases would fail.

**A defect this found, and fixed.** `.ralph/adapters.php` was only ever read by
`ralph preflight`. `Runner::forProject()` built `AdapterRegistry::default()`, so
`run`, `resume` and `retry` saw the shipped adapters and nothing else: an agent
configured for an adapter the project registers itself passed preflight and was
then refused by the run with `names the unsupported adapter`. Section E, item 3
and `AdapterRegistry`'s own class docblock both say the loop performs that
composition; it did not. The fix is one factory, `AdapterRegistry::forProject()`,
used by both the runner and the preflight, so the two cannot disagree about
which adapters exist.

**`tests/Agent/AgentSelectionThroughTheLoopTest.php`** (5 cases, new). Real
`ralph` subprocesses, real repositories, the fixture worker as every agent. No
agent CLI, no credentials, no network (item 8).

- **The project's `agent` setting is the program the run starts.** Two installed
  agents that both write a file of their own; the configuration names one. The
  selected agent made the attempt, the other has an empty journal, and the one
  file in the commit is the selected agent's — so "the right agent ran" is
  answered in Git as well as in the journals. The run prints
  `preferred (adapter "codex", selected by the project configuration)`, which is
  a user's one chance to notice a default they did not mean, and the row records
  `AgentSource::ProjectDefault`.
- **`--agent` decides one run and leaves the default alone.** Two tasks and a
  one-iteration limit, so the two runs do real work rather than repeating one
  task: the option sends the first task to the alternate agent, and the next run
  with no option goes back to the configured default for the second. One attempt
  each, from the agent asked for each time, and `status --json` still reports the
  project's own default — a tool that remembered the last agent used would turn a
  user's one-off experiment into the project's new default without saying so.
- **An adapter the project registers itself carries a task to a commit.** `acme`
  is not a built-in name and nothing in the package mentions it; the only reason
  the loop can execute it is the provider the project's own `.ralph/adapters.php`
  returns. The agent really ran, in the task's own worktree, with the common
  briefing, and the loop treated the outcome as it treats any other: verified,
  committed, ticked, `adapter "acme"` on the run row. Then the other direction —
  with the registration file gone, the same configuration is refused, naming the
  adapter it could not find and listing the ones it has.
- **An agent that cannot be run is refused without anything else being run in
  its place.** The four mistakes a user actually makes, in a project that has a
  good agent installed all along: a name nobody configured, asked for with
  `--agent`; the same mistake in the `agent` setting; an entry naming an adapter
  nobody registered; and an entry whose adapter is real but whose executable is
  not. The first three exit 78 with no run row written, no branch and no edit to
  the checklist. The fourth is not a configuration question — only starting the
  agent can discover its program has gone — so it is a failed pass naming that
  agent, with no commit. Afterwards both installed workers have empty journals
  and no branch holds any work.
- **A resumed run continues with the agent it was interrupted under.** A real
  SIGINT part-way through a task, then the user edits the configuration to name
  the other installed agent, which can do the work perfectly well — so a resume
  that read today's settings would succeed and leave a task implemented half by
  one agent and half by another. `resume --agent alternate` is refused and starts
  nothing; the plain `resume` goes back to the recorded agent, which makes the
  second of its two attempts, commits once, and ticks the box. The alternate
  agent's journal is empty, and the row records `AgentSource::ResumedRun` with
  the predecessor's identifier.

**Supporting changes**

- **`src/Agent/AdapterRegistry.php`**: `forProject()` — the four-line
  composition the class description already documented, in one place.
- **`src/Run/Runner.php`**, **`src/Preflight/Preflight.php`**: both build their
  registry through it.
- **`tests/Agent/WorkerAdapterProvider.php`**, **`tests/Agent/WorkerAdapter.php`**:
  a third-party adapter written the way the documentation says to — two methods
  about its own invocation, everything else inherited from `ProcessAdapter`. It
  is what a project is actually asked to implement, as opposed to
  `NamedAdapterProvider`, which registers a name and nothing more.
- **`tests/Support/LoopProject.php`**: `withAgent()` installs an additional
  agent in a directory of its own (a shared directory would mean a shared
  journal, which is the one thing these cases read), `agent()` hands back its
  worker, and `withAdapterRegistration()`/`withoutAdapterRegistration()` write
  and remove the project's `.ralph/adapters.php`. Installed agents appear in the
  `agents` settings automatically, so a case only says which is the default.
- **`tests/Agent/AdapterRegistryTest.php`**: one case for `forProject()` — a
  project with no registration file is not a project with no adapters, and a
  project with one gets its adapter after the built-ins, where it cannot shadow
  one.

**Verification**

- `vendor/bin/phpunit` → `OK (2717 tests, 9598 assertions)`.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count and the same set as at `HEAD`; none in any changed file.

**Notes / deviations**

- **A refused agent leaves an empty state store.** The store is opened before
  the agent is resolved, so the first three refusals create
  `.ralph/runtime/state.sqlite` with nothing in it. The case asserts that no
  *run row* was written rather than that no file exists — which is the honest
  statement, and the difference from J2's configuration gate, where the refusal
  happens before the store is touched at all.
- **The missing-executable hint is not on the terminal.** The failure prints one
  next action (`Nothing executable exists at …`); the rest of the hint list,
  including "Ralph PHP does not run a different agent in its place", stays in
  the attempt's log. The case asserts the refusal itself and reads the absence
  of a fallback off the workers instead, which is the stronger statement anyway.

## 2026-11-11 — J4: What the agent actually received, and what came back

**Task**: Section J, item 4 — test each built-in adapter's command construction
and result normalisation with deterministic fixtures, including success,
malformed output, blocking, process failure, and cancellation.

Both adapters already had a file each (`CodexAdapterTest`,
`ClaudeAdapterTest`), and `AdapterNormalizationTest` already compared what they
make of one scripted session. All three stop at the adapter's own edges: they
assert the command line the adapter *built* and the result it *returned*, with
a per-test shell-less PHP stub standing in for the CLI. Two things named in this
item were missing from that, and they are the two that cannot be asserted from
the outside.

**Cancellation was untested through any adapter.** `ProcessAdapter` turns a
stopped process into `ExecutionResult::cancelled()` before `interpret()` is
reached, and nothing exercised that branch: both adapter files called their
timeout case "cancellation", which is a different outcome with a different
meaning for the user — a timeout invites raising a limit, a cancellation
invites `ralph resume`. `ProcessRunnerTest` covers the stop one level down, and
`ExecutionResultTest` covers the value object; between them sat an untested
normalisation.

**Malformed output was only covered as an outcome.** A block in the right fence
whose payload is not a report reached `AttemptOutcome::Error` in the
normalisation table, but nothing held what the attempt *carries* — that the
block is quoted back, that the reason is in the evidence the next attempt is
briefed with, and that no claim is taken from a block the package refused.

**`tests/Agent/BuiltInAdapterFixtureTest.php`** (21 cases, new). Every case runs
once per adapter in `BuiltInAdapters::providers()`, through
`AdapterRegistry::default()` from an ordinary `AgentDefinition` — the path a run
takes — against the fixture worker of section E, item 12. No agent CLI, no
credentials, no network (item 8).

- **Command construction, asserted as delivered.** The expected argument array
  is composed from each adapter's own constants and compared against what the
  worker recorded of its `argv`, so `exec --cd … --sandbox workspace-write -`
  and `--print --output-format text --permission-mode bypassPermissions` are
  checked where they can no longer be assumed. The briefing is compared against
  `ExecutionRequest::toMarkdown()` and has to arrive whole, at the end of the
  prompt, with the task description in no argument; the working directory the
  worker reports has to *be* the workspace rather than a directory the command
  line merely names.
- **The five behaviours.** A success is a candidate whose claim is the agent's
  and whose edit is in the workspace; a half-written block is refused with the
  block quoted and `not valid JSON` in the evidence, no claim and retryable; a
  silence is refused differently, naming the status it exited with; a question
  arrives with both halves and is not retryable; a declared failure keeps its
  claim and its status 1; a crash with no report keeps status 127 and no claim.
- **A real interruption.** The worker signals the process that started it, so
  Ctrl+C arrives mid-attempt, with the task timeout set to 60 seconds so a
  cancellation that failed to happen would read as a timeout and fail the case.
  What comes back is `Cancelled` with no exit status, no candidate and nothing
  interpreted from the partial output, and still retryable. The worker's journal
  is the other half: it records the `SIGTERM` it was *asked* to stop with, it is
  gone afterwards, and its half-finished edit is still in the workspace.
- **A guard on the table.** `every_shipped_adapter_has_its_invocation_written_down`
  fails until a third built-in adapter's command line is written down here,
  rather than letting it be run by every case and compared against nothing.

**Supporting changes**

- **`tests/Fixture/FixtureStep.php`**, **`tests/Fixture/FixtureWorkerProgram.php`**:
  `reportingMalformed()` — a step that prints its own correct report with the
  last byte missing. Derived from the same `payload()` the valid block is built
  from, so a fixture asked for malformed output cannot print something that was
  never trying to be a report, and the refusal quotes something recognisable.
  The program's docblock previously said a test needing malformed output should
  script `reportingNothing()`; that is now the other case, and says so.
- **`tests/Fixture/FixtureWorkerTest.php`**: one case for the new knob, holding
  the fixture's own contract — error, no claim, the block and the reason in the
  evidence, and the unclaimed edits still in the workspace.

**Verification**

- `vendor/bin/phpunit` → `OK (2739 tests, 9728 assertions)`.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count and the same set as at `HEAD`; none in any changed or added file.

**Notes / deviations**

- **No adapter changed.** Both gaps were test gaps: the cancellation branch and
  the refusal both behaved correctly the first time they were asked to.
- **The interruption case skips without ext-pcntl or ext-posix.** Stopping a run
  needs one and signalling the runner needs the other, and the package requires
  neither — the same skip `ProcessRunnerTest` uses, for the same reason.
- **`php-cs-fixer` has no committed configuration**, so it was not run: invoking
  it offers to generate `.php-cs-fixer.dist.php` and to edit `.gitignore`, which
  is a decision for the repository rather than for this task.

## 2026-11-11 — J5: What a run leaves in a real repository

**Task**: Section J, item 5 — use temporary Git repositories and the fixture
worker to test branches, worktrees, verification failures, commits,
interruptions, and unrelated-user-change protection.

Each of the six already had a unit suite: `BranchNamerTest` and
`TaskBranchesTest` for names and ownership, `TaskWorktreesTest` and
`WorktreeCleanerTest` for workspaces, `WorkspaceStagerTest` and
`TaskCommitterTest` for staging and commits, `ControllingCheckoutTest` for the
refusal — and `RunnerTest` drives the loop over them in process. All of those
stop at their own object's edge: they assert what a collaborator was *asked*
for. What was missing is the part only a real `ralph` subprocess in a real
repository can answer, which is also the part a user actually sees.

**`tests/Git/WorkspacesThroughTheLoopTest.php`** (new, 7 cases). The loop is a
subprocess, the checks are real commands that really pass or really fail, and
every assertion is something a user could reproduce by hand: `git log`,
`git status`, `git worktree list`, the files on disk, and the rows the next
command will believe. No agent CLI, no credentials, no network (item 8).

- **Isolation, from both ends.** The directory the agent's own journal says it
  ran in is *inside* `.ralph/runtime/worktrees/` — asserted with the separator
  appended, so a sibling named `worktrees-old` could not satisfy it — and the
  content it wrote there is what `git show` returns on the branch. The user's
  end is their checkout: `HEAD` unmoved, the file they wrote still holding their
  bytes, and the whole `git status` equal to one line, the checkbox the run
  ticked. The whole status rather than a lookup: a run that dropped a file,
  staged something or left a half-applied patch fails it, and nothing weaker
  would.
- **Branch names and what outlives the workspace.** Two tasks under a
  *configured* prefix, so the assertion is that the user's setting reached Git.
  Each branch starts from the base SHA its pass recorded rather than from the
  other's work, carries exactly one commit holding exactly its own file, and is
  still there with its commit reachable after the completed pass's workspace has
  been removed — the workspace is scratch space, the branch is the deliverable.
  A closing assertion that nothing was created under the default prefix.
- **Traceability and exclusion in one case.** The commit is read out of
  `git log` rather than off a row, and carries the task, pass, run, attempt,
  base commit and agent. The worker in this case also writes to `.ralph/tasks.md`
  and `.ralph/config.json` — both tracked, so Git reports them and only staging
  can leave them out: an agent must not have its own self-ticked box committed
  as verified work, nor rewrite the settings the run was configured with. One
  file lands in the commit, and the one `- [x]` in the checklist is the run's.
- **A check that really fails.** The verification command is a real process that
  really exits 1. Afterwards: no commit, the user's checkout byte-identical, the
  box unticked, and the work still on disk in a worktree *Git itself* lists with
  the pass's branch checked out in it — which is what makes keeping it useful.
  The check's stderr is asserted to have survived into the attempt's evidence,
  because the next attempt is given it and `ralph logs` prints it.
- **A real Ctrl+C, and a resume that commits once.** The run is signalled while
  the agent is mid-work. It leaves the workspace registered, no commit, the
  checkout untouched. The resume then continues *in the workspace the
  interrupted pass kept* — asserted from the worker's journal, since a completed
  pass has released the row — and the branch ends with one commit. That last
  assertion is the one that matters: a recovery that re-implemented the task
  would still finish green with a ticked box, and the duplication would show
  nowhere else.
- **Somebody else's uncommitted work.** A refusal rather than a convenience:
  stash, discard and commit are each one command away and each can lose it. The
  file is asserted byte for byte, still uncommitted, still the only change; the
  error names the path and says it will not stash, discard or commit; and no
  agent was started, no branch created, no worktree directory even made. Then
  the two things that must *not* block, or the tool would be unusable: an
  ignored path, and an edit to Ralph PHP's own files — the run after them
  carries the task through, which is what shows the refusal was about the user's
  work specifically.
- **Somebody else's branch.** The name is computed with the package's own
  `BranchNamer`, so the case is about the collision rather than about guessing a
  spelling — and the collision is not a mistake anyone made, since a generated
  name is derived from the task a person may well have branched for by hand. The
  other branch is still at its own commit afterwards, no attempt was started,
  and no worktree was prepared (the name is checked first, so there is nothing
  to clean up). `ralph logs TASK-ID` is asserted to tell a user both halves: no
  attempt started, and the branch as the reason.

**Supporting change**

- **`tests/Support/LoopProject.php`**: `worker()`, returning the fixture worker
  installed under the default `agent` name, so a case can ask what the agent was
  asked to do — which invocations, in which directory. The same question
  `agent()` already answered for a worker installed under a name of its own.
  Several assertions here need it: that the workspace the agent ran in was the
  task's own, that a resume continued in the kept workspace, and that on a
  refusal no agent ran at all.

**Verification**

- `vendor/bin/phpunit` → `OK (2746 tests, 9827 assertions)` — the 2739 at `HEAD`
  plus these 7.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count and same set as at `HEAD`; none in any changed or added file.

**Notes / deviations**

- **A rough edge pinned rather than fixed.** On a branch collision the run
  spends no attempt, so the loop reaches the end of the checklist without having
  given up on the task, which is `StopKind::Finished` → **exit 0** — even though
  the only task in the list did not pass and no further `ralph run` can change
  that while the branch stands. `ExitCode::forStop()` confirms the mapping. The
  test pins it with a comment saying so, because it is what a script calling
  `ralph run` sees today; changing the mapping is runner behaviour, outside a
  test item, and worth deciding before 0.1.
- **The refusal names no remedy.** The branch-collision message explains what it
  found but not what to do about it — rename the branch, delete it, configure
  another prefix. Section H, item 9 asks for a documented next action; noted for
  the README (item 9) rather than changed here.
- **The interruption case skips without ext-pcntl.** Recording an interruption
  needs it, and the package requires neither it nor ext-posix — the same skip
  `ProcessRunnerTest` uses, for the same reason.
- **`php-cs-fixer` has no committed configuration**, so it was not run, as in
  previous entries.

## 2026-11-11 — J6: A push that arrives, one that is refused, and the retry

**Task**: Section J, item 6 — test optional pushes against a local bare Git
remote, including rejected pushes and publication-only retry.

Two suites already cover parts of this. `TaskPublisherTest` holds the publisher
to a real bare remote, rejection text and all; `RunnerTest` drives the loop over
a refused push and its retry in process. Both stop short of the one question a
user who turns pushing on is asking: is the commit in the *other* repository
afterwards, and if it is not, what do I type? That is a subprocess question —
exit statuses, `ralph status`, `ralph resume` — so it is a subprocess suite.

**`tests/Git/PublicationThroughTheLoopTest.php`** (new, 5 cases). A bare
repository on the same disk is the remote: the real `git push`, the real
fast-forward rule, the real rejection message, no network and no credentials
(item 8). Every claim about publication is read out of the remote repository
rather than off the row that asserts it or the pushing side's `refs/remotes/` —
a publication that did not arrive is the failure being ruled out, and both of
those would agree with a push that never happened.

- **The commit arrives, and only the task branch does.** The branch is on the
  remote, pointing at the SHA the run recorded, and `git show` in the remote
  returns the bytes the agent wrote — so what was published is the
  implementation, not just a reference. Then the whole set of remote branches,
  which is how "only the task branch" is said: a push that had sent `main` too
  would satisfy the first three assertions. Closed with the completion event,
  which belongs after the push rather than beside the commit (item I4): by the
  time another program reads it, the branch is on the remote.
- **Optional means optional.** A configured, reachable remote and no
  `git.push.enabled`: nothing is published, the commit is still made, and the
  task still completes. The failure mode is a package that pushes because a
  remote happens to exist, and `PushState::NotRequired` is what keeps a
  publication nobody asked for out of the completion conditions.
- **A real rejection, not a missing remote.** Somebody has already pushed work
  of their own under the name the pass takes — the collision a generated branch
  name invites — so Git refuses a non-fast-forward. The three things that must
  survive are each read from a different place: the local branch still at the
  recorded commit with one commit on it, the *remote* branch still at its
  owner's commit, and the checklist still as the user wrote it. The row says
  `Failed`/`Publishing` with the reason Git gave, and `awaitsPublication()` is
  the whole of what is outstanding. `ralph run` exits 1; `ralph status` exits 1
  too, naming the failed push and the phase, and `status --json` carries
  `push: failed` beside the commit SHA and an unticked box.
- **The retry, and nothing but the retry.** With the remote resolved, the push
  is retried and the shape of "only the push" is asserted whole: one invocation
  of the agent in total (from the worker's own journal), one attempt, one commit
  on the branch, one task branch, one checklist write, and the commit on the
  remote is the one the first run made. The worker is given no second step on
  purpose — a run that went back to implementing would fail loudly rather than
  quietly produce a second version.
- **Refused twice.** The branch in the way is left in place, so the second push
  is refused for the first one's reason. Nothing moved: not the remote, not the
  branch, not the checklist, not the agent. The pass still owes the same push
  and still has its one attempt, because somebody else's server must not consume
  the task's attempt limit.

**Supporting change**

- **`tests/Support/LoopProject.php`**: `withBareRemote()`, which initialises a
  bare repository under the project's tools directory — outside the repository,
  so the remote's object store can never appear in a diff a run stages, and it
  is removed with everything else — and registers it with `git remote add`. With
  it, `remote()` for the path, `remoteHeadOf()` for a branch's commit there, and
  `remoteBranches()` for the whole set, since "only the task branch was
  published" is a different claim from "the task branch was published".

**Verification**

- `vendor/bin/phpunit` → `OK (2751 tests, 9904 assertions)` — the 2746 at `HEAD`
  plus these 5.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count and same set as at `HEAD`; none in any changed or added file.

**Notes / deviations**

- **A defect found, pinned rather than fixed: `ralph resume` cannot retry a
  refused push.** The blocker's next action, the `ralph status` report and
  `ralph resume --help` all tell the user to run `ralph resume` after resolving
  their remote. A run that owed the push ended on its own terms, so its row says
  `completed`, and `RunStore::resume()` refuses a completed run: `ralph resume`
  exits 78 with "there is nothing in it to resume". What does finish the work is
  another `ralph run`, which takes the task on, finds the pass owing only its
  publication and goes no further back than the push — which is what the test
  asserts, with the refusal pinned beside it so the mismatch is visible rather
  than merely absent. The publication-retry path itself is fine; only this route
  into it is missing, and it is the only route a user is told about. Deciding
  between the two fixes — let a resume continue a completed run that left a pass
  owing publication, or change all three messages to say `ralph run` — is runner
  behaviour rather than a test item, and should be settled before 0.1, since
  acceptance criterion 5 and section H, item 7 are both about this moment. It
  affects item J9's README too.
- **`ralph status` exits 1 while a failed pass is held.** Not a deviation, but
  worth stating next to the test: the documented mapping is "what the project
  is holding now", and a pass whose push was refused is a failed pass, so the
  status command reports `EXECUTION` rather than `SUCCESS`. Asserted as such.
- **What a bare remote cannot reproduce** is an authentication failure or a slow
  link, so neither is asserted here — the same limitation `TaskPublisherTest`
  records.
- **`php-cs-fixer` has no committed configuration**, so it was not run, as in
  previous entries.

## 2026-11-11 — J7: A crash after the commit, a box that could not be written, and a file edited underneath

**Task:** Test recovery after a post-commit crash, a failed checkbox write, and a
concurrent task-file edit; verify that commits and implementation are not
duplicated. *(PROJECT.md section J, item 7)*

**What was done**

`tests/Run/RecoveryThroughTheLoopTest.php` — seven cases over the three moments
where a durable act and the record of it come apart, every one of them driven
through the real `ralph` binary in a real repository with the fixture worker as
its agent, and every one of them asserting the item's own claim: one commit on
the branch afterwards, and no second implementation.

The three recoveries, as a user meets them:

- **The commit the crash never recorded.** The run is really interrupted —
  SIGINT into a real process with its handlers installed, while the agent is
  working — and what the dying process had done and not written down is then
  done with the package's own `TaskCommitter`, after which the one column it
  never reached is cleared. `ralph resume` reports that it *adopted* the commit,
  and the three readings that make the claim whole come from three different
  places: the branch has exactly one commit, that commit is the one the crash
  made, and the row now names it. Re-implementation would fail the first; a
  branch of the resume's own would fail the second.
- **A pass that has committed will not commit again.** The same crash, except
  that the resumed attempt does edit the workspace, so there is something to
  stage and a pass counting its commits per attempt would put a second one on
  the branch. The committer refuses by name — "already recorded commit <sha>",
  "A pass commits once … never the implementation" — the branch still holds one
  commit, it still contains only the first attempt's file, and the refused edit
  is left in the workspace rather than tidied away.
- **A checkbox write the filesystem refuses.** The checklist's *directory* is
  made read-only, which is the real failure in the real place: a replacement is
  written beside the original and renamed over it. The run exits 75, and what
  survives is everything the tick depended on — the commit on its branch, the
  row naming it, the pass `blocked` but `isFinalizable()`, the planned write
  still outstanding in `checklist_updates`, the file byte-for-byte as the user
  wrote it, and the task's completion still `pending` rather than credited to
  anybody. `ralph status` exits 75 too, and `status --json` carries the commit
  beside an unticked box.
- **Finishing it writes only the box.** The directory is made writable, as the
  blocker's next action says, and the next run says "resuming finalization" and
  is held to a shape rather than to an outcome: one invocation of the agent in
  total, one attempt, one commit, one branch, one applied checklist row, and the
  task recorded as runner-verified. The agent is given no second step on
  purpose, so a run that went back to implementing would fail loudly rather than
  quietly produce a second version.
- **A checklist edited while the agent worked.** The agent writes a task of its
  own into the controlling checkout's `.ralph/tasks.md` while it is working —
  both a real thing agents do and the only way to land a save inside the window
  a run is open. Afterwards both halves hold: the new line is still there, so the
  write did not restore the document to the reading the run began with, and the
  box this run earned is ticked. The added task is not taken on by the same run,
  because a run works from the reading it started with.
- **A task reworded while the agent worked.** The agent rewrites the very task
  it was given, so what comes back answers a description the file no longer
  holds. The pass stops for reconciliation (exit 75) with its commit kept and
  the file exactly as it was found — the package does not undo an edit it did
  not make, even one that cost it a pass.
- **An edit between a failed write and its retry.** The two recoveries meeting:
  a pass already pending finalization, and the user's note appended to the
  checklist before the retry. The planned write now describes a document that no
  longer exists; the log says so ("has been edited since this write was
  planned"), the box goes into the file as it now reads, the note stays, and the
  agent is not invoked at all.

**Verification**

- `vendor/bin/phpunit` → `OK (2758 tests, 9987 assertions)` — the 2751 at `HEAD`
  plus these 7.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count and the same set as at `HEAD`; none in the added file.

**Notes / deviations**

- **A defect found, pinned rather than fixed: a post-commit crash is recovered
  but not finished.** `ralph resume` adopts the commit correctly, and then takes
  the task on as ordinary work: `TaskPass::execute()` short-circuits only for a
  pass that owes a push or a *planned* checklist write, and a crash at
  `committing` has neither — the plan is recorded at finalization, which the
  crashed run never reached. So the agent is invoked a second time. Nothing it
  produces can land (that is the second case above), but the pass ends needing a
  person: with no edits it is blocked on "the workspace is at <sha> rather than
  at the base commit … the task's work may be in a commit nothing recorded",
  which names the commit the same run adopted seconds earlier, and with edits it
  fails on the committer's refusal. Asserted as it behaves, including that
  message, so the mismatch is visible rather than merely absent. The fix belongs
  with the one recorded under J6 and is the same shape: a pass whose
  implementation is recorded and whose checks have passed owes only its
  finalization, and the loop should recognise that from the row rather than from
  a plan a crash prevented. Section H, items 5 and 7, and acceptance criterion 6
  are all about this moment; it should be settled before 0.1, and it affects the
  recovery section of the README (J9).
- **Why the commit is made by the test and not by the run.** The gap this
  reproduces is between two statements — `git commit` has returned, the `UPDATE`
  has not landed — and it cannot be aimed at from outside the process. The
  construction is `PassRecoveryTest`'s: the package's own committer, so the
  branch is claimed the way a run claims it and the commit carries the trailers
  reconciliation reads, and then the single column cleared. Everything before it
  (a genuinely signalled run, a preserved workspace, an interrupted run row) and
  everything after it (`resume`, `run`, `status`) is the real binary.
- **What a read-only directory cannot reproduce** is a full disk or a rename
  refused by something other than permissions; the case skips itself when the
  user running the suite can write to a directory it has no write permission on,
  as the existing `RunnerTest` and `ChecklistWriterTest` cases do.
- **The one-rename window is not tested here.** A save landing between the
  content check and the `rename` is what `ChecklistFile`'s compare-and-swap
  narrows rather than closes, and it cannot be hit deterministically from
  outside the process; `ChecklistWriterTest` holds that refusal directly, and
  what is tested here is the state it leaves — a write still owed, retried
  against the file as it then reads.
- **`php-cs-fixer` has no committed configuration**, so it was not run, as in
  previous entries.

## 2026-11-11 — J8: The suite, on every supported PHP, with nothing to pay for

**Task:** Add CI that runs the automated suite on supported PHP versions without
model credentials or paid external services. *(PROJECT.md section J, item 8)*

**What was done**

`.github/workflows/ci.yml` — one job, `tests`, on push to `main`, on every pull
request, and on manual dispatch, with in-progress runs for the same ref
cancelled because a newer push makes the older answer uninteresting.
`permissions: contents: read` only. Per PHP version in the matrix: check out,
set PHP up, `composer validate --strict`, `composer install`, then
`vendor/bin/phpunit`. No step holds a secret, and the only network access is
Composer reaching Packagist — the agent adapters run against the repository's
own deterministic fixture worker and the Git behaviour against temporary local
repositories and a local bare remote, so nothing here reaches a model or a paid
service. That is release acceptance criterion 8 stated as a job.

The details that decide whether it tests what it looks like it tests:

- **The matrix is `8.3`, `8.4`, `8.5`** — the package's `^8.3` spelled out,
  lowest entry first.
- **Extensions installed are `json, mbstring, pdo, pdo_sqlite` plus `pcntl` and
  `posix`.** The optional two are deliberate: without them the interruption and
  process-tree cases skip themselves, and a skipped test still prints as a pass,
  so a runner missing them would report green over coverage it never had.
- **`coverage: none`**, because nothing here measures coverage and Xdebug would
  slow a suite that starts hundreds of real processes.
- **No lock file is installed from** — `composer.lock` is ignored, as it should
  be for a library, so CI resolves the declared constraints afresh and sees the
  dependency versions a new installation gets.
- **`ubuntu-latest`**, the Linux half of the supported platforms: real Git, a
  `/proc` to find processes through, and POSIX permissions, all of which parts
  of the suite need rather than skip around.
- **The PHPUnit command carries no narrowing flag**, so the whole suite runs,
  including the `installation` group that installs the package into temporary
  directories under a temporary Composer home.

`tests/ContinuousIntegrationTest.php` — five cases, because a workflow file is
code nobody runs locally: it is executed only by a service, on a push, and the
way it goes wrong is by quietly testing less than it appears to. The test parses
the YAML and holds it against the package's own declarations rather than against
a copy of itself: the matrix starts at `Ralph::MINIMUM_PHP_VERSION`, ascends,
repeats nothing, and names only versions `^8.3` admits; every entry is a
*string*, since `8.30` and `8.3` are the same YAML number and two different PHP
versions; the setup step installs every required and every optional extension
`Ralph` declares; the PHPUnit command contains no `--group`, `--exclude-group`,
`--filter`, `--testsuite`, or `--stop-on-failure`; and the file mentions no
`secrets.`, `API_KEY`, `TOKEN`, `ANTHROPIC`, or `OPENAI`. A bumped PHP
requirement or a newly needed extension now fails a local test instead of
silently shrinking what CI covers.

`composer.json` gained `symfony/yaml ^7.3` as a development dependency — the
test parses the workflow rather than pattern-matching it, so the parser is part
of the suite's requirements, not an accident of php-cs-fixer's tree.
`.gitattributes` adds `/.github/ export-ignore`, keeping the workflow out of
distribution archives alongside the other development-only paths.

**Verification**

- `vendor/bin/phpunit` → `OK (2763 tests, 10013 assertions)` — the 2758 at
  `HEAD` plus these 5.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  count and the same set as at `HEAD`; none in the added file.
- `composer validate --strict` → `./composer.json is valid`, which is also the
  workflow's second step.

**Notes / deviations**

- **CI runs the suite and the manifest check, not PHPStan.** Level 9 reports 43
  errors at `HEAD`, every one in test code, so adding the step would make the
  job red on arrival and say nothing about the change that triggered it. Either
  those are cleared or a baseline is committed, and then a static-analysis step
  belongs in this workflow; it is worth settling before 0.1 and is noted here
  rather than silently skipped. `php-cs-fixer` still has no committed
  configuration, so there is nothing for CI to run it with either.
- **The workflow itself has not been executed.** There is no remote on this
  repository (and none is configured), so no push can trigger it; what is
  verified is that it is valid YAML describing the job it claims, that every
  command it runs passes locally, and that the suite passes on PHP 8.5, the
  newest matrix entry, which is this machine's runtime. The first real push is
  what proves the 8.3 and 8.4 legs, and that is the kind of gap J12's recorded
  runs are for.
- **A green job is not proof the suite needed no credentials** — it is proof it
  ran without any being provided. The stronger statement is the one the test
  makes about the file: nothing in it reads a secret, so there is no path by
  which a credential could reach a test even if one existed.

## 2026-11-11 — J9: The README, held to what the package actually does

**Task:** Write a README covering installation, every command, task checklist
syntax, configuration, Codex and Claude Code setup, agent selection, custom
adapter registration, Git behavior, context/memory, recovery, logs, and
limitations. *(PROJECT.md section J, item 9)*

**What was done**

`README.md` — 741 lines, one section per topic the item names, in the order a
reader meets them: requirements, installation (project dependency and global
tool), getting started, every command with its options and exit behaviour,
checklist syntax, the whole of `.ralph/config.json` as a table, Codex and Claude
Code setup with the exact invocation each adapter builds, agent selection,
custom adapters, Git behaviour, context and memory, verification, limits,
recovery, logs and diagnostics, exit codes, limitations, development.

The work this iteration was not writing prose — a draft was already in the
working tree, uncommitted — but **holding every checkable claim in it against
the code and against a real run**, and correcting the ones that were wrong.
Five were:

- **The opening transcript was invented.** It showed an aligned
  `preparing / executing / verifying / committing / finalizing` table that
  `ralph run` does not print. The loop prints `---`-prefixed notes interleaved
  with the agent's and the suite's own output, then one `TASK-… completed: …`
  line per task and a run summary. Replaced with an abridged copy of a real
  run's output, captured by driving the loop through `LoopProject` and the
  fixture worker and eliding only the agent's and the suite's own streams.
  The first thing a reader sees is now the shape they will actually get.
- **`-V`/`--version` was documented as a per-command option.** It is the
  application's: `ralph run --version` exits 64 with `Unknown option`. The
  sentence now says so.
- **The event payload keys were wrong.** The README listed `kind`; `Event::toArray()`
  writes `event`, and also a `data` object the README did not mention. A
  consumer following that list would have read `undefined` for every event's
  kind.
- **Branch and log paths ignored generations.** A reopened task's slug carries
  `-g2` (`TaskGeneration::SEPARATOR`), so the branch is
  `ralph/TASK-…-g2-<description>` and the logs are in `TASK-…-g2/`. The README
  said `TASK-ID[-N]` for logs and said nothing about branches, which is the
  detail that explains how two passes at one task cannot collide.
- **PHPStan was presented as a clean command.** Level 9 reports 43 errors today,
  in `src/State/*` and `src/Verification/CheckRun.php` as well as in tests —
  `mixed` casts where a SQLite row is read. The Development section now states
  the count and why CI runs the suite and `composer validate` instead.

**Verification**

Everything below was checked against the code or by running it, not read off
the draft:

- `ralph init` in a fresh temporary repository: the created paths, the five
  ignore rules appended to `.gitignore`, and `config.json` byte-for-byte against
  the README's configuration block — identical, including every default in the
  settings table.
- A real run through `LoopProject` + the fixture worker, and `ralph status` and
  `ralph logs TASK-ID` over the state it left: the phase vocabulary, the status
  columns, the `---` log convention, the commit trailers and the
  `attempt-01-agent.log` naming all match what is documented.
- The three checklist errors the README promises are errors: an indented
  checkbox, a checkbox with nothing after it, and two descriptions differing
  only in case and spacing. All three refuse with the readable message
  described.
- Adapter claims against `CodexAdapter` and `ClaudeAdapter`: both invocations
  verbatim, and the version floors (`0.40.0`, `2.0.0`).
- Bounds against `MemoryBudget` (20,000 context characters; 10 notes;
  12,000 note characters), extensions and versions against `Ralph`
  (PHP 8.3.0, Git 2.24.0, the four required and two optional extensions),
  the fixed paths against `Defaults`, the exit-code table against
  `ExitCode::describe()` — all nine codes and their wording.
- `php bin/ralph run --version`, `status -V`, `status -v`, `run -h`,
  `--version`: what the flags actually do.
- Every internal anchor link resolves to a heading in the file; the only file
  link is `LICENSE`, which exists; the repository URL matches
  `composer.json`'s `homepage`.
- `vendor/bin/phpunit` → `OK (2763 tests, 10013 assertions)`, unchanged.
- `vendor/bin/phpstan analyse src bin tests --level=9` → 43 errors, the same
  set as at `HEAD`.

**Notes / deviations**

- **No `status`/`logs` sample output is included.** Their shapes are described in
  prose and were verified, but a second and third long transcript would age
  faster than it helps; the run transcript carries the one thing a reader cannot
  infer, which is that output is streamed rather than tabulated.
- **The installation instructions name an unpublished package.** Until 0.1 is
  tagged there is nothing on Packagist, so the README documents the `vcs`
  repository form alongside `composer require --dev`. The URL is
  `composer.json`'s `homepage`, which is the project's own declaration, not a
  guess made here — but nothing in this repository has been pushed to it (there
  is no remote configured), so the snippet is unexercised.
- **`src/Log/LogDirectory.php`'s docblock says `TASK-1a2b3c4d-2/`** where the
  code produces `-g2`. Left alone: it is a comment in code this item does not
  cover, and worth a one-line fix in a commit that is about that file.
- **The README is documentation, not evidence.** J12 is the item that records
  real Codex and Claude Code runs; the invocation and version claims here are
  taken from the adapters and their tests, and the CLI versions they name
  (Codex 0.4x, Claude Code 2.1.226) are the ones earlier iterations exercised.

## 2026-11-11 — J10: An example a reader can copy out and run

**Item:** section J, item 10 — include a working PHP example project and
package-related example tasks that another developer can reproduce.

**What this adds**

`examples/text-tools/`: a real PHP package — `ralph-php-example/text-tools`,
two classes, two test files, `composer test`, PHP 8.3 and `mbstring` and nothing
else — shipped with three unfinished tasks in its own `.ralph/`. `Slugger` turns
a title into a URL slug; `Excerpt` collapses whitespace and shortens a body to a
character limit. Both are honest about where they stop, because that is what the
checklist is for:

- *Add a maximum length to the slug generator* — `src/Slugger.php`.
- *Make Excerpt cut on a word boundary instead of mid-word* — `src/Excerpt.php`,
  which today cuts at the limit and can leave half a word before the ellipsis.
- *Add a ReadingTime class that estimates how long a text takes to read* — new
  files only.

Each touches its own files, so three branches off one base commit do not collide
— the independence this release assumes. Each is verifiable by `composer test`
alone. Each is written the way the README tells a reader to write one: a single
change per item with its acceptance criteria as indented bullets, including the
rejected argument and the edge case.

Around the package: `.ralph/config.json` (the defaults, with `iterations` set to
the three tasks it ships and the check's timeout lowered, since this suite runs
in milliseconds), a `CONTEXT.md` that is the real thing rather than the template
— conventions, the `mb_*` rule, what is out of scope — and `examples/README.md`,
which is the reproduction guide: prerequisites, the copy-out, `git init`,
`composer install`, `ralph init`, `preflight`, `run`, what to look at afterwards,
how to start over, and what the example does not show.

The example must be its own repository, and the guide says why: a run inside this
checkout would create its worktrees and branches here. `cp -r` is therefore step
one, and `ralph init` in the copy is step three — where it keeps all three
shipped files and says so, which is the first release acceptance criterion
demonstrated rather than asserted.

The example tracks its own `.ralph/tasks.md` through a negated ignore rule, so
the checkbox a run ticks shows up in `git diff`. `ralph init` sees the negation
and leaves it alone, which is the documented behaviour this is the first use of.

**Verification**

- `composer test` — **2768 tests, 10071 assertions, green**, up from 2763. Then
  PHPStan level 9 over `src bin tests`: 43 errors, the same pre-existing set as
  at `HEAD` (one new one in the new test was fixed). php-cs-fixer at `@PSR12`
  over the new test and the example's `src/` and `tests/`: nothing to fix.
  `composer validate --strict`: valid.
- `tests/Example/ExampleProjectTest.php` holds the example to being a project.
  Nothing else in the suite touches `examples/`: its PHP is not autoloaded here
  and its tests are not in this suite, so every way it can rot is silent.
  - **Its own suite passes**, run through its real `phpunit.xml.dist` by this
    suite's PHPUnit binary, with a stand-in autoloader written from the
    example's *own* `autoload` and `autoload-dev` PSR-4 declarations — so a
    namespace or directory renamed in one place and not the other fails here
    rather than only for a reader who ran `composer install`. 12 tests.
  - **The real `ConfigurationLoader` accepts its configuration**: both agent
    entries present, the default among them, `composer test` as the check,
    `composer install --no-interaction` as the preparation, nothing to copy,
    one iteration per shipped task, pushing off, every path in it existing, and
    its top-level keys equal to `Defaults::toArray()`'s.
  - **The real `ChecklistReader` finds the three tasks it documents**, in order,
    none ticked, all `Pending`, identifiers unique, and every one of them with
    acceptance criteria attached.
  - **`ralph init` in a copy** keeps all three files byte for byte, creates the
    four runtime directories, leaves the negated checklist rule negated —
    asserted through `git check-ignore`, which has to answer 1 — and `ralph
    status --json` then reports 3 of 3 remaining with those three descriptions.
  - **A run really finishes a task.** The copy gets two configuration changes
    and no more: the `codex` executable becomes the fixture worker, and
    `iterations` becomes 1. The run exits 124 at its iteration limit with the
    first task `completed`, `runner_verified`, ticked in the reader's own
    checklist, and committed on `ralph/TASK-44AEB58A-…`, with `src/Slugger.php`
    in the commit, `.ralph/tasks.md` *not* in it, and the `tests` check recorded
    as passed. The example's own `composer install` prepared the worktree and
    its own `composer test` was the gate.
- By hand, in `/tmp`, following `examples/README.md` exactly: `git init`,
  `composer install` (`OK (12 tests, 13 assertions)`), `ralph init` (three
  `kept` lines, four `created`, `extended .gitignore`, and no
  `/.ralph/tasks.md` rule appended), `ralph status` and `ralph preflight` —
  which passes every check and fails only on `agent`, because no `codex` is
  installed on this machine, naming the setting to change. The status table,
  the init output and the branch names quoted in the guide are that output, and
  the three branch names were checked against `BranchNamer`.
- No agent CLI and no credentials (section J, item 8).

**Notes / deviations**

- **The run test needs Composer and a network, and says so.** It installs
  PHPUnit into the task's worktree exactly as a reader's run would, which is the
  part of the example that cannot be faked: a linked worktree has the tracked
  files and no `vendor/`, and whether `workspace.prepare` closes that gap is the
  question. It skips itself when Composer is absent, like the installation
  group. Cold cache, 28s; warm, 2.3s. `Cli::run()` gained an optional timeout
  for it — the shared helper's fixed minute would report a slow mirror as a
  defect — and the default is unchanged.
- **The fixture's implementation of the first task is not a reference answer.**
  It is the smallest change that makes the item's behaviour true and keeps the
  example's existing tests passing, in a new test file rather than in
  `tests/SluggerTest.php` as the criteria ask. What is under test is the
  example's configuration, not an agent's judgement; a run by a real agent is
  section J, item 11.
- **One task per run in that test, not three.** A fixture plan's last step
  repeats, so letting it run on would tick off *Make Excerpt cut on a word
  boundary* with the slug implementation — a green suite and a task nobody did.
  One task, the iteration limit, exit 124.
- **The example is shipped, not export-ignored.** A reader who installed the
  package can copy it out of `vendor/celyes/ralph-php/examples/`, which is the
  only reproduction path available before 0.1 is on Packagist.
- **`composer test:example`** runs the example's directory of tests on its own,
  and the README's Development section now names it along with what it needs.
- **The example's tasks are deliberately easy**, and the guide says so: a
  failure in a run of it should be a failure of the loop — a worktree, a check,
  a commit — rather than an agent struggling with a hard problem. What a run
  produces still depends on the agent, its model and its version, and the guide
  does not pretend otherwise.

## 2026-11-12 — J12: Letting a maintained package run the processes

**Item:** section J, item 12 — make sure any functionality of the project like
git or printing to terminal is using an external well-maintained package if
available.

**What this adds**

`symfony/process` is now a runtime dependency, and `ProcessRunner` is written on
top of it. Every command the loop runs — each `git`, the coding agent, each
verification command, each preflight probe — goes through it, because that one
class was already the single place a process is started. What left the package
with it:

- the `proc_open` call, its descriptor table and its `proc_close`;
- the `stream_select` loop that drained both pipes without deadlocking;
- the spool file a briefing was written to so that filling standard input could
  not deadlock against output nobody was reading. The library writes input in
  the same turn as it reads output, so the briefing now goes straight to the
  command's standard input and no temporary copy of the user's text exists at
  all — one fewer file, and one fewer thing to remove on the way out;
- the `proc_get_status` retry loop that waited for an exit code to be reported.

What stayed, because it is this loop's and not a process library's:

- **Cancellation.** `wait()` watches the command, asks `Interruptions` whether a
  stop has been requested, and enforces the task's own timeout — the library's
  timeout would stop the command by signalling it alone, leaving an agent's test
  runner behind, which is the whole subject of `Terminator`.
- **The process tree.** `Terminator` and `ProcessTree` are unchanged in
  behaviour: `/proc` read while the child is alive, signalled deepest first,
  asked then killed, with a verdict that distinguishes confirmed from
  unconfirmable. What changed is what they take: a new `ManagedProcess`
  interface — process id, liveness, signal — with `StartedCommand` as the one
  adapter onto the library's `Process`. Nothing else in the package names that
  type, and the id is read while the command is certainly running, because the
  library stops reporting it once the process has gone and the children of a
  worker that returned are found by walking down from it.
- **The environment.** Still the caller's, overridden only where asked, with
  `false` removing a variable. Passed in full and with a removal for everything
  else the library would have filled in from `$_SERVER`: a command-line
  process's `$_SERVER` holds `PHP_SELF`, `SCRIPT_NAME` and `REQUEST_TIME`,
  which are not environment variables and have no business in a coding agent's.

Two deliberate behaviours had to be rebuilt on top of the library rather than
inherited from it:

- **A command that cannot be started is still an error, not an exit status.**
  The library answers a command it could not spawn by retrying it through a
  shell, so a missing executable arrives as exit 127 from a command that never
  ran — indistinguishable from a program that ran and exited 127. The package's
  handling of a missing agent or a missing check depends on telling those apart,
  so the runner now resolves the executable with `ExecutableFinder` first — the
  same resolution preflight and the verifier report against — and a command
  nothing answers to is a `ProcessException` naming where it looked.
- **The waiting is paced.** A fixed poll interval made every `git rev-parse`
  wait for the next turn, and there are dozens of those per task: the suite went
  from 1:40 to 3:42. The schedule now starts at 200µs, relaxes to 5ms after
  50ms, and to 20ms after a second — so a plumbing call is noticed almost at
  once, an agent's output reaches the terminal within 20ms, and a Ctrl+C during
  a quiet twenty-minute agent is acted on sooner than the old one-second
  `stream_select` would have.

The audit behind the item is written down in the README's new **Dependencies**
section, where a reader can disagree with it: one table row for what
symfony/process does here, then the five places the package does the work itself
and why — the `git` binary rather than a Git wrapper (none exposes linked
worktrees, `status --porcelain=v2`, a rejected push or branch ownership, so most
calls would drop to its escape hatch anyway), the process tree, the
byte-preserving checklist parser rather than a Markdown library, the small
argument parser and help text rather than a console framework, and PDO rather
than a query builder.

**Verification**

- `composer test` — **2768 tests, 10073 assertions, green**, the same count as
  at `HEAD`: this is a replacement of plumbing, so the suite that already covers
  streaming, timeouts, cancellation, process trees, large input, large output
  and unstartable commands is exactly the evidence wanted, and it passes
  unchanged except where it named the old implementation.
- **Both major versions of the dependency.** The constraint is
  `^7.2 || ^8.0`, because symfony/process 8 requires PHP 8.4 and this package
  supports 8.3: CI's 8.3 job resolves 7.x and the others resolve 8.x. The full
  suite was run against **v7.4.22** and **v8.1.10** on this machine, green on
  both. (Only PHP 8.5 is installed here; the version matrix is CI's.)
- PHPStan level 9 over `src bin tests`: **43 errors, the same pre-existing set
  as at `HEAD`**, none of them in anything touched here. php-cs-fixer at
  `@PSR12` over every changed file: nothing to fix. `composer validate
  --strict`: valid.
- By hand, in `/tmp`: `ralph preflight` in a copy of the shipped example, which
  reports the same ten checks as the J10 entry quotes — PHP, extensions, Git
  2.43.0, the repository, the base commit, the author identity, the workspace's
  preparation command and the project's check all passing through the new
  runner, and `agent` failing because no `codex` is installed here.
- By hand, a three-line command run through `ProcessRunner` with a
  `TerminalSink`: the lines appear 400ms apart as the command prints them rather
  than together at the end, and the result still carries all 75 bytes.

**Notes / deviations**

- **The installation tests now use the network.** Their Composer workspace had
  Packagist switched off, on the stated grounds that the package required no
  third-party code; that premise is gone. Packagist is back on so a consumer's
  install resolves the runtime dependency exactly as a real `composer require`
  does, with the package itself still coming from a `path` repository. The
  alternative — mirroring the dependency closure out of this checkout's
  `vendor/` as versioned path repositories — would keep the group offline at the
  cost of test support that has to be maintained alongside every future
  dependency, and the suite already needs the network to install PHPUnit at all.
  Said on the test class and in the README's Development section.
- **Printing to the terminal stays the package's own**, which is the half of the
  item's wording this does not adopt, so the reasoning is in the README rather
  than only here. What is hand-rolled is two `fwrite` calls and the redaction
  around them; symfony/console would earn its place as a *command framework*,
  not as a replacement for those, and taking its framework means its argument
  parsing, its error wording and its help layout — `Unknown option "--jason" for
  "ralph status".` with the command to run next becomes `The "--jason" option
  does not exist.` It is a decision worth revisiting on its own; it is not this
  item's process plumbing.
- **A command killed by a signal from outside the loop now reports 128 plus the
  signal** rather than no status, which is the shell's convention and the
  library's. A command the *loop* stopped — timed out or cancelled — still
  reports no exit code, because that is a statement about the work and it never
  made one.
- Section J, item 11 (real runs with Codex and Claude Code) is still open and
  was not attempted here: no `codex` CLI is installed on this machine, and a
  real run of either is a credentialed, paid, supervised exercise rather than
  something for an unattended iteration to start on its own.

## 2026-11-12 — J11 (part): A real pass with Claude Code, and why Codex is still open

**Item:** section J, item 11 — demonstrate real runs with Codex and Claude Code
on small PHP-package changes; record tested agent CLI versions, resulting
commits, verification output, interventions, elapsed time, and known
limitations. **The checkbox stays unticked**, for the reason the last section
below gives: the Claude Code half was done for real, the Codex half cannot be
done on this machine.

**What this adds**

[`docs/demonstrations.md`](docs/demonstrations.md), the record of an actual run,
written so a reader can tell what has been *observed* from what the fixture
worker merely *covers*. The suite proves the loop's mechanics against a
deterministic stand-in; this is the other half — a paid agent, its real CLI, and
three commits it left behind.

The run itself: a copy of the shipped example in `/tmp/ralph-demo`, its own Git
repository, base `a085a0d68e65`, `"agent": "claude"`, `limits.iterations` left
at 1 so each task is its own `ralph run`. **Claude Code 2.1.226** completed all
three of the example's tasks, first attempt each, nothing retried, nothing
blocked, no decision asked for:

| Task | Commit | Diff | Suite afterwards |
| --- | --- | --- | --- |
| `TASK-44AEB58A` slug maximum length | `6008de650aef` | 3 files, +94 −7 | `OK (20 tests, 22 assertions)` |
| `TASK-FC1A4E61` Excerpt word boundary | `81c0095dfe7c` | 2 files, +48 −7 | `OK (15 tests, 15 assertions)` |
| `TASK-31D64F84` new `ReadingTime` | `113cd6f4157d` | 2 files, +131 −0 | `OK (20 tests, 21 assertions)` |

About **3m 10s of wall clock for all three**, of which roughly 3 minutes is the
agent: 1m 16s, 1m 0s and 39.9s of thinking against 79s, 63s and 45s of run. The
loop's own share — worktree, `composer install`, verification, staging, commit,
checklist — is seconds per task, which is worth knowing because it says the cost
of a run is the agent and nothing else.

Three branches all off the same base rather than stacked, every commit carrying
its `Ralph-Task`/`Run`/`Attempt`/`Base`/`Agent` trailers, the three worktrees
removed on completion, `git worktree list` back to just the checkout, and the
only change left in it the one the package is supposed to leave: three flipped
markers in `.ralph/tasks.md`.

**Verification**

- The record quotes the package's own output verbatim — the check block, the
  `status` panels, the branch graph, the commit trailers, the refusal messages —
  rather than describing it. Every commit SHA, branch name, test count and
  timing in it was read back out of the demonstration repository.
- **The diffs were reviewed, not merely verified.** All three follow the
  example's stated conventions unprompted: `final`, `private readonly`,
  `strict_types`, `InvalidArgumentException` naming the value, `mb_*` with an
  explicit `'UTF-8'`, existing tests updated rather than contradicted. Each class
  also dropped the stale "the task in `.ralph/tasks.md` adds this" docblock that
  its own change made untrue.
- `composer test` — **2768 tests, 10073 assertions, green**, unchanged: nothing
  in `src/` was touched.

**Three interventions, recorded as such**

1. **`ralph init` makes the first `ralph run` refuse.** `init` extends
   `.gitignore`, which leaves one uncommitted change, and the pre-run inspection
   will not stash, discard or commit what it did not write — so it stops with
   exit 78 and names the file. Correct behaviour meeting itself head-on, and the
   first thing a reader following the walkthrough hits. Both READMEs now commit
   it as a step, with the reason.
2. **Piping `ralph run` into `head` crashed the run** —
   `fwrite(): Write of 129 bytes failed with errno=32 Broken pipe`, raised into
   an `ErrorException` by the handler at `src/Console/Application.php:271`
   because `Output::write()` (`src/Console/Output.php:147`) writes unguarded. A
   real defect, found by doing it accidentally. Durable state survived intact —
   the task had already been verified, committed and checked off, and `status`
   showed it `completed` — but the run is *reported* as a failure it did not
   have and the remaining tasks never start. `ralph status | less` quit early is
   the same bug. **Recorded, not fixed:** the fix belongs to section J, item 13,
   which is the open item for exactly this. Added to the README's limitations so
   it is not a surprise in the meantime.
3. **One task changed a file it was not asked to.** `TASK-44AEB58A` also edited
   the example's `README.md` and said so — *"tell me if you'd rather the README
   stayed out of this change."* Not asked for, not forbidden by `CONTEXT.md`, so
   it is in the commit; on review it is a change a person would likely keep. In
   the record because it is precisely what "review every commit" means: green
   checks say the project builds, not that the diff is the one you asked for.

**Notes / deviations**

- **The item is half done and the box is unticked.** PROJECT.md's own rule for
  section 5 is to mark an item complete only when its behaviour works, and
  "real runs with Codex **and** Claude Code" has had one of the two. No `codex`
  is installed here, and installing one would only be half the problem: a real
  run needs that CLI *signed in*, which is an interactive, paid, supervised step
  belonging to a person rather than to an unattended iteration. The Codex
  adapter stays **tested but not demonstrated** — its command construction,
  normalisation, blocking, malformed output, failure and cancellation are
  covered against the fixture worker, and preflight checks its executable and
  version floor, but no credentialed run has happened. `docs/demonstrations.md`
  says so under its own heading, with the steps to reproduce it, so whoever has
  a signed-in `codex` can close the item by repeating the pass.
- **Only one task's worth of agent judgement is on display**, three times over.
  This is a demonstration, not PROJECT.md section 7's ten-task evaluation: it
  says the loop works end to end with Claude Code on a small PHP package, and
  nothing about how often that holds.
- A smaller friction worth writing down: the example commits no `composer.lock`,
  so every task's worktree resolves dependencies afresh (*"No composer.lock file
  present"*). Two seconds on this package; on a project with a committed lock it
  installs from the lock as normal.

## 2026-11-12 — J13: The two problems that stood between the loop and a tag

**Item:** section J, item 13 — resolve blocking setup or recovery problems
before tagging version 0.1. **Checked off.** The previous pass found exactly
two, recorded both rather than working around them, and left them for this
item: a crash when a reader walks away, and a first run that refuses because of
what `ralph init` wrote a minute earlier. Both are now behaviour rather than
limitations.

**1. A closed output no longer ends a run**

`ralph run | head` closes the read end of the pipe as soon as `head` has its
lines. The next write failed with `errno=32 Broken pipe`, PHP raised it as a
warning, `Application::convertErrorsToExceptions()` turned it into an
`ErrorException`, and the run stopped — exit 70, reported as a failure it had
not had, with every remaining task unstarted. `ralph status | less` quit on the
first screen was the same bug.

`Output::write()` (`src/Console/Output.php:141`) now suppresses the failed
write, remembers the stream as gone, and never writes to it again; a partial
write — which a non-blocking stream can return without being broken at all — is
looped rather than treated as the end. A terminal is a copy of what the loop is
doing, never the thing itself, so losing it is not a reason to stop: the logs,
the event stream, the commits and the checklist are files and were never
affected.

What this did *not* change: `LogFile`, `EventStream` and `HeldLock` already
guarded their own writes and record a first failure, so the defect was Output's
alone.

**2. `ralph init` now says to commit what it wrote**

`init` appends ignore rules to `.gitignore`, which leaves the checkout with one
uncommitted change; the pre-run inspection will not stash, discard or commit a
change it did not write, so the very next `ralph run` stopped with exit 78
naming the file. The refusal is correct and stays exactly as it was — the fix
belongs where the file was written. `InitCommand::nextSteps()` builds the list
instead of hard-numbering it, and inserts *"Commit .gitignore: a run will not
start while the checkout holds uncommitted changes it did not write"* before
the preflight step — only when rules were in fact written, since a second
`init` writes none and the files under `.ralph/` are the package's own and never
block a run. The README's quick start commits it too, and says why `init` runs
no `git` of its own.

Also in that sentence: *"1 uncommitted change that are not part of…"* now agrees
with itself (`CheckoutInspection::summary()`). It is the first line of a refusal,
and the plural case was already covered by a test.

**Verification**

- `composer test` — **2773 tests, 10091 assertions, green** (2768 before; five
  new).
- The regression test is the real binary, not a unit: `ralph run` on two tasks
  with the read end of its standard output closed before it can write a byte
  (`Cli::runWithoutAReader()`, a plain pipe — a socket pair looked equivalent
  and is not, because the child inherits the parent's end and the pipe never
  breaks). **Against the old `Output` it fails with exactly the recorded
  defect** — `exit code: 70`, `ErrorException: fwrite(): Write of 119 bytes
  failed with errno=32 Broken pipe` — and against the new one both tasks are
  verified, committed and checked off and the run exits 0. Confirmed by
  stashing the fix and re-running.
- `OutputTest` pins the two halves directly: a write to a stream whose reader
  has gone raises nothing, and the *second* write is not attempted at all —
  asserted through `error_get_last()`, since a failed `fwrite()` sets it and a
  write that never happened cannot. Plus: losing one stream leaves the other
  one writing, so a diagnostic still has somewhere to go.
- `InitCommandTest` covers both directions of the new step — printed when rules
  were written, absent when they were not.
- Checked by hand in a throwaway repository: `init` → `run` reproduced the
  refusal before the change; after it, the printed steps name the commit.

**Notes / deviations**

- **The record stays truthful about what was demonstrated when.**
  `docs/demonstrations.md` quoted both problems as open. The quoted output of
  that pass is left verbatim — it is what happened — with a *"Since this pass"*
  note under each intervention, and the known-limitations entry struck through
  rather than deleted. The README's limitations list loses the closed-output
  bullet entirely, because it is no longer one; what the behaviour *is* now
  lives under "Logs and diagnostics".
- **`isIntact()` was written and then removed.** Output briefly exposed whether
  anything had been lost. Nothing asked, and an accessor no caller uses is a
  claim the package would have to keep; the tests observe the behaviour instead.
- **Scope held to the two recorded problems.** PROGRESS.md records exactly one
  other open defect in this package's history, in `bin/ralph`, fixed at the
  time. Nothing else was hunted for under cover of this item.
- **No push.** This repository has no remote, so the work is committed only.

## 2026-11-12 — J11: A second real pass with Claude Code, with nobody stepping in

**Item:** section J, item 11 — demonstrate real runs with Claude Code on small
PHP-package changes and record the CLI versions, commits, verification output,
interventions, elapsed time and known limitations. **Checked off.** The item
asked for Codex as well until it was narrowed to Claude Code; the Codex half is
still recorded in `docs/demonstrations.md` as tested but not demonstrated rather
than dropped, because the package ships the adapter.

**Why a second pass rather than ticking the first one**

The 11 November pass was run against code that no longer exists: the two
defects it found were fixed the next day, and one of them ended a run with
exit 70. A demonstration of the release is a run of the release, so this is a
fresh pass on `6cf26e1` — a copy of `examples/text-tools/` in `/tmp/ralph-demo2`,
its own repository, base commit `a3da8e1c0845`, Claude Code 2.1.226, PHP 8.5.11,
Git 2.43.0, Composer 2.9.5, one check (`composer test`), publication off.

**What happened**

Four tasks, four commits, four checked boxes, **all verified on the first
attempt, and no interventions at all.**

| Task | Commit | Diff | Suite afterwards | Agent |
| --- | --- | --- | --- | --- |
| `TASK-44AEB58A` slug maximum length | `f235d10a1e6f` | 2 files, +70 −6 | OK (17 tests, 19 assertions) | 1m 1s |
| `TASK-FC1A4E61` word-boundary excerpt | `1797d5de2971` | 2 files, +35 −7 | OK (14 tests, 14 assertions) | 52.5s |
| `TASK-31D64F84` new `ReadingTime` | `7fe91ad39c24` | 2 files, +112 −0 | OK (20 tests, 21 assertions) | 30.2s |
| `TASK-FC1A4E61` again, generation 2 | `c917ca01ac54` | 2 files, +34 −7 | OK (14 tests, 14 assertions) | 52.3s |

The three pending tasks are one run — 2m 30s of wall clock, of which 2m 24s is
the agent. The loop's own share is seconds a task.

**The two fixes, met in the wild rather than in the suite**

Both of yesterday's defects were aimed at deliberately, because a regression
test proves a line of code and a run proves the package:

- `ralph init` → `git commit -am` → `ralph run` started first time, because the
  commit is now one of `init`'s own printed next steps. The pre-run refusal
  itself is unchanged and still will not touch a change it did not write.
- The fourth task's run was **piped into `head -20`** — the exact shape that
  stopped a run at exit 70 on 11 November. The pipe closed twenty lines into
  `composer install` output and the run carried on without a reader: agent,
  suite, staging, commit, checkbox, cleanup, and `status` afterwards reads *"the
  checklist was worked through"*. A piped run also exits 0 now, checked through
  `${PIPESTATUS[0]}`.

**The fourth task was a reopening, on purpose**

Unticking `TASK-FC1A4E61` by hand after the first run gave section C item 8
something real to do: generation 2 took its own worktree (`…-g2`), its own
branch, its own log directory and its own commit, with `Ralph-Generation: 2`
and `Ralph-Run: 2` in the trailers, and generation 1's branch, commit and logs
untouched beside it. `ralph logs TASK-FC1A4E61` names the current generation.

**Verification**

- `composer test` — **2773 tests, 10091 assertions, green.** No source changed
  in this pass; the suite was run because the claim is about this code.
- The demonstration itself is the evidence: four commits with their trailers,
  four verification transcripts, `git worktree list` holding only the checkout,
  and `.ralph/tasks.md` the single uncommitted change left behind.
- Everything quoted in `docs/demonstrations.md` is copied from the run's own
  output, not retyped from memory.

**Notes / deviations**

- **Nothing was demonstrated about failure.** Every task here succeeded first
  time. Retry, blocking, verification failure and interruption are covered
  against the fixture worker and no real agent has driven them. That is now in
  the document's limitations rather than implied by its silence.
- **The out-of-scope edit did not recur**, and that is luck rather than a fix:
  nothing in the loop constrains a diff to the files a task named. Recorded as a
  limitation of both passes, not as something closed.
- **The 11 November pass is kept verbatim**, with its three interventions,
  because they are what the two code changes between the passes were for. The
  two limitations it listed that are now behaviour are retired in a closing
  paragraph that says where their history is.
- **Seven paid agent calls across the two passes, cost unknown** — the package
  does not meter it, which the document says.
- **No push.** This repository has no remote, so the work is committed only.

## 2026-11-12 — Section 5, final item: the history rewrite the loop cannot do

**Item:** remove `PROJECT.md`, `PROGRESS.md`, `PROMPT.md` and `loop.sh` from git
history. **Not checked off — blocked on a permission this loop does not hold.**

**What was attempted, and where it stopped**

Earlier today the sequence was run as far as it goes: a full backup bundle at
`/tmp/loop-backup-20261112.bundle`, copies of the four files at
`/tmp/loop-files-20261112/`, and then `git filter-branch`, which the permission
classifier denied. It denies it because rewriting published history is
irreversible for anyone holding the old hashes — the backup does not change that
judgement. `git filter-repo` and a scripted rebase are the same action by another
name and were not used to route around the denial; `git filter-repo` is not
installed here in any case.

This iteration did not retry the denied command. A denial is the user declining,
not a transient failure, and the only honest move left is to hand over the exact
commands with the measurements that make them safe to read.

**What the rewrite would actually do, measured**

- 87 commits in `HEAD`, and all 87 touch at least one of the four files —
  `PROGRESS.md` is appended on every iteration, so the rewrite is total: every
  hash in this repository changes, including the ones quoted throughout this file
  and in `docs/demonstrations.md`.
- 2 of those commits touch nothing else and would disappear under
  `--prune-empty`.
- No remote is configured, so nothing needs force-pushing afterwards and no other
  clone holds the old hashes. The blast radius is this checkout.
- `--index-filter` leaves the working tree alone, so the four files survive on
  disk and `loop.sh` keeps running after the rewrite; they end up untracked,
  which is what `.gitignore` should then say.

**The runbook, for whoever holds the permission**

```sh
git bundle create /tmp/loop-backup-$(date +%Y%m%d).bundle --all   # already done today
git filter-branch --force --index-filter \
  'git rm -r --cached --ignore-unmatch PROJECT.md PROGRESS.md PROMPT.md loop.sh' \
  --prune-empty --tag-name-filter cat -- --all
rm -rf .git/refs/original
git reflog expire --expire=now --all
git gc --prune=now --aggressive
printf '/PROJECT.md\n/PROGRESS.md\n/PROMPT.md\n/loop.sh\n' >> .gitignore
```

The `.gitignore` line is the part that keeps them out afterwards; without it the
next iteration commits them straight back in.

**Notes / deviations**

- **Nothing in the package changed**, so the suite was not re-run. The last
  recorded run is still 2773 tests, 10091 assertions, green.
- **The item stays unchecked.** Marking it done because the surrounding work is
  finished would put a false claim in the one document a reviewer reads first.
- **No push.** This repository has no remote, so the work is committed only.

## 2026-11-14 — Section K, item 1: a configurable Git execution strategy

**Item:** add a configurable Git execution strategy supporting the existing
per-task branch mode and a shared-branch mode in which all tasks in a run are
committed to the same branch. **Checked off for the layer it owns — see the
honest limitation below.**

**What was built**

- `src/Config/BranchStrategy.php`, a `per-task` / `shared` enum with the
  reasoning for both modes on it: per-task is independent and revertable, shared
  is what lets a later task build on an earlier one. The mode is the decision,
  the branch name is a separate setting, so a project can switch modes without
  naming a branch it never set.
- `git.strategy` and `git.shared_branch` in `GitSettings`, with the default
  still `per-task` (section K, item 2 is the one that flips it). `shared_branch`
  defaults to `null` and derives `<branch_prefix>shared` — `ralph/shared` out of
  the box — so the shared branch sorts with the per-task ones and follows a
  project that renames its prefix.
- Validation in `ConfigurationValidator`: an unknown strategy is rejected
  naming both accepted spellings, a blank one and a configured branch Git would
  refuse are rejected against the setting a user wrote. The *derived* name is
  deliberately not re-checked — appending one ordinary segment to a valid prefix
  cannot break any whole-name rule the prefix rules leave, since all of them are
  about how a name ends — and that reasoning is written where the check would
  have been.
- `BranchNamer` honours the mode: under `shared` every pass is named after the
  one configured branch, with the slugging and shortening skipped entirely. The
  rule that matters on resume is kept — a recorded branch is still never
  recomputed — so a pass interrupted under one mode finishes on the branch
  holding its commit even if the project has switched modes since.
- `RunRecord::branchStrategy()` / `sharedBranch()` read the mode out of the
  run's frozen configuration snapshot, not the live file, which is what `resume`
  will need to refuse an implicit mode change. A run recorded before the setting
  existed reads as `per-task`, which is what it was. Both appear in
  `status` (`branches  one branch per task`) and in `status --json`
  (`branch_strategy`, `shared_branch`); no schema bump, since the document's own
  note says an added field is not something a reader has to be told about.

**The limitation, stated rather than discovered**

Enabling `shared` today gets one task, not a run. The first task commits to the
shared branch; the second stops, because `TaskBranches` sees the branch recorded
as another pass's and will not move a branch holding a commit it did not put
there. That refusal is the *correct* interim behaviour, not a bug worth routing
around: each task's worktree is still prepared from the run's base commit, so a
second task's commit would not descend from the first's, and moving the branch
onto it would orphan the first task's work. Creating the shared branch once per
run and carrying each commit forward are section K items 3 and 4; the mode
becomes usable there. Said in the README's Limitations section in those words,
so nobody sets it expecting a working run.

**Verification**

- `vendor/bin/phpunit` — 2891 tests, 10474 assertions, green. 12 new
  assertions' worth of tests: strategy and shared-branch loading and defaults,
  four validation rejections, shared naming across two tasks, prefix derivation,
  a configured name, retention of a recorded branch after a mode switch, both
  failure messages, and the recorded-mode reads including the pre-setting
  snapshot.
- One flake on the way through: `a_time_budget_spent_on_the_clock_stops_the_run_before_the_next_task`
  failed once under full-suite load and passed alone and on a clean re-run of
  the whole suite. It measures real elapsed seconds against a two-second budget,
  so it is load-sensitive by construction; nothing in this change touches it.
- `php bin/ralph status` and `status --json` were run against this repository to
  confirm the new line and fields render from a real recorded run.
- **Note on tooling:** `vendor/bin/php-cs-fixer check` was run and found no
  config in the repository, so it scaffolded `.php-cs-fixer.dist.php` and added
  a line to `.gitignore` instead of checking anything. Both were reverted; the
  repository has no committed cs-fixer or PHPStan configuration, and CI runs the
  PHPUnit suite alone.

**No push.** This repository has no remote, so the work is committed only.

## 2026-11-14 — Section K, item 3: the shared branch, established once per run

**Item:** in shared-branch mode, create or validate the configured shared
branch once per run; do not create a new branch for each task, and persist the
shared branch identity so resume continues on the same branch.

**Why this item and not item 2.** Item 2 — making `shared` the default — is
next in file order and was tried first. Flipping `Defaults::BRANCH_STRATEGY`
and running the suite fails 21 tests, among them every loop-level case that
runs more than one task: with the mode carrying only its first task, a default
of `shared` is a package whose out-of-the-box configuration cannot finish a
checklist. PROJECT.md's own rule for this section is to mark an item complete
"only when its behaviour works and the relevant checks pass", which item 2
cannot satisfy while the mode is unusable past one task. So the default is
deliberately left at `per-task` and item 2 stays unchecked; items 3 and 4 are
what make it checkable, and this is item 3.

**What was built**

- `src/Git/SharedBranches.php`, which answers the question at the two levels the
  mode actually asks it. **Once per run** (`establish()` / `require()`), before
  any task is taken on: the branch is missing and will be created by the first
  commit, or it is at the run's base commit, or it is at a commit this project
  recorded — those three continue. A tip no row accounts for, or a branch
  checked out in a worktree the run does not own, stops the run with the tip
  kept so `git log` is the whole remedy. **Once per pass** (`forPass()`), which
  produces the same `BranchOwnership` the committer already takes, so the
  per-task and shared paths hand it the same thing.
- `src/Git/SharedBranch.php`, the run-level value: name, claim, tip, the pass
  the tip belongs to, whether the name came off the run's row, and the hints —
  which name `git.shared_branch` and `git.branch_prefix` rather than the
  per-task advice `BranchOwnership` gives, because a run stopped there was never
  naming a branch after a task.
- Schema version 3: `runs.shared_branch`, written once by
  `RunStore::recordSharedBranch()` after the branch is established and before
  the first task. `RunRecord::sharedBranch()` now prefers that column over the
  derivation from the run's configuration snapshot, which is the persisted
  identity the item asks for: `git.shared_branch` and `git.branch_prefix` are
  both editable between an interrupted run and the resume that finishes it, and
  a resume that derived the name again would continue on a second branch while
  the first still held the commits. Recording the same branch twice is a no-op,
  so a resume validating the branch again is allowed to reach the same answer;
  recording a *different* one is refused rather than overwritten.
- `GenerationStore::atCommit()` — what tells a commit this package made from one
  somebody made by hand, which is how a shared branch's tip is judged. Full SHAs
  only: a prefix lookup would make the answer depend on how many objects the
  repository happens to hold.
- `Runner::loop()` establishes the branch once, inside the run's `try` so a
  refusal closes the run row with its reason, and prints one `branch
  ralph/shared (new)` or `(continuing <sha>)` line. `TaskPass` takes the
  established branch and uses it for any pass whose row records no branch of its
  own; a pass that *does* record one keeps it, so a pass started under one
  strategy and finished under the other ends up on the branch holding its commit.
- No new event kind. The stream's four kinds are a documented vocabulary
  (section I, item 3), and the branch a run established is already readable from
  its row through `status --json`.

**The two refusals a pass can meet, and why they are refusals**

A task's workspace is still prepared from the run's base commit, so once the
first task has committed, the branch is past where the second task's workspace
starts. Committing there would move the branch onto history that does not
contain the first task's commit — the orphaning this whole mode has to avoid —
so the second task stops, with the reason and the `section K, item 4` reference
in the message rather than only in the hints, because the message is what a run
prints when it stops on a task.

The second refusal is the state store's: a branch belongs to one generation, the
invariant that stops a reopened task from resetting an earlier pass's branch in
per-task mode. A project that merges `ralph/shared` and runs again brings the
base level with the tip, so the first refusal no longer applies — and the row
still cannot be shared. That is caught in `forPass()` and reported in the mode's
own terms instead of as an index complaint at the claim; nothing is committed
and no reference moves either way. Relaxing the index belongs with item 4, where
several passes of one run really do commit to one branch, and the reasoning is
written on the migration.

**Verification**

- `vendor/bin/phpunit` — 2912 tests green. New: `tests/Git/SharedBranchesTest.php`
  (16 cases against real repositories: the four establishment readings, a branch
  checked out elsewhere, the established name winning over an edited setting, a
  per-task run having nothing to establish, a configured and a recorded name Git
  would refuse, and the five pass-level readings), `tests/Git/SharedBranchThroughTheLoopTest.php`
  (five cases through a real `ralph` subprocess: one branch per run with the
  commit on it and the row naming it, the second task stopping with the first
  task's commit still the tip, a name somebody else holds stopping the run
  before an agent starts, a later run continuing the branch, and per-task mode
  still giving every task a branch), plus `RunStore` and `GenerationStore` cases
  for the column and the commit lookup. Every refusal asserts the refs are
  identical afterwards.
- The known load-sensitive flake
  (`a_time_budget_spent_on_the_clock_stops_the_run_before_the_next_task`,
  two real seconds against a two-second budget) failed once under full-suite
  load and passed on a clean run, as it did in the previous entry. Nothing here
  touches it.
- The README's limitation for `git.strategy: "shared"` was rewritten to say what
  the mode now does and what it still refuses, rather than what it did before.

**Known wart, not introduced here.** A branch refusal's "next:" line reads
`Tip: <sha>`, because `TaskPass::refused()` takes `hints()[0]` as the next
action and `BranchException` is built with the evidence first. It is the same
for per-task refusals; left alone rather than changed under a shared-branch item.

**No push.** This repository has no remote, so the work is committed only.

## 2026-11-14 — Section K, item 4: one branch, a commit per task, each built on the last

**Item:** execute shared-branch tasks sequentially, carrying forward previously
committed task changes so later tasks can build on earlier tasks; retain
task-level commits and associate each commit with its task and execution
generation.

Item 3 left the mode with a branch established once per run and the run's first
task committing to it. The second task stopped, on purpose: every task's
workspace was still made from the run's base commit, so a second commit would
have moved the branch onto history that did not contain the first task's work.
This item is the carry-forward that removes that stop, and with it the reason
item 2 — making `shared` the default — was deferred.

**The decision, and it is the only one**

A task's workspace in shared-branch mode is made from the *tip of the run's
branch as it stood when the task was taken on*, not from the run's base commit.
That is `SharedBranches::continuationOf()`, and everything else here follows
from it:

- It is asked **per task**, not once per run, because the answer moves — the
  first task's commit for the second task, that task's for the third.
- It is asked **before the generation's row is written**, because the row is
  where the answer is kept. `TaskPass::openPassFor()` writes the base once, for
  a pass that is starting; `baseOf()` reads it back everywhere afterwards —
  worktree preparation, the already-satisfied audit, the commit message. A
  resumed pass is therefore prepared and committed against the commit it was
  originally built on, which is the same commit `PassRecovery` reconciles it
  against. Those three were already in step through the run's base; keeping the
  row as the single authority is what keeps them in step now that the base moves.
- The run's own base is returned unchanged while the branch is missing or still
  at that commit — the first task of a run, and every task of a run whose
  earlier tasks committed nothing. A pass like that records `git.base` as its
  reference rather than a branch name the commit was not resolved from.
- The run-level reading (`require()`) is made again inside it, so a branch
  somebody moved between two tasks of one run stops the run before an agent is
  handed a workspace built on it.

**What the state store had to give up, and what it kept**

A shared branch is recorded by *every* task of the run that commits to it, which
makes `generations_branch` — one branch, one generation — false. That index is
not decoration: it is what stops a reopened task from resetting the branch an
earlier pass left behind in per-task mode (section F, item 4). So it was made
partial rather than dropped. Migration 4 adds `generations.branch_shared` and
recreates the index with `WHERE branch IS NOT NULL AND branch_shared = 0`.

The flag is the *caller's* reading, not a guess made in storage:
`TaskBranch::shared()` marks the name the runner hands a pass that records none,
`TaskCommitter` passes `$branch->isShared()` through to
`GenerationStore::assignBranch()`, and only the runner knows which strategy
produced the name. A retained name is never marked, even when it is the shared
branch — a pass that already records a branch claimed it, and the claim is where
the distinction was stored. No backfill: a row written before this column either
owned a per-task branch or was the only pass that could record the shared one,
and in both cases the branch was its alone, so the `0` default is the honest
reading.

With the index partial, `SharedBranches::forPass()` lost the refusal it had for
a branch another pass's row holds — that is what a shared branch *is*. Two
refusals remain, and neither is about the name: a pass with a commit keeps the
branch only while the branch still contains that commit, and a pass without one
may commit only when the branch is where its workspace starts. The second is now
the ordinary case agreeing (the tip is what the workspace was made from) and its
refusal means the branch moved on *since* — a newer run committed to it, or an
interrupted pass is being resumed after one did. Its hint changed accordingly:
nothing was written, review `git log`, start a new run so the workspace is made
from the tip.

**Verification**

- `vendor/bin/phpunit` — 2921 tests green, the whole suite, on PHP 8.3.
- The loop-level case is the one that matters, and it asserts from the
  repository rather than from the run's account of itself: two tasks, one
  branch, two commits in a parent chain, the second task's commit containing the
  *first task's edit* — which is only true if the second workspace was made from
  the branch tip. `a_later_run_continues_the_branch_an_earlier_one_committed_to`
  now runs one task per run and asserts the second run's commit sits on top of
  the first's, which is the same carry-forward across a run boundary.
- New unit cases in `tests/Git/SharedBranchesTest.php`: the first task built on
  the run's base, a branch still at the base leaving the base alone, a later task
  built on the commit the branch carries, a task refused a commit nobody
  recorded, and the two passes of a run recording the same branch — the case
  that would have hit the index before migration 4.
- The README's limitation was rewritten: `git.strategy: "shared"` now describes
  a mode that commits a run's tasks to one branch in order, what it still
  refuses, and the worktree cleanup that is not yet part of finalization
  (section K, items 5–8). The "tasks are assumed independent" bullet was
  narrowed to `per-task`, since within one shared run they are no longer.
- `phpstan` has no configuration in this repository and CI runs the suite only;
  nothing was added to that effect here.

**Known wart, unchanged.** A branch refusal's "next:" line still reads
`Tip: <sha>`, because `TaskPass::refused()` takes `hints()[0]` and
`BranchException` is built with the evidence first. Same as per-task refusals,
and still not something to change under a shared-branch item.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-14 — Section K, item 2: shared is what the package does when it is not told

**Item:** make the shared-branch mode the default mode in the config and in the
package.

One constant, and then everything that had been true because of it.
`Defaults::BRANCH_STRATEGY` is now `BranchStrategy::Shared->value`, which is the
whole of the change in the package: the loader fills a missing `git.strategy`
from it, `ralph init` writes it into the file it creates, and
`Defaults::toArray()` is the single source both read, so the file a user gets
and the behaviour a user gets cannot disagree.

**Why shared and not per task.** A checklist is usually one piece of work split
up rather than a bag of unrelated errands, and under per-task mode the second
task cannot see what the first did, because its workspace is made from the run's
base commit. One branch also leaves one history to review instead of a branch
per ticked box. The cost is the one the mode's refusals are about and it is
stated in the README: tasks of a run share a branch, so a task building on a
mistake the task before it made inherits it. `git.strategy: "per-task"` is
unchanged and is still what a project with genuinely independent items wants.

**The reordering the flip required**

Flipping the constant broke the named crash of section H, item 5 — a pass that
commits and stops before its row can say so — and it broke it in the mode that
is now the default. `Runner::loop()` established the shared branch *before*
reconciling, and the branch a crashed pass left is at a commit no row names,
which `SharedBranches::establish()` reads as somebody else's work. The run
refused before `PassRecovery` could adopt the commit, so `ralph resume` stopped
with exit 78 where per-task mode adopted and carried on.

The two blocks are now in the other order: reconcile first, establish second.
Nothing in reconciliation needs the branch — a pass is reconciled against the
branch its own row claimed, and `withoutRecordedCommit()` counts from the base
the row records, which in this mode is the branch tip the pass started from — so
the ordering was free, and it is what writes the SHA into the row before the
branch is judged. Both recovery cases in `RecoveryThroughTheLoopTest` pass under
the default now, with the commit adopted rather than made again.

This is not section K, item 5. That item is the whole of shared-branch
reconciliation, and two gaps in it are visible from here and left alone: an
interrupted pass whose commit is no longer the branch tip is blocked by
`withRecordedCommit()`, which requires `tip === commit`; and
`restoreBranch()`'s comment still reasons from "the schema allows one generation
per branch", which migration 4 made partial. Both are item 5's to settle.

**The tests, and which way each one went**

Fourteen failures and one error, and the split between them is the useful part
of this entry. Three asserted the default value itself and were updated.
Four are about per-task semantics and were pinned to `git.strategy: "per-task"`,
because the thing each one holds *is* the per-task rule: a branch named after
its task (`WorkspacesThroughTheLoopTest`), a branch somebody else owns refused
per pass rather than per run, a reopened task that must not land on the branch
holding the first pass's commit, and a publication refusal carried by the pass
after somebody committed in its worktree. Pinning them is not hiding a
regression — per-task is a supported mode and those are its guarantees.

The rest kept running under the new default with their assertions restated,
which is where the coverage of the default came from: `RunnerTest`'s file-order
case now asserts one branch, two commits and a parent chain; the iteration-limit
case asserts a commit per task on the branch two runs share;
`AgentSelectionThroughTheLoopTest` reads each agent's work out of *its own
commit* rather than off a branch, which is a better assertion than the one it
replaced, since a shared branch holding two tasks' files says nothing about who
wrote which; `PublicationThroughTheLoopTest` names the branch a pass will take
through `BranchNamer::forProject()` instead of a bare namer, so it follows the
configured strategy.

Two tests were added, for the half of the item that is the default rather than
the mode: `a_project_that_configures_no_strategy_commits_to_the_shared_branch`
drives a real `ralph run` over a project with no `git` section at all and reads
the branch, the two commits, the run's row and the carry-forward out of the
repository; `the_written_configuration_names_the_shared_branch_strategy` holds
`ralph init` to writing `"strategy": "shared"` with `shared_branch` left null.

**Verification**

- `vendor/bin/phpunit` — 2923 tests green, the whole suite, on PHP 8.5.
- `ralph init` in a scratch repository, read back: `git.strategy` is `shared`
  and `git.shared_branch` is null.
- The example project's `.ralph/config.json` now spells both settings out, so
  the example demonstrates the default rather than inheriting it silently, and
  `examples/README.md` says one branch and three commits where it said three
  branches.

**Documentation.** The README's sample transcript, phase table, glossary,
configuration reference, Git-behaviour section, troubleshooting table and
limitations were all rewritten where the flip made them false. The
Git-behaviour section now leads with `git.strategy` and describes both modes
instead of opening "each task gets its own branch". One sentence was deleted
rather than rewritten: the claim that worktree cleanup is not yet part of
finalization, which the repository contradicts — `TaskPass::sweep()` removes a
completed pass's workspace today, in both modes. What section K, items 5–8 still
owe about cleanup is theirs to state; this entry does not replace it with a
claim of its own.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-14 — Section K, item 5: reconciling the shared branch, not just its tip

The item asks for the shared branch and **its commit history** to be reconciled
on resume, including the crash between a commit and the row that records it, and
for unexpected branch changes or commits to block rather than be reset over. The
previous entry named two places where the code still reasoned as though a branch
held one commit; both were in this item's way and both are settled here.

**What the reconciliation got wrong.** `PassRecovery` was written for a branch a
pass owns alone, where the pass's commit is the only commit on it — so the tip
*is* the evidence, and every reading compared the two. Under the default
strategy that assumption breaks as soon as the run gets past the task being
reconciled: a pass's commit stops being the tip the moment the next task commits
to the same branch. Three readings therefore refused states that are the ordinary
shape of the mode rather than a problem: a recorded commit the branch had moved
past ("what would be published is not what the pass committed"), a no-change
outcome on a branch whose tip is a later task's commit, and a crashed pass whose
commit was not where a per-task branch would have put it.

**What replaced the comparison.** A branch's advance is read commit by commit
instead of counted. `CommitReader::between()` lists a range oldest-first;
`BranchHistories::beyond($from, $tip)` walks it and asks the state store whose
each commit is, stopping at the first one no row accounts for; `BranchHistory`
is that answer — accounted for, a named stranger, or too long to vouch for.
Ownership is read from the rows rather than from the commits' own trailers,
deliberately: a trailer is written by whoever wrote the commit, and a commit
*claiming* to be a pass's is exactly what must not be taken at face value when
deciding that other work may be built on top of it. The one place trailers are
still the evidence is the pass's own commit in the crash case, where there is no
row by definition.

So the shared readings became: a recorded commit the branch has moved past is
consistent while every commit in between has a row (and blocks naming the
stranger otherwise); a no-change pass is consistent while the advance past the
commit it started from is all recorded, because what contradicts that outcome is
a commit *of its own*, not the branch having a tip it did not write; and a pass
with no recorded commit has the branch read forwards from its base, where the
first unaccounted-for commit is the only one that can be its own — a pass commits
directly onto the commit its workspace was made from. That commit is adopted
when it says in its trailers that this pass made it, says it was written against
the recorded base, and carries only recorded commits above it. Everything else
stops with nothing written, including the pass's own commit with a stranger on
top of it: adopting it would resume a branch carrying work no check of the run
ran against.

**Two further gaps, both about losing work.** A deleted shared branch was being
re-created at the pass's own commit, which would have left every later task's
commit reachable from nothing — not deleted, but out of reach of `git log` and
of the next run. The rows are the only remaining index of those commits once the
reference has gone, so `GenerationStore::committedOn()` lists them and the branch
is put back at the furthest one that still has this pass's commit in its history;
two candidates neither of which contains the other are two histories that cannot
both wear the name, and that is refused rather than chosen between. And the
run-level reading in `SharedBranches::establish()` vouched for a tip alone, which
cannot see a commit somebody added part-way along the history a stopped run left
behind — so it now reads the whole range from the run's base, with the same rule.

The walk is bounded at 500 commits, and a longer range is refused rather than
answered from the part that was read. That is the one new refusal a working
project could meet — a shared branch somebody merged a long line of development
into — and it is in the README's limitations as such.

**What was already right.** `Runner::loop()` reconciles before establishing the
shared branch, which is what makes the named crash recoverable at all in this
mode: the commit the crash left is a commit no row names, which is exactly what
`establish()` reads as somebody else's work, so adoption has to happen first.
That ordering was put in by item 3 and is unchanged; the existing end-to-end case
in `RecoveryThroughTheLoopTest` runs under the default strategy and so was
already exercising the new path by the time it was written.

**Verification**

- `vendor/bin/phpunit` — 2944 tests green, the whole suite, on PHP 8.5 (2923
  before; 21 added, none changed).
- `BranchHistoriesTest`, 8 cases: an empty range, an advance of recorded
  commits, a stranger and where it sits, reading forwards from a commit rather
  than back from the tip, a commit becoming accounted for the moment a row names
  it, the bounded reading, and the serialisation.
- `SharedBranchRecoveryTest`, 11 cases, each one a state a stopped run leaves
  behind: the crash adopted off the shared branch, a pass whose commit a later
  task committed on top of, a stranger above the pass's commit, a stranger not
  adopted as the pass's own, a stranger above the adoptable commit, the pass's
  own commit above later work, a pass that committed nothing while the branch
  moved on, a branch rewritten away from the pass's base, a deleted branch put
  back at the run's furthest commit, and the two no-change cases. Every refusal
  asserts the row and the refs afterwards: nothing adopted, nothing moved.
- `SharedBranchesTest` gained the run-level case — a recorded tip with an
  unrecorded commit beneath it stops the run — and `GenerationStoreTest` the
  index a deleted branch leaves behind.
- `phpstan --level=max` on the three changed and added source files: clean.

**Documentation.** The README's Git-behaviour section says the resume reads the
whole history rather than the tip; Recovery gained a paragraph on what that
means and on the deleted-branch repair; Limitations states the 500-commit bound.

**Not this item.** Worktree cleanup and log wiping (items 6–10) are untouched,
and so is the console newline work (items 11–13).

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-15 — Section K, item 6: cleanup as the last step of a completion

The item asks for the task worktree and its `git worktree` registration to be
removed **after** the commit, any required push, the state update and the
checklist write are durable. The removal itself already existed — section F,
item 10 built `WorktreeCleaner`, which decides and acts — so what this item is
actually about is *where in a pass it is reached from*, and whether a completion
says it happened.

**Where it was reached from before.** Every path ran cleanup in a `finally`
block, under the name `sweep()`. That is the right place for an outcome that
never completes: a failed, blocked or interrupted pass must still have its
workspace assessed so the reason it is being kept reaches the log. But for a
successful pass it made the removal a tidy-up *beside* the finalization rather
than part of it — it ran after `finalize()` had already returned its result, so
nothing about the order was stated anywhere, the completion evidence was written
before the step and could not mention it, and a future edit that moved the
checklist write later would have silently broken the guarantee without failing a
test.

**What the pass does now.** `finalize()` calls cleanup itself, as its last
ordered step: after `markVerified()`, after `complete()`, after the attempt is
finished, and — the one that matters — after `tick()` has written the user's
box. The resume-side finalization (`writeTheBox()`, which finishes a pass whose
publication or checklist write was owed) does the same at the same point, so the
guarantee does not hold on one path and not the other. Both are still backed by
the `finally` blocks for every outcome that never gets there.

**The latch.** Cleanup is now reached from two directions, so `TaskPass` carries
one piece of mutable state — the generation whose workspace is already gone — and
whichever caller arrives first does the work. It is set only when there is
nothing left at the path, never when a workspace was *kept*: a pass whose first
attempt failed keeps its checkout on purpose, and the attempt that then passes
has to be able to remove the same directory the earlier one was told to leave
alone.

**The completion record.** `CompletionEvidence::cleanedUp()` folds the outcome
into the evidence, which is what makes the step part of the completion rather
than something that happened afterwards: a record that says a box was ticked now
also says whether the checkout the work was written in is still on disk, and
where the work is instead when it is not. It is the last line of the details and
the last section of the Markdown, because that is the order the steps happened
in — a reader following the record down is reading the sequence the guarantee
rests on. A refused completion gets no workspace line at all, since a pass that
earned no tick keeps its workspace and listing that under "what this rests on"
would read as a step taken.

`WorktreeCleanup::describe()` lost its own `cleanup: ` prefix on the way, which
is why `WorktreeCleanerTest` has one changed assertion. The caller supplies the
context now: the cleaner writes it to a log under `cleanup:`, the evidence lists
it beside the commit and the push under `workspace:`, and before this the second
of those read `workspace: cleanup: removed …`.

**Verification**

- `vendor/bin/phpunit` — 2953 tests green, the whole suite, on PHP 8.5 (2944
  before; 9 added, 1 changed).
- `CompletionEvidenceTest` gained 6 cases: the outcome carried on the evidence
  and last in its details, a completion that has not reached cleanup saying
  nothing about the workspace at all, a kept workspace reported as kept with its
  reason, the Markdown section a reviewer reads, a cleared registration not
  claimed as deleted files, and the structured form.
- `RunnerTest` gained 3 loop-level cases, all in the default shared-branch mode:
  a completion with a real bare remote whose workspace is asserted gone from the
  disk *and* from `git worktree list` only after the push landed and the box was
  written; a pass owing its publication keeping its workspace and losing it when
  the resume's push succeeds (which is the `writeTheBox()` path); and the
  completion record naming where the workspace went.
- The existing `a_completed_passs_workspace_is_removed_and_a_failed_ones_is_kept`
  asserted nothing about the removed workspace and now does. It read the path off
  the row *after* cleanup, and cleanup clears that column, so
  `assertDirectoryDoesNotExist((string) $pass->worktree()->path())` was asserting
  that `''` is not a directory. It reads the path off the recorded cleanup
  instead, and the kept one off the row, and both now also assert what
  `git worktree list` says.
- `phpstan --level=max` on the four changed source files: clean.

**Documentation.** One paragraph in the README's Git-behaviour section, above the
existing "biased towards leaving things alone": the ordering, that a failed
removal therefore costs nothing, and that the outcome is in the completion
evidence. The fuller pass over branch strategies, cleanup guarantees and log
retention is item 14's.

**Not this item.** Idempotence and finishing an interrupted cleanup (item 7), the
explicit cleanup action for preserved worktrees (item 8), the shared-branch
detach (item 9) and log wiping (items 10–12) are untouched. Item 9 is close to
free as things stand — nothing here removes or moves a reference, and the three
new cases assert the shared branch and its commit survive the workspace — but
the item asks for it to be shown, not noticed.

**No push.** This repository's `origin` is SSH with no usable key, so the work is
committed only.

## 2026-11-15 — Section K, item 7: cleanup that is idempotent and finishes itself

The item asks for three things about the removal item 6 made the last step of a
completion: that it be idempotent, that an interrupted one be detected and
finished, and that it not be called complete until Git confirms the worktree has
gone. The first was nearly true already, the third was not true at all, and the
second needed a fact the old code had no way to know.

**What a crash used to leave.** `WorktreeCleaner::clean()` ran
`git worktree remove`, and if the command exited zero it cleared the row's
workspace columns. Two gaps, in opposite directions:

- *Between the command and the write.* A crash there left a row naming a
  directory that was gone. The next cleanup read that as `absent` and reported
  "no workspace prepared" — a true-sounding line that left the row permanently
  contradicting `forget()`'s own documented invariant, that a row naming a path
  means a directory that exists.
- *During the command.* Git deletes the worktree directory and *then* its
  administrative record of it, and within the directory it deletes entries in
  whatever order the filesystem hands them over. So an interruption leaves a
  part-deleted tree, which `git status` reports as a pile of modified files —
  indistinguishable, from outside, from a workspace somebody has been editing,
  and so preserved for ever with `uncommitted-work` as the reason.

The second of those is what needed a new fact. No question asked of the
directory can tell "being deleted" from "being worked in", so the answer has to
be written down before the deletion starts.

**The mark.** Migration 5 adds `generations.cleanup_state`, nullable, with
`CleanupState` as its vocabulary: `removing` is written *before* the Git command
and `removed` by the same `UPDATE` that clears `worktree_path`. Writing the
completion in one statement is what makes the invariant hold without a
transaction — a row can never both name a directory and claim to have removed
it — and `beginWorktreeCleanup()` is idempotent, so a retry does not have to
remember whether it got that far. `recordWorktree()` clears the column, because
the mark is about the directory the row names and a pass given a fresh workspace
has had no removal issued against *it*.

**Confirmation.** A zero exit status is now Git's account of what it did and not
the thing that gets recorded. `unconfirmed()` asks the repository instead:
`worktree list` must no longer mention the path and the directory must no longer
be there. Either still being so — a deletion the filesystem refused part of, a
registration written back — is the new `removal-unconfirmed` retention reason,
which keeps the row naming the workspace so the next run tries again. A
worktree list that cannot be read is not a confirmation either.

**Finishing an interrupted removal.** A resumed cleanup is one whose row is
marked `removing`, and three things follow:

- The questions that read the *inside* of the workspace are not asked as they
  were. A part-deleted checkout reports its own deletions, a HEAD it cannot
  resolve, or nothing at all, and reading that as evidence is what preserved the
  husk. The commit-reachability check is still made, from the project's
  repository rather than the workspace, because that is the promise that the
  work survives the directory — `a_resumed_removal_still_refuses_to_take_a_commit_no_branch_holds`.
- But not blindly. `writtenSinceTheRemoval()` keeps a workspace holding anything
  the removal *could not have produced*: `WorkingTreeChange::isDeletion()` is new
  and narrow — a tracked file gone, staged or not, and a `DD` conflict is not one
  — so a modification or an untracked file stops the cleanup exactly as it would
  have stopped the first attempt. This matters because the unconfirmed-removal
  path leaves a whole workspace marked `removing` and sends a person to look at
  it; what they write while they are there is theirs.
- The retry may pass `--force`, which a first attempt never may. Git refuses a
  part-deleted tree, so without it the husk is unremovable by the package that
  made it; with the mark, Git has already agreed once that the directory was
  clean, and the refusal now is about the damage its own interrupted removal
  did.

**`git worktree repair`.** Measured against real Git 2.43: if the interruption
took the workspace's `.git` file, `worktree remove` refuses *even with*
`--force` — "validation failed" — so force alone does not finish the job.
`repair` writes that link back, and the removal then goes through. Its exit
status is deliberately ignored (it reported the breakage and exited 1 while
having repaired enough); the removal that follows is the judge. It arrived in
Git 2.30, above this package's floor of 2.24, so on an older Git the step
contributes nothing and the workspace is kept with Git's own refusal. That, and
a part-deleted directory that has *lost* its registration — which takes a
removal that failed rather than one that was interrupted — are the two stated
limits of what cleanup can finish by itself. Neither is a case where this class
starts deleting directories on its own account.

**Idempotence.** The decision is taken afresh from the row and the repository
every time, so what makes a second call a no-op is that the first left the row
saying the workspace had gone. `WorkspaceRetention::cleaned()` is a new absent
reading that says "its workspace has already been removed" rather than "no
workspace prepared", which is the line a sweep over a finished run's history
prints for every completed task.

**Verification**

- `vendor/bin/phpunit` — 2972 tests green, 10899 assertions, the whole suite, on
  PHP 8.5 (2953 before; 19 added, 3 changed).
- `WorktreeCleanerTest` gained 9 cases against real repositories and real
  worktrees: cleaning up twice, sweeping twice, the crash before the state
  write, the part-deleted husk with and without the mark (which is where the
  `--force` gate is proved behaviourally rather than by reading the source), the
  husk whose `.git` file went, a `git` stub that reports a removal it has not
  carried out, the commit check surviving a resume, the workspace somebody wrote
  in, and the mark being cleared when a fresh workspace is recorded.
- Three existing cases changed. `a_row_naming_a_workspace_that_has_entirely_gone_is_absent`
  is now `…_is_finished_off` and asserts the row is cleared; the
  cleared-registration message lost its "Git's" (the record cleared may be Git's,
  the package's, or both); and the source guard now allows exactly one
  `--force` and the `repair` command while still forbidding `-f`, `branch`,
  `reset`, `prune`, `checkout` and `clean`.
- `GenerationStoreTest` gained 4 cases for the two new writes and the column's
  `CHECK`; `CleanupStateTest` is new; `WorkingTreeStatusTest` gained one for
  `isDeletion()`.
- `phpstan --level=max` on the nine changed source files: clean. The one error
  it reports in `GenerationStore` is at `reservedBranches()`, predates this
  work, and is unrelated to it.

**Documentation.** Two additions to the README's Git-behaviour section, after
the existing "biased towards leaving things alone": the confirmation rule and
the three recoverable interruptions, and the two version-and-shape limits. The
fuller pass over branch strategies, cleanup guarantees and log retention is item
14's.

**Not this item.** The explicit cleanup action for preserved worktrees (item 8),
the shared-branch detach (item 9), log wiping (items 10–12) and the console
newline work (items 13–14) are untouched. `ralph status` does not yet show the
new column; the cleanup it describes is the completion evidence's, and the
status surface is where items 8 and 14 arrive.

**No push.** This repository's `origin` is SSH with no usable key, so the work is
committed only.

## 2026-11-15 — Section K, item 8: preserved by default, removed when you ask

**The item.** "Preserve failed, blocked, and interrupted worktrees for
inspection by default; provide an explicit, documented cleanup action for those
worktrees that protects uncommitted changes."

The first clause was already true and had been since section F, item 10:
`RetentionReason::PassUnfinished` keeps the workspace of any pass
`LifecycleState::isOpen()` is true of, and `WorktreeCleanerTest` has had cases
for the failed, blocked and interrupted readings of that for a while. What was
missing was the second clause — there was no way to clear one up. A project
worked on for a week accumulates a checkout per stopped task under
`.ralph/runtime/worktrees/`, each with its own `vendor/`, and the only answer
was `rm -rf`, which takes Git's registration with it and leaves
`git worktree add` refusing the path next time.

**The shape of the action.** `ralph clean`, with three forms:

```
ralph clean              list every recorded workspace; change nothing
ralph clean TASK-ID      remove that task's kept workspaces
ralph clean --all        the same for every stopped pass
```

The default form removes nothing. That is the opposite of most `clean`
commands and it is the point: these directories can hold the only copy of
something, so the first thing a person runs is the thing that shows them what
they are about to do. It is also the only form that takes no repository lock,
because every question `WorktreeCleaner::assess()` asks is a `git` query — so
it is safe during a run. The acting forms take the lock for the whole sweep,
which is what stops a workspace being deleted from one terminal while a runner
uses it in another.

**What an explicit request actually buys, which is one thing.**
`CleanupRequest` is a two-case enum threaded through `assess()`, `clean()` and
`sweep()` as a defaulted last parameter, so every existing call site is a
`Finalization` and behaves exactly as it did. `Explicit` waives
`PassUnfinished`, and only for a pass `LifecycleState::hasStopped()` is true of
— blocked, failed or interrupted. The remaining ten reasons stand untouched, so
the explicit action is refused by uncommitted work, a commit no row recorded, a
commit its branch does not hold, an adopted directory, a path outside the
layout, a locked worktree, and every unreadable case.

Writing it as a request rather than a boolean was deliberate: a `true` in that
position reads as "force", which is precisely what it is not.

`hasStopped()` is narrower than the existing `isRetryable()`, which also admits
`Pending`. A workspace recorded against a pass that never began is a
reconciliation question, not a directory to clear up, and `Running` is excluded
for the obvious reason.

**The protection, stated as a property rather than a default.** There is no
`--force` and no other override anywhere. A workspace holding modified, staged
or untracked files is kept by both forms, reported with the file count, the
paths and the full path to the directory, and clearing it is the user's own
`git worktree remove` once they have decided what in it is worth keeping. That
is what "protects uncommitted changes" means here, and it is the honest version:
the alternative considered was rescuing the changes to a patch before removing
them, which cannot be done honestly because `git diff` does not carry untracked
files and a patch that silently omitted them would be a false promise.

The exit status says which happened. 0 when the listing printed or everything
asked about is gone; 75 when something was kept, because clearing it is then
waiting on a person; 78 for no state store, no record of the task, or another
runner holding the repository.

**What the command does not touch.** Cleanup removes a directory. The branch,
the commit, the push result, the attempts, the logs and the verification
verdicts stay where they are, the checklist item stays unticked, and
`ralph retry TASK-ID` prepares a fresh workspace from the recorded base. There
is a case for exactly that: clean, then retry, then the box is ticked.

**Also changed.** `GenerationStore::all()` — the rows, not `history()`'s
reading of them, which drops the three columns a cleanup decision is made from.
`RetentionReason::PassUnfinished`'s hint now names `ralph clean TASK-ID`
alongside `logs` and `retry`, so the command is discoverable from the line a run
prints when it keeps a workspace. The report prints workspace paths in full
rather than eliding long ones: a path the report tells you to `cd` into or pass
to `git worktree remove` has to be copyable.

**Verification**

- `vendor/bin/phpunit` — 3003 tests green, 11034 assertions, the whole suite, on
  PHP 8.5 (2972 before; 31 added, 1 changed).
- `CleanCommandTest` is new: 13 cases driving the real binary against real
  repositories with real linked worktrees. The listing that removes nothing and
  names the path; the listing that gives the reason; the empty project; the
  named removal, with the pass still `failed` and its attempts and branch
  intact; the lock being taken as `clean` and released; `--all` over two failed
  tasks; cleaning twice; clean-then-retry ending in a ticked box; the refusal
  over uncommitted changes, with the file still on disk and the row still naming
  the workspace; `--force` being rejected as an unknown option; `TASK-ID --all`
  together; an unrecorded task; a project that has never run.
- `WorktreeCleanerTest` gained 8 unit cases for the request itself: the stopped
  pass's clean workspace removed, blocked and interrupted through `sweep()`, the
  uncommitted-work refusal (asserting that the waived reason is gone and the
  protecting one is what kept it), the unrecorded-commit refusal, running and
  pending left alone, the adopted-workspace refusal, and a finished pass
  decided identically either way.
- `LifecycleStateTest` gained 2 for `hasStopped()`, including the pending pass
  that is retryable without having stopped; `GenerationStoreTest` gained 2 for
  `all()`.
- One existing assertion changed: the `PassUnfinished` hint text.
- `phpstan --level=max` on the seven changed source files: clean. The one error
  it reports in `GenerationStore` is at `reservedBranches()` and predates this
  work.

**Documentation.** A `ralph clean` row in the README's command table, a full
`### ralph clean [TASK-ID] [--all]` section covering the three forms, why a
worktree is there at all, the uncommitted-changes refusal with the Git commands
that resolve it, the absence of `--force` and the exit statuses; and a paragraph
in the Git-behaviour section's cleanup discussion pointing at it. The command's
own `--help` says the same things.

`ralph clean` is not in PROJECT.md section 3's proposed command table, which
describes the eight commands the PRD set out before this section was written.
Section 5's item K.8 is what asked for the action; the PRD's own interface
listing is left as the record of what was proposed. Item 14's documentation pass
is where the configuration reference catches up.

**Not this item.** The shared-branch detach (item 9), log wiping (items 10–12)
and the console newline work (items 13–14) are untouched. `ralph status` still
does not report workspace retention; `ralph clean` with no arguments is the
surface for that question, and whether `status` should repeat it is item 14's
call.

**No push.** This repository's `origin` is SSH with no usable key, so the work is
committed only.

## 2026-11-17 — Section K, item 9: a kept worktree lets go of the shared branch

**The item.** "Ensure cleanup in shared-branch mode detaches/removes only the
task worktree while preserving the shared branch and its commits for the next
task."

Half of it was already true and worth saying so: `git worktree remove` takes
Git's record of which worktree is using a branch name with it, so a *removed*
workspace already freed the shared branch and left every commit on it. The
two-task shared-branch case has been passing since item 4 for exactly that
reason.

The other half was a real bug, and it is the one the item names first. Cleanup
is biased towards keeping things — a workspace holding anything uncommitted
stays, because it may be the only copy of somebody's work. In shared-branch
mode that workspace is also sitting on `ralph/shared`, because `TaskCommitter`
attaches it there to make the commit, and Git lets one branch be checked out in
one worktree at a time. So the next task stopped before it started:

```
--- cleanup: TASK-4794E74E: …/worktrees/TASK-4794E74E kept, 1 uncommitted
    change(s): .ralph/notes.md
[2/2] TASK-FBE89BE5 Second task here
--- stopped: ralph/shared is not the branch of TASK-FBE89BE5 to use: it is
    checked out in …/worktrees/TASK-4794E74E, which is not the pass's workspace.
```

That is a run reaching `1 of 2 tasks completed` because the first task's
workspace was worth preserving — and the agent only had to leave a file in
`.ralph/`, which staging excludes from the commit on purpose, for it to happen.
Per-task mode never sees it: there, the branch is the pass's own and nobody
else wants it.

**The fix.** Cleanup detaches a kept workspace from the shared branch.
`git switch --detach` with no start point moves HEAD from `refs/heads/<branch>`
to the commit that branch already points at: no file in the working tree is
written, nothing staged is dropped, and the reference keeps every commit on it.
What the workspace gives up is exactly what it should not have been holding —
Git's record that this worktree is the one using the name.

Four gates, in `WorktreeCleaner::release()`:

1. **Shared mode only.** The cleaner is now built with the configured shared
   branch name, or null under `per-task`, where a kept workspace is left
   attached — the branch is the pass's own, and a retry continuing in that
   directory is better off finding it there.
2. **That branch, by name.** A detached workspace has nothing to release; one
   on some other branch was moved by something this class cannot reason about,
   and is the reconciliation case rather than this one.
3. **The tip, before and after.** "The commits are preserved" is the promise
   the item makes, and a promise checked only in the commentary is not checked.
4. **Confirmation.** The workspace must actually be detached afterwards, asked
   of Git rather than inferred from an exit status, like every other
   confirmation in this class.

Anything that does not hold is a refusal reported against the kept workspace,
never an exception: cleanup runs after the pass has been verified, committed
and published, so a workspace that could not be detached is a line in the log
and a branch the next run will stop on, not a reason to withdraw finished work.

**Also changed.** All three ways of reaching a kept outcome — the decision to
preserve, a removal Git refused, a removal that could not be confirmed — now
funnel through one `keeping()` method, so the branch ends up in the same state
whichever one happened. `BranchRelease` is the new value carrying what was
done, with three readings (unnecessary, released, refused); `WorktreeCleanup`
carries one and adds its line to `details()` and `toArray()`. Nothing else in
the package needed changing: the run already prints cleanup's details, so
`Released the shared branch ralph/shared, still at 859f39a6d75e, for the next
task.` appears in the attempt log, the terminal and the completion evidence
without a new call site.

**Verification**

- `vendor/bin/phpunit` — 3015 tests green, 11113 assertions, the whole suite,
  on PHP 8.5 (3003 before; 12 added, 2 changed).
- `WorktreeCleanerTest` gained 7 cases for the behaviour: the kept workspace
  that lets go of the branch (asserting the branch still at its commit, the
  workspace detached at the same commit, and the committed file, the
  uncommitted file and the registration all still there); releasing twice;
  per-task mode staying attached; a workspace somebody moved onto a branch of
  their own being left on it; a detached workspace reporting nothing; a detach
  a stubbed `git` refuses being reported against the kept workspace with the
  branch untouched; and removal freeing the branch without detaching it.
- It gained 4 more for `BranchRelease` itself, and a per-task-mode switch
  (`inPerTaskMode()`) so the contrast is one line rather than a parallel set of
  helpers. The two changed assertions are `WorktreeCleanup::toArray()` shapes,
  which now carry `release`.
- `SharedBranchThroughTheLoopTest` gained the end-to-end case: the same
  two-task run that stopped at `1 of 2` before, now finishing with both commits
  on the one branch in order, the second built on the first, and the kept
  workspace still holding the file it was kept for and no longer holding the
  branch.
- `phpstan --level=max` on the three changed source files: clean.

**Documentation.** A paragraph in the README's Git-behaviour section, next to
the existing cleanup guarantees: what is detached, what detaching costs
(nothing), that only the configured shared branch is touched and only when the
worktree is on it, that the tip is read before and after, that `per-task` is
unchanged, and that a refused detach is reported rather than fatal. Item 14's
documentation pass is where the configuration reference catches up.

**Not this item.** Log wiping (items 10–12) and the console newline work
(items 13–14) are untouched. The name compared against is the *configured*
shared branch rather than one recorded against the run, so a project that
renames `git.shared_branch` between runs leaves an older workspace holding the
old name — the conservative reading, and the run that would collide with it is
the one that reports it.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-17 — Section K, item 10: a switch for log retention, off

**The item.** "Add a configuration option to wipe task execution and
verification log files after a task succeeds; keep logs by default unless the
option is enabled."

This is the switch, not the deletion. Items 11 and 12 are the behaviour — which
files a successful task's finalisation may remove, what it must keep, and what
`ralph logs TASK-ID` says when it finds a log that was wiped on purpose — and
they read their answer from here.

**The setting.** A new top-level section with one key, written out by
`ralph init` like every other default:

```json
{"logs": {"wipe_on_success": false}}
```

`false` is the default and the honest one. A log is the only account of what an
agent did that outlives the agent, and the moment it is most wanted is usually
after a run that looked fine: a commit that passed the checks and still did the
wrong thing gets read backwards, from the ticked box to the output behind it.
A package that deleted that by default would be quietly lossy about its own
work. What turning it on is for is the other real case — a long checklist on a
project with a loud suite leaves a runtime directory measured in hundreds of
megabytes, nearly all of it successful tasks nobody will open again.

**Why a section of its own.** Retention is not a Git question and not a limit.
The two items after this one read the same answer from two places —
finalisation, which deletes, and `ralph logs`, which has to explain an absent
file — so one named section is where both look. `LogSettings` carries it, with
`wipesOnSuccess()` and its inverse `retainsEverything()`, so a caller
explaining what it did *not* do says what it means rather than negating a verb.

**What "eligible" will mean**, recorded with the switch so the behaviour cannot
drift from the promise: only the log files of a task that *succeeded* — its
agent logs and its verification logs, for that task's generation, after its
commit, its required push, its state update and its checklist write are all
durable. Not the state, not the commit, not the completion evidence, and not
the logs of a failed, blocked or interrupted task.

**Verification**

- `vendor/bin/phpunit` — 3017 tests green, 11121 assertions, the whole suite,
  on PHP 8.5 (3015 before; 2 added, 3 extended).
- `ConfigurationLoaderTest` gained the two rejections that matter for a
  destructive flag: `"wipe_on_success": "false"` is refused rather than
  coerced, exactly as the push flag is — the string is truthy, and a project
  that meant to keep its logs would find them gone — and a misspelled
  `wipe_on_sucess` is named with its full path and a suggestion. The three
  extended cases assert the default reads as off and retaining, that an
  explicit `true` is read back, and (through the existing round-trip case) that
  the effective configuration including `logs` is itself valid input.
- `examples/text-tools/.ralph/config.json` gained the section: `ExampleProjectTest`
  asserts the example sets every key the current configuration has, so the file
  meant to show a reader all the settings keeps showing all of them.
- `phpstan --level=max` on the four changed source files and the changed test:
  clean.

**Not this item.** Nothing deletes anything yet: no finalisation path, no
`ralph logs` behaviour, no README text. Items 11, 12 and 14 are where each of
those catches up, and until then a project that sets the flag gets the
documented default behaviour — logs kept — which is the safe direction for a
setting to be ahead of its implementation in.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-17 — Section K, item 11: the deletion the switch turns on

**The item.** "When successful-task log wiping is enabled, delete only that
task's eligible log files after successful finalization, retain
state/commit/completion metadata, and make the deletion behavior safe to retry
after interruption."

Item 10 added `logs.wipe_on_success` and wrote down what it would mean. This
is the code that means it: `LogWiper`, which deletes a completed pass's agent
and verification logs, and `generations.logs_wiped_at`, which records that it
did.

**Only that task's eligible files.** Eligibility is a name, not an extension:
`attempt-NN-kind.log`, the shape `LogDirectory` composes, inside that
generation's own directory, and an ordinary file rather than a link or a
directory. Everything else in there is left exactly where it is. A runtime
directory is still somewhere a person may have put something, and a wipe that
deleted by extension is the one that finds their note. The three files that
are evidence rather than output — `attempt-NN-completed.md`,
`attempt-NN-satisfied.md`, `attempt-NN-staged.diff` — sit in the same
directory and are not eligible, so the answer to "why is this box ticked"
outlives the output behind it. The directory itself goes only when the wipe
emptied it, and `rmdir` is what decides that rather than a listing that could
go stale.

**What is retained.** Nothing is written to the row but the one timestamp. The
commit, the branch, the push result, the verification rows, the attempt
counters and the completion record are all untouched — the trade the setting
offers is output for disk, not the account of what a task did. The through-the-
loop test asserts that from the repository: the commit is there, the box is
ticked, `status` still counts the task, and the `.md` beside the deleted logs
still explains it.

**Eligibility is the pass, not the caller.** A wipe happens only for a row that
is `completed` with its publication settled — which, where the runner calls it,
means the commit, any required push, the row and the user's checklist are all
durable. Failed, blocked, interrupted and still-running passes keep every byte
they wrote, and that is enforced in the wiper rather than at the call site so a
later caller cannot opt out of it. The `running` case is not only principle: an
attempt in progress owns an open handle on its log.

**Why the call sites are where they are.** After `$log->close()`, in the
attempt loop and in both resumptions — the file being deleted is the one the
handle is open on, and a deletion underneath it would send the rest of the
attempt's notes to an inode nothing can read. The row is re-read rather than
taken from the caller, for the same reason `cleanUp()` re-reads it: whether a
pass's logs may go turns on the pass being completed, and the row is where that
became true. Notes go to the terminal and not to the attempt's log, which by
then is usually one of the files being deleted.

**Retry after interruption, and the column that is deliberately simpler than
`cleanup_state`.** Migration 6 adds `logs_wiped_at`, written *after* the
deletion and only once nothing eligible is left. A part-removed worktree needed
a mark written *before* its command because it cannot be told apart from a
workspace somebody has been editing; a part-wiped log directory has no such
ambiguity — every file left in it is still a log, and deleting a log twice is
the same as deleting it once. So there is no `wiping` state to resume from: a
wipe an interruption cut short leaves the column null and the remaining files
on disk, and the next look finishes it. An interruption costs a retry, not a
decision.

**Verification**

- `vendor/bin/phpunit` — the whole suite green on PHP 8.5: 3039 tests, 11207
  assertions (3017 before; 22 added).
- `LogWiperTest` is 18 cases against a real directory and a real store, because
  what is under test is which paths a deletion reaches — a stub would pass
  every one of them. The ones worth naming: a stray `notes.txt`, an
  `attempt-01-agent.log.bak` and an `attempt-01-AGENT.log` all survive a wipe
  that removes the file beside them; a symbolic link named like a log is not
  followed; the second generation of a reopened task keeps its logs when the
  first is wiped; a read-only directory produces a part-way wipe that records
  nothing, and the next call finishes it and only then writes the row; and a
  second wipe of an already-wiped pass keeps the original timestamp, which
  dates the removal that actually happened.
- `LogWipingThroughTheLoopTest` drives a real `ralph` subprocess, because a
  wiper nothing calls would pass every unit assertion and leave a user's
  runtime directory as full as before. One run over two tasks wipes the
  successful one's logs and preserves the failing one's, and `ralph logs` still
  prints the failing task's worker output.
- `phpstan --level=max` on the changed source and tests: clean, apart from one
  pre-existing `cast.string` in `GenerationStore::reservedBranches()` that this
  work did not touch.

**Not this item.** `ralph logs TASK-ID` still reports a wiped log as "no longer
there" rather than as deliberately removed; that is item 12, and the column it
will read is now on the row. The README's configuration reference is item 14.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-17 — Section K, item 12: an absent log that says which kind of absent it is

**The item.** "Preserve logs for failed, blocked, and interrupted tasks, and
ensure ralph logs TASK-ID reports clearly when successful-task logs were
intentionally wiped."

Two halves, and the first was already load-bearing before this entry: `LogWiper`
refuses any pass that is not `completed`, with `LogRetentionReason::Unsuccessful`
behind the refusal, so a failed, blocked or interrupted task keeps every byte it
wrote whatever `logs.wipe_on_success` says. What was missing was the evidence
that the promise holds through a real run for all three of the states the item
names, and the whole of the second half: `ralph logs` had one sentence for a log
that was not on disk — "is no longer there" — and said it about a file the
package had deleted on purpose a second earlier.

**Why that sentence was wrong.** From outside, three different things look
identical: a log the wipe removed, a log the user deleted, and a log a run never
managed to write. Only the first is fine. A reader who turns the setting on and
then goes looking at a completed task gets a warning about a missing file, and
the only way to find out that nothing is wrong is to remember what they
configured. The row is what knows: `generations.logs_wiped_at` holds the moment
the wipe finished, and it is written only when nothing eligible is left.

**What `ralph logs` says now.** Under the pass's heading, once, when the row
carries a moment:

```
TASK-8CA4A94A: completed, attempt 1, ralph/shared
  base c0faa6a59aae, commit d889f3b84be4
  logs: wiped 2026-11-17T12:03:24+00:00, after this task succeeded (logs.wipe_on_success).
    the commit, the checks below and the completion evidence were kept.
```

and again where each attempt's output would have been printed, because a report
that is pages long should not make a reader scroll back to find out why the
file under their cursor is missing. Both lines are muted rather than warned:
nothing went wrong, and colouring a deliberate deletion red is the same mistake
as the sentence it replaced. The checks printed underneath come out of
`verification_results` rather than off the disk, so they are still there — which
is the part of the item that is about what *survives*, shown rather than
asserted.

**The third case, which the row cannot answer directly.** A wipe writes its
timestamp only once nothing eligible is left, so a wipe a permission cut short
leaves a completed pass with a missing log and no moment on its row — the state
that makes the retry safe (item 11). Reported as a warning, that would be a
false alarm about a file the user asked to have deleted. So `absence()` asks the
project as well: a pass that is complete and finalizable, in a project that
wipes on success, is missing its log because of the wipe, and the line says so
and names the retry — "this pass's wipe has not finished, and the next run
completes it". Everything else is still "is no longer there", because a project
that keeps every log and is missing one has genuinely lost something.

The three conditions in that branch are the wiper's own, re-asked rather than
inferred: `wipesOnSuccess()`, `isComplete()` and `isFinalizable()`. Dropping
`isComplete()` would have attributed a failed pass's missing log to a wipe that
is forbidden from touching it, which is the exact confusion the item is about.

**Verification**

- `vendor/bin/phpunit` — whole suite green on PHP 8.5: 3045 tests, 11241
  assertions (3039 before; 6 added).
- Four new cases in `LogsCommandTest`, each driving a real `ralph` subprocess
  and reading the report off its standard output: a wiped pass reports the wipe
  at both levels and still prints its checks and completion evidence; a failed
  task in a wiping project has its log printed byte for byte and the word
  "wiped" appears nowhere; a log deleted by hand in a non-wiping project is
  still the warning; and a completed pass in a wiping project whose row carries
  no moment is reported as a wipe still owed.
- Two new cases in `LogWipingThroughTheLoopTest` for the states item 12 names
  that were not yet covered end to end — blocked and interrupted. Each produces
  the state for real, the interrupted one with a signal to a running process
  rather than by writing `interrupted` into a row, then asserts the log files
  are still on disk and that `ralph logs` prints them without calling them
  wiped.
- `phpstan --level=max` on the three changed files: clean.

**Not this item.** The README's configuration reference still does not document
`logs.wipe_on_success` or what it promises to keep; that is section K, item 16.
Console newline normalisation is items 13 and 14, next.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-17 — Section K, item 13: one place that decides where a line ends

**The item.** "Normalize console output so every package-generated print/write
operation ends with a newline, including status messages, errors, progress
updates, and streamed worker output; avoid adding duplicate blank lines when
the incoming output already ends with a newline."

**What it was before.** Two rules in one class and a third copied into a
command. `Output::line()` appended `PHP_EOL` to whatever it was handed, so a
caller that passed a string which already ended in one — a line read out of a
file, or composed somewhere else — got a blank line after it and no way to
avoid that except by trimming first. `Output::text()`, the passthrough a
worker's bytes go through, added nothing, which is correct per chunk and
incomplete overall: the newline the last chunk never printed was simply lost.
Two places made up the difference by hand. `TerminalSink` kept its own
`atLineStart` flag and emitted a newline before a note and on close, and
`LogsCommand` tested `str_ends_with($contents, "\n")` after printing a log file
and added one itself.

**Why the hand-made version was wrong in a way that mattered.**
`TerminalSink`'s flag was one boolean for two streams, and the newline it
produced always went to standard output. A worker that left half a line on
standard error therefore got its ending written into the *other* stream: on a
terminal that happens to look right, because both streams are the same screen,
and under `ralph run > out.log 2> err.log` it puts a stray newline in the file
that was fine and leaves the line that was open still open. The flag also knew
nothing about the lines `Application::report()` writes, so an error printed
while a worker's progress indicator was mid-line landed on the end of it.

**Where the rule lives now.** `Output` tracks which destinations have a line
open and answers for all of it:

- `line()` and `diagnosticLine()` end their text exactly once. One trailing
  ending is removed before one is added, rather than all of them, so a caller
  that composed `"a paragraph\n\n"` keeps the blank line it asked for while a
  caller that passed one newline, or none, gets the same single ending.
- A line left open by `text()` is closed before the next composed line starts,
  so a note or an error never lands on the end of a worker's half-printed
  output.
- `line()` with no text is written as the single newline it is. That is both a
  deliberate blank line and the way an open line is closed, and the two must
  not add up to two newlines where one blank line was asked for — the
  "duplicate blank lines" half of the item, in the one case that can actually
  produce them.
- `finishLine()` closes whatever is still open, and `Application::run()` calls
  it in its `finally`. A command cannot hand the shell a prompt part-way along
  a line, whether the unfinished line came from a worker's last chunk or from
  `ralph logs` printing a file that ends without one.

**The cursor belongs to the destination, not the handle.** `destinationOf()`
treats the two streams as one when they are the same resource (`ralph run
2>&1`, and the tests that pass a single handle twice) or when both are
terminals, which is the ordinary case where a worker's standard error and the
package's notes are on the same screen. Redirected apart they are two files
with a cursor each, and neither is given a newline the other one owed. The
answer is resolved once because `stream_isatty()` is a syscall and this is
asked on every write.

Both copies of the rule are gone: `TerminalSink` has no flag and no
`startLine()` — `note()` just writes its line, `close()` calls `finishLine()` —
and `LogsCommand` prints the log's bytes and stops.

**Verification**

- `vendor/bin/phpunit` — whole suite green on PHP 8.5: 3057 tests, 11266
  assertions (3045 before; 12 added). The existing cases that pinned the old
  hand-made behaviour, including `TerminalSinkTest`'s note-after-an-unfinished
  -line and close-finishes-the-line, pass unchanged against the central rule.
- Ten new cases in `OutputTest`: a line ended once whether or not it arrived
  ended, including a `\r\n` one; a caller's own blank line surviving; an empty
  call being one blank line; a composed line after an unfinished chunk starting
  on its own; closing an unfinished line not also producing a blank one; a
  chunk that ended its line not getting a second ending; `finishLine()` closing
  both streams and being idempotent; `finishLine()` adding nothing when
  everything ended itself; a line open on one stream not being closed on the
  other; and one handle given as both streams keeping one cursor.
- One new case in `TerminalSinkTest`: an unfinished line on the error stream is
  finished on the error stream, which is the cross-stream defect above.
- One new case in `LogsCommandTest`, through the real binary: a worker log
  rewritten to end mid-sentence is printed as bytes, the next line of the
  report starts where it should, and neither that join nor the end of the whole
  report carries a blank line.
- `phpstan --level=max` on the four changed source files: clean.

**Not this item.** Chunk-boundary handling — a `\r\n` split across two reads, a
worker's line arriving in pieces — is item 14, and nothing here buffers or
reorders a chunk: bytes are still written the moment they arrive, which is what
keeps a progress indicator visible. The README's account of console newline
behaviour is item 16.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-17 — Section K, item 14: a reading that does not depend on the reads

**The item.** "Handle streamed output split across chunks so line endings
remain correct without corrupting or reordering worker output."

**Where chunks already survived.** Item 13 put the "has this destination got a
line open" question in one place, and the answer it keeps is the last byte of
whatever was written: a chunk ending in `\n` closed its line, anything else
left it open. That is already right for the split this item is about. A pipe
read that falls between the `\r` and the `\n` of a `\r\n` hands over a chunk
ending on a carriage return, which has *not* ended a line — in a file `\r` is
not an ending, and on a terminal the worker's text is still on the line the
cursor returned to the start of — so the worker's own next byte finishes it and
nothing is owed. `Output` and `LogFile` both behaved that way; what they were
missing was a test saying so, which they now have, on either side of the split
(the chunk that ends on the `\r`, and the note printed across one).

**Where they did not: the filter that reads lines.** `ClaudeStream` is the one
component that has to assemble lines out of chunks, because Claude Code's
`stream-json` is an event per line and a wall of envelopes is not a session
anybody can watch. It held bytes until a newline arrived, and two things about
that depended on where the reads fell.

*A line given up on was corrupted from the middle.* Past the 8 MB line limit
the held bytes were written through — correctly, since text that size with no
newline in it is not this format — but the *rest* of that line then went
through the renderer as though it were a line of its own. So a worker's single
long line came out with a newline inserted where the limit happened to fall,
its `\r\n` reduced to `\n`, and a tail that happened to be whitespace dropped
entirely. Confirmed before fixing: 9 MB of one line plus `TAIL\r\n` arrived as
`…aaa\nTAIL\n`.

*An error chunk landed on the end of an open sentence.* The error stream was
forwarded straight to the sink without ending the line the filter was part-way
through writing, so a warning the CLI printed while the agent's message was
being rendered was glued onto the end of it. Both streams are one screen in the
ordinary case, which is where that is unreadable.

**What it does now.** The chunk is taken a line at a time, each piece joins the
line it belongs to, and the one decision — is this line an event — is made from
the line's first non-blank byte rather than from the piece that byte arrived
in. Two consequences, and they are the item:

- A line that cannot be an event is let through *at once*, before its newline.
  An event is a JSON object, so a first non-blank byte that is not `{` settles
  it, and the bytes of that line and every byte after it up to its newline go
  out as they arrive. That also fixes the corruption above — a line given up on
  for its length enters the same passthrough, so its tail is the worker's bytes
  and not a rendering of them — and it makes a session a project pointed at
  `--output-format text` stream as prose instead of a line behind.
- A completed held line is decoded: an event is rendered, and anything else is
  written through with its own ending, `\r\n` included. One rule for both paths,
  because otherwise the same prose line would look different depending on
  whether the read that carried it included its newline.

An error chunk now ends the filter's own open line first. Nothing is inserted
into the error bytes, and the log file would have started a line for its stream
marker anyway, so this changes only the terminal — where it is the difference
between a warning and a sentence with a warning in it.

**What is deliberately not promised.** Ordering between the two pipes. A line
of standard output that is not yet whole is held, so an error printed meanwhile
appears before it. Writing out half a JSON envelope to keep the two in lockstep
would be worse, and the operating system does not interleave two pipes exactly
either. Within standard output nothing is reordered: the pieces of a line go
out in arrival order or not at all. Both of these are now in the class
description rather than implied.

**Verification**

- `vendor/bin/phpunit` — whole suite green on PHP 8.5: 3065 tests, 11281
  assertions (3057 before; 8 added). Every existing `ClaudeStream` case passes
  unchanged, including the prose passthrough and the over-long-line case, which
  are the behaviours the new state machine had to keep.
- The load-bearing new case is `the_rendering_does_not_depend_on_where_the
  _chunks_fell`: a feed with an init event, a text delta, a `\r\n` prose line, a
  blank line, a JSON line that is not an event and a result event is rendered
  once as a single chunk and again one byte at a time — every split there is —
  and the two readings are asserted identical, and then asserted to be the
  right rendering rather than two identically broken ones.
- Five more: the tail of a line given up on keeping its bytes and its `\r\n`
  with no invented newline anywhere in 9 MB; prose being visible before its
  newline arrives; a `\r\n` split between two reads rendering one event once and
  passing one prose ending through; an error chunk not landing on an open line;
  and, in `OutputTest` and `LogFileTest`, the carriage-return split surviving on
  both of the destinations item 13 made responsible for line endings.
- `phpstan --level=max` on `src/Agent/ClaudeStream.php`: clean.

**Not this item.** The tests item 15 asks for across both branch strategies,
cleanup and log wiping, and the README's account of all of it, which is item 16.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-17 — Section K, item 15: the behaviour read back off the real thing

**The item.** "Add automated tests for per-task and shared-branch strategies,
sequential task commits on one branch, resume/recovery, task worktree cleanup,
cleanup retries, preservation of failed worktrees, configurable successful-task
log wiping, and newline behavior across all console output paths."

**The audit first, because most of it was already there.** Items 1 to 14 were
each committed with their own tests, so this item is not "write the tests for
section K" — it is "find what those tests cannot see and cover that". What
exists, facet by facet:

- *Both strategies, and a run's tasks committed sequentially on one branch* —
  `SharedBranchThroughTheLoopTest` (seven cases through a real `ralph` run:
  one branch per run, the branch validated before an agent starts, two commits
  in parent order, the carry-forward asserted from the second task's tree, the
  default with no `git` section configured, per-task mode still one branch per
  task) and `SharedBranchesTest` for the readings underneath it.
- *Resume and recovery* — `SharedBranchRecoveryTest` (eleven cases: the commit
  a crash never recorded, an unrecorded commit above it, a rewritten branch, a
  deleted branch put back, no-change passes while the branch moves on),
  `PassRecoveryTest`, `RecoveryThroughTheLoopTest` and the Ctrl+C case in
  `WorkspacesThroughTheLoopTest`.
- *Worktree cleanup, cleanup retries, preserved failed worktrees* —
  `WorktreeCleanerTest`, which is the largest file in the suite and settles
  every decision: who may be removed, who is kept and why, part-deleted
  directories, a repaired link, a removal Git reported as done while the
  directory stayed, and the sweep that accounts for every pass. Plus
  `CleanCommandTest` for the explicit action.
- *Configurable log wiping* — `LogWiperTest` (sixteen cases, including a wipe
  left for the next one to finish) and `LogWipingThroughTheLoopTest` (wiped on
  success, preserved for failed, blocked and interrupted, off by default).
- *Newline behaviour* — `OutputTest`, `LogFileTest`, `TerminalSinkTest` and
  `ClaudeStreamTest`, all of them against a captured stream.

**The two gaps, which is what this commit is.** Both are of the same kind: a
property of the *program* that no single class's test can observe.

1. **Newline behaviour had never been read off a terminal.** Every existing
   case hands one class a chunk and inspects what it wrote. But where a line
   ends is a property of the whole process — a command that composes its own
   text perfectly and then prints a worker's unterminated chunk after it still
   hands the user two sentences on one line, and the class that wrote each half
   is innocent. `ConsoleLinesThroughTheLoopTest` runs `ralph` as a subprocess
   and asserts, on both pipes as bytes: a stream that was written to ends with
   exactly one newline, and there are no three consecutive newlines anywhere.
   Nine cases carry it across the command surface — a sweep over thirteen
   invocations of a project that has run, the failure paths (unknown command,
   missing argument, unknown task, undefined agent, contradictory options, a
   setting the validator rejects, and a directory that is no project, which is
   the one case that writes on both streams at once), a run that failed, a run
   that blocked, a run stopped with a real SIGINT, a check that stopped
   mid-line, a check whose lines end `\r\n`, the worker's own streamed report,
   `status --json` asserted by parsing it, and a stored log cut off mid-line
   printed by `ralph logs`.
2. **Cleanup had never been read off the repository after a real run.**
   `WorktreeCleanerTest` proves the cleaner decides correctly; `RunnerTest`
   proves the runner asks, in-process. Neither would notice if `ralph run`
   stopped reaching that code. `WorktreeCleanupThroughTheLoopTest` drives the
   binary and then reads `git worktree list`, the directory listing, `git log`
   and `git branch`: a completed task's workspace and its Git registration are
   both gone while its commit, branch and ticked box are not; per-task mode
   removes every workspace and leaves every branch holding its commit; a shared
   run cleans up after each task and the branch keeps both commits in order
   with the first task's file in the second task's tree; a failed task keeps its
   workspace, registration included, with the agent's half-finished file in it;
   a removal an interruption left half done is finished by the next
   `ralph clean --all` and asking again claims nothing; and an interrupted
   task's workspace is kept until the resume completes the pass, which is what
   removes it.

**Verification**

- `vendor/bin/phpunit` — whole suite green on PHP 8.5: 3080 tests, 11471
  assertions (3065 and 11281 before; 15 tests added).
- `phpstan --level=max` on both new files: clean.
- Both files were checked against deliberately broken builds, because a test
  that passes either way is documentation. `Output::writeLine()` no longer
  closing an open line first: three newline cases fail (the mid-line check, the
  cut-off log, the carriage returns). `ended()` adding two endings instead of
  one: all nine fail. `TaskPass::cleanUp()` returning early: five of the six
  cleanup cases fail. Restored after each.

**What the newline sweep deliberately does not assert.** What any command
*says*. That belongs to the command's own test; these cases are about where the
lines end, which is why the sweep can grow a row per new command without
growing an expectation per command.

**One thing the suite still cannot reach.** `Output::finishLine()` — the
last-resort close on the way out of `Application::run()` — is not reachable
through the CLI, because every command writes at least one composed line after
its last streamed bytes, and the log file now ends its own lines too. It stays
unit-tested in `OutputTest`, and the entry says so rather than pretending the
end-to-end cases cover it.

**Not this item.** The README and configuration reference for branch
strategies, cleanup guarantees, log retention and console newlines, which is
item 16 and the last unchecked box in PROJECT.md.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-17 — Section K, item 16: the settings a reader can act on

**The item.** "Update the README and configuration reference to document branch
strategies, shared-branch semantics and limitations, worktree cleanup
guarantees, log-retention settings, and console newline behavior."

**The audit first, because three of the five were already written.** Items 1 to
8 landed with their documentation, so the README already carries the branch
strategies (`shared` establishes one branch per run, validated once before the
first agent starts, each task's workspace made from the tip as it stood;
`per-task` gives each task its own branch off the base commit), the shared
branch's semantics and its refusals (an unrecorded commit, a branch another
worktree holds, a workspace no longer at the tip, and the 500-commit bound on
how far back a resume will vouch for a history), and the cleanup guarantees
(removal only after the commit, the push, the row and the tick are durable; the
six conditions a removal needs; the detach that lets a kept worktree release the
shared branch; the three recoverable interruptions; the two limits of `git
worktree repair` and a lost registration). Both the Configuration table and the
Limitations list already carried their share.

**The two that were missing, which is what this commit is.**

1. **Log retention was undocumented outside `ralph logs --help`.** The
   configuration reference did not have a `logs` section at all — the sample
   `config.json` in the README stopped at `limits`, while `ralph init` has
   written a `logs` block since item 9 — so a reader comparing the two would
   conclude their file had grown a key the package did not know. Worse, the
   Logs section still said files "stay until you delete them or the task's
   workspace is cleaned up", which was wrong in both directions: cleaning a
   workspace never touched a log, and since item 11 something else can delete
   one. The section now states the default and why it is the default, the one
   setting that changes it, what a wipe is entitled to delete
   (`attempt-NN-kind.log`, that pass's own directory, names this package
   composes) against what it may not (the commit, the row, the check results,
   `-staged.diff`, `-completed.md`, `-satisfied.md`), the four states that are
   never wiped, and the retry semantics — the timestamp written only when
   nothing eligible is left, so a stopped wipe costs a retry. `ralph logs` now
   says in the command reference that a wiped log reads as wiped.
2. **Console newline behaviour had no home in the README.** Item 13 made every
   composed line end exactly once and item 14 made a split chunk survive it,
   but a user reading the README could not learn what to expect of `ralph run |
   tee`. A new "Console output" section states the four properties the tests
   assert: a composed line is ended whether or not the caller ended it and a
   blank line is one newline; a worker's bytes are passed through unappended
   and unreordered; the newline a chunk owes is deferred and closed by the next
   composed line or by the last thing a command does; and the cursor belongs to
   the destination, so `2>&1` is one cursor and two redirections are two.

**Two claims corrected against the code while writing them.** The first draft
said every retention decision is reported with its reason. It is not:
`LogWiper::wipe()` notes only a wipe that removed something or one that could
not finish, so a kept pass prints nothing, and the text now says so. The same
draft had an undeletable file recorded "as a reason on the record" — the record
holds one timestamp and nothing else, and what actually happens is that the
timestamp stays unwritten so the next pass tries again.

**Verification**

- `vendor/bin/phpunit` — whole suite green on PHP 8.5: 3080 tests, 11471
  assertions. No test changed; this commit is documentation.
- The sample `config.json` in the README was compared key by key against
  `Defaults::toArray()` (`tasks`, `context`, `agent`, `agents`, `checks`,
  `workspace`, `git`, `limits`, `logs`) and against the shipped example
  project's file, which already carried the `logs` block.
- Every statement about wiping was read off `LogWiper`, `LogSettings`,
  `LogRetentionReason` and `LogsCommand` rather than off the item, which is how
  the two corrections above were found; every statement about line endings was
  read off `Output::writeLine()`, `ended()`, `text()` and `finishLine()`.
- One Markdown trap avoided: a redirection example wrapped so that `> out.log`
  began a line, which CommonMark reads as a blockquote interrupting the
  paragraph. Reflowed.

**The checklist is now complete.** Item 16 was the last unchecked box in
PROJECT.md; sections A through K are all ticked.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-18 — Section K, item 1: the Git work is gone

**The item.** "Remove all the git logic and let the package work on the current
branch of the user. No need for shared-strategy and branch-per-task bullshit.
JUST WORK ON THE CURRENT CODE REGARDLESS OF GIT."

**What the package did before this, and does now.** A pass used to resolve a
base reference to a commit, create a linked worktree under
`.ralph/runtime/worktrees/<pass>`, prepare it with the configured install
commands and copies, claim or establish a branch, invoke the agent there, run
the checks there, stage the result, read the staged diff for changes that
weakened the project's own verification, commit with the task's trailers, push
when publication was enabled, tick the box, and then clean the workspace up.
A pass now loads the context, invokes the agent in the project root, runs the
checks in the project root, and ticks the box. Everything between "the checks
passed" and "the box is written" is gone, and so is everything before "the
agent is invoked" except the briefing.

**Scope, which is most of this commit.** 228 files, −50,170 lines against
+1,894. The deletions are the whole of `src/Git` (54 classes), `tests/Git` (29
cases), the `git` and `workspace` configuration sections with their value
objects, the five Git preflight checks, `ralph clean`, the diff-based
weakening audit and no-change satisfaction audit with their value objects, the
publication and worktree exceptions, `PassRecovery`/`Recovery`/`RecoveryVerdict`
(1,600 lines of reconciling a repository against the rows), and the
corresponding tests. The suite went from 3,080 cases to 2,197; every one of them
passes.

**The decisions worth recording.**

1. **The phases are four, not six.** `committing` and `publishing` had nothing
   left to do, so `Phase` now reads preparing → executing → verifying →
   finalizing. A row written by an earlier build that says `committing` or
   `publishing` is read as `finalizing` rather than refused: such a pass had
   done its work and passed its checks, which is exactly what `finalizing`
   means, and the alternative was a database a resume could not open.
2. **The schema drops its Git columns in a migration rather than by rewriting
   history.** Migration 7 removes twelve columns from `generations`, three from
   `runs`, the `generations_branch` index, and the `git_references` table.
   Versions 1 to 6 are left exactly as they were, with their frozen vocabularies
   inlined where they used to read `Schema::pushStates()` — a historical
   statement has to keep creating what it always created. The one cost is a
   SQLite floor: `ALTER TABLE … DROP COLUMN` needs 3.35, which the migration's
   docblock states beside the remedy (delete the runtime database).
3. **What completes a task is now the checks and only the checks.**
   `CompletionEvidence` used to read four conditions; it reads one. The record
   it writes gained a "The changes" section — where the work is, how to read it,
   what undoes it — because "why is this box ticked?" used to be answered partly
   by a commit SHA and now has to be answered by pointing at the working tree.
4. **A pass that owes only its checkbox is recognised from the plan, not from
   the row.** `isFinalizable()` was a column reading (`commit recorded, push
   settled`); there are no such columns. The planned checklist write —
   `pending` in `checklist_updates`, written before the file is touched — was
   always the real signal, and it is now the whole of it. Section G, item 8
   behaves as it did, which `RecoveryThroughTheLoopTest` still asserts through
   the binary.
5. **Resume no longer reconciles anything.** With no commit, no branch and no
   worktree there is nothing for the repository to disagree with the rows
   about, so the resume path is the ordinary loop with a recorded agent and
   carried-over counters. The post-commit-crash case the item named cannot
   arise: the work is in the working tree the moment the agent writes it.
6. **`LogWiper` asks the checklist store instead of the row.** Its
   `Unsettled` refusal used to mean "the push has not settled"; it now means "a
   planned checkbox write is still outstanding", which is the same defensive
   question asked of the layer that still has an answer.
7. **The example project installs itself.** `workspace.prepare` used to run
   `composer install` inside each worktree; the checks now run in the reader's
   own copy, so `examples/README.md` and the example test install it first. The
   example's `.gitignore` gained `/.ralph/runtime/` so a copy reads cleanly in
   `git status` from the first run.

**What this costs, documented rather than hidden.** Tasks are no longer
isolated from each other or from the user's own uncommitted work: a run's
changes arrive as one diff, a later task inherits an earlier task's mistake, and
`git checkout -- .` is the only undo there is. The README's new "Your checkout"
section states all three beside what the change buys (each task sees the last
one's work; the agent uses the dependencies you already have installed), and the
Limitations list repeats them.

**Verification**

- `vendor/bin/phpunit` — whole suite green on PHP 8.5: 2,197 tests, 7,735
  assertions.
- `vendor/bin/phpstan analyse src tests --level=5` — the five findings it
  reports are the five it reported before this change; no new one.
- `ralph init` and `ralph preflight` run by hand in a scratch repository: the
  runtime directories no longer include `worktrees/`, the next steps no longer
  tell the reader to commit the ignore rules, and preflight reports five checks
  rather than ten.
- `ExampleProjectTest` runs a task through the real binary in a copy of the
  example and asserts what is left behind: ` M .ralph/tasks.md`, ` M
  src/Slugger.php`, `?? tests/SluggerMaximumLengthTest.php`, and no commit.

**Documentation.** The README lost its "Git behaviour" section and the `ralph
clean` reference and gained "Your checkout"; the configuration reference, the
phase table, the worked example, the troubleshooting table, the limitations and
the dependency note were all rewritten where they described branches, commits,
worktrees or pushes. `docs/demonstrations.md` is left as the record it is, with
a note at the top saying the two runs predate this item and that what they were
evidence about — briefings, agent behaviour, verification output, interventions,
elapsed time — is unchanged. `.ralph/CONTEXT.md` in this repository carries the
same note, because it is a copy of the PRD and would otherwise brief the next
agent on a design that no longer exists.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-21 — Section K, item 2: two accounts of a run, and the quiet one is the default

**Task:** Add a new verbose mode that is not enabled by default. When enabled
(using a flag), all the output is printed just like what's happening now (the
current output is verbose). When disabled, which is the default, less output
should be shown. *(PROJECT.md section K, item 2)*

**The shape of the answer.** Two complete accounts rather than an account and a
truncation. A run hands an agent a task and then has nothing to do for twenty
minutes but repeat what the agent is saying, and what the agent says is
thousands of lines of reasoning, tool calls and diffs with the four lines that
are the run's own account buried in it. So the default prints what Ralph PHP
composes about its own work — which run this is, which task was taken on, what
happened to it, why the run ended — and `--verbose` prints that plus everything
else.

**What was done**

- `src/Console/Verbosity.php`, a new enum with `Quiet` and `Verbose` cases. It
  reads the flag off the raw argv tokens rather than parsed input, for the
  reason `ColourChoice::requestedIn()` already does: parsing a command line can
  itself fail, and the report of that failure is output this decides the shape
  of. The short form is found inside a bundle, so `-nv` and `-vn` both ask for
  it, and everything after `--` belongs to no option.
- `Output` gained `detail()` and `detailLines()` beside `line()` and `lines()`.
  Which of the two a line goes through is decided where the line is composed,
  for the same reason the styling is — only the caller knows whether what it is
  about to say is the outcome or the commentary. `useVerbosity()` reaches the
  output already in use rather than making a second one, because the output a
  command was handed is also the one a failure is reported through.
- `Application` now settles verbosity before dispatch, next to the colour, and
  `report()` reads `--verbose` off the output instead of re-scanning the tokens.
  The duplicate `isVerbose()` scanner and its two constants are gone.
- `TerminalSink` writes nothing under `Quiet` — both the agent's bytes and the
  `--- ` notes filed beside them. The decision is taken per write rather than by
  leaving the sink out of the composite, so the one place that knows what is
  being echoed is also the place that decides whether to echo it, and so an
  attempt's log is opened, written and closed identically either way.
- `Runner::announce()` was split three ways. The run's identity line is printed
  however quietly the run was asked to go, because it is what `status`, `logs`
  and `resume` are asked about afterwards. The warnings are too — each one says
  this run will behave differently from the run the user thinks they started,
  and a warning withheld until `--verbose` is a warning that arrives after the
  damage. The resolved settings are commentary on a file the user can read, and
  are detail. The same split was applied to the frozen-checks drift report: the
  fact of the edit is said, which settings changed is detail.

**Nothing durable depends on the flag.** The attempt's log, the event stream,
the state store and the checklist are written identically either way. A quiet
run is a run whose terminal said less, not a run that kept less, and `ralph
logs TASK-ID` prints afterwards what a verbose run would have shown while it
happened.

**Verification**

- `vendor/bin/phpunit` — whole suite green on PHP 8.5: 2,217 tests, 7,798
  assertions, up 20 tests from the last item's 2,197.
- `tests/Console/VerbosityTest.php` covers the flag's reading: the long form,
  the short form, the short form inside a bundle, a token that only looks like
  it, and that `--` ends the options.
- `tests/Console/VerbosityThroughTheLoopTest.php` runs the real binary twice
  over the same project and compares the two terminals. The quiet run is
  asserted to still carry the four kinds of line a user needs to act on and to
  carry none of the three that are the wall of text; the verbose run to be the
  run that was there before the flag existed; and — the assertion that makes the
  default safe — a quiet run to keep on disk everything a verbose run shows.
- `vendor/bin/phpstan analyse src tests --level=5` — the five findings it
  reports are the five from before this change; no new one.
- Run by hand in a scratch repository against a shell-script agent speaking the
  real result protocol: the quiet run is 6 lines and the verbose run 24 over the
  same task, both completing it; the quiet run's `attempt-01-agent.log` holds
  the agent's output and the check's output; a config error reported under
  `--verbose` carries its stack trace and without it does not; and the
  no-checks-configured warning reaches a quiet terminal.

**Documentation.** The README gained a "How much is printed" section with both
accounts shown, the note that nothing durable depends on the flag, and the
closing advice to leave it off if unsure; the command table, the `ralph run`
reference, the phase table and the logs section were corrected where they said
worker output is streamed to the terminal unconditionally; and a troubleshooting
row was added for the run that prints a task and then nothing, which is the one
new way a quiet default can confuse somebody. `ralph --help` and `ralph help
run` describe the flag as what it now is rather than as a stack-trace switch.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.

## 2026-11-21 — Section K, item 3: a blank line after everything the package says

**The item.** "Add a newline (\n\n) after every output that the package prints
to the console regardless of what it is. There should always be a new line."

**The one reading of it that is worth having.** Taken per *line*, the item is
double spacing: `ralph status` would print its table one row per paragraph,
`ralph --help` would come apart into forty, and a `--verbose` run — which item 2
of this section defines as "the output that is there now" — would be twice as
long for the same content. Taken per *thing said*, it is the spacing the output
was missing: the run's heading, each task, each task's result, the run's
figures, each section of a report, each error with its hints. So that is what
was built, and it is stated here because the two readings are different work: a
blank line goes after every piece of output the package writes, where a piece is
what one part of the package says in one go, and the lines inside one of them —
the rows of a table, the heading over a column, the figures of a run and the
action they leave the user with — stay together.

**One place decides, as before.** `Output` gained `separate()`: a caller that
has finished saying something says so, and the blank line is *owed* rather than
written. The next line printed to that destination puts it down first, and
`finishLine()` — the last write of the process — puts down whatever is still
owed, or one of its own if nothing was. Three consequences fall out of the
deferral rather than having to be arranged:

- Two separations in a row are one blank line. The flag is set, not counted, so
  a section that ends, a sink that closes and a caller's own `line()` landing
  on top of each other produce one blank line between them and never two.
- A separation nothing follows costs nothing. An attempt whose agent printed
  nothing, a quiet run's unechoed stream, a command that separates its sections
  and then finds it has none — none of them leave a stray line behind.
- A blank line is never the *first* thing printed. A destination nothing has
  been written to is owed nothing, which is also why `ralph status --json`,
  whose diagnostics stayed empty, does not put a lone newline into the file
  standard error was redirected to.

`line()` with no text now routes into the same mechanism, so the twenty-two
places that already printed a blank line between two sections became collapsing
separations without being touched, and a caller that composed its own `"\n\n"`
has the blank line kept as a separation rather than as bytes already on the
stream that the next one would double.

**Where the pieces end.** `Runner` says explicitly where one thing it says
finishes: the run's whole opening (identity, warnings and all), the heading over
each task, each task's result and its next action, the drift report, the lock
takeover, the unconfirmed-worker note, the event-stream note, and the run's
ending. `TerminalSink::close()` now separates instead of only closing a line,
which is what puts the blank line between an agent's last output and the loop's
report of the attempt. Every other command gets the end of its output from
`finishLine()`, and its internal sections from the separators it already had.

**And the output that is not ours.** A worker's bytes are still passed through
unappended and unreordered: nothing is added to a chunk, because a chunk ends
wherever the agent happened to be and an ending inserted there would break the
agent's own line and make the terminal disagree with the log it copies. The
newline such a chunk is owed is still deferred, and the blank line goes after
it, not into it. The three bootstrap failures in `bin/ralph` — wrong SAPI, PHP
too old, no autoloader — are written with `fwrite()` before `Output` can be
loaded, so they end with the blank line through a `RALPH_OUTPUT_END` constant
instead.

**Verification**

- `vendor/bin/phpunit` — whole suite green on PHP 8.5: 2,224 tests, 7,824
  assertions, up 7 tests from the last item's 2,217.
- `tests/Console/OutputTest.php` gained the mechanism's own cases: a separation
  asked for repeatedly is one blank line; a separation over a line a worker
  left open closes it first; a separation reaches both streams that have
  printed and no stream that has not; finishing after a separation still
  leaves one blank line; and finishing is idempotent.
- `tests/Console/ConsoleLinesThroughTheLoopTest.php` is the same sweep over
  every command and every outcome, with its invariant moved on: a stream that
  was written to now ends with exactly one blank line rather than none, and
  still has no two blank lines anywhere. Two cases were added for the two
  halves of the reading above — a quiet run divides into exactly the four
  pieces it has to say, and `ralph status` divides into exactly three with the
  rows of its table still adjacent to their heading.
- `vendor/bin/phpstan analyse src tests --level=5` — the same five findings as
  before this change, none in a file it touched.
- Run by hand in a scratch repository against a shell-script agent speaking the
  real result protocol: `init`, `preflight`, `run`, `run --verbose`, `status`,
  `status --json`, `logs`, `resume`, `help`, `--version`, an unknown command and
  a failure under `--verbose` all read back through `cat -A`. Each ends with one
  blank line; the quiet run is four paragraphs; the verbose run is the dense
  transcript it was, separated from the result line; `status --json` still
  parses; and `ralph run | head -2` still does not take the run down with it.

**Documentation.** The README's "Console output" section states the new promise
beside the old one, with a quiet run printed out as the example, the note that
the blank line goes after each thing said rather than after each line, and that
it is never doubled anywhere — including after the `--json` document. The colour
section's claim that "a run's tasks are separated by a blank line" was corrected
to the rule it is now a case of.

**No push.** This repository's `origin` is SSH with no usable key, so the work
is committed only.
