<?php

namespace SwallowPHP\Framework\Auth;

use SwallowPHP\Framework\Database\Model; // Keep for potential User model extension
use SwallowPHP\Framework\Auth\AuthenticatableModel; // Use the base model
use SwallowPHP\Framework\Http\Cookie; // Still needed for potential other cookies, but not auth state
use SwallowPHP\Framework\Session\SessionManager; // Use SessionManager
use Exception; // For mailer exception
use RuntimeException; // For internal errors
use SwallowPHP\Framework\Exceptions\AuthenticationLockoutException; // For lockout
use SwallowPHP\Framework\Contracts\CacheInterface; // Need Cache for login attempts
use SwallowPHP\Framework\Foundation\App; // Need container access

class Auth
{
    /** Stores the currently authenticated user model instance for the current request. */
    private static ?AuthenticatableModel $authenticatedUser = null;

    /** The session key used to store the authenticated user ID. */
    private const AUTH_SESSION_KEY = 'auth_user_id';

    /**
     * Logout the user by removing the authentication key from the session.
     */
    public static function logout(): void
    {
        try {
            $session = App::container()->get(SessionManager::class);
            $session->remove(self::AUTH_SESSION_KEY);
            // Optionally regenerate session ID to invalidate old session completely
            $session->regenerate(true); // Regenerate and delete old session file
        } catch (\Throwable $e) {
            // Log error if session manager fails
            error_log("Logout error: Failed to access session manager or regenerate ID. " . $e->getMessage());
        }
        self::$authenticatedUser = null; // Clear static user for current request
        // Cookie::delete('remember'); // Remove remember cookie if it exists
    }

    /**
     * Authenticates a user with the given email and password using sessions.
     *
     * @param string $email The email of the user.
     * @param string $password The password of the user.
     * @param bool $remember (Currently ignored in session-based auth, could extend session cookie lifetime later).
     * @return bool Returns true if authentication is successful, false otherwise.
     * @throws AuthenticationLockoutException If the account is locked.
     * @throws RuntimeException If a database or session error occurs.
     */
    public static function authenticate(string $email, string $password, bool $remember = false): bool
    {
        // --- Brute-Force Check ---
        $cache = null;
        $lockoutKey = null;
        $attemptKey = null;
        try {
             $rawIp = \SwallowPHP\Framework\Http\Request::getClientIp() ?? 'unknown_ip';
             $ipForKey = str_replace(':', '-', $rawIp); // Sanitize IP for key
             $cache = App::container()->get(CacheInterface::class);
             $attemptKey = 'login_attempt_' . $ipForKey . '_' . sha1($email); // Use underscore separator
             $lockoutKey = 'login_lockout_' . $ipForKey . '_' . sha1($email); // Use underscore separator

             // Check if currently locked out
             if ($cache->has($lockoutKey)) {
                  throw new AuthenticationLockoutException("Too many login attempts for {$email} from {$rawIp}. Account locked.");
             }
        } catch (\Throwable $e) {
             // Log if cache or request fails, but maybe proceed without throttling? Or throw?
             error_log("Brute-force check failed during authentication: " . $e->getMessage());
             // Decide whether to proceed without throttling or fail authentication
             // For now, let's proceed but log the issue.
        }

        // Find user by email
        $user = null;
        try {
             $modelClass = self::getUserModelClass();
             $user = $modelClass::query()->where('email', '=', $email)->first();
        } catch (\Exception $e) {
             error_log("Error fetching user during authentication: " . $e->getMessage());
             throw new RuntimeException("Database error during authentication.", 0, $e);
        }

        // Verify user exists and password is correct
        if ($user instanceof AuthenticatableModel && password_verify($password, $user->getAuthPassword())) {
            // --- Successful Login ---
            try {
                 $session = App::container()->get(SessionManager::class);

                 // Regenerate session ID to prevent session fixation
                 if (!$session->regenerate(true)) {
                      throw new RuntimeException("Failed to regenerate session ID during authentication.");
                 }

                 // Store user identifier in the session
                 $session->put(self::AUTH_SESSION_KEY, $user->getAuthIdentifier());

                 // Clear login attempts from cache
                 if ($cache && $attemptKey) $cache->delete($attemptKey);
                 if ($cache && $lockoutKey) $cache->delete($lockoutKey);

                 // Store user statically for this request
                 self::$authenticatedUser = $user;

                 // REMOVED COOKIE LOGIC
                 // if ($remember) { ... set remember cookie ... }

                 return true;

             } catch (\Throwable $e) {
                  error_log("Session/Cache error during successful authentication for {$email}: " . $e->getMessage());
                  // Attempt to clean up session if possible
                  try { App::container()->get(SessionManager::class)->remove(self::AUTH_SESSION_KEY); } catch (\Throwable $_){}
                  self::$authenticatedUser = null;
                  throw new RuntimeException("Session or Cache error prevented login completion.", 0, $e);
             }
            // --- End Successful Login ---

        } else {
            // --- Failed Login ---
            // Only increment attempts if cache was available
            if ($cache && $attemptKey && $lockoutKey) {
                 $attempts = (int) $cache->get($attemptKey, 0);
                 $attempts++;
                 $lockoutTime = config('auth.lockout_time', 900);
                 $cache->set($attemptKey, $attempts, $lockoutTime + 60); // Use TTL slightly longer than lockout

                 $maxAttempts = config('auth.max_attempts', 5);
                 if ($attempts >= $maxAttempts) {
                      error_log("Locking account for {$email} from {$rawIp} for " . $lockoutTime . " seconds.");
                      $cache->set($lockoutKey, time(), $lockoutTime);
                      // Optionally throw lockout exception immediately after setting lock
                      // throw new AuthenticationLockoutException("Account locked due to too many failed login attempts.");
                 }
            } else {
                 error_log("Login attempt failed for {$email} (Brute-force check skipped due to error).");
            }
            return false;
            // --- End Failed Login ---
        }
    }


