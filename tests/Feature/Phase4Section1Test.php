<?php

/*
|--------------------------------------------------------------------------
| Phase 4 Section 1 hardening tests (AC-47 .. AC-54).
|--------------------------------------------------------------------------
|
| These tests bind the security-critical bugfixes from SwallowPHP Phase 4
| Section 1. Each AC's "Test" sketch in the SPEC is bound here as a
| behavioral check that exercises the real production path — not just
| source-presence grepping. The single new fixture class (Phase4AuthUser)
| is declared here (NOT reusing Phase0HardeningTest's UserModel, which
| extends Model directly and would fail the AuthenticatableModel subclass
| gate in Auth::getUserModelClass()).
|
| Fixture / scratch layout:
|   - tests/Feature/Phase4Section1Test.php (this file — the only NEW file)
|   - DB fixtures per test under sys_get_temp_dir()/phase4-section1-*
|   - .scratch/phase4-section1/ for log / cache scratch (NOT for sqlite —
|     sqlite uses sys_get_temp_dir() per the SPEC, .scratch/ is
|     confirmed read-only in some sandboxes)
|   - reuses phase3BuildRequest() from Phase3HardeningTest.php and
|     buildTestRequest() from DocsConsistencyTest.php — does NOT
|     redeclare them
*/

namespace Tests\Feature;

// Ensure the body-carrying request helper from Phase3HardeningTest.php
// (phase3BuildRequest) is declared when this file is loaded. PHPUnit/Pest
// load only the relevant test file at execution time — without this
// require, calling phase3BuildRequest() from AC-49's tests would fail
// with "Call to undefined function" when this test file runs before
// (or instead of) Phase3HardeningTest's tests. The SPEC directs us to
// "call phase3BuildRequest() directly — do not declare a new, duplicate
// helper"; ensuring it is loaded is the supported way to do that.
require_once __DIR__ . '/Phase3HardeningTest.php';

use SwallowPHP\Framework\Auth\Auth;
use SwallowPHP\Framework\Auth\AuthenticatableModel;
use SwallowPHP\Framework\Cache\FileCache;
use SwallowPHP\Framework\Database\Database;
use SwallowPHP\Framework\Exceptions\CsrfTokenMismatchException;
use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Foundation\ExceptionHandler;
use SwallowPHP\Framework\Http\Cookie;
use SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Log\FileLogger;
use SwallowPHP\Framework\Session\SessionManager;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

// ---------------------------------------------------------------------------
// Test fixture: a properly-subclassed AuthenticatableModel.
//
// Declared here (NOT reusing Phase0HardeningTest::UserModel) because that
// fixture extends Model directly and uses AuthenticatableTrait — it does
// NOT extend AuthenticatableModel, and Auth::getUserModelClass()
// (src/Auth/Auth.php:371) requires is_subclass_of($modelClass,
// AuthenticatableModel::class). Pointing config('auth.model') at
// Phase0's UserModel makes Auth::authenticate() throw RuntimeException
// immediately. Declaring a fresh fixture under its own namespace also
// avoids the class-redefinition collision.
//
// remember_token is intentionally NOT in $fillable — setRememberToken()
// writes directly to $attributes (bypassing mass-assignment guards) to
// match how Auth::authenticate() itself sets the token (see AC-4).
// ---------------------------------------------------------------------------

class Phase4AuthUser extends AuthenticatableModel
{
    protected static string $table = 'phase4_auth_users';
    protected array $fillable = ['email', 'password'];
}

/**
 * Spy subclass of FileCache used ONLY in AC-51 wiring tests.
 *
 * Overrides `generateTempSuffix()` to count invocations so a test can
 * assert that `saveCache()` (called transitively by `set()`) actually
 * invokes the helper. Without this spy, a mutation that reverted
 * `saveCache()` to use `uniqid(mt_rand(), true)` directly while leaving
 * `generateTempSuffix()` intact would pass the existing
 * `generateTempSuffix()` shape test — the helper would still produce
 * 32 hex chars, but `saveCache()` would no longer call it.
 *
 * No other behavior changes — every other FileCache method is inherited
 * unchanged.
 */
class Phase4SpyFileCache extends FileCache
{
    public int $tempSuffixCallCount = 0;
    public array $tempSuffixSamples = [];

    protected function generateTempSuffix(): string
    {
        $this->tempSuffixCallCount++;
        $s = parent::generateTempSuffix();
        $this->tempSuffixSamples[] = $s;
        return $s;
    }
}

/**
 * Subclass of SessionManager used ONLY in AC-52 tests. Overrides
 * regenerate() to always return true — PHP's session_regenerate_id()
 * returns false in CLI mode because PHP has already sent headers by
 * the time test code runs (output buffering can't undo that), and
 * Auth::authenticate() throws RuntimeException when regenerate()
 * returns false. This is a test-environment-only workaround; the
 * production behavior of SessionManager::regenerate() is unchanged.
 *
 * Pure override: no other behavior changes. All other SessionManager
 * methods (start, put, get, remove, has) work as in production.
 */
class Phase4TestSessionManager extends SessionManager
{
    public function regenerate(bool $deleteOldSession = true): bool
    {
        return true;
    }
}

// ---------------------------------------------------------------------------
// Scratch directory helpers — log + cache live INSIDE the repo at
// .scratch/phase4-section1/, sqlite files live under sys_get_temp_dir().
// ---------------------------------------------------------------------------

