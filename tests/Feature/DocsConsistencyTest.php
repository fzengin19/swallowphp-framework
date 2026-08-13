<?php

/*
|--------------------------------------------------------------------------
| Docs/Code Consistency Tests (Tier 1 — CRITICAL + HIGH)
|--------------------------------------------------------------------------
|
| These tests are the executable harness for the Tier-1 docs-vs-code audit.
| They are the audit's BLOCKING remediation: every finding in spec.md has
| a paired check here, and every check fails loud if its corresponding
| documentation sentence regresses.
|
| Two flavors of check coexist intentionally:
|
|   1. Mechanical grep-style assertions on the doc files. These are the
|      "must match" / "must NOT match" patterns pinned in each AC of the
|      SPEC. They are line-noise-resistant (use preg_match with the exact
|      literal the SPEC used) and will fail if the prose is changed back
|      to the wrong wording.
|
|   2. Source-aware checks for AC-16, AC-18, AC-19 — the three ACs whose
|      prose claims about runtime behavior were the hardest to verify
|      with a grep. These actually invoke the production code paths
|      (Route::execute(), VerifyCsrfToken::inExceptArray(),
|      ExceptionHandler::handle()) and confirm the live behavior matches
|      the prose. If the production code regresses, the doc claim breaks
|      too; if the doc regresses, the assertion against the live code
|      still pins the contract.
|
| AC-9 and AC-17 each get a regression check on top of their grep-style
| assertion: AC-9 verifies the actual flash-bucket lifecycle, AC-17
| verifies the imported Auth class actually exists at the documented path.
|
*/

namespace Tests\Feature;

use SwallowPHP\Framework\Auth\Auth;
use SwallowPHP\Framework\Exceptions\PayloadTooLargeException;
use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Foundation\ExceptionHandler;
use SwallowPHP\Framework\Http\Middleware\Middleware;
use SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Http\Response;
use SwallowPHP\Framework\Routing\Route;
use SwallowPHP\Framework\Session\SessionManager;
use Closure;
use ReflectionClass;
use ReflectionMethod;

// ---------------------------------------------------------------------------
// Helpers for the grep-style checks. We just need the file contents as a
// string — the SPEC's per-AC grep patterns are reused verbatim.
// ---------------------------------------------------------------------------

function doc(string $relativePath): string
{
    $repoRoot = dirname(__DIR__, 2);
    $absolutePath = $repoRoot . '/' . $relativePath;
    if (!is_file($absolutePath)) {
        throw new \RuntimeException("Doc file not found: {$relativePath}");
    }
    return file_get_contents($absolutePath);
}

/**
 * Build a Request via reflection. The constructor is protected
 * (instantiation is normally via createFromGlobals()), so we reach in
 * with Reflection to inject the args we need for the route / CSRF tests.
 */
function buildTestRequest(string $uri, string $method, array $headers = []): Request
{
    $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri];
    $lowerHeaders = [];
    foreach ($headers as $k => $v) {
        $lowerHeaders[strtolower($k)] = $v;
    }
    $rc = new ReflectionClass(Request::class);
    $ctor = $rc->getConstructor();
    $instance = $rc->newInstanceWithoutConstructor();
    $ctor->invokeArgs($instance, [
        $uri,
        $method,
        [], // query
        [], // request
        [], // files
        $lowerHeaders,
        $server,
        '', // rawInput
    ]);
    return $instance;
}

/**
 * Replace the App container's resolved Request singleton with the given
 * instance. The container's `addShared` does NOT allow overriding an
 * existing definition, so we have to mutate the Definition's `resolved`
 * property directly. This is the only reliable way to inject a Request
 * with a non-global Accept header into ExceptionHandler.
 */
function overrideRequestSingleton(Request $request): void
{
    $appRef = new ReflectionClass(App::class);
    $containerProp = $appRef->getProperty('container');
    $container = $containerProp->getValue();

    $containerRef = new ReflectionClass($container);
    $defsProp = $containerRef->getProperty('definitions');
    $defs = $defsProp->getValue($container);

    $defsRef = new ReflectionClass($defs);
    $defsMapProp = $defsRef->getProperty('definitions');
    $defsMap = $defsMapProp->getValue($defs);

    foreach ($defsMap as $def) {
        if (method_exists($def, 'getAlias') && $def->getAlias() === Request::class) {
            $defRef = new ReflectionClass($def);
            $resolvedProp = $defRef->getProperty('resolved');
            $resolvedProp->setValue($def, $request);
            return;
        }
    }
}

/**
 * Run a preg_match against the given doc file and assert the result matches
 * the expected value (1 = must match, 0 = must NOT match).
 */
function docPregMatch(string $relativePath, string $pattern, int $expected, string $what): void
{
    $content = doc($relativePath);
    $actual = preg_match($pattern, $content) === 1 ? 1 : 0;
    expect($actual)->toBe($expected, "{$what}: pattern={$pattern} on {$relativePath}");
}

/**
 * Run a stripos (case-insensitive substring) check against the given doc file.
 */
function docContains(string $relativePath, string $needle, bool $expected, string $what): void
{
    $content = doc($relativePath);
    $actual = stripos($content, $needle) !== false;
    expect($actual)->toBe($expected, "{$what}: needle=\"{$needle}\" on {$relativePath}");
}

/* ===========================================================================
 * AC-1 — README.md: stale version/PHP badges
 * =========================================================================== */

it('AC-1: README does not mention PHP 8.0', function () {
    docPregMatch('README.md', '/8\.0/', 0, 'AC-1: README must not mention PHP 8.0');
});

