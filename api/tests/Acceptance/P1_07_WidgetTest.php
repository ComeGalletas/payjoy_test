<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use FxInstallments\App;
use FxInstallments\Http\Request;
use FxInstallments\Http\Response;
use FxInstallments\Http\WidgetEndpoint;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Tests\Support\BuiltInServer;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use FxInstallments\Tests\Support\TestRoutes;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * P1-07 acceptance (docs/PLAN.md §10), on the production route table through App::kernel()
 * and, for the headers PHP itself adds, through `php -S` over a socket:
 * - `GET /bot/widget` has a strict Content-Security-Policy (`default-src 'self'`, no inline
 *   script or style, `frame-ancestors 'self'` plus `cors.allowed_origins`) and
 *   `X-Content-Type-Options: nosniff`; the script and style sheet have their types and nosniff;
 * - the page loads only its own two files and has no inline script, style or event handler;
 * - no file in `api/resources/widget/` names an HTML sink or a number-formatting API
 *   (INV-01, the widget half; FORBIDDEN lists each name and why), and the scanner is shown to
 *   catch every one of them;
 * - the conversation the widget runs: it opens with `{"text": ""}` as JSON, a `<script>` typed
 *   at the amount step only gets the clarify text back (as data), buttons come only with a
 *   step that has them, and nothing reaches upstream before the result step;
 * - the errors it shows verbatim are envelopes (BAD_REQUEST, 429 with CORS headers, the
 *   same-origin 403), and a cross-origin blocked request has no CORS headers;
 * - no response carries `X-Powered-By`, even with `expose_php` on;
 * - the admin `Authorization: Bearer` parse is strict (no trailing newline or whitespace).
 *
 * The by-hand check (a `<script>` typed into the chat shows as text) is the lead's, in a browser.
 */
final class P1_07_WidgetTest extends TestCase
{
    use OverridesEnvironment;

    private const CLIENT = '203.0.113.7';

    private const POLICY = "default-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

    private const GREETING = "Hi! Tell me what you paid in USD and I'll show your monthly installment in your local currency.\nWhat was the total price in USD?";

    private const CLARIFY_AMOUNT = "I couldn't read that amount. Please type it like 200, 200.50 or 1.000,50.";

    private const ASK_CURRENCY = 'Which currency or country should I convert to?';

    private const SCRIPT_TAG = '<script>alert("x")</script>';

