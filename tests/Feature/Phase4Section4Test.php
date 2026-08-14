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
        // Wrap fixture cleanup in try/finally so a failing assertion
        // doesn't leak temporary SQLite/WAL files.
        $dbPath = sys_get_temp_dir() . '/phase4s4-cache-' . uniqid('', true) . '.sqlite';
        $spy = new Phase4S4SpySqliteCache($dbPath, 'cache');

        try {
            // A \DateInterval TTL is the branch that double-calls
            // ttlToTimestamp() pre-fix. A plain int TTL's early-guard
            // check is `is_int($ttl) && $ttl <= 0`, which doesn't call
            // ttlToTimestamp() in the guard itself, so an int TTL wouldn't
            // exercise the bug. Use \DateInterval specifically.
            $spy->set('key', 'value', new \DateInterval('PT1H'));

            expect($spy->ttlToTimestampCallCount)->toBe(1);
        } finally {
            // Cleanup is exception-safe — runs even on assertion failure.
            @unlink($dbPath);
            foreach (glob($dbPath . '*') ?: [] as $f) {
                @unlink($f);
            }
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

        try {
            $logger->debug('should not appear');

            $content = file_exists($path) ? file_get_contents($path) : '';
            expect($content)->not->toContain('should not appear');
        } finally {
            @unlink($path);
        }
    });

    it('AC-68.2: WARNING (uppercase) still allows warning-level messages through', function () {
        $path = sys_get_temp_dir() . '/phase4s4-log-' . uniqid('', true) . '.log';
        $logger = new FileLogger($path, 'WARNING');

        try {
            $logger->warning('should appear');

            expect(file_exists($path))->toBeTrue();
            $content = file_get_contents($path);
            expect($content)->toContain('should appear');
        } finally {
            @unlink($path);
        }
    });

    it('AC-68.3 (regression): lowercase "debug" still allows debug-level messages (compatibility)', function () {
        // Compatibility check (NOT a mutation-detecting test —
        // strtolower('debug') === 'debug', so this case behaves
        // identically pre- and post-fix; it only confirms the fix
        // doesn't regress the already-working lowercase path).
        $path = sys_get_temp_dir() . '/phase4s4-log-' . uniqid('', true) . '.log';
        $logger = new FileLogger($path, 'debug');

        try {
            $logger->debug('x');

            expect(file_exists($path))->toBeTrue();
            $content = file_get_contents($path);
            expect($content)->toContain('x');
        } finally {
            @unlink($path);
        }
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

        try {
            // Sanity: the file really does start at 0666.
            expect((fileperms($path) & 0777))->toBe(0666);

            new FileLogger($path);

            // Post-construction, the file MUST be 0644 — the constructor's
            // chmod() now applies regardless of whether the file was
            // just created or pre-existed.
            expect((fileperms($path) & 0777))->toBe(0644);
        } finally {
            @unlink($path);
        }
    });

    it('AC-69.HIGH: a symlink log file path is REJECTED (no chmod follows the link)', function () {
        // Reviewer finding #1 (HIGH): the constructor used to chmod() the
        // log file path unconditionally, and chmod() follows symlinks.
        // An attacker who can place a symlink at the log path could
        // cause this constructor to silently tighten OR loosen a
        // sensitive target's permissions (e.g. a 0600 secrets file to
        // 0644). The fix rejects the symlink entirely.
        $target = sys_get_temp_dir() . '/phase4s4-log-target-' . uniqid('', true) . '.log';
        $link   = sys_get_temp_dir() . '/phase4s4-log-link-'   . uniqid('', true) . '.log';
        touch($target);
        chmod($target, 0600);
        @symlink($target, $link);

        try {
            // Constructing on the symlink MUST throw — we refuse to follow.
            expect(fn () => new FileLogger($link))->toThrow(\RuntimeException::class);

            // And the target's mode must be untouched: 0600 → 0600.
            // Pre-fix, the chmod would have followed the symlink and
            // loosened the target to 0644.
            clearstatcache(true, $target);
            expect((fileperms($target) & 0777))->toBe(0600);
        } finally {
            @unlink($link);
            @unlink($target);
        }
    });

    it('AC-69.MEDIUM#2: a pre-existing log file with STRICTER permissions (0600) is preserved, not loosened', function () {
        // Reviewer finding #2 (MEDIUM): the pre-fix `@chmod(..., 0644)`
        // unconditionally loosened stricter existing modes (e.g. 0600)
        // to 0644 — exposing owner-only logs to group/other read. The
        // fix only removes unsafe write bits (group/other write, 0022)
        // while preserving the rest of the mode.
        $path = sys_get_temp_dir() . '/phase4s4-log-' . uniqid('', true) . '.log';
        touch($path);
        chmod($path, 0600);

        try {
            // Sanity: starts at 0600.
            expect((fileperms($path) & 0777))->toBe(0600);

            new FileLogger($path);

            // Post-construction: mode MUST remain 0600 — the constructor
            // must not loosen it to 0644.
            clearstatcache(true, $path);
            expect((fileperms($path) & 0777))->toBe(0600);
        } finally {
            @unlink($path);
        }
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
        // Use a SAFETY MARGIN (7260s = 2h + 60s) on top of the 2-hour
        // minimum, not exactly 7200 — a test latency of even 1 second
        // drops absDiff from 7200 to 7199 and floor(7199/3600) becomes
        // 1 instead of 2 ("1 saat sonra" rather than "2 saat sonra"),
        // reproducing the reviewer's flake. 7260 keeps floor(7260/3600)
        // = 2 robustly under much larger test-execution latencies.
        $future = date('Y-m-d H:i:s', time() + 7260);
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
        // regress this. Same 7260s margin applied for consistency.
        $past = date('Y-m-d H:i:s', time() - 7260);
        $result = formatDateForHumans($past);

        expect($result)->toContain('2 saat önce');
    });
});
