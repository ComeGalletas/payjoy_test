# 0018 — Keeping tests off the network

- **Status:** Accepted (amended 2026-10-22: test server and Node harness, see "Amendment")
- **Date:** 2026-10-20
- **Deciders:** owner

## Context

GUARDRAILS INV-06: tests never reach the network or load the real key. The obvious switch,
`allow_url_fopen=0`, can only be set when PHP starts, not in `phpunit.xml`. Guzzle, which uses curl,
arrives in P1-04.

## Decision

- **Layer 1:** the Composer `test` and `acceptance` scripts start PHPUnit with
  `-d allow_url_fopen=0`. `make check` runs them and is the gate.
- **Layer 2:** `NetworkGuard` in the test bootstrap replaces the http, https, ftp and ftps stream
  wrappers with ones that throw, and fails the run at shutdown if a test swallowed the exception.
- **P1-04 adds curl and Guzzle:** the scripts also pass
  `-d disable_functions=curl_exec,curl_multi_exec`; the rates client only gets an injected mock
  handler in tests; the test bootstrap fails any test that builds a real Guzzle handler.
- Running `vendor/bin/phpunit` directly is allowed; one test then skips with a message, and the
  stream guard still applies.
- The Composer gate scripts are gate configuration, like hooks and CI: changes need the owner's
  OK (GR-006).

## Consequences

- Contributors run tests through `make` or `composer`.
- Raw sockets (`tcp://`, `ssl://`, `fsockopen`) stay uncovered; listed in GUARDRAILS known limits,
  with review as the backstop.

## Amendment (2026-10-22): the test server and the Node harness

Recorded from P1-07 (ADR 0022).

- `tests/Support/BuiltInServer` is the sanctioned way for a test to run `php -S`: it always passes
  the gate flags (`allow_url_fopen=0`, `disable_functions=curl_exec,curl_multi_exec`), and a `-d`
  that would change one is refused.
- The widget harness runs in Node (`api/tests/js/widget.test.js`, owner decision). The layers above
  don't apply to the Node process; the harness uses only Node's `fs` and `vm`, with no packages
  and no network module, and review is the backstop.