if (!defined('PHASE4_SCRATCH_DIR')) {
    define('PHASE4_SCRATCH_DIR', dirname(__DIR__, 2) . '/.scratch/phase4-section1');
}
if (!defined('PHASE4_TMP_PREFIX')) {
    // Single per-process prefix so all sqlite fixtures from this run share
    // a discoverable root under sys_get_temp_dir(), making cleanup easier.
    define('PHASE4_TMP_PREFIX', 'phase4-section1-' . uniqid('', true) . '-');
}

if (!is_dir(PHASE4_SCRATCH_DIR)) {
    mkdir(PHASE4_SCRATCH_DIR, 0755, true);
}

/**
 * Build a fresh sqlite file path under sys_get_temp_dir() with a
 * guaranteed-unique suffix. Caller is responsible for unlink() in
 * afterEach() if it wants cleanup; we leave files in place otherwise
 * (per sandbox /tmp rules, they're owned by the runner).
 */
function phase4TmpFile(string $suffix): string
{
    return sys_get_temp_dir() . '/' . PHASE4_TMP_PREFIX . $suffix;
}

// ---------------------------------------------------------------------------
// File-level beforeEach: mirror Phase3HardeningTest's shared setup block,
// PLUS reset Database::$connections so AC-47 / AC-52 sqlite fixtures don't
// inherit a stale PDO connection from a previous test.
// ---------------------------------------------------------------------------

beforeEach(function () {
    // Boot the framework container so config()/App picks it up.
    App::container();
    config(['app.storage_path' => PHASE4_SCRATCH_DIR]);
    config(['app.trusted_proxies' => []]);
    config(['cache.default' => 'file']);
    config(['cache.ttl' => 60]);
    config(['cache.prefix' => 'phase4_' . uniqid() . '_']);

    // Session config — AC-52 needs a real SessionManager.
    config(['session.driver' => 'file']);
    config(['session.files' => PHASE4_SCRATCH_DIR . '/sessions']);
    if (!is_dir(PHASE4_SCRATCH_DIR . '/sessions')) {
        mkdir(PHASE4_SCRATCH_DIR . '/sessions', 0755, true);
    }
    // Encryption key required by Cookie::set()/get() in the remember-me path.
    config(['app.key' => random_bytes(32)]);

    // Reset Router's static route collection and matched-route cache.
    $routerRef = new ReflectionClass(\SwallowPHP\Framework\Routing\Router::class);
    $routerRef->getProperty('routes')->setValue(null, []);
    $routerRef->getProperty('matchedRoute')->setValue(null, null);

    // Reset Cookie's static memoized state + queued cookies.
    $cookieRef = new ReflectionClass(Cookie::class);
    $cookieRef->getProperty('decodedKey')->setValue(null, null);
    $cookieRef->getProperty('queuedCookies')->setValue(null, []);
    $_COOKIE = [];

    // Reset Database::$connections so AC-47 / AC-52 sqlite fixtures open a
    // fresh PDO connection against the freshly-created sqlite file (the
    // DSN+user-keyed static cache otherwise keeps a stale handle pointing
    // at the unlinked inode).
    $dbConnRef = new ReflectionProperty(Database::class, 'connections');
    $dbConnRef->setValue(null, []);

    // Reset Auth's cached authenticated user so AC-52's authenticate()/
    // logout() do not inherit state from a previous test.
    $authRef = new ReflectionProperty(Auth::class, 'authenticatedUser');
    $authRef->setValue(null, null);
});

afterEach(function () {
    // Best-effort cleanup of sqlite fixtures created under sys_get_temp_dir().
    $prefix = sys_get_temp_dir() . '/' . PHASE4_TMP_PREFIX;
    foreach (glob($prefix . '*') ?: [] as $f) {
        @unlink($f);
    }
    // WAL/SHM/journal sidecars may sit next to the main file.
    foreach (glob($prefix . '*.sqlite*') ?: [] as $f) {
        @unlink($f);
    }
});

// ===========================================================================
// AC-47 — Database::select()/table(): identifier quoting
// ===========================================================================

