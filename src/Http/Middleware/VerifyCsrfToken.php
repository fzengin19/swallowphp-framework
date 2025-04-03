<?php

namespace SwallowPHP\Framework\Http\Middleware;

use Psr\Log\LoggerInterface;

// Import necessary classes
use SwallowPHP\Framework\Session\SessionManager;
use Closure;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Exceptions\CsrfTokenMismatchException;
use SwallowPHP\Framework\Foundation\App;
// No need to import Config here as it's not used directly

// Import the *actual* base Middleware class
use SwallowPHP\Framework\Http\Middleware\Middleware;


class VerifyCsrfToken extends Middleware // Extend the correct base class
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
                if (!headers_sent($file, $line)) { // Get file/line if headers sent
                    // Use SessionManager to start session correctly with handler
                    try {
                         App::container()->get(SessionManager::class)->start();
                    } catch (\Throwable $e) {
                         // Log this error? Or rethrow? Rethrow is better for critical failure.
                         throw new \RuntimeException("Session could not be started for CSRF check: " . $e->getMessage(), 0, $e);
                    }
                } elseif (!isset($_SESSION)) {
                    // Session should have been started by App::run or previous middleware
                    // if headers are already sent. If not, it's a critical error.
                     $logMsg = "Session not available for CSRF check and headers already sent.";
                     // Log before throwing
                     try { App::container()->get(LoggerInterface::class)->critical($logMsg, ['output_started_at' => "{$file}:{$line}"]); } catch (\Throwable $_) {}
                    throw new \RuntimeException($logMsg);
                }
            }
        }

        // Check conditions to bypass CSRF check or validate token
        if (
            $this->isReading($request) ||
            $this->inExceptArray($request) ||
            $this->tokensMatch($request)
        ) {
            return $next($request); // Proceed
        }

        // If checks fail, throw the exception.
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
        $requestPath = '/' . ltrim($request->getPath(), '/');

        foreach ($this->except as $except) {
            if (!is_string($except) || empty($except)) continue; // Skip invalid entries

            $except = '/' . ltrim($except, '/');
            if ($except !== '/') {
                $except = rtrim($except, '/');
            }

            // Handle wildcard matching (*)
            if (str_ends_with($except, '/*')) {
                $pattern = rtrim($except, '*');
                // If pattern is just '/', match everything (should probably not be used like this)
                // If pattern is '/api/', match '/api/users', '/api/posts/1' etc.
                if ($pattern === '/' || str_starts_with($requestPath, $pattern)) {
                     return true;
                }
            }
            // Handle exact match
            elseif ($requestPath === $except) {
                return true;
            }
        }
        return false;
    }

    /** Determine if the session and input csrf tokens match. */
    protected function tokensMatch(Request $request): bool
    {
        if (!isset($_SESSION)) {
             // Log this critical state
             try { App::container()->get(LoggerInterface::class)->error("CSRF tokensMatch() called but session is not initialized."); } catch (\Throwable $_) {}
             return false;
        }

        $sessionToken = $_SESSION['_token'] ?? null;
        $token = $request->get('_token') ?: $request->header('X-CSRF-TOKEN') ?: $request->header('X-XSRF-TOKEN');

        // Basic check for non-empty strings before hash_equals
        if (!is_string($sessionToken) || $sessionToken === '' || !is_string($token) || $token === '') {
             return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /** Get or generate the CSRF token in the session. */
    public static function getToken(): string
    {
        $session = null;
        $logger = null;
        try {
            $container = App::container();
            $logger = $container->get(LoggerInterface::class); // Get logger first
            $session = $container->get(SessionManager::class);
            $session->start(); // Ensure session is active

            if (!$session->has('_token') || !is_string($session->get('_token'))) {
                try {
                     $newToken = bin2hex(random_bytes(32));
                     $session->put('_token', $newToken);
                     return $newToken;
                } catch (\Exception $e) {
                     if ($logger) $logger->critical("CSRF getToken error: Failed to generate random bytes.", ['exception' => $e]);
                     throw new \RuntimeException("Could not generate CSRF token.", 0, $e);
                }
            }
            return $session->get('_token');

        } catch (\Throwable $e) { // Catch issues getting services or starting session
            $logMsg = "CSRF getToken error: Failed to get services or start session.";
            if ($logger) $logger->critical($logMsg, ['exception' => $e]);
            else error_log($logMsg . " " . $e->getMessage()); // Fallback
            // Re-throw a generic exception so the calling code knows something failed
            throw new \RuntimeException("Could not get CSRF token due to session/service error.", 0, $e);
        }
    }

     /** Regenerate the CSRF token (optional). */
     protected function refreshToken(): void
     {
         $logger = null;
         try {
             $container = App::container();
             $logger = $container->get(LoggerInterface::class);
             $session = $container->get(SessionManager::class);
             $session->start();
             $session->put('_token', bin2hex(random_bytes(32)));
         } catch (\Throwable $e) {
              $logMsg = "CSRF refreshToken error.";
              if ($logger) $logger->error($logMsg, ['exception' => $e]);
              else error_log($logMsg . " " . $e->getMessage());
              // Decide whether to throw or fail silently. Failing silently might hide issues.
              // throw new \RuntimeException("Could not refresh CSRF token.", 0, $e);
         }
     }
}