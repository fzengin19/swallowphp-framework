<?php

namespace SwallowPHP\Framework\Http\Middleware;

use Closure;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Exceptions\CsrfTokenMismatchException; // Will create this next

// Assuming the base Middleware class is in the parent namespace


class VerifyCsrfToken extends Middleware
{
    /**
     * URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // e.g., 'api/*'
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \SwallowPHP\Framework\Request  $request
     * @param  \Closure  $next
     * @return mixed
     *
     * @throws \SwallowPHP\Framework\Exceptions\CsrfTokenMismatchException
     */
    public function handle(Request $request, Closure $next): mixed // Added return type hint
    {
        // Ensure session is started (might be redundant if App.php always starts it)
        if (session_status() == PHP_SESSION_NONE) {
            // Avoid starting session if headers already sent
            if (!headers_sent()) {
                 session_start();
            } else {
                 // Log or handle error: Cannot start session when headers already sent
                 // For now, we might have to assume session was started earlier or fail
                 if (!isset($_SESSION)) {
                      throw new \RuntimeException("Session could not be started.");
                 }
            }
        }

        if (
            $this->isReading($request) ||
            $this->inExceptArray($request) ||
            $this->tokensMatch($request)
        ) {
            // Optionally regenerate token on successful verification
            // $this->refreshToken();

            return $next($request);
        }

        throw new CsrfTokenMismatchException('CSRF token mismatch.');
    }

    /**
     * Determine if the request is a reading type request.
     *
     * @param  \SwallowPHP\Framework\Request  $request
     * @return bool
     */
    protected function isReading($request)
    {
        return in_array($request->getMethod(), ['HEAD', 'GET', 'OPTIONS']);
    }

    /**
     * Determine if the request URI is in the except array.
     *
     * @param  \SwallowPHP\Framework\Request  $request
     * @return bool
     */
    protected function inExceptArray($request)
    {
        // Simple check for now, can be enhanced
        $requestUri = trim(parse_url($request->getUri(), PHP_URL_PATH), '/');
        $appPath = trim(config('app.path', ''), '/');
        if (!empty($appPath)) {
             // Ensure APP_PATH is correctly removed only from the beginning
             if (str_starts_with($requestUri, $appPath . '/')) {
                  $requestUri = substr($requestUri, strlen($appPath) + 1);
             } elseif ($requestUri === $appPath) {
                  $requestUri = ''; // Handle base path itself
             }
        }
        $requestUri = trim($requestUri, '/');


        foreach ($this->except as $except) {
            $except = trim($except, '/');
            if ($requestUri === $except) {
                return true;
            }
            // Basic wildcard support
            if (str_ends_with($except, '/*') && str_starts_with($requestUri, rtrim($except, '*'))) {
                 return true;
            }
        }

        return false;
    }

    /**
     * Determine if the session and input csrf tokens match.
     *
     * @param  \SwallowPHP\Framework\Request  $request
     * @return bool
     */
    protected function tokensMatch($request)
    {
        $sessionToken = $_SESSION['_token'] ?? null;

        // Read token from input field or headers
        $token = $request->get('_token') ?: $request->header('X-CSRF-TOKEN');

        // Fallback for X-XSRF-TOKEN (often used with encrypted cookies, simplified here)
        if (! $token && $header = $request->header('X-XSRF-TOKEN')) {
             $token = $header;
        }

        if (! is_string($sessionToken) || ! is_string($token)) {
            return false;
        }

        // Use hash_equals for timing attack resistance
        return hash_equals($sessionToken, $token);
    }

    /**
     * Get or generate the CSRF token in the session.
     *
     * @return string
     */
    public static function getToken(): string
    {
        if (session_status() == PHP_SESSION_NONE) {
             if (!headers_sent()) {
                 session_start();
             } else {
                 if (!isset($_SESSION)) {
                      throw new \RuntimeException("Session could not be started to get CSRF token.");
                 }
             }
        }
        if (!isset($_SESSION['_token']) || !is_string($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_token'];
    }

     /**
      * Regenerate the CSRF token (optional).
      *
      * @return void
      */
     protected function refreshToken(): void
     {
         if (session_status() == PHP_SESSION_NONE) {
             if (!headers_sent()) {
                 session_start();
             } else {
                  if (!isset($_SESSION)) {
                      throw new \RuntimeException("Session could not be started to refresh CSRF token.");
                  }
             }
         }
         $_SESSION['_token'] = bin2hex(random_bytes(32));
     }
}