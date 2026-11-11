# Tasks

Three small changes to this package, in the order Ralph PHP will run them. Each
one stands on its own: it touches its own files and is verified by this
package's own suite, `composer test`.

The checkbox below is ticked by the runner, not by you — a task is checked off
only after the suite passes on its branch and the change is committed.

## The work

- [ ] Add a maximum length to the slug generator
  - `Slugger` takes an optional maximum length in characters, as a second
    constructor argument, defaulting to no limit at all.
  - A slug longer than the limit is cut at a separator boundary, so that no word
    is left half-written, and the result never ends with the separator.
  - A single word longer than the limit is cut at the limit, because there is no
    boundary to cut at.
  - A maximum length below 1 is rejected in the constructor with an
    `InvalidArgumentException` naming the value.
  - Cover each of those in `tests/SluggerTest.php`, and leave the existing tests
    passing: a `Slugger` built without a limit must behave exactly as it does
    today.

- [ ] Make Excerpt cut on a word boundary instead of mid-word
  - `Excerpt::from()` cuts at the character limit today, which can leave half a
    word in front of the ellipsis.
  - Cut at the last space at or before the limit instead, so the preview ends on
    a whole word.
  - A single word longer than the limit still has to be cut at the limit.
  - A text already within the limit is still returned unchanged, with its
    whitespace collapsed and no ellipsis.
  - Update `tests/ExcerptTest.php`: the test that only asserts the ellipsis and
    the prefix should state the exact preview it now expects.

- [ ] Add a ReadingTime class that estimates how long a text takes to read
  - New `RalphPhpExample\TextTools\ReadingTime` with
    `minutesFor(string $text): int`, in `src/ReadingTime.php`.
  - Words a minute is a constructor argument defaulting to 200; a rate below 1
    is rejected with an `InvalidArgumentException`.
  - The result is rounded up, and is never 0 for a text that holds at least one
    word.
  - An empty or whitespace-only text is 0 minutes.
  - Cover it in a new `tests/ReadingTimeTest.php`, including the rounding, the
    empty text and the rejected rate.
