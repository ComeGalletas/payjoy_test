<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use ArrayObject;
use FilesystemIterator;
use FxInstallments\App;
use FxInstallments\Http\HealthEndpoint;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Kernel;
use FxInstallments\Http\ReportsEndpoint;
use FxInstallments\Http\Request;
use FxInstallments\Http\Response;
use FxInstallments\Http\TextResponse;
use FxInstallments\Http\WidgetEndpoint;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Tests\Support\BuiltInServer;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use FxInstallments\Tests\Support\TestRoutes;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * P1-07 acceptance (docs/PLAN.md §10), on the production route table through App::kernel()
 * and, for the headers PHP itself adds, through `php -S` over a socket:
 * - `GET /bot/widget` has a strict Content-Security-Policy (nothing by default, only this
 *   origin's files, no inline script or style, Trusted Types with no policy,
 *   `frame-ancestors 'self'` plus `cors.allowed_origins`) and `X-Content-Type-Options: nosniff`;
 *   the script, style sheet and icon have their types and nosniff; `/favicon.ico` is a 204;
 * - repeated page views from one IP never answer a 4xx and never block it (owner decision
 *   2026-10-21: the favicon 404 counted toward `block.error_responses`);
 * - INV-04: on the production route table only the widget routes (and, from P1-08, the CSV
 *   report) answer without the envelope;
 * - the page loads only its own files and has no inline script, style or event handler;
 * - no file in `api/resources/widget/` names an HTML sink, a code-from-string API or a
 *   number-formatting API (INV-01, the widget half; FORBIDDEN lists each name and why), and the
 *   scanner is shown to catch every one of them;
 * - the widget's behaviour, by the Node harness `api/tests/js/widget.test.js` (lazy open, input
 *   locked in flight, empty reply skipped, no HTML sink, buttons sent once, session id reused,
 *   restart opens a fresh session...); skipped without Node, except on CI;
 * - the conversation the widget runs, through the kernel: `{"text": ""}` opens it, a `<script>`
 *   typed at the amount step only gets the clarify text back, buttons come only with a step that
 *   has them, and nothing reaches upstream before the result step;
 * - the errors it shows verbatim are envelopes (BAD_REQUEST, 429 with CORS headers, the
 *   same-origin 403), and a cross-origin blocked request has no CORS headers;
 * - invalid `cors.allowed_origins` entries are dropped with one error-log line;
 * - no response carries `X-Powered-By`, even with `expose_php` on;
 * - the admin `Authorization: Bearer` parse is strict (no trailing newline or whitespace).
 *
 * The by-hand check (a `<script>` typed into the chat shows as text) is the lead's, in a browser.
 */
final class P1_07_WidgetTest extends TestCase
{
    use OverridesEnvironment;

    private const CLIENT = '203.0.113.7';

    private const POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; require-trusted-types-for 'script'; trusted-types 'none'";

    private const GREETING = "Hi! Tell me what you paid in USD and I'll show your monthly installment in your local currency.\nWhat was the total price in USD?";

    private const CLARIFY_AMOUNT = "I couldn't read that amount. Please type it like 200, 200.50 or 1.000,50.";

    private const ASK_CURRENCY = 'Which currency or country should I convert to?';

    private const SCRIPT_TAG = '<script>alert("x")</script>';

    private const HARNESS = __DIR__ . '/../js/widget.test.js';

    /** The harness's check count after round 3; fewer means checks were deleted. */
    private const HARNESS_MIN_CHECKS = 61;

    private const HARNESS_TIMEOUT_SECONDS = 30;

