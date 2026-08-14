<?php

/*
|--------------------------------------------------------------------------
| Phase 4 Section 3b hardening tests (AC-64 .. AC-66).
|--------------------------------------------------------------------------
|
| These tests bind the Foundation correctness bugfixes from SwallowPHP
| Phase 4 Section 3b. Each AC's "Test" sketch in the SPEC is bound here as
| a behavioral check that exercises the real production path — not just
| source-presence grepping.
|
| Fixture / scratch layout:
|   - tests/Feature/Phase4Section3bTest.php (this file — the only NEW
|     file under tests/Feature; no helpers extracted because no function
|     in here is shared with other test files)
|   - .env fixtures per Env test under sys_get_temp_dir()/phase4s3b-env-*
|     (NOT .scratch/ — .scratch/ is read-only in some sandboxes; SPEC
|     baseline is the same as Phase4Section1Test/Phase4Section2Test).
|   - Config tests use `new Config()` against the framework's real
|     default config directory; ad-hoc top-level keys are namespaced
|     `phase4s3btest` / `phase4s3btest2` so they never collide with
|     a real config namespace.
*/

namespace Tests\Feature;

use SwallowPHP\Framework\Foundation\Config;
use SwallowPHP\Framework\Foundation\Env;

/*
|--------------------------------------------------------------------------
| File-level beforeEach / afterEach
|--------------------------------------------------------------------------
|
| The Env tests mutate the static `Env::$basePath` and the process-wide
| `$_ENV`/`$_SERVER`/`getenv()` namespaces. Both must be restored between
| tests or they leak: a stale base path would make later Env tests (and
| the rest of the suite) load .env from a tmp dir that may have been
| deleted; a leftover $_ENV entry would shadow the real repo .env; an
| unset key from the original snapshot would silently disappear.
|
| We capture the real repo base path at file load time and restore it in
| afterEach. Real repo root = dirname(__DIR__, 2) from this file's own
| location — matches Env::getBasePath()'s default (it does
| `dirname(__DIR__, 5)` from its own file, which lands at the same
| repo root when this file is at tests/Feature/).
|
| Important: the snapshot captures FULL VALUES (and the full getenv()
| map) on the first beforeEach, not just keys. A test that overrides an
| existing $_SERVER['BASE_PATH'] value (or unsets a pre-existing key)
| must have the original value put back — a key-only diff misses that
| case by construction. Same for getenv(): a test that putenv()s a
| pre-existing key must have the original value restored.
*/

if (!defined('PHASE4_S3B_TMP_PREFIX')) {
    define('PHASE4_S3B_TMP_PREFIX', 'phase4s3b-env-' . uniqid('', true) . '-');
}

beforeEach(function () {
    // Capture real repo base path so afterEach can always restore it,
    // even if a previous test crashed mid-run before its own restore.
    if (!isset($this->phase4s3bOriginalBasePath)) {
        $this->phase4s3bOriginalBasePath = Env::getBasePath();
    }

    // Snapshot $_ENV / $_SERVER / getenv() once per process (first
    // test only) — including full values, not just keys. Keys-only
    // would miss the case where a test overwrites or unsets a
    // pre-existing key.
    if (!isset($this->phase4s3bOriginalEnv)) {
        $this->phase4s3bOriginalEnv = [];
        foreach ($_ENV as $k => $v) {
            $this->phase4s3bOriginalEnv[$k] = $v;
        }
        $this->phase4s3bOriginalServer = [];
        foreach ($_SERVER as $k => $v) {
            $this->phase4s3bOriginalServer[$k] = $v;
        }
        // getenv() with no args returns the full process env map.
        // We snapshot every key so a test that putenv()s any of them
        // gets the original restored (otherwise putenv() is one-way
        // from the perspective of a later test reading getenv()).
        $this->phase4s3bOriginalGetenv = getenv() ?: [];
    }
});