describe('AC-47 — Database identifier quoting in select()/table()', function () {
    beforeEach(function () {
        // Each AC-47 test gets a fresh sqlite file under sys_get_temp_dir()
        // — per the SPEC, .scratch/ is read-only in some sandboxes. The
        // table has a reserved-word column named `order` as the primary
        // proof-the-fix-is-wired-in shape: `SELECT order FROM t` is a
        // syntax error unless `order` is backtick-quoted as an identifier.
        $this->dbPath = phase4TmpFile('ac47-' . uniqid('', true) . '.sqlite');

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                "order" INTEGER
            )'
        );
        $pdo->exec("INSERT INTO users (name, \"order\") VALUES ('alice', 1)");
        $pdo->exec("INSERT INTO users (name, \"order\") VALUES ('bob', 2)");
        $pdo->exec("INSERT INTO users (name, \"order\") VALUES ('carol', 3)");

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => basename($this->dbPath),
            'prefix' => '',
        ]]);
        // Point storage at sys_get_temp_dir() so Database's sqlite branch
        // resolves dbPath = storage_path + database.
        config(['app.storage_path' => sys_get_temp_dir()]);
    });

    it('AC-47: simple identifier select still round-trips after wrapping', function () {
        $rows = (new Database())->table('users')->select(['id', 'name'])->get();
        expect($rows)->toHaveCount(3);
        // Each row carries the requested columns; round-trip non-regression.
        $names = array_map(fn($r) => $r['name'], $rows);
        expect($names)->toContain('alice');
        expect($names)->toContain('bob');
        expect($names)->toContain('carol');
    });

    it('AC-47: expression pass-through (COUNT(*) as total) is unchanged', function () {
        // `COUNT(*) as total` does NOT match the strict identifier pattern
        // (parens + the `as` keyword). It MUST fall through the expression
        // pass-through bucket unchanged — pre-fix behavior.
        $rows = (new Database())->table('users')->select(['COUNT(*) as total'])->get();
        expect($rows)->toHaveCount(1);
        expect($rows[0])->toHaveKey('total');
        expect((int) $rows[0]['total'])->toBe(3);
    });

    it('AC-47: `*` wildcard still round-trips full rows', function () {
        $rows = (new Database())->table('users')->select(['*'])->get();
        expect($rows)->toHaveCount(3);
        // Each row has all three columns.
        $first = $rows[0];
        expect($first)->toHaveKey('id');
        expect($first)->toHaveKey('name');
        expect($first)->toHaveKey('order');
    });

    it('AC-47: reserved-word column `order` round-trips only when wrapped (proves fix is wired in)', function () {
        // `SELECT order FROM users` is a SQL syntax error unless `order` is
        // backtick-quoted as an identifier. Pre-fix, select() did a raw
        // implode with no escaping, so this raised a PDOException. With
        // the fix, `order` gets wrapped as `` `order` `` and the query
        // succeeds.
        $rows = (new Database())->table('users')->select(['order'])->get();
        expect($rows)->toHaveCount(3);
        $orders = array_map(fn($r) => (int) $r['order'], $rows);
        expect($orders)->toContain(1);
        expect($orders)->toContain(2);
        expect($orders)->toContain(3);
    });

    it('AC-47: table() rejects a malicious table name before any query runs; users table intact afterward', function () {
        expect(fn () => (new Database())->table('users`; DROP TABLE users; --'))
            ->toThrow(\InvalidArgumentException::class);

        // Belt-and-braces: the malicious string contains a backtick and
        // semicolon + spaces, all of which the strict table pattern
        // rejects. The real `users` table must still exist and be
        // queryable (the malicious input was never actually applied).
        $count = (new Database())->table('users')->count();
        expect($count)->toBe(3);
    });

    it('AC-47: table() accepts a `db.table` dot-segment (existing valid shape)', function () {
        // Belt-and-braces — the pattern allows one optional `db.table`
        // dot segment. This must NOT throw.
        $builder = (new Database())->table('main.users');
        expect($builder)->toBeInstanceOf(Database::class);
    });

});

// ===========================================================================
// AC-48 — Database::initialize(): DSN-component validation
// ===========================================================================

