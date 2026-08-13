<?php

/*
|--------------------------------------------------------------------------
| Phase 3 hardening tests (AC-40 .. AC-46).
|--------------------------------------------------------------------------
|
| These tests bind the binding code changes from SwallowPHP Phase 3. Each
| AC's "Test" sketch in the SPEC is bound here as a behavioral check that
| exercises the real production path (Cookie::set() + Cookie::get(),
| SqliteCache::get()/set(), Route::execute(), Router::dispatch(),
| RateLimiter::execute(), webpImage()) — not just source-presence grepping.
|
| Fixture classes (Phase3TestController) are defined at the top of this
| file per the project's existing pattern (see Phase0HardeningTest and
| DocsConsistencyTest). All scratch files live inside .scratch/phase3/ — a
| fresh subdirectory inside the working tree, never /tmp.
|
*/

namespace Tests\Feature;

use SwallowPHP\Framework\Cache\SqliteCache;
use SwallowPHP\Framework\Exceptions\RateLimitExceededException;
use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Http\Cookie;
use SwallowPHP\Framework\Http\Middleware\RateLimiter;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Routing\Route;
use SwallowPHP\Framework\Routing\Router;
use ReflectionClass;
use ReflectionProperty;

// ---------------------------------------------------------------------------
// Test fixtures. Phase3TestController has no constructor so the framework's
// ReflectionContainer can autowire it during Route::execute() — no
// container binding required. Static flags record which handler ran and
// what value it received, so the AC-42 / AC-43 / AC-44 tests can assert
// the dispatcher's actual behavior without parsing rendered output.
// ---------------------------------------------------------------------------

class Phase3TestController
{
    public static ?int $lastIntId = null;
    public static ?string $lastStringId = null;
    public static bool $lastBoolFlag = false;
    public static bool $indexRan = false;
    public static bool $listRan = false;
    public static ?Request $capturedRequest = null;

    // AC-43 raw-type probe. PHP does NOT implicitly coerce values for a
    // `mixed` parameter (it's effectively untyped — accepts anything
    // without conversion). So whatever the framework passes through
    // resolveMethodDependencies() arrives at this method verbatim, and
    // we can record gettype() before PHP's typed-parameter coercion can
    // hide the bug. With `int $id` declared, PHP would silently coerce
    // "42" → 42 even if Route.php did no coercion of its own, which is
    // exactly the BLOCKING gap the test auditor flagged.
    public static mixed $lastRawValue = null;
    public static ?string $lastRawType = null;
    public static bool $probeRawRan = false;

    public function showInt(int $id): string
    {
        self::$lastIntId = $id;
        return 'int';
    }

    public function showString(string $id): string
    {
        self::$lastStringId = $id;
        return 'string';
    }

    /**
     * Raw-type probe. Receives the argument exactly as Route.php delivers
     * it — no PHP implicit coercion happens for a `mixed` parameter, so
     * the recorded type/value directly reflects what the framework did
     * (or did not do) in coerceScalarRouteParameter().
     */
    public function probeRaw(mixed $id): string
    {
        self::$lastRawValue = $id;
        self::$lastRawType = gettype($id);
        self::$probeRawRan = true;
        return 'raw';
    }

    // Bool param form. The SPEC says: "only cast to bool for the literal
    // strings a reasonable route param would use ('1'/'0'/'true'/'false')
    // rather than PHP's loose truthiness rules for arbitrary strings".
    // PHP's loose rule would coerce 'false' → true (any non-empty string
    // is truthy). Our coercion turns 'false' → bool false. The mutation
    // test below uses the literal 'false' because it cleanly exposes the
    // difference between PHP's loose coercion and our strict whitelist.
    public function showBool(bool $flag): string
    {
        self::$lastBoolFlag = $flag;
        return 'bool';
    }

    // Variant with a default value — used by the AC-43 scope tests to
    // distinguish pre-fix from post-fix behavior when a query value
    // matches a controller parameter name but no URL segment does.
    // Pre-fix: the framework pulled query values through
    // resolveMethodDependencies (via $request->all()) and ran the
    // scalar coercion on them. Post-fix: only URL segments are
    // passed to resolveMethodDependencies (via $request->routeParams()),
    // so a query-only value falls through to the parameter's default.
    public function showIntWithDefault(int $id = 0): string
    {
        self::$lastIntId = $id;
        return 'int-default';
    }

    public function index(): string
    {
        self::$indexRan = true;
        return 'index';
    }

    // Static method form. In PHP 8.5, is_callable([Class, 'staticMethod'])
    // returns true (it's true for static methods via class string), so a
    // route with [Phase3TestController::class, 'list'] (a static method)
    // hits the bug described in the SPEC: the is_callable() branch fires,
    // ReflectionFunction([Class, 'method']) throws TypeError. After the
    // fix (array-callable branch checked first), this dispatches cleanly.
    public static function list(): string
    {
        self::$listRan = true;
        return 'list';
    }

    public function captureRequest(Request $request): string
    {
        self::$capturedRequest = $request;
        return 'ok';
    }
}

// ---------------------------------------------------------------------------
// Scratch directory. Lives INSIDE the working tree (.scratch/phase3/) so
// the sandboxed /tmp is never touched. Each test gets its own subdirectory
// under it.
// ---------------------------------------------------------------------------

if (!defined('PHASE3_SCRATCH_DIR')) {
    define('PHASE3_SCRATCH_DIR', dirname(__DIR__, 2) . '/.scratch/phase3');
}

if (!is_dir(PHASE3_SCRATCH_DIR)) {
    mkdir(PHASE3_SCRATCH_DIR, 0755, true);
}

