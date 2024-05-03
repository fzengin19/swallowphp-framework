<?php 
namespace SwallowPHP\Framework;

use SwallowPHP\Framework\Request;
use Closure;

abstract class Middleware
{
    protected $next;

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}