    /**
     * Patterns no widget file may contain, with the reason. Matched on the whole text, comments
     * included: JavaScript comments can't be stripped reliably with a pattern (`//` in strings
     * and URLs, regular expression literals), so the widget files simply never mention these.
     * Case-sensitive (JavaScript names are), except `javascript:` URLs.
     *
     * Frozen after P1-07 round 3: this list is a tripwire for review, not the control. The
     * controls are Trusted Types in the page CSP (no HTML or script sink can run) and the Node
     * harness's DOM proxy (any sink or unexpected property set fails it). Don't grow the list to
     * chase obfuscation; strengthen those instead.
     */
    private const FORBIDDEN = [
        // Output safety (§3): every text goes in through textContent, never parsed as HTML.
        'innerHTML' => '/innerHTML/',
        'outerHTML' => '/outerHTML/',
        'insertAdjacentHTML' => '/insertAdjacentHTML/',
        'document.write' => '/document\s*\.\s*write/', // writeln too
        'createContextualFragment' => '/createContextualFragment/',
        'DOMParser' => '/DOMParser/',
        'setHTML' => '/setHTML/', // setHTMLUnsafe too
        'srcdoc' => '/srcdoc/',
        'javascript:' => '/javascript\s*:/i',
        // An attribute that runs code, styles inline or loads a URL; or one whose name is computed.
        'setAttribute(on|style|src|href|srcdoc)' => '/setAttribute\s*\(\s*[\'"`]\s*(?:on|style|src|href|srcdoc)/i',
        'setAttribute(computed name)' => '/setAttribute\s*\(\s*[^\'"`\s]/',
        // Code from strings, and ways around the name checks.
        'eval(' => '/\beval\s*\(/',
        'Function(' => '/\bFunction\s*\(/', // with or without `new`
        'new Function' => '/\bnew\s+Function\b/',
        'Reflect' => '/\bReflect\b/',
        'setTimeout/setInterval(string)' => '/\bset(?:Timeout|Interval)\s*\(\s*[\'"`]/',
        // A backslash-u escape: `inner` + backslash-u-0048 + `TML` in an identifier still names
        // innerHTML. Matches a backslash followed by u.
        'unicode escape' => '/\\\\u/',
        // Ways to set a property whose name the patterns above can't see.
        'Object.assign/defineProperty' => '/\bObject\s*\.\s*assign\b|\bdefinePropert(?:y|ies)\b|__define[GS]etter__/',
        '.call(/.apply(' => '/\.\s*(?:call|apply)\s*\(/',
        // Computed object-literal keys (`{['inner' + 'HTML']: s}`), in JavaScript.
        'computed object key' => '/[{,]\s*\[[^\]]*\]\s*:/',
        // Computed member access (`node['inner' + 'HTML']`, `list[0]`) in JavaScript, after a
        // name, `)` or `]`; an array literal after `return` and similar keywords, and
        // destructuring after `var`, `let` or `const`, are allowed. JSDoc types (`string[]`) are
        // not exempted: a `/**` inside a string or regular expression would hide code, and the
        // widget has no JSDoc.
        'computed member access' => '/(?:[)\]]|\b(?!(?:return|typeof|in|of|case|else|void|await|yield|throw|var|let|const)\b)[A-Za-z_$][\w$]*)\s*\[/',
        // INV-01: the widget shows `display` strings verbatim and never rounds or formats a number.
        'toFixed' => '/toFixed/',
        'toPrecision' => '/toPrecision/',
        'toLocaleString' => '/toLocale[A-Za-z]*String/', // toLocaleDateString etc. too
        'Intl.NumberFormat' => '/\bIntl\b/', // any Intl formatter
        'Math' => '/\bMath\b/', // Math.round, floor, ceil, trunc...
        'parseFloat' => '/parseFloat/',
    ];

    /** Checked in `.js` files only: CSS and HTML use `[` in selectors and attributes. */
    private const JS_ONLY = ['computed object key', 'computed member access'];

    private string $path;