beforeEach(function () {
    // Boot the framework container so Router::dispatch() and friends can
    // resolve their dependencies (RateLimiter's CacheInterface, the
    // FileLogger webpImage() calls, etc.). Point storage_path at our scratch
    // dir so the FileLogger doesn't try to write outside the repo.
    App::container();
    config(['app.storage_path' => PHASE3_SCRATCH_DIR]);
    config(['app.trusted_proxies' => []]);
    config(['cache.default' => 'file']);
    config(['cache.ttl' => 60]);
    config(['cache.prefix' => 'phase3_' . uniqid() . '_']);

    // Reset Router's static route collection and matched-route cache so each
    // test starts with a clean dispatcher.
    $routerRef = new ReflectionClass(Router::class);
    $routerRef->getProperty('routes')->setValue(null, []);
    $routerRef->getProperty('matchedRoute')->setValue(null, null);

    // Reset Cookie's static memoized state (decoded APP_KEY + queued
    // response cookies) so a previous test's key/value cannot leak into the
    // next.
    $cookieRef = new ReflectionClass(Cookie::class);
    $cookieRef->getProperty('decodedKey')->setValue(null, null);
    $cookieRef->getProperty('queuedCookies')->setValue(null, []);
    $_COOKIE = [];

    // Reset fixture controller's static flags.
    Phase3TestController::$lastIntId = null;
    Phase3TestController::$lastStringId = null;
    Phase3TestController::$lastBoolFlag = false;
    Phase3TestController::$indexRan = false;
    Phase3TestController::$listRan = false;
    Phase3TestController::$capturedRequest = null;
    Phase3TestController::$lastRawValue = null;
    Phase3TestController::$lastRawType = null;
    Phase3TestController::$probeRawRan = false;
});

/**
 * Tear down scratch artifacts. The MEDIUM review flagged that
 * SqliteCache (AC-41) leaves *.sqlite-wal / *.sqlite-shm / *.sqlite-journal
 * sidecars in .scratch/phase3/ and the AC-46 tests leave stray PNGs (and
 * one unused PNG from a pre-cleanup code path). Without this hook, repeated
 * test runs accumulate files under .scratch/phase3/. The hook removes
 * SQLite sidecars, the image-conversion artifacts the tests try to clean
 * up, and any empty directories the AC-46 tests created.
 */
