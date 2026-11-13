<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Console;

/**
 * Whether the package may colour what it writes to a stream.
 *
 * Three answers rather than two, because the useful default is neither: a run
 * watched in a terminal wants colour, `ralph status --json > status.json` must
 * not have escape sequences in it, and `ralph run | tee run.log` is a terminal
 * the user is watching through a pipe that this process cannot see. So
 * {@see self::Auto} asks the stream, and the two explicit cases exist for the
 * occasions when the stream's answer is the wrong one.
 *
 * **It is decided per stream, not per process.** `ralph status > out.txt`
 * leaves standard error a terminal and standard output a file, and the same
 * command writes to both; one answer for the process would either strip the
 * colour from the half a person is reading or write escape sequences into the
 * half a program is parsing ({@see Output::style()}).
 */
enum ColourChoice
{
    /**
     * Colour when the stream looks like a terminal and nothing says otherwise.
     */
    case Auto;

    /**
     * Colour whatever the stream is.
     *
     * For output that is going through something before it reaches a person —
     * `less -R`, `tee`, a CI log viewer — where the pipe this process can see
     * is not the terminal the text ends up on.
     */
    case Always;

    /**
     * Never colour, whatever the stream is.
     */
    case Never;

    /**
     * The choice the command line asks for, or null when it says nothing.
     *
     * Read off the raw tokens for the same reason {@see Application} reads
     * `--verbose` off them: parsing the command line can itself fail, and the
     * message explaining why has to be written in whatever form the user asked
     * for. Everything after `--` belongs to no option.
     *
     * Both at once is null — nothing said — rather than a precedence rule. The
     * output this would decide is the output that reports the mistake, so the
     * safe reading is the one that leaves the stream to answer, and the usage
     * error that follows says what was wrong with the line.
     *
     * @param list<string> $tokens argv without the script name
     */
    public static function requestedIn(array $tokens): ?self
    {
        $colour = false;
        $plain = false;

        foreach ($tokens as $token) {
            if ($token === '--') {
                break;
            }

            $colour = $colour || $token === '--color' || $token === '--colour';
            $plain = $plain || $token === '--no-color' || $token === '--no-colour';
        }

        return match (true) {
            $colour && !$plain => self::Always,
            $plain && !$colour => self::Never,
            default => null,
        };
    }

    /**
     * Whether this choice means colour on the given stream.
     *
     * `Auto` consults, in this order:
     *
     * - `NO_COLOR` set to anything but the empty string — the convention at
     *   no-color.org, honoured by enough tools that a user who exports it
     *   expects not to have to tell each one again;
     * - `FORCE_COLOR` set to anything but `0`, which is how a CI job that
     *   renders escape sequences in its log viewer asks for them through a
     *   pipe;
     * - `TERM=dumb`, which is a terminal saying it cannot do this;
     * - and finally the stream itself.
     *
     * `NO_COLOR` is checked before `FORCE_COLOR` because the two together are a
     * contradiction, and of the two readings, "the user who asked for no colour
     * gets none" is the one that cannot make output unreadable.
     *
     * @param resource|mixed $stream
     */
    public function appliesTo(mixed $stream): bool
    {
        if ($this !== self::Auto) {
            return $this === self::Always;
        }

        if (self::environment('NO_COLOR') !== null) {
            return false;
        }

        $forced = self::environment('FORCE_COLOR');

        if ($forced !== null && $forced !== '0') {
            return true;
        }

        if (self::environment('TERM') === 'dumb') {
            return false;
        }

        return is_resource($stream) && stream_isatty($stream);
    }

    /**
     * An environment variable, or null when it is unset or empty.
     *
     * Empty is unset here because that is what the `NO_COLOR` convention says,
     * and because `NO_COLOR= ralph run` is how a shell user turns the variable
     * off for one command.
     */
    private static function environment(string $name): ?string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}