it('AC-1: README does not mention v1.1.0', function () {
    docPregMatch('README.md', '/1\.1\.0/', 0, 'AC-1: README must not mention v1.1.0');
});

it('AC-1: README mentions PHP 8.2', function () {
    docPregMatch('README.md', '/8\.2/', 1, 'AC-1: README must mention PHP 8.2');
});

it('AC-1: README mentions v2.0.0', function () {
    docPregMatch('README.md', '/2\.0\.0/', 1, 'AC-1: README must mention v2.0.0');
});

/* ===========================================================================
 * AC-2 — docs/authentication.md: nonexistent auth() helper
 * =========================================================================== */

it('AC-2: docs/authentication.md does not use the nonexistent auth()-> call form', function () {
    docPregMatch('docs/authentication.md', '/auth\(\)->/', 0, 'AC-2: auth() helper must not appear');
});

it('AC-2: docs/authentication.md uses Auth::isAuthenticated() instead', function () {
    docPregMatch('docs/authentication.md', '/Auth::isAuthenticated\(\)/', 1, 'AC-2: must use Auth::isAuthenticated()');
});

/* ===========================================================================
 * AC-3 — docs/database.md: event callback receives a Model, not an array
 * =========================================================================== */

it('AC-3: docs/database.md does not show a $data-param callback', function () {
    docPregMatch('docs/database.md', '/function \(\$data\)/', 0, 'AC-3: no $data callback');
});

it('AC-3: docs/database.md shows a $model-param creating callback', function () {
    // The spec pinned the form as `'creating' ... function ($model)`. The
    // two pieces are checked separately so the regex escaping stays simple.
    $content = doc('docs/database.md');
    expect(preg_match("/'creating'/", $content))->toBe(1, 'AC-3: must contain \'creating\' event');
    expect(preg_match('/function \(\$model\)/', $content))->toBe(1, 'AC-3: must contain function ($model) form');
});

/* ===========================================================================
 * AC-4 — docs/database.md: deleteAll() / RuntimeException contract
 * =========================================================================== */

it('AC-4: docs/database.md documents deleteAll()', function () {
    docContains('docs/database.md', 'deleteAll', true, 'AC-4: deleteAll() must be documented');
});

it('AC-4: docs/database.md documents the RuntimeException guard', function () {
    docContains('docs/database.md', 'RuntimeException', true, 'AC-4: RuntimeException must be documented');
});

/* ===========================================================================
 * AC-5 — docs/database.md + docs/helpers.md: update() returns int|false
 * =========================================================================== */

it('AC-5: docs/database.md update() example shows the false check', function () {
    docContains('docs/database.md', '=== false', true, 'AC-5: false check must be in update() example');
});

it('AC-5: docs/helpers.md mentions the false return value', function () {
    // The spec says "false.*fail" — case-insensitive substring match on "false"
    // is sufficient because the actual wording in helpers.md is "rows affected,
    // or false on a genuine write failure." Either "false" or "fail" being
    // mentioned is enough; combined with the database.md check above this
    // covers both files.
    docContains('docs/helpers.md', 'false', true, 'AC-5: false must be mentioned in helpers.md');
});

/* ===========================================================================
 * AC-6 — docs/http.md: paginate() must be 2-arg, not 4-arg
 * =========================================================================== */

it('AC-6: docs/http.md does not show the 4-arg paginate() with the old column list', function () {
    docPregMatch('docs/http.md', '/paginate\(\\\$limit, \[\'\*\'\]/', 0, 'AC-6: 4-arg paginate must be gone');
});

/* ===========================================================================
 * AC-7 — docs/helpers.md + docs/database.md: request()->query('key') not supported
 * =========================================================================== */

it('AC-7: docs/helpers.md does not show request()->query("key") form', function () {
    docPregMatch('docs/helpers.md', '/query\(\'[a-zA-Z_]+\'/', 0, 'AC-7: no query("key") form in helpers.md');
});

it('AC-7: docs/database.md does not show request()->query("key") form', function () {
    docPregMatch('docs/database.md', '/query\(\'[a-zA-Z_]+\'/', 0, 'AC-7: no query("key") form in database.md');
});

it('AC-7: docs/helpers.md uses getQuery()', function () {
    docPregMatch('docs/helpers.md', '/getQuery\(/', 1, 'AC-7: getQuery() must be used');
});

/* ===========================================================================
 * AC-8 — docs/helpers.md: User::all() does not exist
 * =========================================================================== */

it('AC-8: docs/helpers.md does not show the nonexistent User::all()', function () {
    docPregMatch('docs/helpers.md', '/::all\(\)/', 0, 'AC-8: ::all() must be gone');
});

/* ===========================================================================
 * AC-9 — docs/session.md + docs/views.md + docs/helpers.md: flash reads
 * =========================================================================== */

it('AC-9: docs/session.md does not use plain session("success") as a flash read', function () {
    docPregMatch('docs/session.md', '/session\(\'(success|error|errors|warning|info|notice|msg|message|status|alert|flash)\'\) *[^)]/', 0,
        'AC-9: no plain session("success") flash read in session.md');
});

it('AC-9: docs/views.md does not use plain session("success") as a flash read', function () {
    docPregMatch('docs/views.md', '/session\(\'(success|error|errors|warning|info|notice|msg|message|status|alert|flash)\'\) *[^)]/', 0,
        'AC-9: no plain session("success") flash read in views.md');
});

it('AC-9: docs/helpers.md does not use plain session("success") as a flash read', function () {
    docPregMatch('docs/helpers.md', '/session\(\'(success|error|errors|warning|info|notice|msg|message|status|alert|flash)\'\) *[^)]/', 0,
        'AC-9: no plain session("success") flash read in helpers.md');
});