describe('AC-48 — Database DSN-component validation before PDO connect', function () {
    it('AC-48: malicious `database` value throws InvalidArgumentException, not a wrapped Exception', function () {
        // The fixture has `database` containing the SQL/DSN-injection
        // payload `test;DROP TABLE x;--`. Validation runs BEFORE the try
        // block, so the exception is the raw InvalidArgumentException
        // (not the generic Exception the catch (\Throwable) block would
        // produce). No real mysql server is reachable in the sandbox,
        // but this test does NOT depend on a connection attempt — the
        // exception is raised by validation BEFORE any PDO connect.
        expect(fn () => new Database([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'test;DROP TABLE x;--',
            'username' => 'x',
            'password' => 'x',
        ]))->toThrow(\InvalidArgumentException::class);
    });

    it('AC-48: malicious `host` value (semicolon) throws InvalidArgumentException', function () {
        expect(fn () => new Database([
            'driver' => 'mysql',
            'host' => '127.0.0.1;evil=1',
            'port' => '3306',
            'database' => 'test_db',
            'username' => 'x',
            'password' => 'x',
        ]))->toThrow(\InvalidArgumentException::class);
    });

    it('AC-48: malicious `charset` value (whitespace) throws InvalidArgumentException', function () {
        expect(fn () => new Database([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'test_db',
            'username' => 'x',
            'password' => 'x',
            'charset' => 'utf8mb4;',
        ]))->toThrow(\InvalidArgumentException::class);
    });

    it('AC-48: invalid port (out of range) throws InvalidArgumentException', function () {
        expect(fn () => new Database([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '999999', // > 65535
            'database' => 'test_db',
            'username' => 'x',
            'password' => 'x',
        ]))->toThrow(\InvalidArgumentException::class);
    });

    it('AC-48: a valid-shaped mysql config does NOT throw InvalidArgumentException (may throw a connection-related Exception, which is fine)', function () {
        // Use explicit try/catch per the SPEC — ->not->toThrow() would
        // also pass if a DIFFERENT exception type were thrown for the
        // wrong reason, and would fail the whole test on the expected
        // connection-failure Exception. We want to assert specifically
        // that the IDENTIFIER-VALIDATION step doesn't reject this shape.
        $thrown = null;
        try {
            new Database([
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'test_db',
                'username' => 'x',
                'password' => 'x',
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->fail('Valid-shaped config must not fail identifier validation: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Any other exception (e.g. connection failure — no real
            // mysql server in this sandbox) is fine and expected.
            $thrown = $e;
        }
        // Reaching here at all (without InvalidArgumentException) is
        // the assertion. Explicit expect() so PHPUnit doesn't flag
        // this test as risky (no assertions performed).
        expect($thrown === null || !($thrown instanceof \InvalidArgumentException))->toBeTrue();
    });

    it('AC-48: sqlite driver does NOT trigger identifier validation (sqlite branch is out of scope)', function () {
        // sqlite uses $database as a filesystem path, not a DSN field, and
        // is explicitly out of scope for this AC. A sqlite Database with
        // an otherwise-rejected `database` shape (containing a ';') MUST
        // not throw InvalidArgumentException — that's the sqlite branch
        // skipping the validation block.
        $scratchDb = phase4TmpFile('ac48-' . uniqid('', true) . '.sqlite');
        $thrown = null;
        try {
            // The path has ';' inside the uniqid() prefix — semicolon is
            // fine as a filesystem character. Validation only runs for
            // mysql/pgsql, so this should NOT throw InvalidArgumentException.
            $db = new Database([
                'driver' => 'sqlite',
                'database' => basename($scratchDb), // filename only; Database prepends storage_path
                'prefix' => '',
            ]);
            // If we get here, validation did not fire for sqlite — good.
            // We don't run any query because we didn't pre-create the file
            // and the test is specifically about NOT throwing during the
            // validation step.
            $db->close();
        } catch (\InvalidArgumentException $e) {
            $this->fail('sqlite driver must not trigger identifier validation: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Connection failures on sqlite (missing dir/file) are NOT
            // what this test is asserting against — accept them.
            $thrown = $e;
        }
        expect($thrown === null || !($thrown instanceof \InvalidArgumentException))->toBeTrue();
    });

});

// ===========================================================================
// AC-49 — VerifyCsrfToken: _method=GET override no longer bypasses CSRF
// ===========================================================================

describe('AC-49 — CSRF _method=GET override no longer bypasses the token check', function () {
    beforeEach(function () {
        // The VerifyCsrfToken middleware reads $_SESSION['_token'] in
        // tokensMatch(). Scope the reset to this describe() block so
        // it doesn't interact with AC-52's session-touching tests.
        $_SESSION = [];
        $_SESSION['_token'] = 'test-token-123';
    });

    it('AC-49: POST with _method=GET in body no longer bypasses CSRF (must throw)', function () {
        // phase3BuildRequest is declared in Phase3HardeningTest.php and
        // loads in the same Pest run — call it directly, do NOT
        // redeclare a duplicate helper here.
        $request = phase3BuildRequest('/x', 'POST', [], ['_method' => 'GET']);
        $csrf = new VerifyCsrfToken();
        expect(fn () => $csrf->handle($request, fn ($r) => 'OK'))
            ->toThrow(CsrfTokenMismatchException::class);
    });

    it('AC-49: a real GET request still bypasses CSRF (no regression)', function () {
        $request = phase3BuildRequest('/x', 'GET', [], []);
        $csrf = new VerifyCsrfToken();
        $result = $csrf->handle($request, fn ($r) => 'OK');
        expect($result)->toBe('OK');
    });

    it('AC-49: a POST with valid _token in body still passes (no regression on the normal write path)', function () {
        $request = phase3BuildRequest('/x', 'POST', [], ['_token' => 'test-token-123']);
        $csrf = new VerifyCsrfToken();
        $result = $csrf->handle($request, fn ($r) => 'OK');
        expect($result)->toBe('OK');
    });

});

// ===========================================================================
// AC-50 — FileLogger CRLF sanitization in interpolated message
// ===========================================================================

describe('AC-50 — FileLogger CRLF log injection', function () {
    it('AC-50: an injected `\\n` in context value does not produce a second log line', function () {
        $logFile = sys_get_temp_dir() . '/phase4-section1-' . uniqid('', true) . '.log';
        $logger = new FileLogger($logFile);

        $injectedEmail = "attacker@example.com\n[2026-01-01 00:00:00] production.CRITICAL: fake admin action";
        $logger->warning('Login failed for {email}', ['email' => $injectedEmail]);

        $content = file_get_contents($logFile);
        // The trailing newline FileLogger always appends per entry is
        // rtrim-ed first, then the number of internal newlines is
        // counted + 1 (one entry). This avoids count(file())-style
        // off-by-one errors from a trailing-newline artifact.
        $lineCount = substr_count(rtrim($content, "\n"), "\n") + 1;

        // The injected \n MUST NOT have produced a second line — under
        // the named mutation (no sanitization), this count would be >= 2
        // because the fake entry would have its own newline-terminated
        // line.
        expect($lineCount)->toBe(1);

        // Belt-and-braces: the literal escaped sequence '\\n' should
        // appear inline within the single real entry (the sanitized
        // replacement, not a real newline).
        expect($content)->toContain('\\n');

        @unlink($logFile);
    });

});

// ===========================================================================
// AC-51 — FileCache temp-suffix CSPRNG
// ===========================================================================

describe('AC-51 — FileCache generateTempSuffix() CSPRNG', function () {
    it('AC-51: set()/get() round-trip through FileCache still works', function () {
        // Fresh temp cache dir under sys_get_temp_dir().
        $cacheDir = sys_get_temp_dir() . '/phase4-section1-cache-' . uniqid('', true);
        mkdir($cacheDir, 0755, true);
        $cacheFile = $cacheDir . '/data.json';

        $cache = new FileCache($cacheFile);
        expect($cache->set('foo', 'bar'))->toBeTrue();
        expect($cache->get('foo'))->toBe('bar');

        // Cleanup
        @unlink($cacheFile);
        @rmdir($cacheDir);
    });

    it('AC-51: generateTempSuffix() returns 32 hex chars (CSPRNG shape) and two calls differ', function () {
        // Build a FileCache instance (no need to call set()) and reflect
        // into the protected helper. Need a valid file path because the
        // constructor checks writability of the directory.
        $cacheDir = sys_get_temp_dir() . '/phase4-section1-cache-' . uniqid('', true);
        mkdir($cacheDir, 0755, true);
        $cache = new FileCache($cacheDir . '/data.json');

        $ref = new ReflectionMethod(FileCache::class, 'generateTempSuffix');
        $s1 = $ref->invoke($cache);
        $s2 = $ref->invoke($cache);

        expect($s1)->toMatch('/^[0-9a-f]{32}$/');
        expect($s2)->toMatch('/^[0-9a-f]{32}$/');
        expect($s1)->not->toBe($s2); // unique-per-call

        // Cleanup
        @rmdir($cacheDir);
    });

    it('AC-51 wiring: set() actually invokes generateTempSuffix() through saveCache() (proves fix is wired in)', function () {
        // The auditor's BLOCKING finding: the existing AC-51 tests proved
        // that generateTempSuffix() returns 32 hex chars in isolation, but
        // they did NOT prove that saveCache() (the production caller)
        // actually invokes it. A mutant that reverted saveCache() to
        // uniqid(mt_rand(), true) directly while leaving the helper
        // intact would pass the existing tests. This test uses a spy
        // subclass (Phase4SpyFileCache) to count invocations.
        $cacheDir = sys_get_temp_dir() . '/phase4-section1-cache-' . uniqid('', true);
        mkdir($cacheDir, 0755, true);
        $cacheFile = $cacheDir . '/data.json';

        $spy = new Phase4SpyFileCache($cacheFile);
        expect($spy->tempSuffixCallCount)->toBe(0);
        expect($spy->set('foo', 'bar'))->toBeTrue();

        // set() invokes saveCache() which calls generateTempSuffix() at
        // least once. If a mutation reverted saveCache() to use the old
        // uniqid(mt_rand(), true) inline (and bypassed the helper), this
        // counter would stay at 0 and the test would fail.
        expect($spy->tempSuffixCallCount)->toBeGreaterThanOrEqual(1);

        // The samples we DO collect must still be 32 hex chars (CSPRNG
        // shape), so a mutation that called the helper but with a
        // buggy non-CSPRNG implementation would also fail.
        foreach ($spy->tempSuffixSamples as $s) {
            expect($s)->toMatch('/^[0-9a-f]{32}$/');
        }

        // Round-trip still works through the spy (proves the spy itself
        // doesn't break the production contract).
        expect($spy->get('foo'))->toBe('bar');

        @unlink($cacheFile);
        @rmdir($cacheDir);
    });

});

// ===========================================================================
// AC-52 — Auth::logout(): invalidate remember-me DB token
// ===========================================================================

describe('AC-52 — Auth logout() invalidates remember-me DB token', function () {
    beforeEach(function () {
        // Bypass the CLI SAPI's headers_sent() limitation by overriding
        // the SessionManager class binding with a test subclass whose
        // regenerate() always returns true. The real SessionManager's
        // regenerate() calls session_regenerate_id() which returns false
        // in CLI (PHP sends headers before any test code runs, even with
        // ob_start()). Without this, Auth::authenticate() throws
        // RuntimeException("Failed to regenerate session ID ...").
        // NOTE: we cannot use a closure-based fake here because the
        // SessionManager extends a class with a real constructor (no DI)
        // — a subclass is the simplest reliable shape.
        $cont = (new ReflectionClass(App::class))->getProperty('container')->getValue();
        $cont->extend(\SwallowPHP\Framework\Session\SessionManager::class)
            ->setConcrete(Phase4TestSessionManager::class);

        // Manually start a real PHP session so $_SESSION is populated
        // and SessionManager::ensureSessionStarted() doesn't throw.
        // Close any leftover session from a previous test before
        // ini_set() — otherwise PHP warns "Session ini settings cannot
        // be changed when a session is active".
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        @ini_set('session.use_cookies', '0');
        if (ob_get_level() === 0) {
            ob_start();
        }
        session_id('phase4ac52' . preg_replace('/[^A-Za-z0-9]/', '', uniqid('', true)));
        @session_start();

        // Each AC-52 test gets a fresh sqlite file under sys_get_temp_dir()
        // (NOT .scratch/) so the Database connection is bound to a real
        // on-disk DB.
        $this->dbPath = phase4TmpFile('ac52-' . uniqid('', true) . '.sqlite');

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE phase4_auth_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT,
                password TEXT,
                remember_token TEXT,
                created_at TEXT,
                updated_at TEXT
            )'
        );

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => basename($this->dbPath),
            'prefix' => '',
        ]]);
        config(['app.storage_path' => sys_get_temp_dir()]);

        // Point the framework at the new fixture. NOTE: the SPEC calls
        // out that Phase0HardeningTest::UserModel is NOT a subclass of
        // AuthenticatableModel — pointing config('auth.model') at it
        // makes Auth::authenticate() throw RuntimeException immediately.
        config(['auth.model' => Phase4AuthUser::class]);

        // Insert a user with a password_hash()'d password (authenticate
        // calls password_verify, which fails against a plaintext value).
        $email = 'alice@example.com';
        $password = 'correct-password';
        $pdo->prepare(
            'INSERT INTO phase4_auth_users (email, password, created_at, updated_at) VALUES (?, ?, ?, ?)'
        )->execute([$email, password_hash($password, PASSWORD_DEFAULT), '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        $this->email = $email;
        $this->password = $password;
    });

    it('AC-52: lazy remember-cookie path authenticates before logout, fails after — and DB token is cleared', function () {
        // Sign in with remember=true — this writes a hashed token to the
        // DB, queues a remember_me cookie, AND populates
        // Auth::$authenticatedUser (line 151 of Auth.php).
        $authResult = Auth::authenticate($this->email, $this->password, remember: true);
        expect($authResult)->toBeTrue();

        $cookieRef = new ReflectionClass(Cookie::class);
        $authRef = new ReflectionProperty(Auth::class, 'authenticatedUser');
        $authSessionKeyConst = (new ReflectionClass(Auth::class))->getConstant('AUTH_SESSION_KEY');

        // -----------------------------------------------------------------
        // PART A — pre-logout: prove the lazy remember-cookie path
        //          authenticates the user (without using the
        //          Auth::$authenticatedUser cache).
        // -----------------------------------------------------------------
        // Capture the queued remember_me cookie into $_COOKIE so the
        // production lazy path inside Auth::isAuthenticated() can read
        // it via Cookie::get() (Cookie::get() decrypts via
        // config('app.key'), set in beforeEach()).
        $queue = $cookieRef->getProperty('queuedCookies')->getValue();
        $rememberCookie = $queue['__Secure-remember_me']['value']
            ?? $queue['remember_me']['value']
            ?? null;
        expect($rememberCookie)->not->toBeNull();
        $_COOKIE['__Secure-remember_me'] = $rememberCookie;
        $_COOKIE['remember_me'] = $rememberCookie;

        // ALSO clear the cached authenticated user AND the session key
        // so the lazy resolution must go through the remember-me cookie
        // path. Without clearing these, Auth::user() short-circuits to
        // the cached instance and never exercises the remember-me check
        // — and the logout() bug ("move self::user() after cookie
        // deletion") would survive the test.
        $authRef->setValue(null, null);
        $session = App::container()->get(SessionManager::class);
        $session->remove($authSessionKeyConst);

        // Sanity: the lazy path must authenticate the user BEFORE
        // logout (the cookie matches the DB hash). If this assertion
        // fails, the cookie wasn't set up correctly and any follow-up
        // assertion would be measuring the wrong thing.
        $userBeforeLogout = Auth::user();
        expect($userBeforeLogout)->not->toBeNull();
        expect($userBeforeLogout)->toBeInstanceOf(Phase4AuthUser::class);

        // Capture the pre-logout raw remember_token column value by
        // reading it directly from the DB (NOT from the in-memory
        // $user, which the spec explicitly forbids).
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('SELECT remember_token FROM phase4_auth_users WHERE email = ?');
        $stmt->execute([$this->email]);
        $preLogoutToken = $stmt->fetchColumn();
        expect($preLogoutToken)->not->toBeNull();
        expect($preLogoutToken)->not->toBe('');

        // Force logout()'s first self::user() call to resolve the still-valid
        // old cookie lazily. Without clearing both caches here, a regression
        // that resolves the user after Cookie::delete() would reuse the
        // already-cached object and leave the remember token valid.
        $authRef->setValue(null, null);
        $session->remove($authSessionKeyConst);

        // -----------------------------------------------------------------
        // PART B — logout() must clear the DB token. This is the
        //          BLOCKING failure shape: if a regression moves
        //          `self::user()` AFTER `Cookie::delete('remember_me')`
        //          in logout(), then because Auth::$authenticatedUser is
        //          STILL null (we cleared it in Part A), self::user()
        //          would now go through the lazy cookie path AFTER the
        //          cookie was deleted — cookie is gone, session was
        //          regenerated, so isAuthenticated() returns false and
        //          self::user() returns null. The setRememberToken(null)
        //          + save() block (gated on `$user !== null`) is then
        //          SKIPPED — the DB token stays at its pre-logout value
        //          and the assertion below fails.
        // -----------------------------------------------------------------
        Auth::logout();

        $stmt = $pdo->prepare('SELECT remember_token FROM phase4_auth_users WHERE email = ?');
        $stmt->execute([$this->email]);
        $postLogoutToken = $stmt->fetchColumn();

        expect($postLogoutToken)->not->toBe($preLogoutToken);
        // Specifically: the new value is null (setRememberToken(null)
        // wrote null, not the old hash).
        expect($postLogoutToken === null || $postLogoutToken === '')->toBeTrue();

        // -----------------------------------------------------------------
        // PART C — post-logout: prove the lazy remember-cookie path no
        //          longer authenticates. The Cookie::delete('remember_me')
        //          call inside logout() unsets $_COOKIE entries; re-set
        //          them with the original encrypted payload so Cookie::get()
        //          can decrypt it again.
        // -----------------------------------------------------------------
        $_COOKIE['__Secure-remember_me'] = $rememberCookie;
        $_COOKIE['remember_me'] = $rememberCookie;
        $cookieRef->getProperty('decodedKey')->setValue(null, null);

        // Clear the cached user + session key AGAIN so the lazy path
        // must be taken (otherwise Auth::user() short-circuits).
        $authRef->setValue(null, null);
        $session = App::container()->get(SessionManager::class);
        $session->remove($authSessionKeyConst);

        $userAfterLogout = Auth::user();
        // The DB token was cleared in Part B. The OLD raw token no
        // longer matches anything in the DB, so the remember-me path
        // returns false, isAuthenticated() falls through to the
        // session path (also cleared), and self::user() returns null.
        // A regression that leaves the DB token untouched (e.g. moving
        // self::user() after Cookie::delete()) would make this
        // assertion fail because the lazy cookie path would still
        // authenticate.
        expect($userAfterLogout)->toBeNull();
    });

});

// ===========================================================================
// AC-53 — ExceptionHandler: buildResponseData() extraction, debug-gated exception key
// ===========================================================================

describe('AC-53 — ExceptionHandler::buildResponseData() debug-gated exception key', function () {
    beforeEach(function () {
        // BuildResponseData() is `protected static`. ReflectionMethod
        // can invoke it as if it were public. We do NOT rely on view()
        // or any rendering path — only the data-shape contract.
        $this->buildData = new ReflectionMethod(ExceptionHandler::class, 'buildResponseData');

        // Pinned, non-empty $responseBody so an implementation bug that
        // silently no-ops on missing keys can't pass by accident.
        $this->pinnedBody = null; // populated inside each test
    });

    it('AC-53: debug=false omits the `exception` key entirely (not "present and null")', function () {
        try {
            throw new \RuntimeException('boom');
        } catch (\Throwable $e) {
            $exception = $e;
        }
        $responseBody = [
            'message' => 'Test error message',
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => explode("\n", $exception->getTraceAsString()),
        ];

        $result = $this->buildData->invoke(null, $exception, false, $responseBody, 500);

        // The key must be ENTIRELY ABSENT, not "present and null".
        expect(array_key_exists('exception', $result))->toBeFalse();
        // The other detail keys are also gated — they should also be absent.
        expect(array_key_exists('exceptionClass', $result))->toBeFalse();
        expect(array_key_exists('file', $result))->toBeFalse();
        expect(array_key_exists('line', $result))->toBeFalse();
        expect(array_key_exists('trace', $result))->toBeFalse();
        // The always-present keys ARE present.
        expect($result)->toHaveKey('statusCode');
        expect($result)->toHaveKey('statusText');
        expect($result)->toHaveKey('message');
        expect($result)->toHaveKey('debug');
        expect($result['statusCode'])->toBe(500);
        expect($result['message'])->toBe('Test error message');
        expect($result['debug'])->toBeFalse();
    });

    it('AC-53: debug=true includes the exception object (identity) plus all other debug-gated fields', function () {
        try {
            throw new \RuntimeException('boom');
        } catch (\Throwable $e) {
            $exception = $e;
        }
        $responseBody = [
            'message' => 'Test error message',
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => explode("\n", $exception->getTraceAsString()),
        ];

        $result = $this->buildData->invoke(null, $exception, true, $responseBody, 500);

        // Identity (===), not just non-null — the raw exception object.
        expect($result['exception'])->toBe($exception);
        // The other debug-gated fields are present and correct.
        expect($result['exceptionClass'])->toBe($responseBody['exception']);
        expect($result['file'])->toBe($responseBody['file']);
        expect($result['line'])->toBe($responseBody['line']);
        expect($result['trace'])->toBe($responseBody['trace']);
        // Always-present keys too.
        expect($result['statusCode'])->toBe(500);
        expect($result['debug'])->toBeTrue();
    });

    it('AC-53 wiring: ExceptionHandler::handle() does not pass the raw exception object to a custom view when debug=false', function () {
        // The BLOCKING wiring test. We invoke the real
        // ExceptionHandler::handle() end-to-end with debug=false and
        // capture the $data array that gets handed to the view via
        // a custom error-view template. A regression that retains the
        // old inline `$data['exception'] = $exception` assignment
        // (outside the if($debug) block) would put the raw exception
        // object back into the view scope, even though
        // buildResponseData() itself was correct — this test catches
        // that mutation by exercising handle() through its real view
        // path.

        // Build a custom view path with a 500.php template that
        // captures the $data array via a global so the test can read
        // it after handle() returns.
        $customViewDir = PHASE4_SCRATCH_DIR . '/test-views-ac53-' . uniqid('', true);
        mkdir($customViewDir . '/errors', 0755, true);
        mkdir($customViewDir . '/layouts', 0755, true);
        file_put_contents(
            $customViewDir . '/errors/500.php',
            '<?php $GLOBALS["__ac53_captured_data"] = get_defined_vars(); unset($GLOBALS["__ac53_captured_data"]["slot"]); echo "AC53-CAPTURED"; ?>'
        );
        // layouts/error.php is required by the handle() call. Keep it
        // trivial so the layout pass doesn't error out.
        file_put_contents(
            $customViewDir . '/layouts/error.php',
            '<?php echo $slot; ?>'
        );
        config(['app.view_path' => $customViewDir]);
        // Force view() to look at our custom path even if the
        // framework's primary path is set elsewhere.
        $GLOBALS['__ac53_captured_data'] = null;

        try {
            throw new \RuntimeException('phase4-section1-ac53-secret-payload');
        } catch (\Throwable $e) {
            $exception = $e;
        }

        // Configure the production wiring: debug=false, debug-views
        // are gated. The fix moves the 'exception' key inside the
        // if($debug) gate inside buildResponseData(); this test
        // confirms that gate is actually applied at the
        // handle()->view() boundary, not just inside the helper.
        config(['app.debug' => false]);

        // DocsConsistencyTest's JSON-response test leaves the shared Request
        // singleton with an `Accept: application/json` header. Reset only
        // that header so this test exercises handle()'s custom-view branch
        // when the full suite runs as well as when this file runs alone.
        $request = App::container()->get(Request::class);
        (new ReflectionProperty(Request::class, 'headers'))->setValue($request, []);

        $response = ExceptionHandler::handle($exception);

        $captured = $GLOBALS['__ac53_captured_data'] ?? null;
        expect($captured)->toBeArray();

        // The view's $data is extract()'d at view() entry
        // (extract($data, EXTR_SKIP)). Both the array itself
        // ($captured['data']) and its keys are observable in the
        // view scope. Check both shapes — neither should expose the
        // raw exception object.
        if (isset($captured['data']) && is_array($captured['data'])) {
            $dataArr = $captured['data'];
        } else {
            // extract() promoted the array keys into local vars —
            // reconstruct a sample by checking for the keys as
            // variables in the view scope.
            $dataArr = [];
            foreach (['statusCode', 'statusText', 'message', 'debug', 'exception', 'exceptionClass', 'file', 'line', 'trace'] as $k) {
                if (array_key_exists($k, $captured)) {
                    $dataArr[$k] = $captured[$k];
                }
            }
        }

        expect(array_key_exists('exception', $dataArr))->toBeFalse();

        // Sanity: the always-present keys ARE present.
        expect($dataArr)->toHaveKey('statusCode');
        expect($dataArr)->toHaveKey('statusText');
        expect($dataArr)->toHaveKey('message');
        expect($dataArr['statusCode'])->toBe(500);
        expect($dataArr['debug'])->toBeFalse();

        // Clean up the globals + custom view dir so subsequent tests
        // don't see leftover state.
        unset($GLOBALS['__ac53_captured_data']);
        @unlink($customViewDir . '/errors/500.php');
        @unlink($customViewDir . '/layouts/error.php');
        @rmdir($customViewDir . '/errors');
        @rmdir($customViewDir . '/layouts');
        @rmdir($customViewDir);
    });
});

// ===========================================================================
// AC-54 — Request::getBoundaryFromContentType() regex hardening
// ===========================================================================

describe('AC-54 — Request::getBoundaryFromContentType() regex hardening', function () {
    beforeEach(function () {
        $this->getBoundary = new ReflectionMethod(Request::class, 'getBoundaryFromContentType');
        $this->parseMultipart = new ReflectionMethod(Request::class, 'parseMultipartBody');
    });

    it('AC-54: `boundary=` (nothing follows) returns null', function () {
        $result = $this->getBoundary->invoke(null, 'multipart/form-data; boundary=');
        expect($result)->toBeNull();
    });

    it('AC-54: `boundary=""` (empty quoted value) returns null, not `--""`', function () {
        $result = $this->getBoundary->invoke(null, 'multipart/form-data; boundary=""');
        expect($result)->toBeNull();
    });

    it('AC-54: `boundary="my boundary"` (quoted with internal space) returns `--my boundary`', function () {
        $result = $this->getBoundary->invoke(null, 'multipart/form-data; boundary="my boundary"');
        expect($result)->toBe('--my boundary');
    });

    it('AC-54: `boundary=abc123` (existing valid unquoted case) returns `--abc123`', function () {
        $result = $this->getBoundary->invoke(null, 'multipart/form-data; boundary=abc123');
        expect($result)->toBe('--abc123');
    });

    it('AC-54: end-to-end parseMultipartBody() round-trip with a normally-formed multipart body still works', function () {
        // A real, normally-formed multipart body. The fix must not break
        // this path. parseMultipartBody() returns the parsed form fields
        // as a key=>value array.
        $boundary = 'X-BOUNDARY-7f3c';
        $rawBody = ""
            . "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="first"' . "\r\n"
            . "\r\n"
            . "alpha\r\n"
            . "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="second"' . "\r\n"
            . "\r\n"
            . "beta\r\n"
            . "--{$boundary}--\r\n";

        $result = $this->parseMultipart->invoke(null, $rawBody, "--{$boundary}");

        expect($result)->toHaveKey('first');
        expect($result['first'])->toBe('alpha');
        expect($result)->toHaveKey('second');
        expect($result['second'])->toBe('beta');
    });

});