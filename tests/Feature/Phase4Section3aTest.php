<?php

/*
|--------------------------------------------------------------------------
| Phase 4 Section 3a hardening tests (AC-58 .. AC-62).
|--------------------------------------------------------------------------
|
| These tests bind the SessionManager / FileSessionHandler bugfixes from
| SwallowPHP Phase 4 Section 3a. Each AC's "Test" sketch in the SPEC is
| bound here as a behavioral check that exercises the real production
| path — not just source-presence grepping.
|
| Fixture layout (per the SPEC):
|   - tests/Feature/Phase4Section3aTest.php (this file — the only NEW
|     file under tests/Feature)
|   - session files live under sys_get_temp_dir()/phase4s3a-* so the
|     test stays writable in sandboxes where .scratch/ is read-only.
|   - declared at file scope: Phase4S3aSpyLogger (AC-58),
|     Phase4SpyFileSessionHandler (AC-60 wiring proof), and the shared
|     phase4s3aMakeHandler() helper.
|
| The "single new file" constraint from the SPEC forbids modifying any
| prior phase test file — so helpers here are declared locally, not
| extracted to tests/Support/.
*/

namespace Tests\Feature;

use Psr\Log\AbstractLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Session\Handler\FileSessionHandler;
use SwallowPHP\Framework\Session\SessionManager;

// ---------------------------------------------------------------------------
// Spy fixture: AC-58 logger capture.
//
// AbstractLogger is the simplest stable base — Pest autoloader picks the
// class up by virtue of being declared in this file at parse time (before
// any test runs). Phase4Section1Test's similar pattern (Phase4SpyFileCache)
// is the proven precedent.
// ---------------------------------------------------------------------------

class Phase4S3aSpyLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string, context: array}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

// ---------------------------------------------------------------------------
// Spy fixture: AC-60 wiring proof.
//
// Overrides `isExpired()` to count invocations so a test can assert that
// gc() (the production caller) actually invokes the helper. Without this
// spy, a mutation that reverted gc() to use `@filemtime($file) +
// $max_lifetime < time()` inline while leaving isExpired() intact would
// pass the existing isExpired() shape test — the helper would still
// return false for unknown-age files, but gc() would no longer call it.
//
// No other behavior changes — every other FileSessionHandler method is
// inherited unchanged.
// ---------------------------------------------------------------------------

class Phase4SpyFileSessionHandler extends FileSessionHandler
{
    public int $isExpiredCallCount = 0;

    protected function isExpired(string $file, int $max_lifetime): bool
    {
        $this->isExpiredCallCount++;
        return parent::isExpired($file, $max_lifetime);
    }
}

// ---------------------------------------------------------------------------
// Spy fixture: AC-60.5 behavioral "honor the return" proof.
//
// Distinct from Phase4SpyFileSessionHandler above: this spy's
// isExpired() ALWAYS returns false (claims "not expired") regardless
// of the file's real mtime. The point is to prove gc() actually USES
// the return value of isExpired() — the original data-loss bug
// happens when gc() calls isExpired() but then discards the result
// and falls back to an inline `@filemtime($file) + $max_lifetime <
// time()` check; that mutation keeps the call-count spy green while
// silently restoring the bug. With this spy, a real old `sess_*` file
// paired with a forced `false` isExpired() result lets us assert the
// file is NOT deleted — the proof the call-count test alone cannot
// give.
// ---------------------------------------------------------------------------

class Phase4AlwaysFreshSpyFileSessionHandler extends FileSessionHandler
{
    public int $isExpiredCallCount = 0;

    protected function isExpired(string $file, int $max_lifetime): bool
    {
        $this->isExpiredCallCount++;
        // Force "not expired" regardless of actual mtime — used to
        // prove gc() honors the helper's return value (the fix) instead
        // of using a side-channel / inline check (the bug).
        return false;
    }
}