afterEach(function () {
    // 1. Remove any temp .env dir created during this test.
    $prefix = sys_get_temp_dir() . '/' . PHASE4_S3B_TMP_PREFIX;
    foreach (glob($prefix . '*') ?: [] as $f) {
        if (is_dir($f)) {
            // Best-effort rmdir of empty dir; recursive since some sandboxes
            // may have left the .env file inside.
            @unlink($f . '/.env');
            @rmdir($f);
        } else {
            @unlink($f);
        }
    }

    // 2. Restore Env::$basePath to the captured original. Use the
    //    captured value (NOT dirname(__DIR__, 2)) because the spec's
    //    recommendation is "what Env::getBasePath() returned before
    //    the test mutated it" — that's the exact "original" the spec
    //    wants restored.
    if (isset($this->phase4s3bOriginalBasePath)) {
        Env::setBasePath($this->phase4s3bOriginalBasePath);
    }

    // 3a. Restore $_ENV values for every originally-snapshotted key
    //     (catches "test overwrote or unset an existing key") and
    //     remove any test-introduced new keys. We restore first, then
    //     diff — diff-after-restore only sees keys truly added by the
    //     test (the originals were just put back to their values).
    $originalEnv = $this->phase4s3bOriginalEnv ?? [];
    foreach ($originalEnv as $k => $v) {
        $_ENV[$k] = $v;
    }
    foreach (array_diff(array_keys($_ENV), array_keys($originalEnv)) as $k) {
        unset($_ENV[$k]);
        putenv($k); // putenv() with a single arg unsets the var.
    }

    // 3b. Same for $_SERVER — restore every original, then drop new ones.
    $originalServer = $this->phase4s3bOriginalServer ?? [];
    foreach ($originalServer as $k => $v) {
        $_SERVER[$k] = $v;
    }
    foreach (array_diff(array_keys($_SERVER), array_keys($originalServer)) as $k) {
        unset($_SERVER[$k]);
    }

    // 3c. getenv() restoration. For every key in the original getenv()
    //     snapshot, putenv() it back to its original value. Then for
    //     any keys currently in getenv() but NOT in the original
    //     snapshot (test-introduced), putenv() them with no value to
    //     unset.
    $originalGetenv = $this->phase4s3bOriginalGetenv ?? [];
    foreach ($originalGetenv as $k => $v) {
        putenv("$k=$v");
    }
    $currentGetenv = getenv() ?: [];
    foreach (array_diff(array_keys($currentGetenv), array_keys($originalGetenv)) as $k) {
        putenv($k);
    }
});

/**
|--------------------------------------------------------------------------
| Cleanup helper (file-scoped)
|--------------------------------------------------------------------------
|
| Same logic as the afterEach above, callable directly so the dedicated
| cleanup tests at the bottom of this file can observe its effects
| (Pest's afterEach runs AFTER the test body has finished asserting, so
| you cannot observe afterEach's effects from inside the same test).
|
| The pre-review implementation only diffed KEYS — test-introduced keys
| were removed, but pre-existing keys whose values were overwritten or
| removed by a test were silently NOT restored. This helper restores the
| full value of every original key (so a test that unsets / overwrites an
| existing key has it put back) and still unsets every test-introduced
| new key.
*/

if (!function_exists('phase4s3b_cleanup_restore')) {
    function phase4s3b_cleanup_restore(
        array $originalEnv,
        array $originalServer,
        array $originalGetenv
    ): void {
        foreach ($originalEnv as $k => $v) {
            $_ENV[$k] = $v;
        }
        foreach (array_diff(array_keys($_ENV), array_keys($originalEnv)) as $k) {
            unset($_ENV[$k]);
            putenv($k);
        }
        foreach ($originalServer as $k => $v) {
            $_SERVER[$k] = $v;
        }
        foreach (array_diff(array_keys($_SERVER), array_keys($originalServer)) as $k) {
            unset($_SERVER[$k]);
        }
        foreach ($originalGetenv as $k => $v) {
            putenv("$k=$v");
        }
        $currentGetenv = getenv() ?: [];
        foreach (array_diff(array_keys($currentGetenv), array_keys($originalGetenv)) as $k) {
            putenv($k);
        }
    }
}

/* ===========================================================================
 * AC-64 — Env::load() skips malformed lines instead of aborting the loop
 * ===========================================================================
 *
 * Pre-fix: `putenv("=orphan")` throws \ValueError on PHP 8+; since this is
 * inside the foreach with no try/catch, the exception propagates and every
 * .env line after the malformed one silently never loads.
 *
 * Post-fix: a `=value` line (empty name) is logged via error_log() and
 * `continue`d; lines before AND after it still load. Additionally, a name
 * containing internal whitespace (e.g. "BAD NAME=val") is also rejected —
 * trim() only strips leading/trailing whitespace, so such a name survives
 * trim as "BAD NAME" and is a malformed env var by POSIX convention even
 * though PHP's putenv() happens to accept it silently. The SPEC explicitly
 * names this as a defensive requirement ("doesn't itself contain `=` or
 * whitespace — already implied by explode"; the latter half is in fact not
 * true, only leading/trailing is stripped, hence the explicit guard).
 *
 * The named mutation for this AC is "remove the malformed-name guard" —
 * the AC-64.1/AC-64.2 tests must fail with an uncaught \ValueError
 * (\ValueError propagates from Env::load()), and AC-64.3 must fail with a
 * successful load that pollutes $_ENV/getenv() with the bad key.
 */