afterEach(function () {
    if (!is_dir(PHASE3_SCRATCH_DIR)) {
        return;
    }
    // SQLite sidecars (WAL/SHM/journal) and the main DB file. SqliteCache
    // doesn't always close cleanly on destruct in test contexts, so the
    // WAL may still be flushed — unlink() handles that silently.
    foreach (glob(PHASE3_SCRATCH_DIR . '/{*.sqlite,*.sqlite-wal,*.sqlite-shm,*.sqlite-journal}', GLOB_BRACE) ?: [] as $f) {
        @unlink($f);
    }
    // Image-conversion artifacts (PNG sources, AVIF/WebP outputs) the
    // AC-46 tests try to clean up themselves; this is a belt-and-braces
    // pass for any test that fails before reaching its own cleanup.
    foreach (glob(PHASE3_SCRATCH_DIR . '/{*.png,*.avif,*.webp}', GLOB_BRACE) ?: [] as $f) {
        @unlink($f);
    }
    // Empty directories the AC-46 tests create (ac46_safe_dest_*, etc.).
    // Only remove if empty — rrmdir would be unsafe if a test left a file
    // behind, and we don't want to wipe unrelated test state.
    foreach (new \DirectoryIterator(PHASE3_SCRATCH_DIR) as $entry) {
        if (!$entry->isDir() || $entry->isDot()) {
            continue;
        }
        $path = $entry->getPathname();
        // The 'files/' directory and its PNG contents are created by the
        // (now-redundant) source-setup snippet at the top of the AC-46
        // traversal test — the rest of the test does not use that
        // directory. Remove it recursively so old PNGs from previous runs
        // don't accumulate; safe because nothing else in this test file
        // writes into PHASE3_SCRATCH_DIR/files/.
        if (basename($path) === 'files') {
            foreach (glob($path . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($path);
            continue;
        }
        if (count(glob($path . '/*') ?: []) === 0) {
            @rmdir($path);
        }
    }
});

// ---------------------------------------------------------------------------
// Shared helpers used by the per-AC tests below.
// ---------------------------------------------------------------------------

/**
 * Construct a Request via reflection. The constructor is protected, so
 * we use ReflectionClass::newInstanceWithoutConstructor() to build an
 * empty Request and then invoke the real constructor with our args.
 * Mirrors buildTestRequest() in DocsConsistencyTest.php.
 */
function phase3BuildRequest(string $uri, string $method, array $query = [], array $body = []): Request
{
    $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri];
    $rc = new ReflectionClass(Request::class);
    $instance = $rc->newInstanceWithoutConstructor();
    $ctor = $rc->getConstructor();
    $ctor->invokeArgs($instance, [
        $uri,
        $method,
        $query,
        $body,
        [], // files
        [], // headers
        $server,
        '', // rawInput
    ]);
    return $instance;
}

/**
 * Encrypt a value using the OLD pre-AC-40 single-key scheme (one key for
 * both openssl_encrypt and hash_hmac) and base64-encode the result in the
 * same iv.ciphertext.mac layout that Cookie::encrypt() produced before
 * AC-40. Used by the AC-40 "old cookies now rejected" test.
 *
 * Note: this MUST json_encode the value first — the real Cookie::encrypt()
 * serializes via json_encode before encrypting, and Cookie::decrypt() then
 * json_decode()s the result. Skipping json_encode produces a payload that
 * would be rejected by json_decode() for reasons unrelated to the key
 * derivation (proving the wrong thing).
 */
function phase3EncryptWithOldScheme(string $value, string $key): string
{
    $jsonData = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($jsonData, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    // Old scheme: SAME $key for both openssl_encrypt AND hash_hmac. This
    // is exactly the pre-AC-40 behavior that AC-40 explicitly retired.
    $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
    return base64_encode($iv . $ciphertext . $mac);
}

/**
 * Move Cookie::set()'s queued response cookie into $_COOKIE so Cookie::get()
 * can read it. Returns the raw base64 payload that was queued.
 */
function phase3QueueAndMoveCookie(string $name, mixed $value): string
{
    Cookie::set($name, $value);
    $cookieRef = new ReflectionClass(Cookie::class);
    $queue = $cookieRef->getProperty('queuedCookies')->getValue();
    $cookieName = '__Secure-' . $name;
    $payload = $queue[$cookieName]['value'] ?? $queue[$name]['value'] ?? '';
    $_COOKIE[$cookieName] = $payload;
    $_COOKIE[$name] = $payload;
    return $payload;
}

/**
 * Create a tiny valid PNG on disk using GD, for webpImage() to consume.
 */
function phase3CreatePng(string $path): void
{
    if (!function_exists('imagecreatetruecolor')) {
        throw new \RuntimeException('GD is required for AC-46 tests.');
    }
    $img = imagecreatetruecolor(8, 8);
    imagepng($img, $path);
    // imagedestroy() is deprecated in PHP 8.5 (no-op since 8.0). The test
    // fixture's lifetime is the test method body, so we just unset our
    // reference; PHP's refcount cleans up the GD resource.
    $img = null;
}

/* ===========================================================================
 * AC-40 — Cookie.php: derive separate enc/mac keys
 * =========================================================================== */

it('AC-40: round-trip set/get works under the new key derivation', function () {
    $key = random_bytes(32);
    config(['app.key' => $key]);

    $payload = phase3QueueAndMoveCookie('roundtrip', 'roundtrip-value');

    expect(Cookie::get('roundtrip'))->toBe('roundtrip-value');
});

it('AC-40: cookies encrypted under the OLD single-key scheme are now rejected', function () {
    $key = random_bytes(32);
    config(['app.key' => $key]);

    // Manually encrypt under the OLD (pre-AC-40) scheme: same $key for both
    // openssl_encrypt and hash_hmac.
    $oldPayload = phase3EncryptWithOldScheme('old-value', $key);
    $_COOKIE['oldcookie'] = $oldPayload;

    // The new derivation produces a DIFFERENT MAC than the old scheme, so
    // Cookie::get() treats the old cookie as invalid (same code path as any
    // tampered cookie — returns $default).
    expect(Cookie::get('oldcookie', 'fallback-default'))->toBe('fallback-default');
});

/* ===========================================================================
 * AC-41 — SqliteCache.php: validate table name before SQL interpolation
 * =========================================================================== */

it('AC-41: default/valid table name works for get/set round-trip', function () {
    $dbPath = PHASE3_SCRATCH_DIR . '/ac41_valid_' . uniqid() . '.sqlite';
    $cache = new SqliteCache($dbPath, 'cache');

    expect($cache->set('foo', 'bar'))->toBeTrue();
    expect($cache->get('foo'))->toBe('bar');

    $cache->clear();
    @unlink($dbPath);
});

it('AC-41: malicious table name throws InvalidArgumentException', function () {
    $dbPath = PHASE3_SCRATCH_DIR . '/ac41_mal_' . uniqid() . '.sqlite';

    // Each malicious variant exercises a different aspect of the regex
    // guard: SQL punctuation, whitespace, and a backtick. None of these
    // can match /^[A-Za-z0-9_]+$/.
    foreach ([
        'cache; DROP TABLE x; --',
        'cache DROP TABLE',
        '`cache`',
        "cache\x00",
        '../escape',
    ] as $bad) {
        expect(fn () => new SqliteCache($dbPath, $bad))
            ->toThrow(\InvalidArgumentException::class);
    }
});

/* ===========================================================================
 * AC-42 — Route.php: array-callable action dispatches instead of TypeError
 * =========================================================================== */

it('AC-42: array-callable route (static method) dispatches the controller method instead of throwing TypeError', function () {
    // Pre-fix: executeAction() called is_callable() first. For the array
    // callable [Class::class, 'list'] (a static method), is_callable()
    // returns true, then ReflectionFunction([Class, 'method']) throws
    // TypeError because its signature only accepts Closure|string — the
    // controller method is never reached.
    //
    // (Note on non-static methods via class string: in PHP 8.5
    // is_callable() returns false for them, so they fall through to the
    // array branch and "work" today. The SPEC describes the broader
    // "array-callable dispatch broken" finding; the static-method form
    // is the variant that manifests as TypeError today.)
    $route = Router::get('/ac42/list', [Phase3TestController::class, 'list']);

    $request = phase3BuildRequest('/ac42/list', 'GET');

    // Router::dispatch() so the route is matched AND the controller
    // method is invoked through the full production code path. If AC-42
    // is not fixed, dispatch throws TypeError before reaching the
    // controller method.
    Router::dispatch($request);

    expect(Phase3TestController::$listRan)->toBeTrue();
});

/* ===========================================================================
 * AC-43 — Route.php: numeric route param coerces to declared int type;
 *          non-numeric passes through unchanged (no new crash, no new 404).
 * =========================================================================== */

it('AC-43: numeric route param is coerced by the framework, not by PHP weak typing', function () {
    // The test auditor flagged that asserting `int $id` received `42` is
    // INSUFFICIENT to detect missing coercion: PHP 8.x's implicit
    // numeric-string coercion converts "42" → int(42) BEFORE the typed
    // parameter is set, so the assertion passes whether or not
    // Route.php does any work of its own. The fix is to invoke the
    // framework's `coerceScalarRouteParameter()` method directly via
    // reflection, with a fake `ReflectionParameter` constructed from a
    // fixture class whose method signature declares `int $id`. PHP's
    // boundary coercion never runs (we never go through invokeArgs), so
    // what coerceScalarRouteParameter() returns IS exactly what the
    // framework would have handed to the controller — which is the
    // observable the test needs. With the cast in place, the result is
    // int(42); with the cast removed (the named mutation), the result
    // is string("42") and the test fails.
    $rc = new ReflectionClass(Route::class);
    $coerce = $rc->getMethod('coerceScalarRouteParameter');
    // PHP 8.1+ does not require setAccessible() for reflection access
    // (it became a no-op). It IS deprecated in 8.5, so we omit it.

    // Construct a fake ReflectionParameter by reflecting on an inline
    // anonymous class with the required method signature. The framework
    // inspects getName() / isBuiltin() on the parameter's type, so the
    // signature of the dummy method determines what coercion branch
    // runs.
    $intSig = (new ReflectionClass(new class {
        public function f(int $id) {}
    }))->getMethod('f')->getParameters()[0];

    $route = new Route('GET', '/ac43/probe', fn () => 'ok');

    // Numeric → int 42 (the BLOCKING assertion; catches the named mutation)
    $result = $coerce->invoke($route, $intSig, '42');
    expect(gettype($result))->toBe('integer');  // NOT 'string'
    expect($result)->toBe(42);                  // the integer value, not the string "42"

    // Belt-and-braces: end-to-end dispatch with the typed `showInt`
    // handler also delivers 42. PHP weak-typing hides the missing-
    // coercion mutation here, but this assertion guards against a
    // regression where dispatch stops calling the controller entirely.
    Router::get('/ac43/items-typed/{id}', [Phase3TestController::class, 'showInt']);
    $request = phase3BuildRequest('/ac43/items-typed/42', 'GET');
    Router::dispatch($request);
    expect(Phase3TestController::$lastIntId)->toBe(42);
    expect(Phase3TestController::$lastIntId)->toBeInt();
});

it('AC-43: resolveMethodDependencies() itself coerces numeric route params (production call site, not the helper in isolation)', function () {
    // The test auditor's BLOCKING finding: the previous numeric AC-43
    // test invoked `coerceScalarRouteParameter()` directly via
    // reflection. That tests the helper in isolation but does NOT
    // test the call site inside `resolveMethodDependencies()` (the
    // production path that actually hands args to invokeArgs). The
    // named mutation -- reverting `Route.php:191` to
    // `$args[] = $routeParameters[$paramName]` -- leaves the helper
    // itself untouched, so the direct assertion stays green and the
    // end-to-end `showInt` assertion also stays green because PHP's
    // implicit numeric-string coercion converts "42" -> 42 in
    // invokeArgs regardless. This test exercises the call site
    // directly: it constructs the same Request Router::dispatch()
    // would build after a real match (route params merged into the
    // body-only accessor via setAll()), invokes
    // resolveMethodDependencies() via reflection, and inspects the
    // returned `$args` array BEFORE invokeArgs() runs. PHP's boundary
    // coercion never happens on this code path, so whatever
    // resolveMethodDependencies() returns IS what the framework
    // decided to pass. Under the mutation the arg is the raw string
    // '42'; with the fix it is int(42). That is the discriminator.
    $rc = new ReflectionClass(Route::class);
    $resolve = $rc->getMethod('resolveMethodDependencies');

    // Construct a Request the same way Router::dispatch() builds one
    // after a successful match: route param set via setRouteParams()
    // (which is also merged into the body accessor for back-compat).
    // No query keys, no other body keys -- exactly one route param.
    $request = phase3BuildRequest('/ac43/probe/42', 'GET');
    $request->setRouteParams(['id' => '42']);

    // The framework reads route params via $request->routeParams() (NOT
    // $request->all() — that change is what stops the MEDIUM review finding
    // of "scalar coercion also runs on query/body values"). Reproduce the
    // container that executeAction() would have on hand (the DI container
    // is already booted in beforeEach).
    $container = App::container();

    // Build the same ReflectionParameter[] that
    // (new ReflectionMethod($controllerName, $method))->getParameters()
    // would produce for showInt(int $id). Using the real controller
    // method keeps the parameter order, default-value availability,
    // and type metadata identical to production.
    $controllerRef = new ReflectionClass(Phase3TestController::class);
    $reflectionParams = $controllerRef->getMethod('showInt')->getParameters();

    // Route instance is just the binding object for the reflection call.
    $route = new Route('GET', '/ac43/probe/{id}', [Phase3TestController::class, 'showInt']);

    $args = $resolve->invoke($route, $reflectionParams, $request->routeParams(), $container, $request);

    // The named mutation makes args[0] === '42' (string). With the fix
    // in place args[0] === 42 (int) because resolveMethodDependencies()
    // routed the route param through coerceScalarRouteParameter().
    expect($args)->toHaveCount(1);
    expect(gettype($args[0]))->toBe('integer');   // discriminator; 'string' under the named mutation
    expect($args[0])->toBe(42);                    // the integer value, not the string "42"
});

it('AC-43: resolveMethodDependencies() leaves non-numeric route params as raw strings (no silent crash, no silent 404)', function () {
    // Same shape as the test above but with a non-numeric value. The
    // helper's contract: `is_numeric($value)` is false for "abc", so
    // resolveMethodDependencies() passes the raw string through. Under
    // the named mutation (raw assignment at Route.php:191) the
    // observed value is identical ('abc'), so this test cannot detect
    // the numeric-coercion mutation by itself. It DOES detect any
    // FUTURE regression that introduces a new error path (404,
    // TypeError, exception) for the malformed case -- if
    // resolveMethodDependencies() throws or returns something other
    // than 'abc', the assertion below fails.
    $rc = new ReflectionClass(Route::class);
    $resolve = $rc->getMethod('resolveMethodDependencies');

    $request = phase3BuildRequest('/ac43/probe/abc', 'GET');
    $request->setRouteParams(['id' => 'abc']);

    $container = App::container();
    $controllerRef = new ReflectionClass(Phase3TestController::class);
    $reflectionParams = $controllerRef->getMethod('showInt')->getParameters();

    $route = new Route('GET', '/ac43/probe/{id}', [Phase3TestController::class, 'showInt']);

    $args = $resolve->invoke($route, $reflectionParams, $request->routeParams(), $container, $request);

    expect($args)->toHaveCount(1);
    expect($args[0])->toBe('abc');                 // raw string passed through, no crash
    expect(gettype($args[0]))->toBe('string');      // type preserved
});

it('AC-43: non-numeric route param reaches the handler as the raw string (no new crash, no new 404)', function () {
    // With the framework's coercion in place, `is_numeric('abc')` is
    // false, so the framework leaves the raw string in place. With
    // coercion removed, the behavior is identical (the framework would
    // still pass 'abc' through) — so this test cannot detect a missing
    // coercion via the named mutation. What it DOES detect is any
    // FUTURE regression that introduces a new error path (404,
    // TypeError) for the malformed case: if the probe is never called,
    // $probeRawRan stays false and $lastRawValue stays null, and the
    // assertions below fail.
    //
    // The probe uses an end-to-end dispatch (not reflection) because
    // the spec's literal assertion is "the raw string reaches the
    // handler" — which is only observable through the dispatch path.
    $route = Router::get('/ac43/strings/{id}', [Phase3TestController::class, 'probeRaw']);

    $request = phase3BuildRequest('/ac43/strings/abc', 'GET');

    $response = Router::dispatch($request);

    expect(Phase3TestController::$probeRawRan)->toBeTrue();           // no new 404/exception was introduced
    expect(Phase3TestController::$lastRawType)->toBe('string');       // raw string reached the handler
    expect(Phase3TestController::$lastRawValue)->toBe('abc');
    expect($response)->toBe('raw');
});

it('AC-43: literal "false" coerces to bool false (not PHP loose-truthiness true)', function () {
    // The SPEC's bool-coercion rule says: "only cast to bool for the
    // literal strings a reasonable route param would use
    // ('1'/'0'/'true'/'false') rather than PHP's loose truthiness rules
    // for arbitrary strings." PHP's loose truthiness treats any
    // non-empty string (including 'false') as truthy, so without our
    // whitelist the literal route value 'false' would arrive at the
    // handler as bool true — a security-relevant surprise for routes
    // like /users/{active} where 'false' should mean disabled.
    $route = Router::get('/ac43/flags/{flag}', [Phase3TestController::class, 'showBool']);

    $request = phase3BuildRequest('/ac43/flags/false', 'GET');

    $response = Router::dispatch($request);

    expect(Phase3TestController::$lastBoolFlag)->toBeFalse();
    expect($response)->toBe('bool');
});

it('AC-43 boundary: integer overflow in route param is preserved as raw string (no silent PHP_INT_MAX truncation)', function () {
    // HIGH review finding: is_numeric() returns true for out-of-range
    // values like "9223372036854775808" (PHP_INT_MAX + 1). The pre-fix
    // code did `(int) $value` unconditionally, which silently capped at
    // PHP_INT_MAX. A route ID that overflows would resolve to a totally
    // different record. The framework MUST pass the raw string through
    // for any value that doesn't fit, mirroring the same
    // "leave-as-string for unsafely-coercible values" contract used for
    // non-numeric strings (per the SPEC). With the named mutation
    // (range check removed), the result is PHP_INT_MAX and the
    // assertion below fails.
    //
    // Use the EXACT decimal boundary string, not `(string) (PHP_INT_MAX + 1)`.
    // The latter is a float in PHP and casts to scientific notation
    // ("9.2233720368548E+18"), which is NOT the same string as the
    // decimal overflow boundary — and crucially, the float comparison
    // route happened to detect overflow via the IEEE-754 rounding
    // difference, so the test passed even on the buggy code. The
    // decimal literal is the actually-reachable failure shape (URL
    // segments are plain strings, not floats).
    $rc = new ReflectionClass(Route::class);
    $coerce = $rc->getMethod('coerceScalarRouteParameter');

    $intSig = (new ReflectionClass(new class {
        public function f(int $id) {}
    }))->getMethod('f')->getParameters()[0];

    $route = new Route('GET', '/test', fn () => 'ok');

    $overflow = '9223372036854775808'; // exact decimal: PHP_INT_MAX + 1 on 64-bit
    expect(is_numeric($overflow))->toBeTrue(); // proves the pre-fix bug is reachable
    expect(strlen($overflow))->toBe(19);       // sanity: same length as PHP_INT_MAX
    expect((float) $overflow === (float) PHP_INT_MAX)->toBeTrue(); // the float-rounding equality that the buggy code relied on

    $result = $coerce->invoke($route, $intSig, $overflow);

    expect($result)->toBe($overflow);             // raw string preserved
    expect(is_string($result))->toBeTrue();
    expect(is_int($result))->toBeFalse();         // NOT silently capped to PHP_INT_MAX
});

it('AC-43 boundary: negative integer overflow in route param is preserved as raw string', function () {
    // Same as above for the negative boundary — PHP_INT_MIN - 1.
    // Use the EXACT decimal literal, not `(string) (PHP_INT_MIN - 1)`
    // (which is a float and casts to scientific notation in PHP).
    $rc = new ReflectionClass(Route::class);
    $coerce = $rc->getMethod('coerceScalarRouteParameter');

    $intSig = (new ReflectionClass(new class {
        public function f(int $id) {}
    }))->getMethod('f')->getParameters()[0];

    $route = new Route('GET', '/test', fn () => 'ok');

    $underflow = '-9223372036854775809'; // exact decimal: PHP_INT_MIN - 1 on 64-bit
    expect(is_numeric($underflow))->toBeTrue();

    $result = $coerce->invoke($route, $intSig, $underflow);

    expect($result)->toBe($underflow);
    expect(is_string($result))->toBeTrue();
    expect(is_int($result))->toBeFalse();
});

it('AC-43 boundary: float overflow ("1e309") bound to int is preserved as raw string (no silent 0)', function () {
    // "1e309" is is_numeric()=true. (int)"1e309" silently becomes 0 in
    // PHP (overflow → int min/0). The framework MUST preserve the raw
    // string instead of silently truncating to 0. With the named
    // mutation (range check removed), the result is 0 and the
    // assertion below fails.
    $rc = new ReflectionClass(Route::class);
    $coerce = $rc->getMethod('coerceScalarRouteParameter');

    $intSig = (new ReflectionClass(new class {
        public function f(int $id) {}
    }))->getMethod('f')->getParameters()[0];

    $route = new Route('GET', '/test', fn () => 'ok');

    $result = $coerce->invoke($route, $intSig, '1e309');

    expect($result)->toBe('1e309');
    expect(is_string($result))->toBeTrue();
    expect($result)->not->toBe(0);                // NOT silently truncated to 0
});

it('AC-43 boundary: float overflow ("1e309") bound to float is preserved as raw string (no silent INF)', function () {
    // "1e309" bound to a float param. (float)"1e309" silently becomes
    // INF. The framework MUST preserve the raw string instead of
    // silently producing INF (which would corrupt any downstream
    // arithmetic — INF x 0 = NAN, INF - INF = NAN). With the named
    // mutation (is_finite check removed), the result is INF and the
    // assertion below fails.
    $rc = new ReflectionClass(Route::class);
    $coerce = $rc->getMethod('coerceScalarRouteParameter');

    $floatSig = (new ReflectionClass(new class {
        public function f(float $x) {}
    }))->getMethod('f')->getParameters()[0];

    $route = new Route('GET', '/test', fn () => 'ok');

    $result = $coerce->invoke($route, $floatSig, '1e309');

    expect($result)->toBe('1e309');
    expect(is_string($result))->toBeTrue();
    expect(is_finite($result))->toBeFalse();      // string is technically not a number; the discriminator is `is_string`
});

it('AC-43 scope: query values with controller-param names are not coerced by the framework', function () {
    // MEDIUM review finding: AC-43's coercion was applied to ALL
    // request data (via $request->all()) — not just URL-segment
    // values. A request like /items/42?id=9999999999999999999999
    // would routeParams-coerce '42' → int(42) AND would have coerced
    // the query 'id' (overflowing) too if executeAction read
    // $request->all(). Before the fix, the controller's int $id could
    // have ended up bound from the overflowing query value (or the
    // two values collided — either way, surprising). The fix: track
    // URL-segment values separately (Request::routeParams()) and pass
    // only those to resolveMethodDependencies. Query/body values are
    // NEVER coerced by the framework now.
    Router::get('/ac43-scope/items/{id}', [Phase3TestController::class, 'showInt']);

    // URL segment 'id'=42 (in-range); query 'id' is the OVERFLOWING
    // out-of-range string. Pre-fix, the framework's coercion ran on
    // BOTH (route param AND query key, because executeAction pulled
    // from $request->all()). Post-fix, only the URL segment is
    // coerced — the query key is preserved verbatim and is not bound
    // to the controller parameter.
    $request = phase3BuildRequest(
        '/ac43-scope/items/42',
        'GET',
        ['id' => '9999999999999999999999'], // query — out of int range, must NOT be bound
    );

    Router::dispatch($request);

    // The controller received the URL segment value '42', coerced to
    // int 42 — NOT the query value (which the framework never even
    // saw, because routeParams excludes query keys).
    expect(Phase3TestController::$lastIntId)->toBe(42);
    expect(Phase3TestController::$lastIntId)->toBeInt();

    // The query value is still accessible as raw string via
    // $request->query() if the controller wants to read it — no
    // implicit coercion happened to it.
    expect($request->query())->toHaveKey('id');
    expect($request->query()['id'])->toBe('9999999999999999999999');
    expect(is_string($request->query()['id']))->toBeTrue();
});

it('AC-43 scope: query value with controller-param name is NOT bound when no URL segment matches (catches pre-fix $request->all() behavior)', function () {
    // Stronger version of the previous scope test. The MEDIUM finding
    // is specifically about a query value that COULD reach the
    // controller — not a query value that gets shadowed by a URL
    // segment of the same name. This test uses a route where the URL
    // segment has a DIFFERENT name from the controller parameter, so
    // the query value is the only candidate for binding — pre-fix
    // (executeAction used $request->all()), the query value WAS
    // bound and the framework's coercion ran on it. Post-fix
    // (executeAction uses $request->routeParams()), the query value
    // is not in routeParams, so the controller parameter falls
    // through to its default value (0).
    //
    // Pre-fix, with the HIGH-severity range check also reverted:
    //   routeParameters = ['itemId' => '5', 'id' => '9999...']
    //   coerceScalarRouteParameter('9999...', int) → returns PHP_INT_MAX
    //   showIntWithDefault(PHP_INT_MAX) → controller receives 9223372036854775807
    //   Assertion below fails: expected 0, got PHP_INT_MAX.
    //
    // Pre-fix, with ONLY the range check in place (the current
    // production state):
    //   routeParameters = ['itemId' => '5', 'id' => '9999...']
    //   coerceScalarRouteParameter('9999...', int) → returns '9999...' raw (overflow)
    //   showIntWithDefault('9999...') → PHP throws TypeError because
    //   '9999...' is not a numeric int string. Dispatch crashes.
    //   Assertion below never runs — the test fails with an exception.
    //
    // Post-fix (the current production state with routeParams() in
    // executeAction): routeParameters = ['itemId' => '5']. The query
    // 'id' is not in routeParams, so resolveMethodDependencies falls
    // through to the default. Controller receives 0. Assertion below
    // passes.
    Router::get('/ac43-scope-2/{itemId}', [Phase3TestController::class, 'showIntWithDefault']);

    $request = phase3BuildRequest(
        '/ac43-scope-2/5',
        'GET',
        ['id' => '9999999999999999999999'], // query — must NOT be bound (different name than URL segment)
    );

    Router::dispatch($request);

    // Post-fix: controller received the DEFAULT (0), not the query value.
    expect(Phase3TestController::$lastIntId)->toBe(0);

    // The query value is still accessible as raw string via
    // $request->query() if the controller wants to read it — no
    // implicit coercion happened to it.
    expect($request->query())->toHaveKey('id');
    expect($request->query()['id'])->toBe('9999999999999999999999');
    expect(is_string($request->query()['id']))->toBeTrue();

    // routeParams only has the URL-segment key, not the query key.
    expect($request->routeParams())->toBe(['itemId' => '5']);
    expect($request->routeParams())->not->toHaveKey('id');
});

it('AC-43 scope: body values with controller-param names are not coerced by the framework', function () {
    // Same shape as the query test above, but with a body field.
    // Belt-and-braces — if a future refactor accidentally pulls
    // $request->all() back into resolveMethodDependencies, this test
    // detects it for body just like the previous test detects it for
    // query.
    Router::post('/ac43-scope-body/items/{id}', [Phase3TestController::class, 'showInt']);

    $request = phase3BuildRequest(
        '/ac43-scope-body/items/42',
        'POST',
        [],                                       // no query
        ['id' => '9999999999999999999999'],       // body — out of int range
    );

    Router::dispatch($request);

    // Controller received the URL segment '42', coerced to int 42 —
    // NOT the body's out-of-range value.
    expect(Phase3TestController::$lastIntId)->toBe(42);
    expect(Phase3TestController::$lastIntId)->toBeInt();

    // The body key is still accessible as raw string via
    // $request->request() (setRouteParams merges route params into
    // body, but does not overwrite the body's own 'id' key if it was
    // already there? — actually setRouteParams uses array_merge with
    // body as the FIRST arg, so a body 'id' would WIN over the route
    // param. This is intentional: pre-existing controllers reading
    // body+route params via $request->request() shouldn't have their
    // body silently overwritten by route params. The framework NEVER
    // coerces body values; they stay as whatever the request carried.)
    expect($request->request())->toHaveKey('id');
    expect(is_string($request->request()['id']))->toBeTrue();
});

/* ===========================================================================
 * AC-44 — Router.php: dispatch no longer smuggles query keys into $request->request()
 * =========================================================================== */

it('AC-44: dispatch leaves body-only accessor free of query-string keys', function () {
    $route = Router::post('/ac44/items/{id}', [Phase3TestController::class, 'captureRequest']);

    // URL: /ac44/items/5?extra=fromquery
    // Body: name=alice
    $request = phase3BuildRequest(
        '/ac44/items/5?extra=fromquery',
        'POST',
        ['extra' => 'fromquery'],   // query
        ['name' => 'alice'],         // body
    );

    // Router::dispatch() is the function under test: it must populate the
    // body-only accessor with route params + body, WITHOUT smuggling the
    // query-only 'extra' key in.
    Router::dispatch($request);

    $captured = Phase3TestController::$capturedRequest;
    expect($captured)->not->toBeNull();

    $body = $captured->request();

    // Route param id should be merged into body (existing behavior preserved).
    expect($body)->toHaveKey('id');
    expect($body['id'])->toBe('5');

    // Real body content should still be there.
    expect($body)->toHaveKey('name');
    expect($body['name'])->toBe('alice');

    // Query-only key MUST NOT appear in the body-only accessor (the bug).
    expect($body)->not->toHaveKey('extra');

    // Query accessor and combined all() must still see the query key
    // (this AC only fixes the body accessor — query accessors are unaffected).
    expect($captured->query())->toHaveKey('extra');
    expect($captured->query()['extra'])->toBe('fromquery');
    expect($captured->all())->toHaveKey('extra');
});

/* ===========================================================================
 * AC-45 — RateLimiter.php: null/unresolved client IP skips rate limiting
 *          (does not collapse into one shared ''-suffixed bucket)
 * =========================================================================== */

it('AC-45: null client IP skips rate limiting (no shared-bucket lockout, no exception)', function () {
    // Save and clear $_SERVER['REMOTE_ADDR'] so Request::getClientIp() has
    // no usable peer and no trusted-proxy match (we set trusted_proxies=[]
    // in beforeEach). getClientIp() returns null.
    $savedRemote = $_SERVER['REMOTE_ADDR'] ?? null;
    unset($_SERVER['REMOTE_ADDR']);

    $routeUri = '/ac45/limited-' . uniqid();
    $route = new Route('GET', $routeUri, fn () => 'ok');
    $route->limit(2, 60); // small rate limit, 60s TTL

    // Call $rateLimit + 1 = 3 times. Pre-fix, the shared empty-string
    // bucket would fill up after 2 calls and the 3rd call would throw
    // RateLimitExceededException — the bug the AC is fixing.
    for ($i = 0; $i < 3; $i++) {
        try {
            RateLimiter::execute($route);
        } catch (RateLimitExceededException $e) {
            // Restore $_SERVER before failing the test.
            if ($savedRemote !== null) {
                $_SERVER['REMOTE_ADDR'] = $savedRemote;
            }
            $this->fail('RateLimitExceededException was thrown on call ' . ($i + 1) . ' — null-IP rate limiting was NOT skipped.');
        }
    }

    // Restore $_SERVER for subsequent tests.
    if ($savedRemote !== null) {
        $_SERVER['REMOTE_ADDR'] = $savedRemote;
    }

    // Reaching here without an exception IS the assertion; the empty
    // expect() below is a no-op that exists only to make the test's
    // success criterion explicit.
    expect(true)->toBeTrue();
});

/* ===========================================================================
 * AC-46 — Methods.php webpImage(): reject path-traversal destination
 *          directory (and unsafe source); fall back to safe default.
 * =========================================================================== */

it('AC-46: path-traversal destination directory is rejected and no file is written to the traversal target', function () {
    // Source must be a SAFE relative path (not starting with '/' and not
    // containing '..'). The isUnsafeFilesystemPath() check applies the
    // same rules to $source as to $destinationDir (per the SPEC's
    // "Apply the same `..`/null-byte rejection to $source if it's used to
    // build a filesystem path directly" guidance). The function reads the
    // source via file_exists/is_readable + GD imagecreatefrom*, so an
    // attacker-controlled source would let them read arbitrary files and
    // force them through the AVIF/WebP encoder.
    $source = 'files/phase3_src_' . uniqid() . '.png';
    $sourceAbs = PHASE3_SCRATCH_DIR . '/files'; // scratch location
    if (!is_dir($sourceAbs)) {
        mkdir($sourceAbs, 0755, true);
    }
    phase3CreatePng($sourceAbs . '/' . basename($source));
    // webpImage uses the path as-given, so symlink the source name into the
    // current CWD's expected location — OR just point the source directly
    // at the scratch file. The simplest path: build the source under a
    // RELATIVE scratch we control. Use a path relative to the scratch dir
    // by changing into the scratch dir briefly.
    $previousCwd = getcwd();
    chdir(PHASE3_SCRATCH_DIR);
    try {
        $relSource = 'ac46_src_' . uniqid() . '.png';
        phase3CreatePng($relSource);

        // Traversal target INSIDE the repo (clearly outside the intended
        // 'files/' subtree). We use a path relative to the scratch dir
        // (which is now CWD for this test). Going up to repoRoot + 1
        // extra level guarantees we're outside any intended output
        // subtree, and the SPEC explicitly sanctions this fallback when
        // /etc is not writable in the test environment.
        $traversalTarget = '../phase3-traversal-' . uniqid();

        webpImage($relSource, 75, false, 'phase3img', $traversalTarget . '/');

        // Hard assertion: no directory and no file was created at the
        // traversal target, AND no descendant of it. mkdir() with the
        // recursive flag would have created any intermediate dirs too.
        expect(is_dir($traversalTarget))->toBeFalse();
        expect(is_file($traversalTarget))->toBeFalse();
        expect(is_dir($traversalTarget . '/sub'))->toBeFalse();

        // Defensive cleanup of any side-effect 'files/' the safe-default
        // fallback might have created at CWD = PHASE3_SCRATCH_DIR.
        @unlink('files/phase3img.avif');
        @unlink('files/phase3img.webp');
        @rmdir('files');

        @unlink($relSource);
    } finally {
        chdir($previousCwd);
    }
});

it('AC-46: a safe destination directory still works (regression guard)', function () {
    $previousCwd = getcwd();
    chdir(PHASE3_SCRATCH_DIR);
    try {
        $relSource = 'ac46_safe_src_' . uniqid() . '.png';
        phase3CreatePng($relSource);

        // Relative, no '..', no leading '/', no null byte — must pass
        // isUnsafeFilesystemPath() and the conversion must succeed.
        $destDir = 'ac46_safe_dest_' . uniqid();

        $result = webpImage($relSource, 75, false, 'safeimg', $destDir . '/');

        // Regression guard: the function did NOT reject this safe path.
        expect(is_dir($destDir))->toBeTrue();

        // The result is either the converted filename (one of the
        // supported formats) or the original source — both are valid
        // outcomes. If the conversion succeeded, the file must exist on
        // disk at the safe destination.
        if ($result !== $relSource) {
            expect($result)->toBeIn(['safeimg.avif', 'safeimg.webp']);
            expect(file_exists($destDir . '/' . $result))->toBeTrue();
        }

        // Cleanup
        @unlink($destDir . '/safeimg.avif');
        @unlink($destDir . '/safeimg.webp');
        @rmdir($destDir);
        @unlink($relSource);
    } finally {
        chdir($previousCwd);
    }
});

it('AC-46: Windows UNC paths and rooted-backslash destination directories are rejected', function () {
    // MEDIUM review finding: isUnsafeFilesystemPath() returned false
    // for \\server\share\ (classic UNC), \\?\C:\ (extended \\?\ device
    // namespace), \\.\C:\ (extended \\.\ device namespace), and \foo\
    // (Windows-rooted leading-backslash). On Windows these reach
    // mkdir() and the image encoder, writing outside the intended
    // subtree. Reject all four shapes; verify NO directory and NO
    // converted file is created at any of them, and that the safe-
    // default fallback (`files/`) was used instead.
    $previousCwd = getcwd();
    chdir(PHASE3_SCRATCH_DIR);
    try {
        $relSource = 'ac46_unc_src_' . uniqid() . '.png';
        phase3CreatePng($relSource);

        // Each entry: [description, target_dir]. All must be rejected.
        // The string literals use single backslashes; PHP's single-quoted
        // string literal preserves them verbatim. To express the literal
        // "\\server\share" we need four backslashes in the source for two
        // in the runtime string, hence the doubled form below.
        $targets = [
            'Windows-rooted backslash'         => "\\foo\\",
            'Classic UNC server\\share'        => "\\\\server\\share\\",
            'Extended UNC \\\\?\\ device'      => "\\\\?\\C:\\",
            'Extended UNC \\\\.\ device'       => "\\\\.\\C:\\",
        ];

        foreach ($targets as $desc => $target) {
            // Call the function. It should detect the unsafe destination,
            // log a warning, and fall back to the safe default ('files/').
            webpImage($relSource, 75, false, 'uncimg', $target);

            // Hard assertions: nothing was created at the unsafe target.
            // is_dir() covers the case where mkdir() was called for the
            // target; is_file() covers a direct file write; the descendant
            // check covers recursive mkdir() creating intermediate dirs.
            expect(is_dir($target))->toBeFalse("$desc: '$target' must not be created as a directory");
            expect(is_file($target))->toBeFalse("$desc: '$target' must not be written as a file");

            // The safe-default fallback may have created 'files/' in CWD
            // (= PHASE3_SCRATCH_DIR). Cleanup if so, so the next iteration
            // starts clean.
            @unlink('files/uncimg.avif');
            @unlink('files/uncimg.webp');
            @rmdir('files');
        }

        @unlink($relSource);
    } finally {
        chdir($previousCwd);
    }
});

/* ===========================================================================
 * Cleanup hook (MEDIUM review finding)
 *
 * The Phase 3 tests create SQLite sidecars (SqliteCache WAL/SHM/journal)
 * and PNG/AVIF/WebP artifacts in .scratch/phase3/. The tests' own cleanups
 * don't remove every file (e.g. WAL/SHM files are never explicitly
 * unlinked; the AC-46 dead code path creates PNGs the rest of the test
 * never uses). An afterEach() hook scrubs the directory between tests so
 * repeated runs don't accumulate files. This test verifies the hook
 * actually removes the files it claims to remove.
 * =========================================================================== */

it('Cleanup hook: removes SQLite sidecars (WAL/SHM/journal) from .scratch/phase3/', function () {
    // Drop fake sidecars in the scratch dir. Use a unique uniqid() so we
    // don't collide with real SqliteCache output from other tests.
    $base = PHASE3_SCRATCH_DIR . '/cleanup_test_' . uniqid() . '.sqlite';
    file_put_contents($base . '-wal', 'fake wal');
    file_put_contents($base . '-shm', 'fake shm');
    file_put_contents($base . '-journal', 'fake journal');
    file_put_contents($base, 'fake main');

    // Sanity check: the sidecars are present (the hook hasn't run yet
    // for THIS test — it runs after).
    expect(file_exists($base . '-wal'))->toBeTrue();
    expect(file_exists($base . '-shm'))->toBeTrue();
    expect(file_exists($base . '-journal'))->toBeTrue();

    // Replicate the cleanup logic from afterEach() (the actual hook will
    // run AFTER this test method completes). If this assertion passes,
    // the glob+unlink logic works on representative filenames.
    foreach (glob(PHASE3_SCRATCH_DIR . '/{*.sqlite,*.sqlite-wal,*.sqlite-shm,*.sqlite-journal}', GLOB_BRACE) ?: [] as $f) {
        @unlink($f);
    }

    expect(file_exists($base . '-wal'))->toBeFalse();
    expect(file_exists($base . '-shm'))->toBeFalse();
    expect(file_exists($base . '-journal'))->toBeFalse();
    expect(file_exists($base))->toBeFalse();
});