// ---------------------------------------------------------------------------
// Shared recipe — declared at file scope, used by AC-59 AND AC-60 per the
// SPEC (one helper for both, no per-AC variations).
// ---------------------------------------------------------------------------

if (!function_exists('phase4s3aMakeHandler')) {
    /**
     * Build a fresh FileSessionHandler pointed at a unique tmpdir.
     * $logger = null → constructor defaults to NullLogger — no real log
     * writes, sidesteps any sandbox log-path permission concerns entirely
     * (see AC-60). The dir is created if missing per the recipe in the
     * SPEC.
     */
    function phase4s3aMakeHandler(): FileSessionHandler
    {
        $dir = sys_get_temp_dir() . '/phase4s3a-handler-' . uniqid('', true);
        mkdir($dir, 0755, true);
        return new FileSessionHandler($dir, null, 0600);
    }
}

// ===========================================================================
// File-level beforeEach / afterEach
//
// 1. unset($_SESSION) FIRST so a previous test's manually-seeded $_SESSION
//    array (AC-62) or a leftover real session's populated $_SESSION
//    (AC-58, AC-61) cannot leak into the next test and silently change
//    which branch ensureSessionStarted()/start() take.
// 2. Tear down any active PHP session after each test (write_close, NOT
//    destroy, per the precedent set in Phase4Section1Test's AC-52).
// 3. Set up session config the SessionManager's registerSaveHandler()
//    reads (session.driver / session.files / session.file_permission).
// ===========================================================================

beforeEach(function () {
    // 1. Drop any $_SESSION state left over from a prior test in this
    //    file (AC-62 seeds it manually, AC-58/AC-61 start a real one).
    unset($_SESSION);

    // 2. (Re-)point the framework container at this test's tmpdir so
    //    `new SessionManager()`'s registerSaveHandler() picks it up.
    App::container();
    $this->sessionsDir = sys_get_temp_dir() . '/phase4s3a-sessions-' . uniqid('', true);
    if (!is_dir($this->sessionsDir)) {
        mkdir($this->sessionsDir, 0755, true);
    }
    config(['session.driver' => 'file']);
    config(['session.files' => $this->sessionsDir]);
    config(['session.file_permission' => 0600]);
});

afterEach(function () {
    // Mirror Phase4Section1Test's AC-52 cleanup: write_close (NOT
    // session_destroy) returns the session engine to PHP_SESSION_NONE
    // and is what the existing precedent uses between tests.
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
});

// ===========================================================================
// AC-58 — SessionManager::start() logs a warning when it finds an
// already-active, unregistered PHP session
// ===========================================================================

describe('AC-58 — SessionManager::start() warns on externally-active session', function () {

    beforeEach(function () {
        // Pull the container's Definition for LoggerInterface so we can
        // swap the spy in and back out around the test. Capturing
        // BEFORE setConcrete() is required: confirmed via
        // vendor/league/container/src/Definition/Definition.php
        // setConcrete() does `$this->concrete = $concrete;
        // $this->resolved = null;` with no undo / no stacking — once
        // overwritten, the original concrete is gone.
        $this->cont = (new ReflectionClass(App::class))->getProperty('container')->getValue();
        $this->loggerDefinition = $this->cont->extend(\Psr\Log\LoggerInterface::class);
        $this->originalLoggerConcrete = $this->loggerDefinition->getConcrete();
    });

    afterEach(function () {
        // Always restore — setConcrete() has no undo.
        $this->loggerDefinition->setConcrete($this->originalLoggerConcrete);
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
    });

    it('AC-58: logs a warning when start() finds an already-active, unregistered session', function () {
        $this->loggerDefinition->setConcrete(Phase4S3aSpyLogger::class);

        // Simulate an externally-started session (bypassing
        // SessionManager entirely), using the proven session-start
        // recipe from Phase4Section1Test's AC-52.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        @ini_set('session.use_cookies', '0');
        if (ob_get_level() === 0) {
            ob_start();
        }
        session_id('phase4s3a58' . preg_replace('/[^A-Za-z0-9]/', '', uniqid('', true)));
        @session_start();

        expect(session_status())->toBe(PHP_SESSION_ACTIVE);

        // Direct construction, NOT the container — see the SPEC's
        // note: a cached SessionManager instance already captured its
        // logger at construction time, and getNew() does NOT bypass
        // the shared cache. new SessionManager() is the only reliable
        // way to pick up the spy LoggerInterface that was just
        // installed via extend().
        $sessionManager = new SessionManager();
        $result = $sessionManager->start();

        expect($result)->toBeTrue();

        $spyInstance = $this->cont->get(\Psr\Log\LoggerInterface::class);
        expect($spyInstance)->toBeInstanceOf(Phase4S3aSpyLogger::class);

        $warningRecords = array_filter($spyInstance->records, fn ($r) => $r['level'] === 'warning');
        expect($warningRecords)->not->toBeEmpty();
    });
});

