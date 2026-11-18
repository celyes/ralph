#!/bin/bash

set -uo pipefail

ITERATIONS=50
PROMPT_FILE="PROMPT.md"
EXTRA_ARGS=() # unrecognized options are passed through to claude

usage() {
    cat <<'USAGE'
Usage: ./loop.sh [options] [-- claude options]

Options:
  --iterations=N, -i N   Number of iterations to run (default: 50)
  --prompt=FILE, -p FILE Prompt file to send each iteration (default: PROMPT.md)
  --help, -h             Show this help

Any other option is forwarded to claude, e.g.:
  ./loop.sh --iterations=3 --allowed-tools "Bash(echo *)"

Exit codes:
  0    all tasks complete, or max iterations reached
  2    Claude usage/rate limit reached — wait for the reset and re-run
  3    out of API credits
  4    not authenticated (run `claude /login`)
  5    the API is overloaded — transient, try again
  130  interrupted
  *    claude failed for another reason; its exit status is passed through
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --iterations=*) ITERATIONS="${1#*=}"; shift ;;
        --iterations|-i) [[ $# -ge 2 ]] || { echo "loop.sh: $1 needs a value" >&2; exit 1; }
                         ITERATIONS="$2"; shift 2 ;;
        --prompt=*)     PROMPT_FILE="${1#*=}"; shift ;;
        --prompt|-p)    [[ $# -ge 2 ]] || { echo "loop.sh: $1 needs a value" >&2; exit 1; }
                        PROMPT_FILE="$2"; shift 2 ;;
        --help|-h)      usage; exit 0 ;;
        --)             shift; EXTRA_ARGS+=("$@"); break ;;
        *)              EXTRA_ARGS+=("$1"); shift ;;
    esac
done

[[ "$ITERATIONS" =~ ^[0-9]+$ && "$ITERATIONS" -gt 0 ]] \
  || { echo "loop.sh: --iterations must be a positive integer, got '$ITERATIONS'" >&2; exit 1; }
command -v jq >/dev/null 2>&1 || { echo "loop.sh: jq is required" >&2; exit 1; }
[[ -f "$PROMPT_FILE" ]] || { echo "loop.sh: prompt file '$PROMPT_FILE' not found" >&2; exit 1; }

# Colors only when stdout is a terminal and NO_COLOR is unset.
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then COLOR=1; else COLOR=0; fi

