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
            try {
                App::container()->get(LoggerInterface::class)->critical($logMsg);
            } catch (\Throwable $_) {
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
            } elseif ($requestPath === $except) {
                return true;
            }
        }
        return false;
    }

    /** Determine if the session and input csrf tokens match. */
    protected function tokensMatch(Request $request): bool
    {
        $logger = null;
        try {
            $logger = App::container()->get(LoggerInterface::class);
        } catch (\Throwable $_) {
        }

        if (!isset($_SESSION) || !is_array($_SESSION)) {
            if ($logger) $logger->error("CSRF tokensMatch() called but session is not initialized.");
            return false;
        }

        $sessionToken = $_SESSION['_token'] ?? null;
        $requestToken = $request->get('_token');
        $headerToken = $request->header('X-CSRF-TOKEN') ?: $request->header('X-XSRF-TOKEN');
        $token = $requestToken ?: $headerToken;

        // Debug logging - bu logları production'da kaldırın!
        if ($logger) {
            $logger->debug("CSRF Debug", [
                'session_id' => session_id(),
                'session_token_exists' => isset($_SESSION['_token']),
                'session_token_preview' => $sessionToken ? substr($sessionToken, 0, 10) . '...' : 'NULL',
                'request_token_preview' => $requestToken ? substr($requestToken, 0, 10) . '...' : 'NULL',
                'header_token_preview' => $headerToken ? substr($headerToken, 0, 10) . '...' : 'NULL',
                'tokens_match' => ($sessionToken && $token) ? ($sessionToken === $token ? 'YES' : 'NO') : 'CANNOT_COMPARE',
                'session_keys' => array_keys($_SESSION ?? []),
            ]);
        }

        if (!is_string($sessionToken) || $sessionToken === '') {
            if ($logger) $logger->warning("CSRF: Session token is empty or not a string", [
                'session_token_type' => gettype($sessionToken),
            ]);
            return false;
        }

        if (!is_string($token) || $token === '') {
            if ($logger) $logger->warning("CSRF: Request token is empty or not a string", [
                'request_token_from_body' => $requestToken,
                'request_token_from_header' => $headerToken,
                'all_request_data_keys' => array_keys($request->all()),
            ]);
            return false;
        }

        $result = hash_equals($sessionToken, $token);
        
        if (!$result && $logger) {
            $logger->warning("CSRF: Token mismatch!", [
                'session_token' => $sessionToken,
                'request_token' => $token,
                'length_session' => strlen($sessionToken),
                'length_request' => strlen($token),
            ]);
        }

        return $result;
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

            // DEBUG: Session durumunu kontrol et
            if ($logger) {
                $logger->debug("CSRF getToken: Checking session state", [
                    'session_status' => session_status(),
                    'session_id' => session_id(),
                    'session_has_token' => $session->has('_token'),
                    'raw_session_token' => $_SESSION['_token'] ?? 'NOT_SET',
                    'session_keys' => array_keys($_SESSION ?? []),
                ]);
            }

            if (!$session->has('_token') || !is_string($session->get('_token'))) {
                try {
                    $newToken = bin2hex(random_bytes(32));
                    
                    // DEBUG: Token oluşturuldu
                    if ($logger) {
                        $logger->info("CSRF getToken: Generated NEW token", [
                            'token_preview' => substr($newToken, 0, 10) . '...',
                            'session_id' => session_id(),
                        ]);
                    }
                    
                    $session->put('_token', $newToken);
                    
                    // DEBUG: Token session'a yazıldı mı kontrol et
                    if ($logger) {
                        $logger->debug("CSRF getToken: After put() - verifying", [
                            'raw_session_token_after' => $_SESSION['_token'] ?? 'STILL_NOT_SET',
                            'session_get_token' => $session->get('_token'),
                            'match' => ($_SESSION['_token'] ?? '') === $newToken ? 'YES' : 'NO',
                        ]);
                    }
                    
                    return $newToken;
                } catch (\Exception $e) {
                    if ($logger) $logger->critical("CSRF getToken error: Failed to generate random bytes.", ['exception' => $e]);
                    throw new \RuntimeException("Could not generate CSRF token.", 0, $e);
                }
            }
            
            // DEBUG: Mevcut token döndürülüyor
            $existingToken = $session->get('_token');
            if ($logger) {
                $logger->debug("CSRF getToken: Returning EXISTING token", [
                    'token_preview' => substr($existingToken, 0, 10) . '...',
                    'session_id' => session_id(),
                ]);
            }
            
            return $existingToken;
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
