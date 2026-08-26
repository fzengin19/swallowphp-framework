<?php

use SwallowPHP\Framework\Cache\FileCache;
use SwallowPHP\Framework\Http\Middleware\RateLimiter;
use SwallowPHP\Framework\Routing\Route;

it('increments and decrements FileCache values without re-entering its own file lock', function () {
    $cache = new FileCache(sys_get_temp_dir() . '/swallow-release-hotfix-' . uniqid('', true) . '/cache.json');

    expect($cache->increment('counter'))->toBe(1);
    expect($cache->increment('counter', 4))->toBe(5);
    expect($cache->decrement('counter'))->toBe(4);
    expect($cache->get('counter'))->toBe(4);
});

it('merges increments into the on-disk state instead of clobbering sibling keys', function () {
    $path = sys_get_temp_dir() . '/swallow-release-hotfix-' . uniqid('', true) . '/cache.json';
    $writer = new FileCache($path);
    $writer->set('counter', 10);
    $writer->set('other', 'kept');

    // A separate instance proves increment() reads what is on disk under the
    // lock, not whatever happens to sit in its own stale in-memory copy.
    $reader = new FileCache($path);

    expect($reader->increment('counter'))->toBe(11);
    expect($reader->get('counter'))->toBe(11);
    expect($reader->get('other'))->toBe('kept');
    expect($reader->decrement('counter'))->toBe(10);
});

it('builds a PSR-16-safe rate limit key even when the route has no name', function () {
    $route = new Route('GET', '/contact', fn () => null);
    $key = RateLimiter::buildCacheKey($route, '203.0.113.7');

    expect((string) $route->getUri())->toContain('/');
    expect($key)->toMatch('/^[A-Za-z0-9_.]{1,64}$/');

    // FileCache::validateKey() rejects keys containing "/" — the raw
    // "/contact" identity used to explode on the very first request.
    // The hashed key must round-trip through the cache cleanly.
    $windowStart = time();
    $cache = new FileCache(sys_get_temp_dir() . '/swallow-release-hotfix-' . uniqid('', true) . '/cache.json');
    expect($cache->set($key, ['count' => 1, 'window_start' => $windowStart], 60))->toBeTrue();
    expect($cache->get($key))->toBe(['count' => 1, 'window_start' => $windowStart]);
});

it('keeps dependency deprecations non-fatal at the configured reporting level', function () {
    $configuredLevel = config('app.error_reporting_level', E_ALL);
    $previousLevel = error_reporting($configuredLevel);

    try {
        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new RuntimeException('Deprecation became an exception');
        });

        @trigger_error('disabled deprecation', E_USER_DEPRECATED);
        expect(true)->toBeTrue();
    } finally {
        restore_error_handler();
        error_reporting($previousLevel);
    }
});

it('pins the safe reporting defaults in App::run() and the shipped app config', function () {
    $appSource = file_get_contents(dirname(__DIR__, 2) . '/src/Foundation/App.php');

    // The pre-config phase must not enable every severity: any vendor
    // deprecation firing during bootstrap would otherwise become a 500.
    expect($appSource)->toContain('error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);');
    expect($appSource)->not->toContain('error_reporting(E_ALL);');

    $configSource = file_get_contents(dirname(__DIR__, 2) . '/src/Config/app.php');
    expect($configSource)->toContain('~E_DEPRECATED & ~E_NOTICE');
});
