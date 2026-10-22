# 0022 — Built-in widget: serving, embedding and non-JSON responses

- **Status:** Accepted (amended 2026-10-22: the CSV report, see "Amendment")
- **Date:** 2026-10-22
- **Deciders:** owner
- **Relates to:** 0016 (blocking), 0018 (tests off the network), 0021 (bot message contract)

## Context

P1-07 (PR #21, three rounds) built the built-in chat widget at `GET /bot/widget`, the first
response this API serves that isn't a JSON envelope, plus the P1-01 leftovers (no `X-Powered-By`,
a strict `Authorization: Bearer` parse). PLAN §3 and §7 fix the widget's purpose and the CSP,
`nosniff` and `textContent` rules; how the files are served, embedded and tested was open. The
builder chose these and listed them; the tester and judge reviewed them.

The round-1 tester found that a browser's automatic `/favicon.ico` request was a 404 that counts
toward `block.error_responses`, so about 100 page views from one shared IP blocked it for an hour.
The round-1 judge found that the widget routes have no rate limit, that nothing checked INV-04 now
that a second response type exists, and gaps in the forbidden-name list. The owner then decided
items 1, 6 and 11 below. The co-leads' round-2 brief, from the judge's findings and nits,
required the INV-04 route-table test, the stricter CSP, a wider scan, the origin validation, the
accessibility fixes and the test-server flags (items 2, 3, 4, 9, 10 and 13). After the round-2
reviews the owner asked for a short round 3 and chose the start button's wording (item 8); the
co-leads' round-3 brief hardened the harness run and its checks (item 11) and the scan (item 10).
How each is built, and everything else below, is the builder's design, which the tester and judge
accepted.

## Decision

1. **Serving (owner decision for the favicon and the limit).** The widget lives in
   `api/resources/widget/` (`widget.html`, `widget.js`, `widget.css`, `widget.svg`), read at
   request time and served at fixed `GET` routes: `/bot/widget`, `/bot/widget.js`,
   `/bot/widget.css` and `/bot/widget.svg`, with `X-Content-Type-Options: nosniff`,
   `Cache-Control: no-cache` and `Referrer-Policy: no-referrer`. The page links the SVG as its
   icon, and `GET /favicon.ico` answers **204** with no body and no content type
   (`Cache-Control: max-age=86400`), so a browser's icon request never counts toward blocking.
   These routes have **no rate limiter**: a known limit until P3-01, when nginx serves or limits
   static files. An acceptance test replays 40 page views (page, script, style sheet, icon,
   `/favicon.ico`) from one IP under a low `block.error_responses` and checks there is no block,
   while a control IP sending real 404s is blocked.
2. **Non-JSON responses.** A `Response` interface is implemented by `JsonResponse` (the envelope),
   `TextResponse` (always 200, checked content type, `nosniff`) and `NoContentResponse` (204, no
   body or type); `SendsResponse` sends them and stops PHP adding its default `text/html` type.
   Errors on the widget routes are still envelopes, and the request log and blocking are unchanged
   (a 2xx never counts toward a block). **INV-04** is now checked over the production route table
   (`Router::routes()`): only the widget files and `/favicon.ico` may answer without the envelope
   (since P1-08 also the CSV report, see "Amendment"), and the test pins the route list.
3. **CSP** (from the round-1 judge, required by the co-leads): the page sends `default-src 'none';
   script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; object-src 'none';
   base-uri 'none'; form-action 'none'; frame-ancestors 'self' <allowed origins>;
   require-trusted-types-for 'script'; trusted-types 'none'`. With Trusted Types, browsers that
   support them (Chromium) refuse every HTML sink at runtime. The icon gets `default-src 'none'`;
   the script and style sheet need none. No `X-Frame-Options`.
4. **Allowed origins.** `Http/AllowedOrigins` checks `cors.allowed_origins` once per `App`: only
   the exact form a browser sends as `Origin` is kept (`http(s)://`, lowercase host or bracketed
   IPv6, an optional port that is neither the default nor zero-padded, no path or trailing slash),
   duplicates are removed, and dropped entries produce one error-log line with their **count**,
   never their values. CORS and `frame-ancestors` use the same checked list.
5. **Embedding.** The widget works as an iframe of `/bot/widget` or as
   `<script src="…/bot/widget.js" defer>` with an optional `data-fx-target`. The script derives the
   `/bot/message` endpoint and the style sheet from its own `src`, sends `credentials: 'omit'`, and
   uses `fxw-` scoped classes without Shadow DOM.
6. **Lazy open (owner decision).** Nothing is sent on page load. The session opens with
   `{"text": ""}` (ADR 0021) on the first focus of the message box or a click on "Start chat". The
   box stays usable while the session opens; a message submitted meanwhile is sent right after,
   as the session's first answer. "Start a new conversation" and "Try again" open at once.
7. **Client behaviour (ADR 0021):** every text reaches the page through `textContent`
   (`white-space: pre-line`); an empty `reply` is skipped; buttons appear only when present and
   send their text; input and buttons are locked while a request is in flight, with a 15 s
   timeout; any envelope's `message_for_user` is shown verbatim, and any other failure (network,
   timeout, CORS) gets a generic text; `done: true` shows an ended note and a restart button;
   whitespace-only input isn't sent; the box takes at most 500 characters. The widget never
   computes, rounds or formats money.
