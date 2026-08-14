<?php

/*
|--------------------------------------------------------------------------
| Phase 4 Section 4 hardening tests (AC-67 .. AC-70).
|--------------------------------------------------------------------------
|
| These tests bind the SqliteCache/FileLogger/Methods.php bugfixes from
| SwallowPHP Phase 4 Section 4. Each AC's "Test" sketch in the SPEC is
| bound here as a behavioral check that exercises the real production
| path — not just source-presence grepping.
|
| Fixture layout (per the SPEC):
|   - tests/Feature/Phase4Section4Test.php (this file — the only NEW
|     file under tests/Feature)
|   - SqliteCache files live under sys_get_temp_dir()/phase4s4-cache-*
|   - FileLogger files live under sys_get_temp_dir()/phase4s4-log-*
|
| The "single new file" constraint from the SPEC forbids modifying any
| prior phase test file — so helpers here are declared locally, not
| extracted to tests/Support/.
*/

namespace Tests\Feature;

use ReflectionMethod;
use SwallowPHP\Framework\Cache\SqliteCache;
use SwallowPHP\Framework\Log\FileLogger;

// ---------------------------------------------------------------------------
// Spy fixture: AC-67.
//
// Overrides `ttlToTimestamp()` to count invocations so a test can assert
// that `set()` calls the helper exactly once per call with a \DateInterval
// TTL. Without this spy, a mutation that reverted to a second independent
// `ttlToTimestamp($ttl)` call at its original site would still pass any
// behavioral set()/get() round-trip test — the value would land in the
// cache correctly either way; only the call count reveals the regression.
//
// No other behavior changes — every other SqliteCache method is inherited
// unchanged.
// ---------------------------------------------------------------------------

class Phase4S4SpySqliteCache extends SqliteCache
{
    public int $ttlToTimestampCallCount = 0;

    protected function ttlToTimestamp(null|int|\DateInterval $ttl): ?int
    {
        $this->ttlToTimestampCallCount++;
        return parent::ttlToTimestamp($ttl);
    }
}

// ===========================================================================
// AC-67 — SqliteCache::set() computes the expiration timestamp once
// ===========================================================================

describe('AC-67 — SqliteCache::set() calls ttlToTimestamp() exactly once', function () {

    it('AC-67 prerequisite: ttlToTimestamp() is protected (not private) on SqliteCache', function () {
        // Without this assertion, a spy subclass built against a
        // still-private parent method silently produces
        // ttlToTimestampCallCount === 0 for every call (PHP's private
        // methods can't be overridden by subclasses — the parent
        // class's own calls always go to the parent's own private
        // method). The downstream `=== 1` assertion would still fail,
        // but for the wrong, more confusing reason. This prerequisite
        // catches "visibility not widened" separately from "widened
        // but still calls it twice".
        $ref = new ReflectionMethod(SqliteCache::class, 'ttlToTimestamp');
        expect($ref->isProtected())->toBeTrue();
    });

    it('AC-67: set() with a \\DateInterval TTL calls ttlToTimestamp() exactly once', function () {
        $dbPath = sys_get_temp_dir() . '/phase4s4-cache-' . uniqid('', true) . '.sqlite';
        $spy = new Phase4S4SpySqliteCache($dbPath, 'cache');

        // A \DateInterval TTL is the branch that double-calls
        // ttlToTimestamp() pre-fix. A plain int TTL's early-guard
        // check is `is_int($ttl) && $ttl <= 0`, which doesn't call
        // ttlToTimestamp() in the guard itself, so an int TTL wouldn't
        // exercise the bug. Use \DateInterval specifically.
        $spy->set('key', 'value', new \DateInterval('PT1H'));

        expect($spy->ttlToTimestampCallCount)->toBe(1);

        // Cleanup.
        @unlink($dbPath);
        foreach (glob($dbPath . '*') ?: [] as $f) {
            @unlink($f);
        }
    });
});

// ===========================================================================
// AC-68 — FileLogger constructed with uppercase $minLevel actually filters
// ===========================================================================