    /**
     * Check if the user is currently authenticated based on the session.
     * Verifies session data against the database.
     *
     * @return bool Returns true if the user is authenticated, false otherwise.
     */
    public static function isAuthenticated(): bool
    {
        // If already checked and user is set in this request cycle, return true
        if (self::$authenticatedUser !== null) {
            return true;
        }

        $session = null;
        try {
            $session = App::container()->get(SessionManager::class);
        } catch (\Throwable $e) {
             error_log("Error getting SessionManager in isAuthenticated: " . $e->getMessage());
             return false;
        }

        // Check if the authentication key exists in the session
        if (!$session->has(self::AUTH_SESSION_KEY)) {
            return false;
        }

        $userId = $session->get(self::AUTH_SESSION_KEY);

        if (empty($userId)) {
            // Invalid user ID in session, remove it
            $session->remove(self::AUTH_SESSION_KEY);
            return false;
        }

        // Fetch user from DB based on the ID found in the session
        try {
             $modelClass = self::getUserModelClass();
             // Need an instance to get identifier name (usually 'id')
             $userModelInstance = new $modelClass();
             if (!$userModelInstance instanceof AuthenticatableModel) {
                  throw new \RuntimeException("Configured user model {$modelClass} does not extend AuthenticatableModel.");
             }
             $identifierName = $userModelInstance->getAuthIdentifierName();

             // Fetch user by ID
             $dbUser = $modelClass::query()->where($identifierName, '=', $userId)->first();

        } catch (\Throwable $e) {
             error_log("Error fetching user during isAuthenticated check (ID: {$userId}): " . $e->getMessage());
             // Invalidate session on DB error? Or just return false? Return false for now.
             // $session->remove(self::AUTH_SESSION_KEY);
             return false;
        }

        if ($dbUser instanceof AuthenticatableModel) {
            // User found and valid, store statically for this request
            self::$authenticatedUser = $dbUser;
            return true;
        } else {
            // User ID was in session, but user not found in DB (deleted?)
            // Invalidate the session key
            $session->remove(self::AUTH_SESSION_KEY);
            return false;
        }
    }


    /**
     * Retrieves the authenticated user instance.
     *
     * @return AuthenticatableModel|null The authenticated user object or null if not authenticated.
     */
    public static function user(): ?AuthenticatableModel
    {
        // Attempt to authenticate via session if not already done in this request cycle
        if (self::$authenticatedUser === null) {
             self::isAuthenticated(); // This will populate self::$authenticatedUser if successful
        }
        return self::$authenticatedUser;
    }

    /**
     * Checks if the authenticated user has the 'admin' role. (Example)
     *
     * @return bool
     */
    public static function isAdmin(): bool
    {
        $user = self::user();
        // Assumes 'role' property/attribute exists on the User model
        return ($user && ($user->role ?? null) === 'admin');
    }

    /**
     * Get the class name of the authenticatable model from config.
     *
     * @return string
     * @throws \RuntimeException If the model class is not configured or invalid.
     */
    protected static function getUserModelClass(): string
    {
        $modelClass = config('auth.providers.users.model', config('auth.model')); // Check new structure first

        if (empty($modelClass)) {
             throw new \RuntimeException("Authenticatable model class is not configured in config/auth.php (providers.users.model).");
        }
        if (!class_exists($modelClass)) {
            throw new \RuntimeException("Authenticatable model class '{$modelClass}' configured not found.");
        }
        if ($modelClass !== AuthenticatableModel::class && !is_subclass_of($modelClass, AuthenticatableModel::class)) {
             throw new \RuntimeException("Authenticatable model class '{$modelClass}' must extend AuthenticatableModel.");
        }
        return $modelClass;
    }
}