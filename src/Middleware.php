<?php 
namespace Framework;

use Framework\Request;
use Closure;

abstract class Middleware
{
    protected $next;

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}