# jq filter: turns the stream-json event feed into readable live output.
read -r -d '' RENDER <<'JQ' || true
def col(c): if $color == "1" then "\u001b[" + c + "m" else "" end;
def rst:    if $color == "1" then "\u001b[0m"       else "" end;
def paint(c): col(c) + . + rst;
def flat: gsub("\\s+"; " ");
def clip(n): if (length > n) then .[0:n] + "…" else . end;
def summarize(n):
  (.input.command // .input.file_path // .input.pattern // .input.path
   // .input.url // .input.prompt // .input.description // (.input | tostring))
  | tostring | flat | clip(n);

def errtext:
  if type == "object" then (.message // .error?.message? // tostring) else (. // "" | tostring) end;

def render:
if .type == "system" and .subtype == "init" then
  ("  " + (.model // "?") + " · session " + ((.session_id // "????????")[0:8]) + "\n" | paint("90"))

# API trouble (429s, overloads, auth) arrives as its own event. Showing it is
# what makes a limit visible while it happens instead of only at the exit code.
elif .type == "system" and .subtype == "api_error" then
  ("⚠ api error: " + (.error | errtext | flat | clip(200))
   + (if .retryAttempt then " · retry " + (.retryAttempt | tostring)
                            + "/" + ((.maxRetries // 0) | tostring) else "" end)
   + (if .retryInMs then " in " + ((.retryInMs / 1000 | round) | tostring) + "s" else "" end)
   + "\n" | paint("33"))

elif .type == "stream_event" then
  .event as $e
  | if   $e.type == "content_block_start" and $e.content_block.type == "thinking" then
      ("\n✻ thinking " | paint("35"))
    elif $e.type == "content_block_start" and $e.content_block.type == "text" then
      "\n"
    elif $e.delta.type == "thinking_delta" then
      # Real text when the model exposes it; otherwise a progress tick.
      (if ($e.delta.thinking // "") != "" then ($e.delta.thinking | paint("35")) else ("·" | paint("90")) end)
    elif $e.delta.type == "text_delta" then
      $e.delta.text
    elif $e.type == "content_block_stop" then
      "\n"
    else empty end

elif .type == "assistant" then
  (.message.content[]? | select(.type == "tool_use")
   | ("⏺ " + .name + "(" + summarize(100) + ")\n" | paint("36")))

elif .type == "user" then
  (.message.content[]? | select(.type == "tool_result")
   # Pick the color before the pipe: past it, "." is the rendered string, not the block.
   | (if .is_error then "31" else "90" end) as $tone
   | ("  ⎿ "
      + ((.content | if type == "array" then (map(.text? // "") | join(" ")) else (. // "" | tostring) end)
         | flat | clip(120))
      + "\n" | paint($tone)))

elif .type == "result" then
  ("\n" + (if .is_error then "✗ failed" else "✓ done" end)
   + (if (.subtype // "success") != "success" then " (" + .subtype + ")" else "" end)
   + " · " + ((.num_turns // 0) | tostring) + " turns"
   + " · " + ((((.duration_ms // 0) / 100) | round / 10 | tostring)) + "s"
   + (if .total_cost_usd then " · $" + ((.total_cost_usd * 10000 | round) / 10000 | tostring) else "" end)
   + "\n" | paint("90")),
  ((.errors // [])[] | ("  " + (. | tostring | flat | clip(300)) + "\n" | paint("31")))

else empty end;

# Read as raw lines, not as JSON, so that a line claude prints as plain text
# does not abort the renderer and hide everything after it. Fail-fast errors —
# a usage limit above all — arrive exactly that way, so they are shown rather
# than swallowed.
#
# "fromjson?" yields nothing rather than null on a line that is not JSON, so a
# failed parse is detected by the collecting array being empty: binding it
# directly would skip the body altogether and print neither branch.
[fromjson? | objects] as $event
| if ($event | length) == 0 then
    (if test("^\\s*$") then empty else ("‼ " + . + "\n" | paint("31")) end)
  else ($event[0] | render) end
JQ

raw=""
err=""
cleanup() {
    [[ -n "$raw" && -f "$raw" ]] && rm -f "$raw"
    [[ -n "$err" && -f "$err" ]] && rm -f "$err"
    return 0
}
trap cleanup EXIT

say() { printf '%s\n' "$*" >&2; }

# The stream's JSON events, one per line, skipping anything claude printed as
# plain text. Reading the file with a plain `jq` instead stops dead at the first
# such line — losing every event after it and spraying parse errors — which is
# how a fail-fast failure used to end up looking like no information at all.
events() { jq -cR 'fromjson? | objects' "$1"; }

# Reproduce the claude invocation so a failure can be retried by hand.
command_line() {
    printf '%s' "claude -p \"\$(cat $PROMPT_FILE)\""
    local arg
    for arg in "${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"}"; do printf ' %q' "$arg"; done
    printf '\n'
}

# claude exits non-zero without saying why, so collect everything it did tell
# us — the result event, the api_error events, stderr — and name the cause.
# Always exits.
report_failure() {
    local status="$1" iter="$2"
    local subtype="" errors="" api_errors="" stderr_tail="" result_text="" cli_text=""
    local haystack="" epoch="" secs="" reset="" section=""

    if [[ -s "$raw" ]]; then
        # Anything on stdout that is not one of the stream's JSON objects was
        # printed by the CLI itself, which is how a fail-fast failure — a usage
        # limit reached before the first event — reports itself. It is the only
        # evidence in that case, and mining the stream by field never sees it.
        cli_text="$(grep -v '^[[:space:]]*{' "$raw" | grep -v '^[[:space:]]*$' \
                  | awk '!seen[$0]++' | tail -n 10)"

        subtype="$(events "$raw" | jq -r 'select(.type == "result") | .subtype // empty' | tail -n 1)"
        errors="$(events "$raw" | jq -r 'select(.type == "result") | (.errors // [])[] | tostring')"
        result_text="$(events "$raw" | jq -r 'select(.type == "result") | .result // empty' | tail -n 1)"
        api_errors="$(events "$raw" | jq -r '
            select(.type == "system" and .subtype == "api_error")
            | .error
            | if type == "object" then (.message // tostring) else tostring end' \
          | awk '!seen[$0]++' | tail -n 5)"
    fi
    [[ -s "$err" ]] && stderr_tail="$(tail -n 20 "$err")"

    # Only the fields that actually describe the failure go in the haystack, so
    # stray numbers elsewhere in the stream cannot trip the patterns below.
    haystack="$(printf '%s\n%s\n%s\n%s\n%s\n%s\n' \
        "$subtype" "$errors" "$api_errors" "$result_text" "$stderr_tail" "$cli_text" \
      | tr '[:upper:]' '[:lower:]')"

    say ""
    case "$haystack" in
        *"usage limit"*|*"rate limit"*|*"rate_limit_error"*|*"usage_cap_reached"*|\
        *"reached your specified"*|*"5-hour limit"*|*"weekly limit"*|*" 429"*|*"(429)"*)
            # The reset point travels either as an epoch appended to the message
            # ("…reached|1761700000") or as an HTTP Retry-After in seconds.
            epoch="$(printf '%s' "$haystack" | grep -oE '\b1[0-9]{9}\b' | head -n 1)"
            secs="$(printf '%s' "$haystack" | grep -oiE 'retry-after:? *[0-9]+' \
                    | grep -oE '[0-9]+' | head -n 1)"
            # Trust the epoch only if it really is a reset moment ahead of us and
            # not some other ten-digit number that happened to be in the message.
            if [[ -n "$epoch" ]] \
               && (( epoch > $(date +%s) && epoch < $(date +%s) + 2592000 )); then
                reset="$(date -d "@$epoch" '+%a %d %b %H:%M %Z' 2>/dev/null || true)"
            elif [[ -n "$secs" ]]; then
                reset="$(date -d "+$secs seconds" '+%a %d %b %H:%M %Z' 2>/dev/null || true)"
            fi

            say "loop.sh: stopping at iteration $iter of $ITERATIONS — Claude usage limit reached."
            say "  Your plan's limit is exhausted, so no further iteration can do any work."
            [[ -n "$reset" ]] && say "  The limit resets around $reset."
            say "  Work from the iterations that did finish is already committed;"
            say "  re-run ./loop.sh after the reset to pick up where it left off."
            say "  Check your remaining quota with: claude /usage"
            status=2
            ;;
        *"credit balance"*|*"insufficient credit"*|*"out of credits"*|*"purchase credits"*)
            say "loop.sh: stopping at iteration $iter of $ITERATIONS — out of API credits."
            say "  Top up at console.anthropic.com/settings/billing, then re-run ./loop.sh."
            status=3
            ;;
        *"not logged in"*|*"please run /login"*|*"authentication_error"*|*"invalid api key"*|\
        *"oauth token"*|*"401 unauthorized"*|*"bad credentials"*)
            say "loop.sh: stopping at iteration $iter of $ITERATIONS — Claude is not authenticated."
            say "  Run \`claude\` and sign in with /login (or set ANTHROPIC_API_KEY), then re-run ./loop.sh."
            status=4
            ;;
        *"overloaded"*|*"529"*)
            say "loop.sh: stopping at iteration $iter of $ITERATIONS — the Claude API is overloaded."
            say "  This is transient; re-run ./loop.sh in a few minutes."
            status=5
            ;;
        *)
            if [[ "$status" -eq 0 ]]; then
                say "loop.sh: claude reported a failed result on iteration $iter of $ITERATIONS."
            else
                say "loop.sh: claude exited with status $status on iteration $iter of $ITERATIONS."
            fi
            # Only claim the cause was something else when claude actually said
            # something. With nothing to go on, saying "not a usage limit" is a
            # guess, and the wrong one often enough to be worth not making.
            if [[ -n "${haystack//[[:space:]]/}" ]]; then
                say "  This is not a usage limit, a credit or an auth failure — see the output below."
            else
                say "  claude gave no reason at all, so this could still be a limit it failed to report."
                say "  Check with: claude /usage   (and \`claude doctor\` for the install)"
            fi
            [[ -z "$raw" || ! -s "$raw" ]] && say "  It produced no output whatsoever."
            status=$((status == 0 ? 1 : status))
            ;;
    esac

    [[ -n "$subtype" && "$subtype" != "success" ]] && say "  result subtype: $subtype"
    for section in "claude printed:|$cli_text" "claude reported:|$errors" \
                   "api errors:|$api_errors" "stderr:|$stderr_tail"; do
        [[ -n "${section#*|}" ]] || continue
        say "  ${section%%|*}"
        printf '%s\n' "${section#*|}" | sed 's/^/    /' >&2
    done
    say ""
    say "  Retry this iteration by hand with:"
    say "    $(command_line)"

    exit "$status"
}

# Ctrl+C stops the whole loop, not just the current iteration.
on_interrupt() {
    trap - INT TERM
    printf '\n%s\n' "loop.sh: interrupted — stopping." >&2
    cleanup
    exit 130
}
trap on_interrupt INT TERM

for ((i = 1; i <= ITERATIONS; i++)); do
    echo ""
    echo "── Iteration $i of $ITERATIONS ──"

    raw="$(mktemp)"
    err="$(mktemp)"

    # stream-json + --include-partial-messages emits token-level deltas as they
    # arrive; tee keeps the raw events so we can read the final result afterwards.
    # stderr goes to a file rather than the terminal because jq is mid-pipe and
    # would interleave with it; it is printed verbatim if the iteration fails.
    claude -p "$(cat "$PROMPT_FILE")" \
        --output-format stream-json \
        --include-partial-messages \
        --verbose \
        "${EXTRA_ARGS[@]}" </dev/null 2>"$err" \
      | tee "$raw" \
      | jq -j -R --unbuffered --arg color "$COLOR" "$RENDER"

    status="${PIPESTATUS[0]}"
    # claude may absorb the signal and exit 130/143 itself; stop rather than loop on.
    if [[ "$status" -eq 130 || "$status" -eq 143 ]]; then
        printf '\n%s\n' "loop.sh: interrupted — stopping." >&2
        exit 130
    fi
    [[ "$status" -ne 0 ]] && report_failure "$status" "$i"

    # A failed result with a zero exit status means the iteration did no work
    # either — explain it rather than spending the remaining iterations on it.
    if [[ "$(events "$raw" | jq -r 'select(.type == "result") | .is_error' | tail -n 1)" == "true" ]]; then
        report_failure 0 "$i"
    fi

    # Warnings on a successful run are still worth seeing.
    [[ -s "$err" ]] && sed 's/^/  /' "$err" >&2

    results="$(events "$raw" | jq -r 'select(.type == "result") | .result // ""')"
    rm -f "$raw" "$err"
    raw=""
    err=""

    if [[ "$results" == *"<promise>COMPLETE</promise>"* ]]; then
        echo "All tasks are complete after $i iterations"
        exit 0
    fi
done

echo "Reached max iterations"