    private PDO $pdo;

    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(MockUpstream::LAST_UPDATE + 3600);
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path), 'the test database was released and removed');
    }

    public function testTheWidgetPageHasTheCspAndNosniffHeaders(): void
    {
        $response = $this->get($this->app(), WidgetEndpoint::PAGE_PATH);

        self::assertSame(200, $response->status());
        self::assertSame([
            'content-type' => 'text/html; charset=utf-8',
            'x-content-type-options' => 'nosniff',
            'cache-control' => 'no-cache',
            'referrer-policy' => 'no-referrer',
            'content-security-policy' => self::POLICY,
        ], $response->headers());
        self::assertSame(self::widgetFile('widget.html'), $response->body());

        // A page view is a request-log row, not an error.
        self::assertSame([['path' => '/bot/widget', 'status' => 200]], $this->rows('SELECT path, status FROM requests'));
        self::assertSame([], $this->rows('SELECT event_type FROM events'));
    }

    public function testTheScriptStyleSheetAndIconHaveTheirTypesAndNosniff(): void
    {
        $app = $this->app();
        $files = [
            [WidgetEndpoint::SCRIPT_PATH, 'widget.js', 'text/javascript; charset=utf-8'],
            [WidgetEndpoint::STYLE_PATH, 'widget.css', 'text/css; charset=utf-8'],
            [WidgetEndpoint::ICON_PATH, 'widget.svg', 'image/svg+xml'],
        ];
        foreach ($files as [$path, $file, $type]) {
            $response = $this->get($app, $path);

            self::assertSame(200, $response->status(), $path);
            self::assertSame($type, $response->header('Content-Type'), $path);
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'), $path);
            self::assertSame(self::widgetFile($file), $response->body(), $path);
        }
        self::assertSame("default-src 'none'", $this->get($app, WidgetEndpoint::ICON_PATH)->header('Content-Security-Policy'));
        self::assertStringNotContainsString('<script', self::widgetFile('widget.svg'));

        $favicon = $this->get($app, WidgetEndpoint::FAVICON_PATH);
        self::assertSame(204, $favicon->status());
        self::assertSame('', $favicon->body());
        self::assertSame('nosniff', $favicon->header('X-Content-Type-Options'));
        self::assertNull($favicon->header('Content-Type'));
    }

    /**
     * Owner decision 2026-10-21: page views from one IP (a shared office or mobile network) never
     * add up to a block. Each view is the page, its script, style sheet and icon, and the
     * `/favicon.ico` a browser asks for on any top-level page.
     */
    public function testRepeatedPageViewsFromOneIpNeverBlockIt(): void
    {
        $kernel = $this->app(['block.error_responses' => 10])->kernel();
        $view = [WidgetEndpoint::PAGE_PATH, WidgetEndpoint::SCRIPT_PATH, WidgetEndpoint::STYLE_PATH, WidgetEndpoint::ICON_PATH, WidgetEndpoint::FAVICON_PATH];

        for ($i = 0; $i < 40; $i++) {
            foreach ($view as $path) {
                $status = $kernel->handle(new Request('GET', $path, remoteAddr: self::CLIENT))->status();
                self::assertContains($status, [200, 204], "view {$i}, {$path}");
            }
        }

        self::assertSame([['count' => 200, 'worst' => 204]], $this->rows("SELECT COUNT(*) AS count, MAX(status) AS worst FROM requests WHERE ip = '" . self::CLIENT . "'"));
        self::assertSame([], $this->rows('SELECT ip FROM ip_blocks'));

        // Control: the threshold is live, so 404s from another IP do block it.
        for ($i = 0; $i < 11; $i++) {
            $kernel->handle(new Request('GET', '/favicon.png', remoteAddr: '203.0.113.8'));
        }
        self::assertSame([['ip' => '203.0.113.8']], $this->rows('SELECT ip FROM ip_blocks'));
    }

    /**
     * INV-04 over the production route table: every route answers the JSON envelope except the
     * widget's files and the favicon 204, and from P1-08 `GET /reports?format=csv`; that
     * exception list is exactly WidgetEndpoint's plus the CSV report. The admin routes are
     * tried with and without the token, and `/reports` with a bad format.
     */
    public function testOnlyTheWidgetRoutesAndTheCsvReportAnswerWithoutTheEnvelope(): void
    {
        $this->setEnvironment('ADMIN_TOKEN', 'the-real-admin-token');
        $app = $this->app(upstream: new MockUpstream());
        $routes = $app->router()->routes();
        self::assertSame(
            [['GET', '/convert'], ['POST', '/bot/message'], ['GET', '/bot/widget'], ['GET', '/bot/widget.js'], ['GET', '/bot/widget.css'], ['GET', '/bot/widget.svg'], ['GET', '/favicon.ico'],
                ['GET', '/reports'], ['GET', '/health']],
            $routes,
            'a new route must be added here, with its envelope status',
        );

        $kernel = $app->kernel();
        $nonEnvelope = [];
        $requests = [];
        foreach ($routes as [$method, $path]) {
            $requests[] = new Request($method, $path, [], ['Content-Type' => 'application/json'], '{}', self::CLIENT);
        }
        $admin = ['Authorization' => 'Bearer the-real-admin-token'];
        foreach ([[], ['format' => 'json'], ['format' => 'csv'], ['format' => 'xml'], ['from' => '2026-13-01']] as $query) {
            $requests[] = new Request('GET', ReportsEndpoint::PATH, $query, $admin, remoteAddr: self::CLIENT);
        }
        $requests[] = new Request('GET', ReportsEndpoint::PATH, ['format' => 'csv'], remoteAddr: self::CLIENT);
        $requests[] = new Request('GET', HealthEndpoint::PATH, [], $admin, remoteAddr: self::CLIENT);
        $requests[] = new Request('GET', HealthEndpoint::PATH, [], ['Authorization' => 'Bearer wrong'], remoteAddr: self::CLIENT);
        $requests[] = new Request('GET', '/no/such/route', remoteAddr: self::CLIENT);
        $requests[] = new Request('OPTIONS', '/bot/message', [], ['Origin' => 'https://shop.example', 'Access-Control-Request-Method' => 'POST'], remoteAddr: self::CLIENT);

        foreach ($requests as $request) {
            $response = $kernel->handle($request);
            $label = $request->method() . ' ' . $request->path() . ' ' . json_encode($request->query(), JSON_THROW_ON_ERROR);
            if (!$response instanceof JsonResponse) {
                $nonEnvelope[] = $request->path();
                self::assertNotSame('application/json', $response->header('Content-Type'), $label);
                continue;
            }
            self::assertSame('application/json', $response->header('Content-Type'), $label);
            $envelope = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsBool($envelope['ok'] ?? null, $label);
            self::assertSame($envelope['ok'] ? ['ok', 'data'] : ['ok', 'error'], array_keys($envelope), $label);
        }
        self::assertSame([...WidgetEndpoint::paths(), ReportsEndpoint::PATH], $nonEnvelope);

        $csv = $kernel->handle(new Request('GET', ReportsEndpoint::PATH, ['format' => 'csv'], $admin, remoteAddr: self::CLIENT));
        self::assertInstanceOf(TextResponse::class, $csv);
        self::assertSame('text/csv; charset=utf-8', $csv->header('Content-Type'));
        self::assertSame('nosniff', $csv->header('X-Content-Type-Options'));
    }

    public function testTheAllowedOriginsMayFrameThePageAndCallTheBot(): void
    {
        $log = new ArrayObject();
        $app = TestEnvironment::app($this->clock, ['cors.allowed_origins' => [
            'https://shop.example',
            "https://bad.example; script-src 'unsafe-inline'",
            'HTTPS://SHOP.EXAMPLE',
            'https://shop.example/',
        ]], $this->pdo, $log);

        self::assertSame(
            str_replace("frame-ancestors 'self'", "frame-ancestors 'self' https://shop.example", self::POLICY),
            $this->get($app, WidgetEndpoint::PAGE_PATH)->header('Content-Security-Policy'),
        );

        // The same origin passes the CORS preflight a <script> embed needs for its JSON POST.
        $preflight = $app->kernel()->handle(new Request('OPTIONS', '/bot/message', [], [
            'Origin' => 'https://shop.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ], remoteAddr: self::CLIENT));
        self::assertSame('https://shop.example', $preflight->header('Access-Control-Allow-Origin'));
        self::assertSame('Content-Type', $preflight->header('Access-Control-Allow-Headers'));

        // Dropped loudly: one line for the app, with the count and not the values.
        self::assertSame(
            ['fx-installments config: cors.allowed_origins dropped 3 invalid entries (each must be a lowercase http(s)://host[:port] with no path)'],
            $log->getArrayCopy(),
        );
    }

    public function testThePageLoadsOnlyItsOwnFilesWithNoInlineScriptOrStyle(): void
    {
        $html = self::widgetFile('widget.html');

        preg_match_all('~<script\b([^>]*)>(.*?)</script\s*>~is', $html, $scripts, PREG_SET_ORDER);
        self::assertCount(1, $scripts);
        self::assertSame(1, preg_match_all('~<script\b~i', $html), 'every script tag is closed and counted');
        self::assertMatchesRegularExpression('~\bsrc="widget\.js"~', $scripts[0][1]);
        self::assertSame('', $scripts[0][2], 'no inline script');

        self::assertSame(0, preg_match('~<style\b~i', $html), 'no style element');
        self::assertSame(0, preg_match('~\sstyle\s*=~i', $html), 'no style attribute');
        self::assertSame(0, preg_match('~\son[a-z]+\s*=~i', $html), 'no event-handler attribute');

        preg_match_all('~\s(?:src|href)\s*=\s*"([^"]*)"~i', $html, $urls);
        self::assertSame(['widget.svg', 'widget.css', 'widget.js'], $urls[1], 'only the widget files, by relative URL');
        self::assertMatchesRegularExpression('~<link rel="icon" href="widget\.svg" type="image/svg\+xml">~', $html);
        self::assertMatchesRegularExpression('~\.fxw-msg \{[^}]*white-space: pre-line;~', self::widgetFile('widget.css'), 'line breaks in replies are kept');
    }

    public function testNoWidgetFileNamesAForbiddenApi(): void
    {
        $files = self::scanDirectory(App::WIDGET_DIR);

        self::assertSame(
            ['widget.css' => [], 'widget.html' => [], 'widget.js' => [], 'widget.svg' => []],
            $files,
            'the files scanned, and what each names',
        );
    }

    public function testTheScannerCatchesEveryForbiddenName(): void
    {
        $directory = TestEnvironment::tempDir('widget-scan');
        $samples = [
            'innerHTML' => 'box.innerHTML = reply;',
            'outerHTML' => 'box.outerHTML = reply;',
            'insertAdjacentHTML' => "box.insertAdjacentHTML('beforeend', reply);",
            'document.write' => 'document.writeln(reply);',
            'createContextualFragment' => 'range.createContextualFragment(reply);',
            'DOMParser' => 'new DOMParser().parseFromString(reply, "text/html");',
            'setHTML' => 'box.setHTMLUnsafe(reply);',
            'srcdoc' => 'frame.srcdoc = reply;',
            'javascript:' => 'link.href = "JavaScript :alert(1)";',
            'setAttribute(on|style|src|href|srcdoc)' => "box.setAttribute( 'onClick', reply);",
            'setAttribute(computed name)' => 'box.setAttribute(name, reply);',
            'eval(' => 'eval (reply);',
            'Function(' => "Function('return 1')();",
            'new Function' => 'const make = new Function;',
            'Reflect' => "Reflect.set(box, 'inner' + 'HTML', reply);",
            'setTimeout/setInterval(string)' => "setInterval('tick()', 10);",
            'unicode escape' => 'box.inner' . chr(92) . 'u0048TML = reply;', // chr(92) is a backslash
            'Object.assign/defineProperty' => 'Object.assign(box, props);',
            '.call(/.apply(' => 'setter.call(box, reply);',
            'computed object key' => "var props = { ['inner' + 'HTML']: reply };",
            'computed member access' => "box ['inner' + 'HTML'] = reply;",
            'toFixed' => 'total.toFixed(2);',
            'toPrecision' => 'total.toPrecision(4);',
            'toLocaleString' => "total.toLocaleString('en-US');",
            'Intl.NumberFormat' => "new Intl.NumberFormat('en-US').format(total);",
            'Math' => 'const m = Math; m.round(total);',
            'parseFloat' => 'parseFloat(reply);',
        ];
        self::assertSame(array_keys(self::FORBIDDEN), array_keys($samples), 'one sample per forbidden pattern');
        $names = [];
        foreach ($samples as $name => $code) {
            $file = 'sample-' . count($names) . '.js';
            $names[$file] = $name;
            file_put_contents($directory . '/' . $file, "// fine\n{$code}\n");
        }
        file_put_contents($directory . '/comment.css', "/* never set innerHTML, even in a comment */\n.fxw [hidden], .a, [b]:focus { display: none; }\n");
        file_put_contents($directory . '/clean.js', implode("\n", [
            'node.textContent = reply; // retrieval(x) is not eval',
            "node.setAttribute('aria-label', label);",
            'setTimeout(function () { done(); }, 10);',
            'function make() { return [1, 2]; }',
            "var link = document.querySelector('link.fxw-stylesheet');",
            'var mathematics = 1;',
            'const [first, second] = pair;',
            'let [head] = list;',
            'handlers.callback(reply);',
            '',
        ]));

        $found = self::scanDirectory($directory);

        foreach ($names as $file => $name) {
            self::assertSame([$name], $found[$file], "the {$name} sample is caught, and only by its own pattern");
        }
        self::assertSame(['innerHTML'], $found['comment.css'], 'comments are scanned; brackets in CSS are fine');
        self::assertSame([], $found['clean.js']);
    }

    /**
     * The widget's behaviour (owner decision 2026-10-21): the Node harness drives widget.js in a
     * fake DOM. Skipped when `node` isn't installed, except on CI (GitHub Actions sets `CI`).
     */
    public function testTheWidgetBehaviourHarnessPasses(): void
    {
        if (!self::nodeIsAvailable()) {
            $ci = (string) getenv('CI');
            if ($ci !== '' && $ci !== '0' && strtolower($ci) !== 'false') {
                self::fail('node is required on CI to run api/tests/js/widget.test.js');
            }
            self::markTestSkipped('node is not installed; the widget harness runs on CI');
        }

        [$exit, $output] = self::runCommand(['node', self::HARNESS, App::WIDGET_DIR . '/widget.js']);

        self::assertSame(0, $exit, $output);
        self::assertSame(1, preg_match('/^0 failed of (\d+) *$/m', $output, $summary), $output);
        self::assertGreaterThanOrEqual(self::HARNESS_MIN_CHECKS, (int) $summary[1], 'harness checks were removed');
        self::assertSame(0, substr_count($output, 'FAIL '));
        self::assertSame((int) $summary[1], substr_count($output, 'PASS '), 'every check reported a result');
    }

    public function testAWidgetSessionOpensWithAnEmptyTextAndAScriptTagIsOnlyText(): void
    {
        $upstream = new MockUpstream();
        $kernel = $this->app(upstream: $upstream)->kernel();

        $opened = $this->post($kernel, ['text' => '']);
        self::assertSame(200, $opened->status());
        $data = self::data($opened);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $data['session_id']);
        self::assertSame(['session_id' => $data['session_id'], 'reply' => self::GREETING, 'step' => 'amount', 'done' => false], $data, 'no buttons at the amount step');

        $script = $this->post($kernel, ['session_id' => $data['session_id'], 'text' => self::SCRIPT_TAG]);
        self::assertSame(
            ['session_id' => $data['session_id'], 'reply' => self::CLARIFY_AMOUNT, 'step' => 'amount', 'done' => false],
            self::data($script),
        );
        self::assertStringNotContainsString('<', $script->body(), 'the typed tag is not echoed back');

        $amount = $this->post($kernel, ['session_id' => $data['session_id'], 'text' => '200']);
        self::assertSame(
            ['session_id' => $data['session_id'], 'reply' => self::ASK_CURRENCY, 'buttons' => ['BRL', 'COP', 'MXN', 'EUR'], 'step' => 'currency', 'done' => false],
            self::data($amount),
        );

        self::assertSame(0, $upstream->calls(), 'nothing reaches upstream before the result step');
        self::assertSame(
            [['channel' => 'static']],
            $this->rows("SELECT DISTINCT channel FROM events WHERE source = 'bot'"),
            'widget sessions are static',
        );
    }

    public function testTheErrorsTheWidgetShowsVerbatimAreEnvelopes(): void
    {
        $app = $this->app(['ratelimit.per_minute' => 2, 'cors.allowed_origins' => ['https://shop.example']]);
        $kernel = $app->kernel();
        $origin = ['Origin' => 'https://shop.example'];

        $badRequest = $kernel->handle(new Request('POST', '/bot/message', [], ['Content-Type' => 'text/plain'], '{"text": ""}', self::CLIENT));
        self::assertSame(400, $badRequest->status());
        self::assertSame(self::envelope(ErrorCodes::BAD_REQUEST), json_decode($badRequest->body(), true, flags: JSON_THROW_ON_ERROR));

        $this->post($kernel, ['text' => ''], $origin);
        $limited = $this->post($kernel, ['text' => ''], $origin);
        self::assertSame(429, $limited->status());
        self::assertSame(self::envelope(ErrorCodes::RATE_LIMITED), json_decode($limited->body(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('https://shop.example', $limited->header('Access-Control-Allow-Origin'), 'a 429 is readable cross-origin');

        $app->ipBlocks()->blockManually(self::CLIENT, 'test', ($this->clock)(), null);
        $sameOrigin = $this->post($kernel, ['text' => '']);
        self::assertSame(403, $sameOrigin->status());
        self::assertSame(self::envelope(ErrorCodes::IP_BLOCKED), json_decode($sameOrigin->body(), true, flags: JSON_THROW_ON_ERROR));

        // From another origin the 403 has no CORS headers, so fetch fails: the widget then shows
        // its generic text instead.
        $crossOrigin = $this->post($kernel, ['text' => ''], $origin);
        self::assertSame(403, $crossOrigin->status());
        self::assertNull($crossOrigin->header('Access-Control-Allow-Origin'));
    }

    public function testTheBearerParseIsStrict(): void
    {
        $this->setEnvironment('ADMIN_TOKEN', 'the-real-admin-token');
        $app = $this->app();
        $kernel = $app->kernel(TestRoutes::router($app));
        $admin = static fn (string $header): Response => $kernel->handle(new Request('GET', TestRoutes::ADMIN, [], ['Authorization' => $header]));

        self::assertSame(200, $admin('Bearer the-real-admin-token')->status());
        foreach (["\n", "\r\n", ' ', "\t", "\0"] as $suffix) {
            self::assertSame(401, $admin('Bearer the-real-admin-token' . $suffix)->status(), json_encode($suffix, JSON_THROW_ON_ERROR));
        }
        self::assertSame(401, $admin("Bearer\tthe-real-admin-token")->status());
    }

    public function testTheTestServerAlwaysKeepsTheGateFlags(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allow_url_fopen=0');
        BuiltInServer::start($this->path, ['allow_url_fopen' => '1']);
    }

    public function testNoResponseCarriesXPoweredByEvenWithExposePhpOn(): void
    {
        // Control: with expose_php on, PHP's built-in server adds the header to a bare script.
        $control = TestEnvironment::tempDir('e2e-control') . '/bare.php';
        file_put_contents($control, "<?php\necho 'bare';\n");
        $bare = BuiltInServer::start($this->path, ['expose_php' => '1'], $control);
        [, $bareHeaders] = $bare->request('GET', '/');
        $bare->stop();
        self::assertArrayHasKey('x-powered-by', $bareHeaders, 'the control server sends X-Powered-By');

        $server = BuiltInServer::start($this->path, ['expose_php' => '1']);
        $responses = [
            'page' => $server->request('GET', WidgetEndpoint::PAGE_PATH),
            'script' => $server->request('GET', WidgetEndpoint::SCRIPT_PATH),
            'style sheet' => $server->request('GET', WidgetEndpoint::STYLE_PATH),
            'icon' => $server->request('GET', WidgetEndpoint::ICON_PATH),
            'favicon' => $server->request('GET', WidgetEndpoint::FAVICON_PATH),
            'not found' => $server->request('GET', '/no/such/route'),
            'bot message' => $server->request('POST', '/bot/message', ['Content-Type' => 'application/json'], '{"text": ""}'),
        ];
        foreach ($responses as $name => [$status, $headers]) {
            self::assertArrayNotHasKey('x-powered-by', $headers, $name);
            self::assertSame(['nosniff'], $headers['x-content-type-options'] ?? null, $name);
        }

        [$pageStatus, $pageHeaders, $pageBody] = $responses['page'];
        self::assertSame(200, $pageStatus);
        self::assertSame(['text/html; charset=utf-8'], $pageHeaders['content-type']);
        self::assertSame([self::POLICY], $pageHeaders['content-security-policy']);
        self::assertSame(self::widgetFile('widget.html'), $pageBody);
        self::assertSame(['text/javascript; charset=utf-8'], $responses['script'][1]['content-type']);
        self::assertSame(['text/css; charset=utf-8'], $responses['style sheet'][1]['content-type']);
        self::assertSame(['image/svg+xml'], $responses['icon'][1]['content-type']);
        self::assertSame(204, $responses['favicon'][0]);
        self::assertSame('', $responses['favicon'][2]);
        self::assertArrayNotHasKey('content-type', $responses['favicon'][1], 'PHP adds no default type to the 204');
        self::assertSame(404, $responses['not found'][0]);

        // The widget's conversation over the socket, with no upstream configured at all.
        [$openStatus, , $openBody] = $responses['bot message'];
        self::assertSame(200, $openStatus);
        $session = json_decode($openBody, true, flags: JSON_THROW_ON_ERROR)['data']['session_id'];
        [, , $scriptBody] = $server->request('POST', '/bot/message', ['Content-Type' => 'application/json'], json_encode(['session_id' => $session, 'text' => self::SCRIPT_TAG], JSON_THROW_ON_ERROR));
        self::assertSame(self::CLARIFY_AMOUNT, json_decode($scriptBody, true, flags: JSON_THROW_ON_ERROR)['data']['reply']);
        $server->stop();

        // The fallback before routing (a database behind the code) has no X-Powered-By either.
        $behind = BuiltInServer::start(TestEnvironment::tempDir('e2e-db') . '/behind.db', ['expose_php' => '1']);
        [$behindStatus, $behindHeaders] = $behind->request('GET', WidgetEndpoint::PAGE_PATH);
        $behind->stop();
        self::assertSame(500, $behindStatus);
        self::assertArrayNotHasKey('x-powered-by', $behindHeaders);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function app(array $config = [], ?MockUpstream $upstream = null): App
    {
        return TestEnvironment::app($this->clock, $config, $this->pdo, upstream: $upstream);
    }

    private function get(App $app, string $path): Response
    {
        return $app->kernel()->handle(new Request('GET', $path, remoteAddr: self::CLIENT));
    }

    /**
     * A message as the widget sends it: a JSON body with `Content-Type: application/json`.
     *
     * @param array<string, string> $message
     * @param array<string, string> $headers
     */
    private function post(Kernel $kernel, array $message, array $headers = []): Response
    {
        return $kernel->handle(new Request(
            'POST',
            '/bot/message',
            [],
            ['Content-Type' => 'application/json'] + $headers,
            json_encode($message, JSON_THROW_ON_ERROR),
            self::CLIENT,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(Response $response): array
    {
        $envelope = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($envelope['ok']);

        return $envelope['data'];
    }

    /**
     * @return array{ok: false, error: array{code: string, message_for_user: string|null}}
     */
    private static function envelope(string $code): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message_for_user' => ErrorCodes::messageForUser($code)]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value, $row),
            $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private static function widgetFile(string $name): string
    {
        $contents = file_get_contents(App::WIDGET_DIR . '/' . $name);
        self::assertIsString($contents, $name);

        return $contents;
    }

    /**
     * Every file under $directory (relative path, sorted) => the forbidden patterns it contains.
     *
     * @return array<string, list<string>>
     */
    private static function scanDirectory(string $directory): array
    {
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            $isJs = $file->getExtension() === 'js';
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $found[$relative] = [];
            foreach (self::FORBIDDEN as $name => $pattern) {
                if (($isJs || !in_array($name, self::JS_ONLY, true)) && preg_match($pattern, $text) === 1) {
                    $found[$relative][] = $name;
                }
            }
        }
        ksort($found);

        return $found;
    }

    private static function nodeIsAvailable(): bool
    {
        [$exit, $output] = self::runCommand(['node', '--version']);

        return $exit === 0 && preg_match('/^v\d+\./', $output) === 1;
    }

    /**
     * Runs a command without a shell. Returns [exit code, stdout and stderr]; exit -1 when it
     * couldn't be started. A command still running after $timeoutSeconds is terminated and
     * the test fails.
     *
     * @param list<string> $command
     * @return array{int, string}
     */
    private static function runCommand(array $command, float $timeoutSeconds = self::HARNESS_TIMEOUT_SECONDS): array
    {
        $output = TestEnvironment::tempDir('node') . '/output.txt';
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']], $pipes);
        if (!is_resource($process)) {
            return [-1, ''];
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + $timeoutSeconds;
        while (($status = proc_get_status($process))['running']) {
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                proc_close($process);
                self::fail(sprintf('%s did not finish within %d s: %s', implode(' ', $command), $timeoutSeconds, (string) file_get_contents($output)));
            }
            usleep(20_000);
        }
        proc_close($process);

        return [(int) $status['exitcode'], (string) file_get_contents($output)];
    }
}