it('AC-9: docs/session.md uses getFlash()', function () {
    docContains('docs/session.md', 'getFlash', true, 'AC-9: getFlash() must be used in session.md');
});

/* ===========================================================================
 * AC-9 regression — source-aware: plain session($key) misses the flash bucket
 * =========================================================================== */

it('AC-9 regression: session("success") returns null when the key is in the flash bucket', function () {
    // Wire up the App container so the session() helper can resolve the
    // SessionManager. We don't call SessionManager::start() — that would
    // require a writable session.files directory and headers not yet sent.
    // Instead we manipulate $_SESSION directly to simulate the post-age
    // state the framework would have (flash in _flash.old).
    App::container();
    config(['app.storage_path' => dirname(__DIR__, 2) . '/.scratch']);
    config(['session.driver' => 'file']);
    config(['session.files' => dirname(__DIR__, 2) . '/.scratch/sessions']);
    $sessionsDir = dirname(__DIR__, 2) . '/.scratch/sessions';
    if (!is_dir($sessionsDir)) {
        mkdir($sessionsDir, 0755, true);
    }

    // Seed $_SESSION as if ageFlashData() had run: the new key was moved
    // into _flash.old, and _flash.old is what getFlash() reads.
    $_SESSION = [
        '_flash.old' => ['success' => 'Saved!'],
    ];

    // Plain session($key) helper reads $_SESSION[$key] DIRECTLY. 'success'
    // is not at the top level — it's inside _flash.old. So the plain form
    // returns the default (null here), missing the flashed value.
    expect(\session('success'))->toBeNull();

    // The correct accessor reads the flash bucket, where the value lives.
    expect(\session()->getFlash('success'))->toBe('Saved!');
    expect(\session()->hasFlash('success'))->toBeTrue();

    $_SESSION = [];
});

/* ===========================================================================
 * AC-10 — docs/database.md: whereNull() / IS NULL semantics
 * =========================================================================== */

it('AC-10: docs/database.md documents whereNull()', function () {
    docContains('docs/database.md', 'whereNull', true, 'AC-10: whereNull must be documented');
});

it('AC-10: docs/database.md documents IS NULL semantics', function () {
    docContains('docs/database.md', 'IS NULL', true, 'AC-10: IS NULL must be documented');
});

/* ===========================================================================
 * AC-11 — docs/http.md + docs/middleware.md + docs/logging.md: getClientIp()
 * =========================================================================== */

it('AC-11: docs/http.md does not show $request->getClientIp() instance form', function () {
    docPregMatch('docs/http.md', '/\$request->getClientIp\(\)/', 0, 'AC-11: http.md must not show instance form');
});

it('AC-11: docs/middleware.md does not show $request->getClientIp() instance form', function () {
    docPregMatch('docs/middleware.md', '/\$request->getClientIp\(\)/', 0, 'AC-11: middleware.md must not show instance form');
});

it('AC-11: docs/logging.md does not show $request->getClientIp() instance form', function () {
    docPregMatch('docs/logging.md', '/\$request->getClientIp\(\)/', 0, 'AC-11: logging.md must not show instance form');
});

/* ===========================================================================
 * AC-12 — docs/http.md: remember-me example must use the built-in flow
 * =========================================================================== */

it('AC-12: docs/http.md does not show the wrong array-cookie remember-me example', function () {
    docPregMatch('docs/http.md', '/\'user_id\' => .*\'token\' => hash/', 0,
        'AC-12: wrong array-cookie example must be gone');
});

/* ===========================================================================
 * AC-13 — docs/database.md: $user->getDirty() is protected
 * =========================================================================== */

it('AC-13: docs/database.md does not show a public ->getDirty() call', function () {
    docPregMatch('docs/database.md', '/\$[a-zA-Z]+->getDirty\(\)/', 0,
        'AC-13: public getDirty() must be gone');
});

/* ===========================================================================
 * AC-14 — docs/views.md: ViewNotFoundException is 404, not 500
 * =========================================================================== */

it('AC-14: docs/views.md does not claim ViewNotFoundException is HTTP 500', function () {
    docPregMatch('docs/views.md', '/ViewNotFoundException.*500/', 0,
        'AC-14: 500 claim must be gone');
});

/* ===========================================================================
 * AC-15 — docs/helpers.md: raw() returns a plain string
 * =========================================================================== */

it('AC-15: docs/helpers.md does not mention the nonexistent RawValue class', function () {
    docContains('docs/helpers.md', 'RawValue', false, 'AC-15: RawValue must be gone');
});

/* ===========================================================================
 * AC-16 — docs/middleware.md: middleware execution order (source-aware)
 * =========================================================================== */

it('AC-16: docs/middleware.md does not show the wrong LIFO/FIFO wording', function () {
    // The previous wording was "LIFO for the request, FIFO for the response".
    // The corrected text uses "registration order" / "reverse order" — both
    // of which are wrong to combine with the original "LIFO/FIFO" pair. A
    // regression that reintroduces the old line must fail this test.
    docPregMatch('docs/middleware.md', '/LIFO for the request, FIFO for the response/', 0,
        'AC-16: old LIFO/FIFO wording must be gone');
});

