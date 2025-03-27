<?php

namespace SwallowPHP\Framework\Http\Middleware;

use SwallowPHP\Framework\Exceptions\RateLimitExceededException;
use SwallowPHP\Framework\Contracts\CacheInterface; // Use the interface
use SwallowPHP\Framework\Foundation\App; // Need container access
use SwallowPHP\Framework\Routing\Route;
use SwallowPHP\Framework\Http\Request; // Need access to getClientIp

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

        $cacheTTL = $route->getTimeToLive() ?? env('RATE_LIMIT_CACHE_TTL', 60); // Use TTL from route or default
        $ipAddress = Request::getClientIp(); // Get client IP
        $routeName = $route->getName() ?? $route->getUri(); // Use name or URI for key
        $cacheKey = 'rate_limit:' . $routeName . ':' . $ipAddress;

        $cache = App::container()->get(CacheInterface::class); // Get cache instance

        $cacheData = $cache->get($cacheKey);

        $requestCount = 1;
        $lastRequestTime = time(); // Default for new entry

        if (is_array($cacheData) && isset($cacheData['count'], $cacheData['last_request_time'])) {
            // Entry exists, increment count
            $requestCount = $cacheData['count'] + 1;
            $lastRequestTime = $cacheData['last_request_time']; // Keep track of last request for Retry-After
        }

        // Prepare data to be stored/updated in cache
        $newCacheData = [
            'count' => $requestCount,
            'last_request_time' => time() // Update last request time
        ];

        // Save updated data to cache with TTL
        $cache->set($cacheKey, $newCacheData, $cacheTTL);

        // Check if limit is exceeded
        $remainingRequests = max(0, $rateLimit - $requestCount); // Calculate remaining, ensure non-negative
        $limitExceeded = $requestCount > $rateLimit;

        // Set RateLimit headers (optional but good practice)
        if (!headers_sent()) {
             header("X-RateLimit-Limit: {$rateLimit}");
             header("X-RateLimit-Remaining: {$remainingRequests}");
             if ($limitExceeded) {
                  // Calculate Retry-After based on when the cache entry expires
                  $retryAfter = max(0, $cacheTTL - (time() - $lastRequestTime)); // Time until cache expires
                  header("Retry-After: {$retryAfter}");
             }
        }

        // Throw exception if limit exceeded
        if ($limitExceeded) {
            throw new RateLimitExceededException('Too many requests. Please try again later.');
        }
    }

     // getClientIp was here, but moved to Request class
}