# 0013 — Amount input: strict API, lenient bot

- **Status:** Accepted
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
  `bot.max_retries` the session is abandoned.

## Consequences

- One strict validator for every path; the bot only translates.
- `1,000` is read as one thousand, never as 1.000 with three decimals (USD has two).
- Phase 2 LLM output goes through the same parser and validator.