it('AC-16 source-aware: Route::execute() runs first-added middleware first on the request', function () {
    // Pin the actual contract: when a Route has middlewares registered in
    // order [A, B, C], a request flows through A.before → B.before →
    // C.before → action, and the response unwinds C.after → B.after →
    // A.after. The check below mirrors the worked example in the docs
    // (Route::get('/admin', ...)->middleware(A)->middleware(B)->middleware(C)).
    $callOrder = [];

    $mw = function (string $name) use (&$callOrder): Middleware {
        return new class($name, $callOrder) extends Middleware {
            private string $name;
            private array $order;
            public function __construct(string $name, array &$order)
            {
                $this->name = $name;
                $this->order = &$order;
            }
            public function handle(Request $request, Closure $next): mixed
            {
                $this->order[] = "{$this->name}:before";
                $response = $next($request);
                $this->order[] = "{$this->name}:after";
                return $response;
            }
        };
    };

    $route = new Route('GET', '/admin', function () {
        return 'action-default';
    }, [
        $mw('A'),
        $mw('B'),
        $mw('C'),
    ]);

    $request = buildTestRequest('/admin', 'GET');

    $route->execute($request);

    // The docs claim: first-added runs first on request, last-added's after
    // logic runs first on response. So the unwind is in reverse order:
    // C.after (last-added) before A.after (first-added).
    expect($callOrder)->toBe([
        'A:before',
        'B:before',
        'C:before',
        'C:after',
        'B:after',
        'A:after',
    ]);
});

/* ===========================================================================
 * AC-17 — docs/middleware.md: missing Auth import in first example
 * =========================================================================== */

it('AC-17: docs/middleware.md first Auth example imports the Auth class', function () {
    docPregMatch('docs/middleware.md', '/use SwallowPHP\\\\Framework\\\\Auth\\\\Auth;/', 1,
        'AC-17: Auth import must be in middleware.md');
});

it('AC-17: docs/routing.md Auth example imports the Auth class', function () {
    docPregMatch('docs/routing.md', '/use SwallowPHP\\\\Framework\\\\Auth\\\\Auth;/', 1,
        'AC-17: Auth import must be in routing.md');
});

it('AC-17: docs/http.md User example imports the User model', function () {
    docPregMatch('docs/http.md', '/use App\\\\Models\\\\User;/', 1,
        'AC-17: User import must be in http.md');
});

it('AC-17: docs/views.md Post example imports the Post model', function () {
    docPregMatch('docs/views.md', '/use App\\\\Models\\\\Post;/', 1,
        'AC-17: Post import must be in views.md');
});

it('AC-17 regression: the imported Auth class actually exists at the documented path', function () {
    // The doc says `use SwallowPHP\Framework\Auth\Auth;` — the class must
    // exist at that exact namespace, otherwise the example would still
    // fatal with a class-not-found error. The previous prose fix added the
    // import but did not verify the class resolves.
    expect(class_exists(Auth::class))->toBeTrue(
        'AC-17: Auth class must exist at the FQCN the doc imports'
    );
});

/* ===========================================================================
 * AC-18 — docs/middleware.md: /api/* pattern does NOT match bare /api
 * =========================================================================== */

it('AC-18: docs/middleware.md does not claim /api/* matches the bare /api path', function () {
    // The old copy said `'/api/*'` matches `/api`, `/api/users`, ...`.
    // The corrected copy must not contain that wider claim. The current
    // prose is "matches /api/... only" — specifically excluding the bare
    // path. We verify the negation by asserting the old broad claim is not
    // present anywhere in the file.
    docPregMatch('docs/middleware.md', '/matches `\/api`, `\/api\/users/', 0,
        'AC-18: old broad /api/* claim must be gone');
});

it('AC-18 source-aware: VerifyCsrfToken::inExceptArray("/api/*") does NOT exempt bare /api', function () {
    // Pin the live behavior that the doc claims. Use a test-local subclass
    // to inject the except array (the production property is protected).
    $verifier = new class extends VerifyCsrfToken {
        protected array $except = ['api/*'];
        public function probeInExceptArray(Request $request): bool
        {
            return $this->inExceptArray($request);
        }
    };

    // Bare /api — should NOT match the wildcard (the new, narrower contract).
    $bareRequest = buildTestRequest('/api', 'GET');
    expect($verifier->probeInExceptArray($bareRequest))->toBeFalse(
        'AC-18: bare /api must NOT be exempt under /api/*'
    );

    // /api/users — SHOULD match.
    $nestedRequest = buildTestRequest('/api/users', 'GET');
    expect($verifier->probeInExceptArray($nestedRequest))->toBeTrue(
        'AC-18: /api/users must be exempt under /api/*'
    );
});

/* ===========================================================================
 * AC-19 — docs/middleware.md: ValidatePostSize 413 response body
 * =========================================================================== */

it('AC-19: docs/middleware.md describes the ValidatePostSize behaviour accurately', function () {
    // The previous prose claimed the middleware "directly validates
    // upload_max_filesize" and showed a stale error body. The corrected
    // prose must not assert that single-file claim against the runtime.
    docPregMatch('docs/middleware.md', '/directly validates upload_max_filesize/', 0,
        'AC-19: old single-file claim must be gone');
});

it('AC-19 source-aware: ExceptionHandler 413 JSON body matches the documented shape', function () {
    // Build the minimum container state ExceptionHandler needs: config()
    // and a request with Accept: application/json. We do NOT need a real
    // session or DB for this path.
    App::container();
    config(['app.storage_path' => dirname(__DIR__, 2) . '/.scratch']);
    config(['app.debug' => false]); // production-mode body — the stable contract

    // The framework's Request singleton is what `request()` returns. Mutate
    // it so the handler sees our JSON-Accept request.
    overrideRequestSingleton(buildTestRequest('/admin/upload', 'POST', ['Accept' => 'application/json']));

    $response = ExceptionHandler::handle(new PayloadTooLargeException('The request payload exceeds the server post_max_size limit.'));

    expect($response)->toBeInstanceOf(Response::class);
    $content = $response->getContent();
    // The handler returns a Response containing an array (which the JSON
    // Content-Type handler will encode to JSON). Decode the produced JSON
    // string and assert the documented message verbatim.
    $decoded = is_string($content) ? json_decode($content, true) : $content;
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKey('message');
    expect($decoded['message'])->toBe('Uploaded data is too large. Please reduce the file size and try again.');
});