describe('AC-64 — Env::load() skips malformed lines instead of aborting the loop', function () {

    it('AC-64.1: lines BEFORE and AFTER a malformed `=value` line still load', function () {
        $dir = sys_get_temp_dir() . '/' . PHASE4_S3B_TMP_PREFIX . 'ac64-' . uniqid('', true);
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.env', "BEFORE_KEY=before_value\n=orphan_value\nAFTER_KEY=after_value\n");

        Env::setBasePath($dir);
        Env::load();

        // Both assertions must hold. BEFORE_KEY proves the loop was alive
        // when the malformed line was hit (the malformed line is the 2nd
        // of 3); AFTER_KEY proves the loop did NOT abort on the malformed
        // line — it continued and processed the 3rd line.
        expect(Env::get('BEFORE_KEY'))->toBe('before_value');
        expect(Env::get('AFTER_KEY'))->toBe('after_value');
    });

    it('AC-64.2: malformed line does NOT crash Env::load() with a Throwable', function () {
        // Direct throw-surfacing assertion: Env::load() must complete
        // normally. We use explicit try/catch rather than
        // `not->toThrow(\Throwable::class)` because Pest 4's `toThrow`
        // treats interfaces (Throwable IS an interface, not a class)
        // via assertStringContainsString against the exception message
        // — that's not what we want. try/catch is unambiguous: any
        // throw from Env::load() fails the test.
        $dir = sys_get_temp_dir() . '/' . PHASE4_S3B_TMP_PREFIX . 'ac64-' . uniqid('', true);
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.env', "=only_malformed\n");

        Env::setBasePath($dir);

        $threw = false;
        try {
            Env::load();
        } catch (\Throwable $t) {
            $threw = true;
        }
        expect($threw)->toBeFalse();
    });

    it('AC-64.3: a name containing INTERNAL whitespace is rejected, surrounding lines still load', function () {
        // The SPEC's "doesn't itself contain whitespace" rule: trim()
        // only strips leading/trailing whitespace, so "BAD NAME=val"
        // (or "TAB\tNAME=val") survives trim as a key that contains a
        // space. PHP's putenv() happens to accept it silently — but the
        // spec calls this out as a malformed name to reject defensively.
        // We test: the bad key is NOT in $_ENV / getenv() (proves the
        // guard fired), AND the lines before/after it still load (proves
        // the loop did not abort).
        $dir = sys_get_temp_dir() . '/' . PHASE4_S3B_TMP_PREFIX . 'ac64-' . uniqid('', true);
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.env', "PRE_WS=pre_val\nBAD NAME=ws_val\nPOST_WS=post_val\n");

        Env::setBasePath($dir);
        Env::load();

        // Lines before and after the bad one still load.
        expect(Env::get('PRE_WS'))->toBe('pre_val');
        expect(Env::get('POST_WS'))->toBe('post_val');

        // The bad-name key is rejected — Env::get() returns the
        // default (null), it is NOT in $_ENV, and getenv() returns false
        // (the framework's getenv() contract is `false` for missing,
        // not null — a meaningful distinction from putenv()).
        expect(Env::get('BAD NAME'))->toBeNull();
        expect(isset($_ENV['BAD NAME']))->toBeFalse();
        expect(getenv('BAD NAME'))->toBeFalse();
    });
});

/* ===========================================================================
 * AC-65 — Env::load() no longer overwrites $_SERVER with .env values
 * ===========================================================================
 *
 * Pre-fix: `$_SERVER[$name] = $value;` for every loaded var lets a .env
 * entry overwrite request-supplied keys (HTTP_HOST, REQUEST_URI, etc.).
 * The $_SERVER mirror is unnecessary because Env::get() reads $_ENV
 * FIRST and load() already populates $_ENV.
 *
 * Post-fix: only $_ENV and putenv() are populated; $_SERVER is left alone.
 *
 * The named mutation for this AC is "restore the
 * $_SERVER[$name] = $value; line" — the first assertion must fail
 * (the test's $_SERVER seed gets overwritten with the .env value).
 */

