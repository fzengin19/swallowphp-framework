<?php 
namespace SwallowPHP\Framework\Http\Middleware;
use Closure;
use SwallowPHP\Framework\Http\Request; // Import Request from Http namespace


abstract class Middleware
{
    protected $next;

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}