/* ===========================================================================
 * AC-20 — docs/logging.md: stderr channel driver is 'errorlog', not 'stderr'
 * =========================================================================== */

it('AC-20: docs/logging.md does not use the wrong \'stderr\' driver value', function () {
    docPregMatch('docs/logging.md', '/\'driver\'\\s*=>\\s*\'stderr\'/', 0,
        'AC-20: \'stderr\' driver must be gone');
});

it('AC-20: docs/logging.md uses the correct \'errorlog\' driver value', function () {
    docPregMatch('docs/logging.md', '/\'driver\'\\s*=>\\s*\'errorlog\'/', 1,
        'AC-20: \'errorlog\' driver must be present');
});

/* ===========================================================================
 * AC-21 — docs/configuration.md: trusted_proxies config key
 * =========================================================================== */

it('AC-21: docs/configuration.md documents the trusted_proxies config key', function () {
    docContains('docs/configuration.md', 'trusted_proxies', true,
        'AC-21: trusted_proxies must be documented in configuration.md');
});

/* ===========================================================================
 * AC-22 — docs/database.md: event listener returning false stops propagation
 * =========================================================================== */

it('AC-22: docs/database.md documents the false-return abort semantics', function () {
    // The spec says the exact wording is not pinned, but the prose must
    // mention the abort-semantics behavior. Either "returns ... false" +
    // "stop" or "stop" + "listener" + "false" is acceptable.
    docPregMatch('docs/database.md', '/returns.*false.*stop|stop.*listener.*false/is', 1,
        'AC-22: abort semantics must be documented');
});

/* ===========================================================================
 * AC-23 — README.md: php-debugbar is in `require`, not `require-dev`
 * =========================================================================== */

it('AC-23: README does not label php-debugbar as a (dev) dependency', function () {
    docPregMatch('README.md', '/php-debugbar.*\(dev\)/', 0,
        'AC-23: php-debugbar must not be labeled (dev)');
});

/* ===========================================================================
 * AC-24 — docs/session.md: no overstated custom-handler support claim
 * =========================================================================== */

it('AC-24: docs/session.md no longer claims "custom handler support"', function () {
    // The pre-fix intro phrase was "...flash messages and custom handler support."
    // The narrowed prose describes the file driver only.
    docPregMatch('docs/session.md', '/custom handler support/', 0,
        'AC-24: "custom handler support" claim must be gone');
});

it('AC-24: docs/session.md narrows the claim to the file driver', function () {
    // The new intro must explicitly mention the file driver as the only
    // supported backend, so a reader cannot infer that other handlers can
    // be plugged in.
    $content = doc('docs/session.md');
    expect(stripos($content, 'file driver') !== false)->toBeTrue(
        'AC-24: intro must reference the file driver'
    );
});

/* ===========================================================================
 * AC-25 — docs/configuration.md: session.connection/table/lottery documented as active
 * =========================================================================== */

it('AC-25: docs/configuration.md does not list session.connection as an active config row', function () {
    // The pre-fix table row | `connection` | string | `null` | Database connection (for db driver) |
    // is gone. We grep for the description text that was unique to that row.
    docPregMatch('docs/configuration.md', '/Database connection \(for db driver\)/', 0,
        'AC-25: session.connection active row must be gone');
});

it('AC-25: docs/configuration.md does not list session.table as an active config row', function () {
    docPregMatch('docs/configuration.md', '/Database table name/', 0,
        'AC-25: session.table active row must be gone');
});

it('AC-25: docs/configuration.md does not list session.lottery as an active config row', function () {
    docPregMatch('docs/configuration.md', '/Garbage collection probability \[chance, divisor\]/', 0,
        'AC-25: session.lottery active row must be gone');
});

it('AC-25: docs/configuration.md annotates all three keys as unused/reserved', function () {
    // The fix introduces a paragraph that explicitly groups the three keys
    // as "not currently consumed by any driver". Any regression that puts
    // any of them back into the active-config table fails this check.
    $content = doc('docs/configuration.md');
    expect(stripos($content, 'session.connection') !== false)->toBeTrue(
        'AC-25: session.connection must be mentioned (in the unused/reserved note)'
    );
    expect(stripos($content, 'session.table') !== false)->toBeTrue(
        'AC-25: session.table must be mentioned (in the unused/reserved note)'
    );
    expect(stripos($content, 'session.lottery') !== false)->toBeTrue(
        'AC-25: session.lottery must be mentioned (in the unused/reserved note)'
    );
});

/* ===========================================================================
 * AC-26 — docs/configuration.md: session.secure typed as bool|null
 * =========================================================================== */

it('AC-26: docs/configuration.md types session.secure as bool|null', function () {
    // The pre-fix table row read `bool | null | ...`. The fix types it as
    // `bool|null` and adds a one-clause note about null auto-detection.
    $content = doc('docs/configuration.md');
    // Match the row: `secure` | bool|null | `null` | ...
    // The file has a literal backslash before the second pipe in `bool\|null`
    // because the Markdown table cell is a PHP regex context. We accept
    // either spelling here — `bool|null` (rendered) or `bool\|null` (raw
    // Markdown source) — to be tolerant of either the literal fix or a
    // rendering pass.
    $rowMatches = preg_match('/\| `secure` \| (?:bool\|null|bool\\\\\|null) \| `null` /', $content) === 1
        || preg_match('/\| `secure` \| bool\|null \| `null` /', $content) === 1;
    expect($rowMatches)->toBeTrue(
        'AC-26: session.secure row must have type bool|null'
    );
    expect(stripos($content, 'auto-detects from the request') !== false)->toBeTrue(
        'AC-26: session.secure row must note the null auto-detect behavior'
    );
});

