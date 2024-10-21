<?php

namespace SwallowPHP\Framework;

use SwallowPHP\Framework\Exceptions\RateLimitExceededException;
use SwallowPHP\Framework\Cache;
use SwallowPHP\Framework\Route;

class RateLimiter
{
    private static $cacheKeyPrefix = 'rate_limit_';

    /**
     * Executes a request and checks if the rate limit has been exceeded.
     *
     * @return void
     *
     * @throws RateLimitExceededException If the rate limit has been exceeded.
     */
    public static function execute(Route $route)
    {
        $rateLimit = $route->getRateLimit() !== null ? $route->getRateLimit() : env('APP_RATE_LIMIT', 60);
        $cacheTTL = $route->getTimeToLive() ?? env('RATE_LIMIT_CACHE_TTL', 60); 
        $ip = getIp();
        if ($rateLimit == 0) {
            return true;
        }
        $uri = $route->getUri();
        $cacheKey = self::$cacheKeyPrefix . $ip;
        if (Cache::has($cacheKey)) {
            $cachedRequest = Cache::get($cacheKey);

            if (!isset($cachedRequest[$uri])) {
                // Bu rota için kayıt henüz yoksa, başlangıç verilerini oluştur
                $cachedRequest[$uri] = [
                    'last_reset' => time(),
                    'request_count' => 1
                ];
            } else {
                $requestCount = $cachedRequest[$uri]['request_count'];
                $requestCount++;
                $cachedRequest[$uri] = [
                    'last_reset' => $cachedRequest[$uri]['last_reset'],
                    'request_count' => $requestCount
                ];
            }

           
            Cache::set($cacheKey, $cachedRequest, time() + $cacheTTL);
            
            $remainingRequests = $rateLimit - $cachedRequest[$uri]['request_count'];
            $break = false;
            if($remainingRequests < 0){
                $remainingRequests = 0;
                $break = true;
            }

            header("X-RateLimit-Limit: $rateLimit");
            header("X-RateLimit-Remaining: $remainingRequests");
            if ($break) {
                throw new RateLimitExceededException('Too many requests. Please try again later.');
            }
        } else {
            // Bu IP için herhangi bir kayıt yoksa, başlangıç verilerini oluştur
            $request[$uri] = [
                'last_reset' => time(),
                'request_count' => 1
            ];
         

            Cache::set($cacheKey, $request, time() + $cacheTTL);
            $remainingRequests = $rateLimit - 1;

            header("X-RateLimit-Limit: $rateLimit");
            header("X-RateLimit-Remaining: $remainingRequests");
            if ($remainingRequests < 0) {
                throw new RateLimitExceededException('Too many requests. Please try again later.');
            }
        }
    }
}
