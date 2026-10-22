# 0013 — Amount input: strict API, lenient bot

- **Status:** Accepted (amended 2026-10-21: bot parsing rules, see "Amendment")
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

`FILTER_VALIDATE_FLOAT` accepts `1e3`, and "max 2 decimals" can't be checked on a float. Latin
American users often type `200,50` or `1.000,50`. The owner decided the bot speaks English but must
accept both `.` and `,`, and ask for clarification when a number isn't clear.

## Decision

- `/convert` accepts only `^\d{1,7}(\.\d{1,2})?$`, checked on the raw string before casting.
- The bot runs `Validation/AmountParser` first: it strips `$`, `US$`, `USD` and spaces, then
  reads separators by position (the last of mixed separators is the decimal; a single separator
  with 1–2 digits after it is a decimal, with exactly 3 it's thousands; repeated separators are
  thousands with groups of 3). Its output is the canonical string, which then goes through the
  same strict validator.
- Anything it can't read unambiguously gets a clarifying question, counted as a retry; after
  `bot.max_retries` the session is abandoned (counted per step, ADR 0021).

## Consequences

- One strict validator for every path; the bot only translates.
- `1,000` is read as one thousand, never as 1.000 with three decimals (USD has two).
- Phase 2 LLM output goes through the same parser and validator.

## Amendment (2026-10-21): bot parsing rules after the P1-03 review

**Context.** Building P1-03 (PR #10, three review rounds) showed that a literal reading of the
rules above could make the bot read a different number from the one typed: `200.505` became
200,505 USD (1,000× too much), `200 3` became 2003 and `5$5` became 55. The round-2 code also
read a two-line message `200\n300` as 200300. The owner decided items 4 and 9; the other changes
came from the review rounds (items 1–3) or are builder choices the reviewers accepted (items 5–8).
They make input unclear or invalid, or read the same number the user typed (edge trimming,
leading zeros). None of them reads a different number.

**Decision.** Changes to the bot's parsing, in addition to the rules above:
1. **Currency markers** (`$`, `US$`, `USD`, any case) are stripped only at the start or end of the
   text, once at each end. A marker anywhere else makes the input unclear.
2. **Edge whitespace** — space, tab, CR, LF, U+00A0 and U+202F — is trimmed at the ends, including
   between a marker and the number. Any other whitespace character is unclear.
3. **Inner separators.** Inside a number only space, U+00A0 and U+202F may appear, and only as
   thousands separators in groups of exactly 3, optionally followed by one `.` or `,` decimal with
   1–2 digits (`1 000,50` → 1000.50). Anything else, including a tab, CR or LF inside the number,
   is unclear.
4. **One separator followed by exactly 3 digits** (owner decision): after a single `.` it is unclear
   (`200.505`, `1.500`); after a single `,` it is thousands (`1,000`, `1,234`, `200,505`).
   Repeated (`1.000.000`) and mixed (`1.000,50`) separators keep the rules above.
5. **First thousands group** must be 1–3 digits and must not start with `0`: `0,500`,
   `01.000.000`, `0.000,50` and `1234,567` are unclear.
6. **Input longer than 64 bytes** is unclear.
7. **More than 7 integer digits** (counted after leading zeros are dropped) is invalid
   (`over_max`), not unclear.
8. **Leading zeros** in an ungrouped integer part are dropped (`007` → 7, `00,5` → 0.50); the
   canonical string is rebuilt from cents, so it never has leading zeros. `/convert` keeps the
   pattern above, so `007.5` is accepted there too and becomes `7.50`.
9. **Installments** (owner decision): the bot trims the same edge set and accepts leading zeros
   (`03` → 3; `00` → `out_of_range`); `/convert` trims nothing and rejects them (`format`).

**Consequences.**
- The parser returns ok, unclear (ask again) or invalid (read but out of range). For amounts,
  `meta.reason` is `unclear`, `not_positive` or `over_max` from the parser, and `format` from the
  strict validator.
- A Latin American user who types `1.500` gets one clarifying question instead of a wrong amount.
- Tests: unit tables per rule, value-checking sweeps against an independent oracle (pinned read
  counts change only with a rule change), and acceptance cases for each owner decision.
