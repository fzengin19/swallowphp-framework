<?php

namespace SwallowPHP\Framework\Http\Middleware;

use Psr\Log\LoggerInterface;
use SwallowPHP\Framework\Session\SessionManager;
use Closure;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Exceptions\CsrfTokenMismatchException;
use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Http\Middleware\Middleware;


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
     * @throws \RuntimeException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // CSRF check should only be done if session is started and exists.
        // Assuming session is started in App::run().
        if (!isset($_SESSION) && !$this->isReading($request)) {
             $logMsg = "Session is not available for CSRF check. Check App::run() and headers_sent() errors.";
             // We don't bypass CSRF check, because this is a critical security issue.
             // We log it and continue, because this might have been logged in App.
             // However, `tokensMatch` will return false, so the next line will throw an exception.
             try { App::container()->get(LoggerInterface::class)->critical($logMsg); } catch (\Throwable $_) {}
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
            if (!is_string($except) || empty($except)) continue;

            $except = '/' . ltrim($except, '/');
            if ($except !== '/') {
                $except = rtrim($except, '/');
            }

            if (str_ends_with($except, '/*')) {
                $pattern = rtrim($except, '*');
                if ($pattern === '/' || str_starts_with($requestPath, $pattern)) {
                     return true;
                }
            }
            elseif ($requestPath === $except) {
                return true;
            }
        }
        return false;
    }

    /** Determine if the session and input csrf tokens match. */
    protected function tokensMatch(Request $request): bool
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
             try { App::container()->get(LoggerInterface::class)->error("CSRF tokensMatch() called but session is not initialized."); } catch (\Throwable $_) {}
             return false;
        }

        $sessionToken = $_SESSION['_token'] ?? null;
        $token = $request->get('_token') ?: $request->header('X-CSRF-TOKEN') ?: $request->header('X-XSRF-TOKEN');

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
            $logger = $container->get(LoggerInterface::class);
            $session = $container->get(SessionManager::class);
            // App::run() in session is already started.
            // This prevents unnecessary and potentially faulty session start.

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

        } catch (\Throwable $e) {
            $logMsg = "CSRF getToken error: Failed to get services or start session.";
            if ($logger) $logger->critical($logMsg, ['exception' => $e]);
            else error_log($logMsg . " " . $e->getMessage());
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
            // App::run() in session is already started.
            $session->put('_token', bin2hex(random_bytes(32)));
        } catch (\Throwable $e) {
             $logMsg = "CSRF refreshToken error.";
             if ($logger) $logger->error($logMsg, ['exception' => $e]);
             else error_log($logMsg . " " . $e->getMessage());
        }
    }
}