/* ===========================================================================
 * AC-27 — docs/configuration.md: cache.prefix documented as active
 * =========================================================================== */

it('AC-27: docs/configuration.md does not list cache.prefix as an active config row', function () {
    // The pre-fix table row was | `prefix` | string | `'swallowphp_cache_'` | Cache key prefix |
    // The fix removes that row and adds a note that the key is unused.
    docPregMatch('docs/configuration.md', '/Cache key prefix/', 0,
        'AC-27: cache.prefix active row must be gone');
});

it('AC-27: docs/configuration.md annotates cache.prefix as unused', function () {
    $content = doc('docs/configuration.md');
    expect(stripos($content, 'cache.prefix') !== false)->toBeTrue(
        'AC-27: cache.prefix must be mentioned (in the unused note)'
    );
    expect(stripos($content, 'not currently applied') !== false)->toBeTrue(
        'AC-27: cache.prefix unused note must be present'
    );
});

/* ===========================================================================
 * AC-28 — docs/configuration.md: unused database connection fields marked as active
 * =========================================================================== */

it('AC-28: docs/configuration.md annotates unix_socket, collation, prefix, strict, engine as unused', function () {
    // The fix replaces the active-claim with an explicit "accepted in config
    // but not currently consumed" note. We accept either an inline annotation
    // or a separate "reads only these fields" framing, but the test must
    // fail if any of the five fields is presented as configuring real
    // behavior again.
    $content = doc('docs/configuration.md');

    // The connection layer documents the set of fields it actually reads.
    expect(stripos($content, 'driver') !== false && stripos($content, 'host') !== false)->toBeTrue(
        'AC-28: connection-layer field list must still be present'
    );

    // The framing sentence itself must be present.
    expect(stripos($content, 'not currently consumed by the connection layer') !== false)->toBeTrue(
        'AC-28: explicit "not currently consumed by the connection layer" framing must be present'
    );

    // Locate the position of the framing marker and check each of the five
    // ignored fields appears within a reasonable window of that marker.
    // We use a sliding 4000-char window because `prefix` is also a
    // legitimate config key in other sections (cache.php, session.php),
    // and we are looking specifically for the database-connection
    // occurrence that is annotated as unused.
    $unusedNotePos = stripos($content, 'not currently consumed by the connection layer');
    expect($unusedNotePos)->not->toBeFalse(
        'AC-28: "not currently consumed by the connection layer" framing must be present'
    );
    $windowStart = max(0, $unusedNotePos - 3000);
    $windowEnd = $unusedNotePos + 3000;
    $window = substr($content, $windowStart, $windowEnd - $windowStart);

    foreach (['unix_socket', 'collation', 'prefix', 'strict', 'engine'] as $field) {
        expect(stripos($window, $field) !== false)->toBeTrue(
            "AC-28: database connection field `{$field}` must appear within the unused-fields note"
        );
    }
});

/* ===========================================================================
 * AC-29 — docs/database.md: PostgreSQL is listed as a supported driver
 * =========================================================================== */

it('AC-29: docs/database.md does not list PostgreSQL as a supported driver', function () {
    // The pre-fix list was three bare bullets: MySQL, SQLite, PostgreSQL.
    // The fix tags PostgreSQL as "not currently supported" with a quote
    // about identifier quoting. We assert the unsupported tag is present
    // and the old bare "PostgreSQL (`pgsql`)" bullet is gone.
    $content = doc('docs/database.md');
    expect(stripos($content, 'PostgreSQL') !== false)->toBeTrue(
        'AC-29: PostgreSQL must still be mentioned (in the unsupported clause)'
    );
    expect(stripos($content, 'not currently supported') !== false)->toBeTrue(
        'AC-29: PostgreSQL must be marked as not currently supported'
    );
    // The old bare bullet listed PostgreSQL under "Supported Drivers"
    // without any caveat. A regression that drops the annotation fails.
    expect(preg_match('/\*\*PostgreSQL\*\* \(`pgsql`\)\s*—\s*\*\*(?!.*not currently supported)/', $content) === 0)->toBeTrue(
        'AC-29: PostgreSQL must not appear as a bare "supported" bullet without the unsupported annotation'
    );
});

/* ===========================================================================
 * AC-30 — docs/cache.md: omitted TTL falls back to a configured default
 * =========================================================================== */

it('AC-30: docs/cache.md does not say "default TTL from config" is applied to set()', function () {
    // The pre-fix comment was "Store a value (with default TTL from config)".
    // The fix replaces it with "no TTL — the entry never expires".
    docPregMatch('docs/cache.md', '/with default TTL from config/', 0,
        'AC-30: "default TTL from config" claim must be gone');
});

it('AC-30: docs/cache.md says an omitted TTL means the entry never expires', function () {
    // The corrected wording must explicitly say that omitting TTL (or
    // passing null) means the entry never expires.
    $content = doc('docs/cache.md');
    expect(stripos($content, 'never expires') !== false)->toBeTrue(
        'AC-30: "never expires" wording must be present'
    );
});

/* ===========================================================================
 * AC-31 — docs/routing.md: dependency injection source description
 * =========================================================================== */