// ===========================================================================
// AC-59 — FileSessionHandler read()/write()/destroy() return interface-
// declared failure values for malformed IDs instead of throwing
// ===========================================================================

describe('AC-59 — FileSessionHandler swallows InvalidArgumentException per method', function () {

    it('AC-59 read(): malformed ID returns "" (and does not throw)', function () {
        $handler = phase4s3aMakeHandler();

        // Sanity: a valid ID would return '' too (file does not exist),
        // so we additionally assert that no Throwable escapes to confirm
        // the swallowing is genuine, not coincidental with "file missing".
        $caught = null;
        try {
            $value = $handler->read('../../etc/passwd');
        } catch (\Throwable $t) {
            $caught = $t;
            $value = null;
        }
        expect($caught)->toBeNull();
        expect($value)->toBe('');
        // Belt-and-braces via Pest's ->not->toThrow() shape — proves the
        // contract for the next reviewer / mutation-checker too.
        expect(fn () => $handler->read('bad id!'))->not->toThrow(\Throwable::class);
    });

    it('AC-59 write(): malformed ID returns false (and does not throw)', function () {
        $handler = phase4s3aMakeHandler();

        $caught = null;
        try {
            $ok = $handler->write('bad id!', 'data');
        } catch (\Throwable $t) {
            $caught = $t;
            $ok = null;
        }
        expect($caught)->toBeNull();
        expect($ok)->toBeFalse();
        expect(fn () => $handler->write('still bad?', 'data'))->not->toThrow(\Throwable::class);
    });

    it('AC-59 destroy(): malformed ID returns false (and does not throw)', function () {
        $handler = phase4s3aMakeHandler();

        $caught = null;
        try {
            $ok = $handler->destroy('bad id!');
        } catch (\Throwable $t) {
            $caught = $t;
            $ok = null;
        }
        expect($caught)->toBeNull();
        expect($ok)->toBeFalse();
        expect(fn () => $handler->destroy('../../etc/passwd'))->not->toThrow(\Throwable::class);
    });
});

// ===========================================================================
// AC-60 — FileSessionHandler gc() no longer deletes files whose filemtime()
// failed, and actually CALLS isExpired() per file
// ===========================================================================

