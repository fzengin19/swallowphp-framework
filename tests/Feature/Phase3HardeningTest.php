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

it('AC-43: numeric route param coerces to the declared int type', function () {
    $route = Router::get('/ac43/items/{id}', [Phase3TestController::class, 'showInt']);

    $request = phase3BuildRequest('/ac43/items/42', 'GET');

    // Router::dispatch() is what populates $request with the route
    // parameter before executing the controller method.
    Router::dispatch($request);

    // The handler declared `int $id` and received 42 — not the string '42'.
    // The coercion also widens the previously implicit numeric-string rule
    // to any is_numeric() value (e.g. "3.14" → 3 via (int)).
    expect(Phase3TestController::$lastIntId)->toBe(42);
    expect(Phase3TestController::$lastIntId)->toBeInt();
});

it('AC-43: non-numeric route param passes through as the raw string (no new crash)', function () {
    // Handler declared string $id so we can assert the raw string was
    // delivered without crashing. Pre-AC-43 this would still work for
    // string-typed params (PHP coerces strings to strings). Post-AC-43
    // it still works for the same reason; the AC explicitly widens the
    // common numeric case and does NOT introduce new error-handling for
    // the malformed case.
    $route = Router::get('/ac43/strings/{id}', [Phase3TestController::class, 'showString']);

    $request = phase3BuildRequest('/ac43/strings/abc', 'GET');

    // Router::dispatch() returns whatever the route returned. It should
    // not throw, and the controller method should have been invoked.
    $response = Router::dispatch($request);

    expect(Phase3TestController::$lastStringId)->toBe('abc');
    expect($response)->toBe('string');
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