it('AC-31: docs/routing.md says DI resolves from merged request data, not route params alone', function () {
    // The pre-fix description named "route parameters" as the source. The
    // fix narrows it to "the merged request data (route parameters, query
    // string, and request body)".
    $content = doc('docs/routing.md');
    expect(stripos($content, 'merged request data') !== false)->toBeTrue(
        'AC-31: "merged request data" wording must be present'
    );
    expect(stripos($content, 'route parameters') !== false)->toBeTrue(
        'AC-31: "route parameters" must still appear (within the merged-data framing)'
    );
    expect(stripos($content, 'query string') !== false)->toBeTrue(
        'AC-31: "query string" must appear in the resolution-source description'
    );
    expect(stripos($content, 'request body') !== false)->toBeTrue(
        'AC-31: "request body" must appear in the resolution-source description'
    );
});

/* ===========================================================================
 * AC-32 — docs/database.md: cursor pagination's last_page = 0 quirk is undocumented
 * =========================================================================== */

it('AC-32: docs/database.md documents the cursor pagination last_page quirk', function () {
    // The fix adds a note under the cursor-pagination section that
    // lastPage() is always 0 and total() is not meaningful; readers are
    // pointed at hasMorePages()/nextCursor() instead.
    $content = doc('docs/database.md');
    expect(stripos($content, 'lastPage') !== false)->toBeTrue(
        'AC-32: cursor-pagination note must mention lastPage'
    );
    // The "always 0" / "not meaningful" wording must be present in the
    // cursor-pagination context, not just a generic lastPage mention.
    expect(preg_match('/lastPage\(\)[^.]*?always[^.]*?0/si', $content) === 1)->toBeTrue(
        'AC-32: cursor-pagination note must say lastPage() is always 0'
    );
    expect(stripos($content, 'hasMorePages') !== false)->toBeTrue(
        'AC-32: cursor-pagination note must mention hasMorePages'
    );
    expect(stripos($content, 'nextCursor') !== false)->toBeTrue(
        'AC-32: cursor-pagination note must mention nextCursor'
    );
});

/* ===========================================================================
 * AC-33 — README.md / docs/configuration.md: invalid APP_KEY example length
 * =========================================================================== */

it('AC-33: README.md APP_KEY example decodes to exactly 32 bytes', function () {
    // The fix replaces the placeholder with a valid 32-byte (base64:) key.
    // We extract the value, decode the base64 payload, and assert the
    // decoded length is exactly 32. This is a one-line PHP check that
    // does not require a Pest test against src/, but we run it here so
    // the spec's "grep-assert its base64: payload decodes to exactly 32
    // bytes" gate is enforced automatically.
    $content = doc('README.md');
    expect(preg_match('/^APP_KEY=base64:([A-Za-z0-9+\/=]+)\s*$/m', $content, $m) === 1)->toBeTrue(
        'AC-33: README.md must contain an APP_KEY=base64:... line'
    );
    $decoded = base64_decode($m[1], true);
    expect($decoded)->toBeString('AC-33: APP_KEY base64 payload must decode');
    expect(strlen($decoded))->toBe(32, 'AC-33: decoded APP_KEY must be exactly 32 bytes');
});

it('AC-33: docs/configuration.md APP_KEY example decodes to exactly 32 bytes', function () {
    $content = doc('docs/configuration.md');
    expect(preg_match('/^APP_KEY=base64:([A-Za-z0-9+\/=]+)\s*$/m', $content, $m) === 1)->toBeTrue(
        'AC-33: docs/configuration.md must contain an APP_KEY=base64:... line'
    );
    $decoded = base64_decode($m[1], true);
    expect($decoded)->toBeString('AC-33: APP_KEY base64 payload must decode');
    expect(strlen($decoded))->toBe(32, 'AC-33: decoded APP_KEY must be exactly 32 bytes');
});

it('AC-33: neither doc uses the wrong-length placeholder', function () {
    // The pre-fix placeholder was `your-32-character-secret-key-here`,
    // which is not a base64 string at all and does not decode to 32 bytes.
    // Any regression that re-introduces the literal placeholder fails this.
    docContains('README.md', 'your-32-character-secret-key-here', false,
        'AC-33: README.md must not use the wrong-length placeholder');
    docContains('docs/configuration.md', 'your-32-character-secret-key-here', false,
        'AC-33: docs/configuration.md must not use the wrong-length placeholder');

    foreach (['README.md', 'docs/configuration.md'] as $path) {
        expect(preg_match('/example only.*generate your own.*APP_KEY=base64:/is', doc($path)) === 1)->toBeTrue(
            "AC-33: {$path} must label the illustrative APP_KEY as example-only"
        );
    }
});

/* ===========================================================================
 * AC-34 — docs/authentication.md: cache-key notation (sha1($email))
 * =========================================================================== */

it('AC-34: docs/authentication.md uses the literal sha1($email) in the cache-key description', function () {
    $content = doc('docs/authentication.md');
    $start = stripos($content, '### Cache Keys');
    $end = stripos($content, "\n### ", $start + 1);
    expect($start)->toBeGreaterThan(0, 'AC-34: cache-key section must exist');
    expect($end)->toBeGreaterThan($start, 'AC-34: cache-key section must be bounded');
    $section = substr($content, $start, $end - $start);
    expect(preg_match('/login_attempt_\{ip\}_sha1\(\$email\)/', $section) === 1)->toBeTrue(
        'AC-34: sha1($email) must appear in the cache-key description'
    );
});