describe('AC-65 — Env::load() no longer overrides $_SERVER values', function () {

    it('AC-65.1: a $_SERVER seed survives .env load with a colliding key', function () {
        $_SERVER['PHASE4S3B_HOST_TEST'] = 'real-request-value';

        $dir = sys_get_temp_dir() . '/' . PHASE4_S3B_TMP_PREFIX . 'ac65-' . uniqid('', true);
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.env', "PHASE4S3B_HOST_TEST=env-file-value\n");

        Env::setBasePath($dir);
        Env::load();

        // $_SERVER must still hold the real-request value — .env must
        // NOT have overwritten it.
        expect($_SERVER['PHASE4S3B_HOST_TEST'])->toBe('real-request-value');

        // Env::get() still returns the .env value, but via $_ENV (NOT
        // $_SERVER) — load() still populates $_ENV.
        expect(Env::get('PHASE4S3B_HOST_TEST'))->toBe('env-file-value');
    });

    it('AC-65.2: BASE_PATH is also NOT written to $_SERVER by load()', function () {
        // Pre-fix, load() did `$_SERVER['BASE_PATH'] = $base;` after the
        // loop. Post-fix, $_SERVER['BASE_PATH'] (if it exists at all) is
        // not touched by load().
        unset($_SERVER['BASE_PATH']);

        $dir = sys_get_temp_dir() . '/' . PHASE4_S3B_TMP_PREFIX . 'ac65-' . uniqid('', true);
        mkdir($dir, 0755, true);
        // Empty .env — we only care about the BASE_PATH side-effect,
        // not any user-supplied keys.
        file_put_contents($dir . '/.env', "");

        Env::setBasePath($dir);
        Env::load();

        expect($_SERVER['BASE_PATH'] ?? null)->toBeNull();
        // Sanity: Env::get('BASE_PATH') still works via $_ENV.
        expect(Env::get('BASE_PATH'))->toBe($dir);
    });
});

/* ===========================================================================
 * AC-66 — Config::set() throws when dot-traversal hits an existing scalar
 * ===========================================================================
 *
 * Pre-fix: set('a.b', v) walks segments; if an intermediate segment
 * already holds a SCALAR, the `!isset(...) || !is_array(...)` check is
 * true, and the scalar is silently overwritten with []. The original
 * value is destroyed.
 *
 * Post-fix: when an intermediate segment is set AND non-array, throw
 * \RuntimeException. An unset segment still initializes to [] silently
 * (so a genuinely-new nested key still works).
 *
 * The named mutation for this AC is "revert to the unconditional
 * overwrite" — the `->toThrow(...)` assertion must fail and the
 * "original value survives" assertion must fail.
 */

describe('AC-66 — Config::set() throws when dot-traversal hits an existing scalar', function () {

    it('AC-66.1: set() on a sub-key whose parent is a scalar throws and preserves the scalar', function () {
        $config = new Config();
        $config->set('phase4s3btest', 'scalar-value');
        expect($config->get('phase4s3btest'))->toBe('scalar-value');

        // Pre-fix: silently turns phase4s3btest into ['nested' => 'x']
        // and the original scalar is gone. Post-fix: throws and the
        // original scalar survives.
        expect(fn () => $config->set('phase4s3btest.nested', 'x'))
            ->toThrow(\RuntimeException::class);

        expect($config->get('phase4s3btest'))->toBe('scalar-value');
    });

    it('AC-66.2: regression — a genuinely-new nested key still works', function () {
        $config = new Config();
        $config->set('phase4s3btest2.nested', 'ok');
        expect($config->get('phase4s3btest2.nested'))->toBe('ok');
    });

    it('AC-66.3: an UNSET intermediate segment still initializes to [] silently', function () {
        // The guard must only fire when the segment ALREADY holds a
        // non-array value; an unset segment must still silently become
        // an empty array (this is the "existing behavior preserved for
        // the non-bug case" half of the fix).
        $config = new Config();
        $config->set('phase4s3btest3.nested', 'first');
        expect($config->get('phase4s3btest3.nested'))->toBe('first');

        // Adding a second level under the same parent must still work
        // (the existing array IS an array, so the guard does not fire).
        $config->set('phase4s3btest3.deeper', 'second');
        expect($config->get('phase4s3btest3.deeper'))->toBe('second');
    });
});

