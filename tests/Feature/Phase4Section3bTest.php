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
| deleted; a leftover $_ENV entry would shadow the real repo .env.
|
| We capture the real repo base path at file load time and restore it in
| afterEach. Real repo root = dirname(__DIR__, 2) from this file's own
| location — matches Env::getBasePath()'s default (it does
| `dirname(__DIR__, 5)` from its own file, which lands at the same
| repo root when this file is at tests/Feature/).
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

    // Snapshot $_ENV / $_SERVER / getenv() so afterEach can scrub any
    // test-introduced keys. We only snapshot once per process (first
    // test), then on subsequent tests we add the new keys to a per-test
    // removal list captured in afterEach.
    if (!isset($this->phase4s3bOriginalEnvKeys)) {
        $this->phase4s3bOriginalEnvKeys = array_keys($_ENV);
        $this->phase4s3bOriginalServerKeys = array_keys($_SERVER);
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

    // 3. Scrub any test-introduced $_ENV / $_SERVER / getenv() keys.
    //    We compute the diff against the snapshot taken in the first
    //    beforeEach; anything new is test pollution and gets removed.
    foreach (array_diff(array_keys($_ENV), $this->phase4s3bOriginalEnvKeys ?? []) as $k) {
        unset($_ENV[$k]);
        putenv($k); // putenv() with a single arg unsets the var.
    }
    foreach (array_diff(array_keys($_SERVER), $this->phase4s3bOriginalServerKeys ?? []) as $k) {
        unset($_SERVER[$k]);
    }
});

/* ===========================================================================
 * AC-64 — Env::load() skips malformed lines instead of aborting the loop
 * ===========================================================================
 *
 * Pre-fix: `putenv("=orphan")` throws \ValueError on PHP 8+; since this is
 * inside the foreach with no try/catch, the exception propagates and every
 * .env line after the malformed one silently never loads.
 *
 * Post-fix: a `=value` line (empty name) is logged via error_log() and
 * `continue`d; lines before AND after it still load.
 *
 * The named mutation for this AC is "remove the $name === '' guard" — the
 * test must fail with an uncaught \ValueError from Env::load().
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
