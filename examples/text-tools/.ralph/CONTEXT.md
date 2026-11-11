# Project context

## What this project is

`ralph-php-example/text-tools` is a small PHP library that turns article titles
and bodies into web-ready strings: `Slugger` makes a URL slug out of a title,
and `Excerpt` shortens a body to a preview. It is the example project shipped
with Ralph PHP, so it is deliberately tiny — two classes, one test file each,
and no dependencies beyond PHPUnit.

## How it is run and verified

```bash
composer install
composer test
```

`composer test` is PHPUnit over `tests/`, and it is the command Ralph PHP runs
to verify a task. A task is not finished until it passes.

Each task is implemented in its own linked worktree, which Git creates with the
tracked files and nothing else — so there is no `vendor/` in it. The
`workspace.prepare` setting in `.ralph/config.json` runs `composer install
--no-interaction` there before you start; it has already run by the time you
see the workspace. There is nothing else to set up: this package has no `.env`,
no credentials and no generated code.

## Conventions

- PHP 8.3, `declare(strict_types=1)` in every file, PSR-12 formatting, PSR-4
  under `RalphPhpExample\TextTools\`.
- Classes are `final` and immutable: options arrive as constructor arguments and
  are `private readonly`.
- Invalid arguments are rejected in the constructor with
  `InvalidArgumentException` and a message that names the value it got.
- Strings are UTF-8, so use the `mb_*` functions and pass `'UTF-8'` explicitly.
  Counting characters means `mb_strlen`, never `strlen`.
- Tests are PHPUnit with the `#[Test]` attribute and snake_case method names
  that read as sentences, one behaviour per test. A new behaviour needs a test;
  a changed behaviour needs its existing test updated rather than a second test
  asserting the opposite.
- Keep public docblocks describing behaviour, including the edge cases
  (empty input, text shorter than the limit).

## Architecture and important files

| Path | What it is |
| --- | --- |
| `src/Slugger.php` | title → slug |
| `src/Excerpt.php` | body → preview of a given length |
| `tests/` | one test file per class |
| `phpunit.xml.dist` | the suite `composer test` runs |

## Out of scope

- Do not add a Composer dependency: this package requires PHP and `mbstring`,
  and the example is meant to install in seconds.
- Do not add a framework, a service container or a configuration file.
- Do not touch `composer.json`'s `name`, `autoload` or `scripts`, or
  `phpunit.xml.dist`.
- Do not change `.ralph/`: the checklist and this file are the task's input,
  not part of the work.
