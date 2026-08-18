<?php
namespace SwallowPHP\Framework\Http\Middleware;

use Closure;
use SwallowPHP\Framework\Http\Request;

/**
 * Base contract for HTTP middleware.
 *
 * The debugbar timeline marker ('middleware.<class>') is emitted by the
 * Route pipeline (see Route::execute()), NOT here — so subclasses are
 * free to override handle() without losing the measure. If we put the
 * marker in this base method, every override that forgets to call
 * parent::handle() would silently drop its timeline entry, which is the
 * trap we just fell into.
 */
abstract class Middleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed Typically a Response object or result from the next middleware.
     */
    abstract public function handle(Request $request, Closure $next): mixed;
}