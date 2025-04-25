<?php

namespace SwallowPHP\Framework\Auth;

use SwallowPHP\Framework\Database\Model;
use SwallowPHP\Framework\Auth\AuthenticatableModel;
use SwallowPHP\Framework\Session\SessionManager;
use RuntimeException;
use SwallowPHP\Framework\Exceptions\AuthenticationLockoutException;
use SwallowPHP\Framework\Contracts\CacheInterface;
use SwallowPHP\Framework\Foundation\App;
use Psr\Log\LoggerInterface; // Import Logger
use Psr\Log\LogLevel; // Import LogLevel
use SwallowPHP\Framework\Http\Cookie; // Import Cookie class

class Auth
{
    private static ?AuthenticatableModel $authenticatedUser = null;
    private const AUTH_SESSION_KEY = 'auth_user_id';

    /** Get logger instance */
    private static function logger(): ?LoggerInterface
    {
        try {
            return App::container()->get(LoggerInterface::class);
        } catch (\Throwable $e) {
            error_log("CRITICAL: Could not resolve LoggerInterface in Auth class: " . $e->getMessage());
            return null; // Return null if logger fails
        }
    }

    public static function logout(): void
    {
        $logger = self::logger();
        try {
            $session = App::container()->get(SessionManager::class);
            $session->remove(self::AUTH_SESSION_KEY);
            $session->regenerate(true);
            $cookie = App::container()->get(Cookie::class);
            $cookie->delete('remember_me');
        } catch (\Throwable $e) {
            $message = "Logout error: Failed to access session manager or regenerate ID.";
            if ($logger) $logger->error($message, ['exception' => $e]);
            else error_log($message . " " . $e->getMessage()); // Fallback
        }
        self::$authenticatedUser = null;
    }