8. **Chrome wording.** Owner-chosen: "Start chat". Owner-confirmed as built: "Start a new
   conversation", "Try again", the screen-reader prefixes "You: ", "Bot: " and "Error: ", "Sorry,
   the chat can't be reached right now. Please try again later." and "This conversation has
   ended." The control labels ("Send", "Your message", "Installments chat", "Conversation",
   "Suggested answers") are the builder's. These strings live in `widget.js`; bot wording stays in
   the flow file.
9. **Accessibility:** `role="log"`, `aria-live="polite"` and `aria-busy` on the conversation,
   labelled controls, a focus outline of `#1d2026` (about 16:1 on white), visually hidden speaker
   prefixes, and focus that moves only after a user action.
10. **Widget checks.** Output safety (PLAN §3: no HTML sink) is enforced at runtime by Trusted
    Types (item 3) and in the suite by the widget harness's DOM proxy (item 11). INV-01 in the
    widget is enforced by the harness's verbatim-text checks (replies, errors and the result shown
    exactly as sent) and by a tripwire: `P1_07_WidgetTest` scans `resources/widget/` for forbidden
    names (HTML sinks, `eval`/`Function`, `Reflect`, `javascript:`, sensitive `setAttribute`
    names, string timers, any `Math`, `Intl`, `toFixed`, `toLocaleString`, `\u` escapes, computed
    keys and member access, `Object.assign`, `defineProperty`, `.call(`, `.apply(`, and others).
    The scan is case-sensitive (except the `javascript:` and `setAttribute` name patterns),
    includes comments, and its list is frozen: obfuscation is answered by strengthening the widget
    harness (and, for HTML sinks, Trusted Types), not by new patterns. Each pattern has a self-test
    sample.
11. **Widget harness in the suite (owner decision).** `api/tests/js/widget.test.js` runs in Node,
    uses no packages, only Node's `fs` and `vm`, with its own fake DOM and `fetch`. A proxy reports
    every HTML-sink or `on*` write, and any other write outside an allow-list and the fake node's
    own fields. `P1_07_WidgetTest` runs it with a plain `node` subprocess: a 30 s
    deadline, at least 61 checks each reported as passed, a skip when `node` isn't on `PATH`, and a
    failure instead of a skip when `CI` is set. The Makefile, CI and Composer scripts are
    unchanged; CI relies on the runner image's preinstalled Node.
12. **Admin token grammar:** `Authorization: Bearer` must match `^Bearer +([\x21-\x7E]+)\z`
    (case-insensitive scheme), so a trailing newline or any other whitespace is refused, and an
    `ADMIN_TOKEN` must be visible ASCII.
13. **No `X-Powered-By`:** `public/index.php` calls `header_remove('X-Powered-By')` first, since
    `expose_php` can only be set in `php.ini`. **Test server:** `tests/Support/BuiltInServer` runs
    `php -S` with ADR 0018's gate flags, which always win; a conflicting `-d` is refused.

## Consequences

- Tests check that the widget shows no unescaped text and computes no money, and every behaviour
  rule is a test: 17 widget mutations tried in review, all caught.
- A browser's icon request no longer gets shared IPs blocked.
- **Known limits:**
  - The widget routes have no rate limit until P3-01; each hit writes a `requests` row.
  - `/apple-touch-icon*.png` and `/robots.txt` still 404 and count toward `block.error_responses`
    (P3-01, or threshold tuning).
  - No ETag or Last-Modified, so each page view downloads the files again.
  - The `<script>` embed runs under the host page's CSP and CSS: Trusted Types and this CSP don't
    apply there, and review is the backstop.
  - The scan can be beaten by obfuscation outside its patterns; it is a tripwire. It also scans
    comments, so a JSDoc type such as `{string[]}` trips the computed-access rule; the widget has
    no JSDoc.
  - The `AllowedOrigins` log line repeats on every request while the config is bad (one `App` per
    request).
  - A blocked IP that loads `/bot/widget` sees the raw 403 JSON. `HEAD` is a 404, which counts
    toward `block.error_responses` (P3-01).
  - Tabbing into an embedded widget opens a session; a programmatic `focus()` inside an unfocused
    iframe may not, while a click or "Start chat" does.
  - The Node version on CI isn't pinned, and ADR 0018's network layers don't cover the Node
    process; the harness uses only `fs` and `vm`, and review is the backstop.
  - On a host with `display_errors=1`, a PHP startup warning (an oversized POST) leaks as a 200
    HTML page; INSTALL sets `display_errors=Off` and `expose_php=Off` (P1-09).

## Amendment (2026-10-22): the CSV report

Recorded from P1-08 (PR #23, ADR 0023).

- `GET /reports?format=csv` answers a `TextResponse` (`text/csv; charset=utf-8`, attachment,
  `nosniff`, `no-store`), the third non-envelope response. The INV-04 route-table test now covers
  `/reports` and `/health` and allows the CSV report as the only non-envelope answer besides the
  widget routes. Errors on `/reports`, a bad `format` included, are envelopes.
