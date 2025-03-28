<?php 
namespace SwallowPHP\Framework\Http\Middleware;
use Closure;
use SwallowPHP\Framework\Http\Request; // Import Request from Http namespace


abstract class Middleware
{

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed Typically a Response object or result from the next middleware.
     */
    public function handle(Request $request, Closure $next): mixed // Added return type hint
    {
        return $next($request);
    }
}