/* ===========================================================================
 * Cleanup tests — binding for the test-fixture bug found by review
 * ===========================================================================
 *
 * The test file's afterEach hook is the production cleanup path: it must
 * fully restore $_ENV, $_SERVER, and getenv() to the state they were in
 * before the test ran. The pre-review implementation only diffed KEYS
 * (test-introduced keys were removed, but values of pre-existing keys
 * were NOT put back when a test overwrote or unset them). The cleanup
 * logic is now extracted to a file-scoped helper
 * `phase4s3b_cleanup_restore`, and the tests below bind both directions
 * of the regression — not just "new keys get removed" (the trivial
 * part), but "values for pre-existing keys get restored" (the part the
 * keys-only diff missed).
 *
 * The tests invoke `phase4s3b_cleanup_restore` directly with a synthetic
 * snapshot because Pest's afterEach runs AFTER the test body has finished
 * asserting — you cannot observe afterEach's effects from inside the same
 * test, so cross-test pollution is unavoidable otherwise.
 */

describe('Cleanup — test-fixture restore preserves pre-existing values', function () {

    it('Cleanup.1: an UNSET pre-existing $_SERVER key is restored', function () {
        // Set up a pre-existing $_SERVER key, snapshot it, unset it
        // (simulating what AC-65.2 does to BASE_PATH), run the cleanup,
        // and verify the original value is back.
        $_SERVER['__PHASE4S3B_SENTINEL_A'] = 'original-a';
        $originalServer = ['__PHASE4S3B_SENTINEL_A' => 'original-a'];

        unset($_SERVER['__PHASE4S3B_SENTINEL_A']);
        expect(array_key_exists('__PHASE4S3B_SENTINEL_A', $_SERVER))->toBeFalse();

        phase4s3b_cleanup_restore([], $originalServer, []);

        expect($_SERVER['__PHASE4S3B_SENTINEL_A'] ?? null)->toBe('original-a');
        // Cleanup, then a final safety purge so this test doesn't pollute
        // siblings' $_SERVER (afterEach will run again, but defense in depth).
        unset($_SERVER['__PHASE4S3B_SENTINEL_A']);
    });

    it('Cleanup.2: an OVERWRITTEN pre-existing $_ENV value is restored', function () {
        // Set up a pre-existing $_ENV value, snapshot it, overwrite it,
        // run the cleanup, and verify the original value is back. This
        // is the case the previous keys-only diff implementation
        // silently lost.
        $_ENV['__PHASE4S3B_SENTINEL_B'] = 'original-b';
        $originalEnv = ['__PHASE4S3B_SENTINEL_B' => 'original-b'];

        $_ENV['__PHASE4S3B_SENTINEL_B'] = 'overwritten-by-test';
        expect($_ENV['__PHASE4S3B_SENTINEL_B'])->toBe('overwritten-by-test');

        phase4s3b_cleanup_restore($originalEnv, [], []);

        expect($_ENV['__PHASE4S3B_SENTINEL_B'] ?? null)->toBe('original-b');
        unset($_ENV['__PHASE4S3B_SENTINEL_B']);
    });

    it('Cleanup.3: an UNSET pre-existing getenv() key is restored via putenv()', function () {
        // The keys-only diff did not touch getenv() at all (its previous
        // implementation explicitly left getenv alone), so the case where
        // a test putenv()s a key that was already set in the process was
        // never cleaned up. The fix must putenv() the original value back.
        putenv('__PHASE4S3B_SENTINEL_C=original-c');
        $originalGetenv = ['__PHASE4S3B_SENTINEL_C' => 'original-c'];

        putenv('__PHASE4S3B_SENTINEL_C');
        expect(getenv('__PHASE4S3B_SENTINEL_C'))->toBeFalse();

        phase4s3b_cleanup_restore([], [], $originalGetenv);

        expect(getenv('__PHASE4S3B_SENTINEL_C'))->toBe('original-c');
        putenv('__PHASE4S3B_SENTINEL_C');
    });

    it('Cleanup.4: a TEST-INTRODUCED new key is removed', function () {
        // The trivial half of the cleanup — the keys-only diff also did
        // this. Keeping it as a regression guard so the value-restoration
        // changes don't accidentally break the simple case.
        $_ENV['__PHASE4S3B_SENTINEL_D'] = 'new-by-test';
        expect(array_key_exists('__PHASE4S3B_SENTINEL_D', $_ENV))->toBeTrue();

        phase4s3b_cleanup_restore([], [], []);

        expect(array_key_exists('__PHASE4S3B_SENTINEL_D', $_ENV))->toBeFalse();
        expect(getenv('__PHASE4S3B_SENTINEL_D'))->toBeFalse();
    });
});