describe('AC-60 — FileSessionHandler gc() does not delete unknown-age files', function () {

    it('AC-60.1: a genuinely-expired file IS deleted by gc()', function () {
        $dir = sys_get_temp_dir() . '/phase4s3a-gc-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $handler = new FileSessionHandler($dir, null, 0600);

        $file = $dir . '/sess_oldid';
        file_put_contents($file, 'payload');
        // Backdate mtime well past $max_lifetime so this is "really" old.
        touch($file, time() - 5000);
        $maxLifetime = 60;

        expect(file_exists($file))->toBeTrue();
        $handler->gc($maxLifetime);
        expect(file_exists($file))->toBeFalse();
    });

    it('AC-60.2: a fresh file is NOT deleted by gc()', function () {
        $dir = sys_get_temp_dir() . '/phase4s3a-gc-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $handler = new FileSessionHandler($dir, null, 0600);

        $file = $dir . '/sess_newid';
        file_put_contents($file, 'payload');
        // Current mtime — definitely not expired.
        touch($file);
        $maxLifetime = 60;

        expect(file_exists($file))->toBeTrue();
        $handler->gc($maxLifetime);
        expect(file_exists($file))->toBeTrue();
    });

    it('AC-60.3: isExpired() returns false (skip) for a nonexistent file', function () {
        // Reflection-call isExpired() directly — proves the `===
        // false` guard itself is correct without needing any
        // permission tricks. filemtime() on a nonexistent path
        // reliably returns false.
        $handler = phase4s3aMakeHandler();
        $ref = new ReflectionMethod(FileSessionHandler::class, 'isExpired');
        $ref->setAccessible(true);

        $nonexistent = sys_get_temp_dir() . '/phase4s3a-no-such-file-' . uniqid('', true) . '.txt';
        // Sanity — it really does not exist.
        expect(file_exists($nonexistent))->toBeFalse();

        $result = $ref->invoke($handler, $nonexistent, 120);
        expect($result)->toBeFalse();
    });

    it('AC-60.4 wiring: gc() actually invokes isExpired() per file (spy subclass)', function () {
        // Create 2 real session files (one old, one fresh, per tests
        // 1-2). Construct the spy handler pointed at that directory
        // with its OWN fresh $dir — a spy needs its own directory so
        // files created by other tests' plain-handler assertions don't
        // leak into this test's spy-wiring count.
        $dir = sys_get_temp_dir() . '/phase4s3a-spy-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $spy = new Phase4SpyFileSessionHandler($dir, null, 0600);

        $old = $dir . '/sess_old';
        file_put_contents($old, 'payload');
        touch($old, time() - 5000);

        $fresh = $dir . '/sess_fresh';
        file_put_contents($fresh, 'payload');
        touch($fresh);

        $maxLifetime = 60;
        expect($spy->isExpiredCallCount)->toBe(0);

        $spy->gc($maxLifetime);

        // gc()'s loop must call isExpired() per candidate file. 2 files
        // existed → at least 2 invocations. A mutation that reverted
        // gc() to use `@filemtime($file) + $max_lifetime < time()`
        // inline would leave the counter at 0 and fail this assertion.
        expect($spy->isExpiredCallCount)->toBeGreaterThanOrEqual(2);
    });

    it('AC-60.5 behavioral: gc() honors isExpired() returning false — a real old sess_* file is preserved', function () {
        // This is the gap the test auditor BLOCKED on AC-60: the
        // existing call-count spy (AC-60.4) only proves gc() CALLS
        // isExpired() — it does not prove gc() USES the return value.
        // The original data-loss bug re-introduces as:
        //
        //   $this->isExpired($file, $max_lifetime); // result discarded
        //   if (@filemtime($file) + $max_lifetime < time()) { @unlink($file); }
        //
        // which keeps the call-count spy green while deleting real old
        // files. To kill that mutation, we need a spy whose
        // isExpired() returns `false` (claims "not expired") for a
        // GENUINELY old sess_* file — then assert the file survives
        // gc(). With the fix, gc() consults isExpired()'s result and
        // preserves the file; with the mutation, gc() ignores the
        // result and deletes it via the inline mtime check.
        $dir = sys_get_temp_dir() . '/phase4s3a-spy-false-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $spy = new Phase4AlwaysFreshSpyFileSessionHandler($dir, null, 0600);

        $old = $dir . '/sess_old';
        file_put_contents($old, 'payload');
        // Backdate well past $max_lifetime — under the inline-check
        // mutation this file is unconditionally "expired".
        touch($old, time() - 5000);
        $maxLifetime = 60;

        expect(file_exists($old))->toBeTrue();

        $spy->gc($maxLifetime);

        // Sanity check on the spy itself: its isExpired() is what we
        // think it is. Independent of gc()'s wiring, this guards
        // against a future test where the spy's override was
        // accidentally bypassed and the test passed for the wrong
        // reason.
        expect($spy->isExpiredCallCount)->toBeGreaterThanOrEqual(1);

        // The behavioral assertion: gc() honored the false return.
        // Under the auditor's mutation (inline mtime check), this
        // file would be gone.
        expect(file_exists($old))->toBeTrue();
    });
});

