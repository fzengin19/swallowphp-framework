<?php

namespace SwallowPHP\Framework\Http\Middleware;

use SwallowPHP\Framework\Session\SessionManager;

use Closure;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Exceptions\CsrfTokenMismatchException;
use SwallowPHP\Framework\Foundation\App; // For config access
use SwallowPHP\Framework\Foundation\Config; // For type hint

// Assuming the base Middleware class exists or is not strictly needed if only handle is used
abstract class Middleware // Make it abstract if it's just a base
{
    abstract public function handle(Request $request, Closure $next): mixed;
}


class VerifyCsrfToken extends Middleware
{
    /**
     * URIs that should be excluded from CSRF verification.
     * Use asterisks (*) as wildcards.
     * Example: 'api/*'
     *
     * @var array<int, string>
     */
    protected array $except = [
        // Add URIs here, e.g., 'api/*'
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     * @throws CsrfTokenMismatchException
     * @throws \RuntimeException If session cannot be started.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Ensure session is started only if needed (non-reading requests, not excluded)
        if (!$this->isReading($request) && !$this->inExceptArray($request)) {
            if (session_status() === PHP_SESSION_NONE) {
                if (!headers_sent()) {
                    // Use SessionManager to start session correctly with handler
                    try {
                         App::container()->get(SessionManager::class)->start();
                    } catch (\Throwable $e) {
                         // Log this error? Or rethrow? Rethrow for now.
                         throw new \RuntimeException("Session could not be started for CSRF check: " . $e->getMessage(), 0, $e);
                    }
                } elseif (!isset($_SESSION)) {
                    // Session should have been started by App::run or previous middleware
                    // if headers are already sent. If not, it's a critical error.
                    throw new \RuntimeException("Session not available for CSRF check and headers already sent.");
                }
            }
        }

        // Check conditions to bypass CSRF check or validate token
        if (
            $this->isReading($request) ||
            $this->inExceptArray($request) ||
            $this->tokensMatch($request)
        ) {
            // Token is valid or check is not required for this request.
            // Optionally add the token to the response headers for AJAX requests
            // $this->addCookieToResponse($request, $next($request));

            return $next($request); // Proceed to the next middleware or controller
        }

        // If none of the above conditions are met, throw the exception.
        throw new CsrfTokenMismatchException('CSRF token mismatch.');
    }

    /** Determine if the request is a reading type request. */
    protected function isReading(Request $request): bool
    {
        return in_array($request->getMethod(), ['HEAD', 'GET', 'OPTIONS']);
    }

    /** Determine if the request URI is in the except array. */
    protected function inExceptArray(Request $request): bool
    {
        $requestPath = '/' . ltrim($request->getPath(), '/'); // Ensure leading slash

        foreach ($this->except as $except) {
            $except = '/' . ltrim($except, '/'); // Ensure leading slash for pattern
            if ($except !== '/') {
                $except = rtrim($except, '/'); // Remove trailing slash unless it's '/'
            }

            if ($requestPath === $except) {
                return true;
            }
            // Simple wildcard matching at the end
            if (str_ends_with($except, '/*')) {
                $pattern = rtrim($except, '*');
                if ($pattern === '/' || str_starts_with($requestPath, $pattern)) {
                     return true;
                }
            }
        }
        return false;
    }

    /** Determine if the session and input csrf tokens match. */
    protected function tokensMatch(Request $request): bool
    {
        // Ensure session is available (should have been started by handle method if needed)
        if (!isset($_SESSION)) {
             // This indicates a problem with session start logic earlier
             error_log("Warning: CSRF tokensMatch() called but session is not available.");
             return false;
        }

        $sessionToken = $_SESSION['_token'] ?? null;

        // Read token from input field or headers
        $token = $request->get('_token') ?: $request->header('X-CSRF-TOKEN');

        // Fallback for X-XSRF-TOKEN (consider decryption if needed)
        if (!$token && $header = $request->header('X-XSRF-TOKEN')) {
             // If XSRF token is encrypted, it needs decryption here using App Key
             // For now, assume it's plain if used.
             $token = $header;
        }

        // Tokens must be non-empty strings and match
        return is_string($sessionToken)
               && is_string($token)
               && !empty($sessionToken) // Ensure session token is not empty
               && !empty($token)        // Ensure request token is not empty
               && hash_equals($sessionToken, $token);
    }

    /** Get or generate the CSRF token in the session. */
    public static function getToken(): string
    {
        // Use SessionManager to ensure session is started correctly
        $session = null;
        try {
            $session = App::container()->get(SessionManager::class);
            $session->start(); // Ensure session is active
        } catch (\Throwable $e) {
            // Log error and re-throw as RuntimeException?
            error_log("CSRF getToken error: Failed to start session. " . $e->getMessage());
            throw new \RuntimeException("Session could not be started to get CSRF token.", 0, $e);
        }

        if (!$session->has('_token') || !is_string($session->get('_token'))) {
            try {
                 $newToken = bin2hex(random_bytes(32));
                 $session->put('_token', $newToken);
                 return $newToken;
            } catch (\Exception $e) {
                 error_log("CSRF getToken error: Failed to generate random bytes. " . $e->getMessage());
                 throw new \RuntimeException("Could not generate CSRF token.", 0, $e);
            }
        }
        return $session->get('_token');
    }

     /** Regenerate the CSRF token (optional). */
     protected function refreshToken(): void
     {
         try {
             $session = App::container()->get(SessionManager::class);
             $session->start(); // Ensure session is active
             $session->put('_token', bin2hex(random_bytes(32)));
         } catch (\Throwable $e) {
              error_log("CSRF refreshToken error: " . $e->getMessage());
              // Decide if this should throw or fail silently
              // throw new \RuntimeException("Could not refresh CSRF token.", 0, $e);
         }
     }
}