# Text Tools

A small PHP library that turns article titles and bodies into web-ready
strings.

```php
use RalphPhpExample\TextTools\Excerpt;
use RalphPhpExample\TextTools\Slugger;

(new Slugger())->slugify('Ralph PHP: the loop!');        // "ralph-php-the-loop"
(new Slugger('_'))->slugify('A tiny package');           // "a_tiny_package"

(new Excerpt(20))->from("Ralph PHP reads a\nchecklist.");  // "Ralph PHP reads a ch…"
```

| Class | What it does |
| --- | --- |
| `Slugger` | lowercases a title and joins its words with `-` or `_`, dropping everything that is not a letter or a digit |
| `Excerpt` | collapses whitespace and shortens a text to a given number of characters, adding `…` when it had to cut |

## Install and test

```bash
composer install
composer test
```

PHP 8.3+ and `mbstring`. PHPUnit is the only development dependency; there is
nothing else to set up.

## This is Ralph PHP's example project

It is shipped with [Ralph PHP](https://github.com/celyes/ralph-php) as something
real to run the loop on, and it is deliberately unfinished: `.ralph/tasks.md`
holds three small changes to the two classes above, and `.ralph/CONTEXT.md`
describes the package to the agent that will make them.

See [`../README.md`](../README.md) for how to copy this directory out and run it.
The short version:

```bash
cp -r text-tools /tmp/text-tools && cd /tmp/text-tools
git init && git add --all && git commit -m "Import the example package"
composer install
ralph init && ralph preflight && ralph run
```

`composer test` is what Ralph PHP verifies each task with, so a task is checked
off only once this suite passes on its own branch.

## Licence

MIT, as the package that ships it.