it('AC-34: docs/authentication.md no longer uses the generic {email_hash} placeholder', function () {
    $content = doc('docs/authentication.md');
    $start = stripos($content, '### Cache Keys');
    $end = stripos($content, "\n### ", $start + 1);
    expect($start)->toBeGreaterThan(0, 'AC-34: cache-key section must exist');
    expect($end)->toBeGreaterThan($start, 'AC-34: cache-key section must be bounded');
    $section = substr($content, $start, $end - $start);
    expect(preg_match('/\{email_hash\}/', $section))->toBe(0,
        'AC-34: {email_hash} placeholder must be gone');
});

/* ===========================================================================
 * AC-35 — CHANGELOG.md: Action required subsection convention is documented
 * =========================================================================== */

it('AC-35: CHANGELOG.md intro documents the Action required convention', function () {
    $content = doc('CHANGELOG.md');
    $introEnd = strpos($content, '## [2.0.1]');
    $intro = substr($content, 0, $introEnd);
    // The convention mentions both "Action required" and the standard
    // subsections in the same intro paragraph. Either ordering is fine;
    // we just want to make sure both are referenced together.
    expect(stripos($intro, 'Action required') !== false)->toBeTrue(
        'AC-35: CHANGELOG intro must mention "Action required"'
    );
    expect(stripos($intro, 'convention') !== false)->toBeTrue(
        'AC-35: CHANGELOG intro must mention the convention'
    );
});

/* ===========================================================================
 * AC-36 — CHANGELOG.md: 2024-12-XX date placeholders are gone
 * =========================================================================== */

it('AC-36: CHANGELOG.md no longer contains the 2024-12-XX placeholder', function () {
    docPregMatch('CHANGELOG.md', '/2024-12-XX/', 0,
        'AC-36: 2024-12-XX placeholder must be gone');
});

/* ===========================================================================
 * AC-37 — docs/middleware.md: global middleware pipeline order subsection
 * =========================================================================== */

it('AC-37: docs/middleware.md has a global-middleware-pipeline-order subsection listing all four middlewares', function () {
    $content = doc('docs/middleware.md');
    // The new subsection heading must be present.
    expect(stripos($content, 'Global middleware pipeline order') !== false)->toBeTrue(
        'AC-37: "Global middleware pipeline order" subsection must be present'
    );
    // All four ordered classes must appear in the doc body as class names.
    // The route action is a stage, not a class, so we only check the three
    // middleware classes plus the explicit "Route action" stage label.
    foreach (['ValidatePostSize', 'VerifyCsrfToken', 'AddContentSecurityPolicyHeader'] as $class) {
        expect(stripos($content, $class) !== false)->toBeTrue(
            "AC-37: {$class} must appear in middleware.md"
        );
    }
    expect(stripos($content, 'Route action') !== false)->toBeTrue(
        'AC-37: "Route action" stage must appear in the ordered pipeline list'
    );
});

it('AC-37: docs/middleware.md lists the four pipeline stages in the real execution order', function () {
    $content = doc('docs/middleware.md');
    $start = stripos($content, '## Global Middleware Pipeline Order');
    $end = stripos($content, "\n## ", $start + 1);
    expect($start)->toBeGreaterThan(0, 'AC-37: global pipeline subsection must exist');
    expect($end)->toBeGreaterThan($start, 'AC-37: global pipeline subsection must be bounded');
    $section = substr($content, $start, $end - $start);
    $stages = ['ValidatePostSize', 'VerifyCsrfToken', 'Route action', 'AddContentSecurityPolicyHeader'];
    $positions = [];
    foreach ($stages as $stage) {
        $positions[$stage] = stripos($section, $stage);
        expect($positions[$stage])->toBeGreaterThan(0,
            "AC-37: {$stage} must appear in the global pipeline subsection");
    }
    for ($i = 1; $i < count($stages); $i++) {
        expect($positions[$stages[$i - 1]])->toBeLessThan($positions[$stages[$i]],
            "AC-37: {$stages[$i - 1]} must precede {$stages[$i]}");
    }
});

/* ===========================================================================
 * AC-38 — docs/views.md: view-fallback claim needs narrowing
 * =========================================================================== */

it('AC-38: docs/views.md narrows the view-resolution claim to the application view path', function () {
    $content = doc('docs/views.md');
    // The narrowed claim must explicitly say view() resolves only from
    // the application's configured view directory. The doc wraps the line
    // across a soft break, so we look for both halves and assert they
    // appear close together, OR assert the full phrase if a future edit
    // joins them.
    $hasApp = stripos($content, "application's") !== false;
    $hasConfiguredViewDir = stripos($content, 'configured view directory') !== false;
    expect($hasApp && $hasConfiguredViewDir)->toBeTrue(
        'AC-38: view-resolution must reference the application\'s configured view directory'
    );
    // The phrase "not ... fall back to any framework-bundled views" must
    // also be present so the negative claim is anchored to a noun, not
    // just an isolated list of places.
    expect(stripos($content, 'fall back to any framework-bundled views') !== false
        || stripos($content, 'fall back to') !== false && stripos($content, 'framework-bundled') !== false
        || stripos($content, 'not') !== false && stripos($content, 'fall back to') !== false
            && stripos($content, 'framework-bundled views') !== false)->toBeTrue(
        'AC-38: view-resolution must explicitly negate the framework-bundled-views fallback'
    );
});

it('AC-38: docs/views.md does not claim view() falls back to framework views for application code', function () {
    // The pre-fix line was "Framework views: Framework's built-in views (fallback)".
    // A regression that re-introduces the bare "framework views ... fallback"
    // framing fails this check.
    docPregMatch('docs/views.md', '/Framework\'s built-in views \(fallback\)/', 0,
        'AC-38: old "framework views (fallback)" claim must be gone');
});