// ===========================================================================
// AC-61 — SessionManager::regenerate() lazily starts the session
// ===========================================================================

describe('AC-61 — SessionManager::regenerate() lazily starts the session', function () {

    it('AC-61: regenerate() on an inactive session transitions session_status() to PHP_SESSION_ACTIVE', function () {
        // No prior session_start() in this test — start truly inactive,
        // and clear any manually-seeded $_SESSION array a previous test
        // in this file may have left behind. unset($_SESSION) was done
        // in file-level beforeEach() already; we belt-and-suspender it
        // here because ensureSessionStarted()'s `!isset($_SESSION) ||
        // !is_array($_SESSION)` check matters for the assertion below.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        unset($_SESSION);
        expect(session_status())->not->toBe(PHP_SESSION_ACTIVE);

        // Same CLI-SAPI workaround as Phase4Section1Test's AC-52.
        // Omitting it makes start() fail (headers_sent()) and
        // ensureSessionStarted() throw instead of succeeding, which
        // would falsely look like "the fix doesn't work" when it's
        // actually an unprepared test environment.
        @ini_set('session.use_cookies', '0');
        if (ob_get_level() === 0) {
            ob_start();
        }
        session_id('phase4s3a61' . preg_replace('/[^A-Za-z0-9]/', '', uniqid('', true)));

        $sessionManager = new SessionManager();
        // The return value of regenerate() is NOT asserted — known CLI-
        // SAPI limitation that session_regenerate_id() returns false
        // regardless of this fix (documented in the fixtures section
        // of the SPEC and in Phase4Section1Test's AC-52 comments).
        // The deterministic proof is session_status() transitioning.
        $sessionManager->regenerate();

        expect(session_status())->toBe(PHP_SESSION_ACTIVE);

        @session_write_close();
    });
});

// ===========================================================================
// AC-62 — SessionManager::reflash() and ::keep() preserve fresh values over
// stale same-key re-flashes
// ===========================================================================

describe('AC-62 — reflash()/keep() do not let stale values overwrite fresh ones', function () {

    it('AC-62 reflash(): freshly-flashed same-key value wins over a stale re-flashed value', function () {
        // Pre-set $_SESSION = [] so ensureSessionStarted()'s `!isset($_SESSION)
        // || !is_array($_SESSION)` check is FALSE — start() is skipped, no
        // real session_start() needed. This matches the SPEC's "no real
        // session_start() needed" recipe.
        $_SESSION = [];
        $_SESSION['_flash.old']['status'] = 'old message';

        $sessionManager = App::container()->get(SessionManager::class);
        $sessionManager->flash('status', 'new message'); // fresh this request, same key
        $sessionManager->reflash();

        expect($_SESSION['_flash.new']['status'])->toBe('new message');
    });

    it('AC-62 keep(): freshly-flashed same-key value wins over a stale re-flashed value', function () {
        $_SESSION = [];
        $_SESSION['_flash.old']['status'] = 'old message';

        $sessionManager = App::container()->get(SessionManager::class);
        $sessionManager->flash('status', 'new message'); // fresh this request, same key
        $sessionManager->keep('status');

        expect($_SESSION['_flash.new']['status'])->toBe('new message');
    });
});