    /**
     * Names no widget file may contain, with the reason. Matched case-sensitively (JavaScript
     * names are) on the whole text, comments included: JavaScript comments can't be stripped
     * reliably with a pattern (`//` in strings and URLs, regular expression literals), so the
     * widget files simply never mention these names.
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
        'eval(' => '/\beval\s*\(/',
        'new Function' => '/\bnew\s+Function\b/',
        // INV-01: the widget shows `display` strings verbatim and never rounds or formats a number.
        'toFixed' => '/toFixed/',
        'toPrecision' => '/toPrecision/',
        'toLocaleString' => '/toLocale[A-Za-z]*String/', // toLocaleDateString etc. too
        'Intl.NumberFormat' => '/\bIntl\b/', // any Intl formatter
        'Math.round' => '/Math\s*\.\s*round/',
        'Math.floor' => '/Math\s*\.\s*floor/',
        'Math.ceil' => '/Math\s*\.\s*ceil/',
        'Math.trunc' => '/Math\s*\.\s*trunc/',
        'parseFloat' => '/parseFloat/',
    ];

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

    public function testTheScriptAndStyleSheetHaveTheirTypesAndNosniff(): void
    {
        $app = $this->app();
        foreach ([[WidgetEndpoint::SCRIPT_PATH, 'widget.js', 'text/javascript; charset=utf-8'], [WidgetEndpoint::STYLE_PATH, 'widget.css', 'text/css; charset=utf-8']] as [$path, $file, $type]) {
            $response = $this->get($app, $path);

            self::assertSame(200, $response->status(), $path);
            self::assertSame($type, $response->header('Content-Type'), $path);
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'), $path);
            self::assertSame(self::widgetFile($file), $response->body(), $path);
        }
    }

    public function testTheAllowedOriginsMayFrameThePageAndCallTheBot(): void
    {
        $app = $this->app(['cors.allowed_origins' => ['https://shop.example', "https://bad.example; script-src 'unsafe-inline'"]]);

        self::assertSame(
            self::POLICY . ' https://shop.example',
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
        self::assertSame(0, preg_match('~javascript:~i', $html), 'no javascript: URL');

        preg_match_all('~\s(?:src|href)\s*=\s*"([^"]*)"~i', $html, $urls);
        self::assertSame(['widget.css', 'widget.js'], $urls[1], 'only the two widget files, by relative URL');
    }

    public function testNoWidgetFileNamesAnHtmlSinkOrANumberFormatter(): void
    {
        $files = self::scanDirectory(App::WIDGET_DIR);

        self::assertSame(['widget.css', 'widget.html', 'widget.js'], array_keys($files), 'the files scanned');
        self::assertSame(['widget.css' => [], 'widget.html' => [], 'widget.js' => []], $files);
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
            'eval(' => 'eval (reply);',
            'new Function' => "new Function('return 1');",
            'toFixed' => 'total.toFixed(2);',
            'toPrecision' => 'total.toPrecision(4);',
            'toLocaleString' => "total.toLocaleString('en-US');",
            'Intl.NumberFormat' => "new Intl.NumberFormat('en-US').format(total);",
            'Math.round' => 'Math.round(total * 100);',
            'Math.floor' => 'Math . floor(total);',
            'Math.ceil' => 'Math.ceil(total);',
            'Math.trunc' => 'Math.trunc(total);',
            'parseFloat' => 'parseFloat(reply);',
        ];
        self::assertSame(array_keys(self::FORBIDDEN), array_keys($samples), 'one sample per forbidden name');
        foreach ($samples as $name => $code) {
            file_put_contents($directory . '/' . str_replace(['.', '('], '_', $name) . '.js', "// fine\n{$code}\n");
        }
        file_put_contents($directory . '/comment.css', "/* never set innerHTML, even in a comment */\n");
        file_put_contents($directory . '/clean.js', "node.textContent = reply; // retrieval(x) is not eval\n");

        $found = self::scanDirectory($directory);

        foreach (array_keys($samples) as $name) {
            self::assertSame([$name], $found[str_replace(['.', '('], '_', $name) . '.js'], "the {$name} sample is caught");
        }
        self::assertSame(['innerHTML'], $found['comment.css'], 'comments are scanned too');
        self::assertSame([], $found['clean.js']);
    }

    public function testTheWidgetFollowsTheBotMessageContract(): void
    {
        $js = self::widgetFile('widget.js');

        // Opens with an empty text, as JSON (ADR 0021), and keeps the server's session id.
        self::assertStringContainsString("var body = { text: text };", $js);
        self::assertStringContainsString("headers: { 'Content-Type': 'application/json' }", $js);
        self::assertStringContainsString("submit('', byUser);", $js);
        self::assertStringContainsString('sessionId = data.session_id;', $js);
        // Text goes in through textContent only, with line breaks kept by CSS.
        self::assertStringContainsString('node.textContent = text;', $js);
        self::assertMatchesRegularExpression('~\.fxw-msg \{[^}]*white-space: pre-line;~', self::widgetFile('widget.css'));
        // The bot is reached next to the script's own URL, so an embed calls the API's origin.
        self::assertStringContainsString("new URL('message', script.src)", $js);
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
    private function post(\FxInstallments\Http\Kernel $kernel, array $message, array $headers = []): Response
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
     * Every file under $directory (relative path, sorted) => the forbidden names it contains.
     *
     * @return array<string, list<string>>
     */
    private static function scanDirectory(string $directory): array
    {
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $found[$relative] = array_keys(array_filter(
                self::FORBIDDEN,
                static fn (string $pattern): bool => preg_match($pattern, $text) === 1,
            ));
        }
        ksort($found);

        return $found;
    }
}