    public static function authenticate(string $email, string $password, bool $remember = false): bool
    {
        $logger = self::logger();
        $cache = null;
        $lockoutKey = null;
        $attemptKey = null;
        $rawIp = 'unknown_ip'; // Default IP

        // --- Brute-Force Check ---
        try {
            $rawIp = \SwallowPHP\Framework\Http\Request::getClientIp() ?? 'unknown_ip';
            $ipForKey = str_replace(':', '-', $rawIp);
            $cache = App::container()->get(CacheInterface::class);
            $attemptKey = 'login_attempt_' . $ipForKey . '_' . sha1($email);
            $lockoutKey = 'login_lockout_' . $ipForKey . '_' . sha1($email);

            if ($cache->has($lockoutKey)) {
                $logContext = ['email' => $email, 'ip' => $rawIp];
                if ($logger) $logger->warning("Authentication lockout: Too many attempts.", $logContext);
                throw new AuthenticationLockoutException("Too many login attempts for {$email} from {$rawIp}. Account locked.");
            }
        } catch (AuthenticationLockoutException $e) {
            throw $e; // Re-throw lockout exception
        } catch (\Throwable $e) {
            $message = "Brute-force check failed during authentication.";
            if ($logger) $logger->error($message, ['exception' => $e, 'email' => $email, 'ip' => $rawIp]);
            else error_log($message . " " . $e->getMessage()); // Fallback
            // Proceed without throttling but log the error
            $cache = null; // Ensure cache is not used later if check failed
        }

        // Find user by email
        $user = null;
        $modelClass = self::getUserModelClass();
        $user = $modelClass::query()->where('email', '=', $email)->first();

        // Verify user exists and password is correct
        if ($user instanceof AuthenticatableModel && password_verify($password, $user->getAuthPassword())) {
            // --- Successful Login ---
            try {
                $session = App::container()->get(SessionManager::class);
                if (!$session->regenerate(true)) {
                    throw new RuntimeException("Failed to regenerate session ID during authentication.");
                }
                $session->put(self::AUTH_SESSION_KEY, $user->getAuthIdentifier());

                if ($cache && $attemptKey) $cache->delete($attemptKey);
                if ($cache && $lockoutKey) $cache->delete($lockoutKey);

                self::$authenticatedUser = $user;

                // --- Handle Remember Me ---
                if ($remember) {
                    try {
                        $rawToken = bin2hex(random_bytes(32)); // Generate secure RAW token
                        $hashedToken = hash('sha256', $rawToken); // Hash the token for DB storage

                        $user->setRememberToken($hashedToken); // Set the HASHED token on the model
                        $saveResult = $user->save(); // Save the HASHED token to the database

                        // Check specifically for false, as 0 affected rows on update isn't necessarily an error here.
                        if ($saveResult === false) {
                            throw new RuntimeException("Failed to save remember token for user ID: " . $user->getAuthIdentifier());
                        }

                        // Cookie value still contains the RAW token
                        $cookieValue = $user->getAuthIdentifier() . '|' . $rawToken;
                        $lifetimeMinutes = config('auth.remember_lifetime', 43200); // Default: 30 days in minutes
                        $lifetimeDays = floor($lifetimeMinutes / 1440); // Convert minutes to days for Cookie::set

                        // Set the cookie directly using Cookie::set
                        $cookieSet = Cookie::set(
                            'remember_me', // Cookie name
                            $cookieValue,   // Value (user_id|token)
                            $lifetimeDays,  // Lifetime in days
                            // Let Cookie::set use defaults from config for path, domain, secure, httpOnly, sameSite
                        );

                        if (!$cookieSet) {
                            // Log the failure but don't necessarily fail the login
                            if ($logger) $logger->error("Failed to set remember me cookie.", ['user_id' => $user->getAuthIdentifier()]);
                        } elseif ($logger) {
                            $logger->debug("Remember me cookie set.", ['user_id' => $user->getAuthIdentifier(), 'lifetime_days' => $lifetimeDays]);
                        }
                    } catch (\Throwable $rememberError) {
                        // Log remember me token generation/save error but don't fail the login
                        $rememberMessage = "Failed to set remember me token/cookie.";
                        if ($logger) $logger->error($rememberMessage, ['exception' => $rememberError, 'user_id' => $user->getAuthIdentifier()]);
                        else error_log($rememberMessage . " " . $rememberError->getMessage()); // Fallback
                    }
                }
                // --- End Handle Remember Me ---

                return true;
            } catch (\Throwable $e) {
                // If remember me failed, it's already logged. Log other session/cache errors.
                $message = "Session/Cache/DB error during successful authentication.";
                if ($logger) $logger->critical($message, ['exception' => $e, 'email' => $email]);
                else error_log($message . " " . $e->getMessage()); // Fallback
                try {
                    App::container()->get(SessionManager::class)->remove(self::AUTH_SESSION_KEY);
                } catch (\Throwable $_) {
                }
                self::$authenticatedUser = null;
                throw new RuntimeException("Session or Cache error prevented login completion.", 0, $e);
            }
        } else {
            // --- Failed Login ---
            $logContext = ['email' => $email, 'ip' => $rawIp];
            if ($logger) $logger->warning("Login attempt failed: Invalid credentials.", $logContext);
            else error_log("Login attempt failed for {$email} from {$rawIp}: Invalid credentials."); // Fallback

            if ($cache && $attemptKey && $lockoutKey) {
                $attempts = (int) $cache->get($attemptKey, 0);
                $attempts++;
                $lockoutTime = config('auth.lockout_time', 900);
                $cache->set($attemptKey, $attempts, $lockoutTime + 60);

                $maxAttempts = config('auth.max_attempts', 5);
                if ($attempts >= $maxAttempts) {
                    $lockContext = ['email' => $email, 'ip' => $rawIp, 'lockout_time' => $lockoutTime];
                    if ($logger) $logger->warning("Account locked due to too many failed login attempts.", $lockContext);
                    else error_log("Locking account for {$email} from {$rawIp} for " . $lockoutTime . " seconds."); // Fallback
                    $cache->set($lockoutKey, time(), $lockoutTime);
                }
            }
            return false;
        }
    }

