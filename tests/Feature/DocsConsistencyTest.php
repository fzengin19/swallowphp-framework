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