describe('AC-68 — FileLogger uppercase $minLevel lookup', function () {

    it('AC-68.1: WARNING (uppercase) filters out debug-level messages', function () {
        $path = sys_get_temp_dir() . '/phase4s4-log-' . uniqid('', true) . '.log';
        $logger = new FileLogger($path, 'WARNING');

        $logger->debug('should not appear');

        $content = file_exists($path) ? file_get_contents($path) : '';
        expect($content)->not->toContain('should not appear');

        @unlink($path);
    });

    it('AC-68.2: WARNING (uppercase) still allows warning-level messages through', function () {
        $path = sys_get_temp_dir() . '/phase4s4-log-' . uniqid('', true) . '.log';
        $logger = new FileLogger($path, 'WARNING');

        $logger->warning('should appear');

        expect(file_exists($path))->toBeTrue();
        $content = file_get_contents($path);
        expect($content)->toContain('should appear');

        @unlink($path);
    });

    it('AC-68.3 (regression): lowercase "debug" still allows debug-level messages (compatibility)', function () {
        // Compatibility check (NOT a mutation-detecting test —
        // strtolower('debug') === 'debug', so this case behaves
        // identically pre- and post-fix; it only confirms the fix
        // doesn't regress the already-working lowercase path).
        $path = sys_get_temp_dir() . '/phase4s4-log-' . uniqid('', true) . '.log';
        $logger = new FileLogger($path, 'debug');

        $logger->debug('x');

        expect(file_exists($path))->toBeTrue();
        $content = file_get_contents($path);
        expect($content)->toContain('x');

        @unlink($path);
    });
});

// ===========================================================================
// AC-69 — FileLogger chmods pre-existing log files to 0644
// ===========================================================================

describe('AC-69 — FileLogger chmods pre-existing log files', function () {

    it('AC-69: a pre-existing log file with loose permissions is chmod\'d to 0644 on construction', function () {
        $path = sys_get_temp_dir() . '/phase4s4-log-' . uniqid('', true) . '.log';

        // Pre-create with loose permissions (0666) — the SPEC's
        // documented scenario.
        touch($path);
        chmod($path, 0666);

        // Sanity: the file really does start at 0666.
        expect((fileperms($path) & 0777))->toBe(0666);

        new FileLogger($path);

        // Post-construction, the file MUST be 0644 — the constructor's
        // chmod() now applies regardless of whether the file was
        // just created or pre-existed.
        expect((fileperms($path) & 0777))->toBe(0644);

        @unlink($path);
    });
});

// ===========================================================================
// AC-70 — formatDateForHumans() handles future dates sensibly
// ===========================================================================

describe('AC-70 — formatDateForHumans() future-date wording', function () {

    it('AC-70.1: a date 2 hours in the future returns a sensible, non-negative string with "sonra" wording', function () {
        // Use a 2-hour offset (not 1-hour) per the SPEC — a 1-hour
        // exact offset sits right at the 3600 branch boundary and a
        // few milliseconds of test execution time could tip it into
        // the < 3600 "dakika" branch instead of "saat", an avoidable
        // flaky-test risk.
        $future = date('Y-m-d H:i:s', time() + 7200);
        $result = formatDateForHumans($future);

        // Must not start with '-' — the pre-fix code returned
        // "-7200 saniye önce" (negative seconds count) for a future
        // date, since the early < 60 branch returned "$diff saniye
        // önce" with a negative $diff.
        expect($result[0] ?? '')->not->toBe('-');

        // Exact substring '2 saat sonra' (not just 'sonra somewhere') —
        // a partial fix touching only the seconds branch could satisfy
        // a looser "contains 'sonra'" assertion by accident.
        expect($result)->toContain('2 saat sonra');
    });

    it('AC-70.2 (regression): a date 2 hours in the PAST still returns "2 saat önce"', function () {
        // The pre-existing, already-working case. The fix must not
        // regress this.
        $past = date('Y-m-d H:i:s', time() - 7200);
        $result = formatDateForHumans($past);

        expect($result)->toContain('2 saat önce');
    });
});