    public static function isAuthenticated(): bool
    {
        if (self::$authenticatedUser !== null) {
            return true;
        }

        $logger = self::logger();
        $session = null;
        try {
            $session = App::container()->get(SessionManager::class);
        } catch (\Throwable $e) {
            $message = "Error getting SessionManager in isAuthenticated.";
            if ($logger) $logger->error($message, ['exception' => $e]);
            else error_log($message . " " . $e->getMessage()); // Fallback
            return false;
        }

        // --- Check Remember Me Cookie FIRST ---
        $rememberCookie = Cookie::get('remember_me');
        if ($rememberCookie && is_string($rememberCookie) && str_contains($rememberCookie, '|')) {
            list($cookieUserId, $cookieToken) = explode('|', $rememberCookie, 2);

            if (!empty($cookieUserId) && !empty($cookieToken)) {
                try {
                    $modelClass = self::getUserModelClass();
                    $userModelInstance = new $modelClass();
                    if (!$userModelInstance instanceof AuthenticatableModel) {
                        throw new \RuntimeException("Configured user model {$modelClass} does not extend AuthenticatableModel.");
                    }
                    $identifierName = $userModelInstance->getAuthIdentifierName();
                    $rememberTokenName = $userModelInstance->getRememberTokenName();

                    // Find user by ID from cookie
                    $dbUser = $modelClass::query()->where($identifierName, '=', $cookieUserId)->first();

                    // --- DEBUG LOGGING ---
                    $dbTokenHash = $dbUser ? $dbUser->getRememberToken() : 'USER_NOT_FOUND';
                    $cookieTokenHash = hash('sha256', $cookieToken);

                    // --- END DEBUG LOGGING ---

                    // Verify user exists and token matches
                    // Compare the HASHED token from DB with the HASH of the RAW token from cookie
                    if (
                        $dbUser instanceof AuthenticatableModel &&
                        !empty($dbTokenHash) && $dbTokenHash !== 'USER_NOT_FOUND' && // Check if user was found and token exists
                        !empty($cookieToken) && // Ensure cookie token is not empty
                        hash_equals($dbTokenHash, $cookieTokenHash) // Compare HASHES using variables from debug log
                    ) {
                        // Valid remember me cookie! Log the user in.
                        if (!$session->regenerate(true)) {
                            throw new RuntimeException("Failed to regenerate session ID during remember me login.");
                        }
                        $session->put(self::AUTH_SESSION_KEY, $dbUser->getAuthIdentifier());
                        self::$authenticatedUser = $dbUser;
                        return true; // User is now authenticated
                    } else {
                        // Invalid cookie (user not found, token mismatch, or token empty in DB)
                        if ($logger) $logger->warning("Invalid remember me cookie detected. Deleting.", ['cookie_user_id' => $cookieUserId]);
                        Cookie::delete('remember_me'); // Delete the invalid cookie
                    }
                } catch (\Throwable $e) {
                    // Error during remember me check (DB error, etc.)
                    $message = "Error processing remember me cookie.";
                    // Log more details about the exception
                    $errorContext = [
                        'exception_message' => $e->getMessage(),
                        'exception_trace' => mb_substr($e->getTraceAsString(), 0, 2000), // Log first 2000 chars of trace
                        'cookie_user_id' => $cookieUserId ?? 'N/A'
                    ];
                    if ($logger) $logger->error($message, $errorContext);
                    else error_log($message . " Exception: " . $e->getMessage() . " Trace: " . $e->getTraceAsString()); // Fallback with more details
                    Cookie::delete('remember_me'); // Delete potentially problematic cookie
                }
            } else {
                // Malformed cookie value
                if ($logger) $logger->warning("Malformed remember me cookie value detected. Deleting.", ['value' => $rememberCookie]);
                Cookie::delete('remember_me');
            }
        }
        // --- End Check Remember Me Cookie ---

        // Proceed with session check ONLY if not authenticated via cookie
        if (!$session->has(self::AUTH_SESSION_KEY)) {
            return false; // No session and no valid remember me cookie
        }
        $userId = $session->get(self::AUTH_SESSION_KEY);
        if (empty($userId)) {
            $session->remove(self::AUTH_SESSION_KEY);
            return false;
        }

        try {
            $modelClass = self::getUserModelClass();
            $userModelInstance = new $modelClass();
            if (!$userModelInstance instanceof AuthenticatableModel) {
                throw new \RuntimeException("Configured user model {$modelClass} does not extend AuthenticatableModel.");
            }
            $identifierName = $userModelInstance->getAuthIdentifierName();
            $dbUser = $modelClass::query()->where($identifierName, '=', $userId)->first();
        } catch (\Throwable $e) {
            $message = "Error fetching user during isAuthenticated check.";
            if ($logger) $logger->error($message, ['exception' => $e, 'user_id' => $userId]);
            else error_log($message . " (ID: {$userId}): " . $e->getMessage()); // Fallback
            return false;
        }

        if ($dbUser instanceof AuthenticatableModel) {
            self::$authenticatedUser = $dbUser;
            return true;
        } else {
            if ($logger) $logger->warning("User ID found in session but user not found in DB.", ['user_id' => $userId]);
            $session->remove(self::AUTH_SESSION_KEY);
            return false;
        }
    }

    public static function user(): ?AuthenticatableModel
    {
        if (self::$authenticatedUser === null) {
            self::isAuthenticated();
        }
        return self::$authenticatedUser;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        // Use correct logical AND operator '&&'
        return ($user && ($user->role ?? null) === 'admin');
    }

    protected static function getUserModelClass(): string
    {
        // Check new structure first, fallback to old 'auth.model' if needed
        $modelClass = config('auth.providers.users.model', config('auth.model'));

        if (empty($modelClass)) {
            throw new \RuntimeException("Authenticatable model class is not configured in config/auth.php (providers.users.model).");
        }
        if (!class_exists($modelClass)) {
            throw new \RuntimeException("Authenticatable model class '{$modelClass}' configured not found.");
        }
        // Use correct logical AND operator '&&'
        if ($modelClass !== AuthenticatableModel::class && !is_subclass_of($modelClass, AuthenticatableModel::class)) {
            throw new \RuntimeException("Authenticatable model class '{$modelClass}' must extend AuthenticatableModel.");
        }
        return $modelClass;
    }
}
