<?php

namespace SwallowPHP\Framework\Http\Middleware;

use SwallowPHP\Framework\Exceptions\RateLimitExceededException;
use SwallowPHP\Framework\Contracts\CacheInterface; // Use the interface
use SwallowPHP\Framework\Foundation\App; // Need container access
use SwallowPHP\Framework\Routing\Route;
use SwallowPHP\Framework\Http\Request; // Need access to getClientIp
use Psr\Log\LoggerInterface; // For logger type hint

class RateLimiter
{
    /**
     * Executes rate limiting logic for a given route and IP address.
     *
     * @param Route $route The route object containing rate limit information.
     * @throws RateLimitExceededException If the rate limit is exceeded.
     * @return void
     */
    public static function execute(Route $route): void
    {
        $rateLimit = $route->getRateLimit(); // Get limit specific to this route

        // If no specific limit for the route, maybe check a global limit?
        // If still no limit, or limit is explicitly 0, skip rate limiting.
        if ($rateLimit === null) {
             // Optionally check global limit: $rateLimit = env('APP_RATE_LIMIT', null);
             // If still null or 0, return.
             // For now, assume only route-specific limits are checked.
             return; // No rate limit defined or needed for this route
        }
        if ($rateLimit <= 0) {
             return; // Rate limit explicitly disabled
        }

        $cacheTTL = $route->getTimeToLive() ?? config('cache.ttl', 60); // Use TTL from route or default
        $ipAddress = Request::getClientIp(); // Get client IP
        if ($ipAddress === null) {
            // Without a usable client IP we cannot safely bucket per client.
            // Using an empty-string suffix would collapse every unresolvable
            // client into one shared bucket — one such client exhausting the
            // limit locks out every other unresolvable-IP client on that route
            // for the TTL window. Skip rate limiting for this request (fail
            // open) and warn so the misconfiguration is visible in logs.
            try {
                $logger = App::container()->get(LoggerInterface::class);
                $logger->warning('Rate limiting skipped: client IP could not be resolved for route ' . ($route->getName() ?? $route->getUri()) . '.');
            } catch (\Throwable $e) {
                error_log('Rate limiting skipped: client IP could not be resolved.');
            }
            return;
        }
        $cacheKey = self::buildCacheKey($route, $ipAddress);

        $cache = App::container()->get(CacheInterface::class); // Get cache instance

        $now = time();
        $cacheData = $cache->get($cacheKey);

        // Fixed window: the window starts on the first request and lasts $cacheTTL
        // seconds. Crucially, the TTL is anchored to window_start and is NOT extended
        // on every hit — otherwise a steady stream of requests would keep the window
        // (and the lockout) alive forever.
        if (
            is_array($cacheData)
            && isset($cacheData['count'], $cacheData['window_start'])
            && ($now - $cacheData['window_start']) < $cacheTTL
        ) {
            $requestCount = $cacheData['count'] + 1;
            $windowStart = $cacheData['window_start'];
        } else {
            $requestCount = 1;
            $windowStart = $now;
        }

        // Store with the REMAINING window lifetime so the entry expires when the
        // window ends, not $cacheTTL seconds after the latest request.
        // ponytail: get-then-set is not atomic, so concurrent requests can under-count
        // near the limit boundary. Upgrade path: an atomic increment() on CacheInterface
        // (SQLite: UPDATE ... SET count=count+1; File: flock) if precise limiting matters.
        $remainingTtl = max(1, $cacheTTL - ($now - $windowStart));
        $cache->set($cacheKey, ['count' => $requestCount, 'window_start' => $windowStart], $remainingTtl);

        // Check if limit is exceeded
        $remainingRequests = max(0, $rateLimit - $requestCount); // Calculate remaining, ensure non-negative
        $limitExceeded = $requestCount > $rateLimit;

        // Set RateLimit headers (optional but good practice)
        if (!headers_sent()) {
             header("X-RateLimit-Limit: {$rateLimit}");
             header("X-RateLimit-Remaining: {$remainingRequests}");
             if ($limitExceeded) {
                  // Time until the current window expires.
                  header("Retry-After: {$remainingTtl}");
             }
        }

        // Throw exception if limit exceeded
        if ($limitExceeded) {
            throw new RateLimitExceededException('Too many requests. Please try again later.');
        }
    }

     // getClientIp was here, but moved to Request class

    /**
     * Builds the PSR-16-safe cache key for a rate-limit bucket.
     *
     * Unnamed routes fall back to their URI ("/contact"), which contains "/"
     * — a reserved cache-key character that FileCache::validateKey() rejects.
     * Hashing the identity keeps the key within the allowed charset and a
     * bounded length regardless of route name/URI/IP input.
     */
    public static function buildCacheKey(Route $route, string $ipAddress): string
    {
        $routeName = $route->getName() ?? $route->getUri();
        return sha1('rate_limit:' . $routeName . ':' . $ipAddress);
    }
}
