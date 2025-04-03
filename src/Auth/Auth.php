<?php

namespace SwallowPHP\Framework\Auth;

use SwallowPHP\Framework\Database\Model; // Keep for potential User model extension
// Removed: use App\Models\User; 
use SwallowPHP\Framework\Auth\AuthenticatableModel; // Use the base model
use SwallowPHP\Framework\Http\Cookie;
use Exception; // For mailer exception
use RuntimeException; // For internal errors
use SwallowPHP\Framework\Exceptions\AuthenticationLockoutException; // For lockout
use SwallowPHP\Framework\Contracts\CacheInterface; // Need Cache for login attempts
use SwallowPHP\Framework\Foundation\App; // Need container access


class Auth
{
    /** Stores the currently authenticated user model instance. */
    private static ?AuthenticatableModel $authenticatedUser = null; // Updated type hint

    /**
     * Logout the user by deleting the 'user' cookie.
     */
    public static function logout(): void
    {
        Cookie::delete('user');
        Cookie::delete('remember'); // Also delete remember cookie
        self::$authenticatedUser = null; // Clear static user
    }

    /**
     * Authenticates a user with the given email and password.
     *
     * @param string $email The email of the user.
     * @param string $password The password of the user.
     * @param bool $remember (Optional) Whether to remember the user or not. Default is false.
     * @return bool Returns true if authentication is successful, false otherwise.
     * @throws AuthenticationLockoutException If the account is locked.
     * @throws RuntimeException If a database or cookie error occurs.
     */
    public static function authenticate(string $email, string $password, bool $remember = false): bool
    {
        // --- Brute-Force Check ---
        $rawIp = \SwallowPHP\Framework\Http\Request::getClientIp() ?? 'unknown_ip';
        // Sanitize IP for cache key (replace IPv6 colons)
        $ipForKey = str_replace(':', '-', $rawIp);
        $cache = App::container()->get(CacheInterface::class);
        $attemptKey = 'login_attempt:' . $ipForKey . ':' . sha1($email); // Use sanitized IP
        $lockoutKey = 'login_lockout:' . $ipForKey . ':' . sha1($email); // Use sanitized IP
        $now = time();
        $maxAttempts = config('auth.max_attempts', 5); // Get from config
        $lockoutTime = config('auth.lockout_time', 900); // Get from config

        // Check if currently locked out
        if ($cache->has($lockoutKey)) {
             // Throw specific exception for lockout
             throw new AuthenticationLockoutException("Too many login attempts for {$email} from {$rawIp}. Account locked."); // Show original IP in message
        }

        // Get current attempt count
        $attempts = (int) $cache->get($attemptKey, 0);
        // --- End Brute-Force Check ---

        // Find user by email
        try {
             $modelClass = self::getUserModelClass();
             $user = $modelClass::query()->where('email', '=', $email)->first();
        } catch (\Exception $e) {
             error_log("Error fetching user during authentication: " . $e->getMessage());
             // Re-throw as a runtime exception
             throw new RuntimeException("Database error during authentication.", 0, $e);
        }


        // Verify user exists and password is correct
        if ($user instanceof AuthenticatableModel && password_verify($password, $user->getAuthPassword())) {
            // --- Successful Login ---
            // Clear attempts and lockout from cache on successful login
            $cache->delete($attemptKey);
            $cache->delete($lockoutKey);

            // Store authenticated user statically for this request
            self::$authenticatedUser = $user;

            // Prepare data for cookie (exclude sensitive info like password)
            $userData = ($user instanceof Model) ? $user->toArray() : [];
            unset($userData['password']);

            // Set secure cookies
            $days = $remember ? 30 : 0;
            $cookieSet = Cookie::set(
                name: 'user',
                value: $userData,
                days: $days,
                path: config('session.path', '/'),
                domain: config('session.domain', ''),
                secure: config('session.secure', null), // Let Cookie::set handle default based on env
                httpOnly: config('session.http_only', true),
                sameSite: config('session.same_site', 'Lax')
            );

            if (!$cookieSet) {
                 // Throw an exception if cookie setting fails critical auth flow
                 error_log("Authentication failed for {$email}: Could not set user cookie.");
                 self::$authenticatedUser = null;
                 throw new RuntimeException("Authentication failed for {$email}: Could not set user cookie.");
            }

            if ($remember) {
                 Cookie::set(
                     name: 'remember',
                     value: 'true',
                     days: 30,
                     path: config('session.path', '/'),
                     domain: config('session.domain', ''),
                     secure: config('session.secure', null),
                     httpOnly: config('session.http_only', true),
                     sameSite: config('session.same_site', 'Lax')
                 );
            } else {
                 Cookie::delete('remember');
            }

            return true;
            // --- End Successful Login ---

        } else {
            // --- Failed Login ---
            error_log("Login attempt failed for {$email} from {$rawIp}: Invalid credentials."); // Log original IP

            // Increment attempt count in cache
            $attempts++;
            $cache->set($attemptKey, $attempts, $lockoutTime + 60); // Use TTL slightly longer than lockout

            // Check if lockout threshold is reached
            if ($attempts >= $maxAttempts) {
                 error_log("Locking account for {$email} from {$rawIp} for " . $lockoutTime . " seconds."); // Log original IP
                 // Set lockout key with TTL
                 $cache->set($lockoutKey, $now, $lockoutTime);
            }

            return false;
            // --- End Failed Login ---
        }
    }


    /**
     * Check if the user is currently authenticated based on the cookie.
     * Verifies cookie data against the database.
     *
     * @return bool Returns true if the user is authenticated, false otherwise.
     */
    public static function isAuthenticated(): bool
    {
        // If already checked and user is set in this request cycle, return true
        if (self::$authenticatedUser !== null) {
            return true;
        }

        if (!Cookie::has('user')) {
            return false;
        }

        $cookieUserData = Cookie::get('user');
        if (!is_array($cookieUserData)) {
            Cookie::delete('user'); // Clean up invalid cookie
            return false;
        }

        // Get the configured user model class
        try {
             $modelClass = self::getUserModelClass();
             // Need an instance to get identifier name
             $userModelInstance = new $modelClass();
             if (!$userModelInstance instanceof AuthenticatableModel) { // Check against base model
                  throw new \RuntimeException("Configured user model {$modelClass} does not extend AuthenticatableModel.");
             }
             $identifierName = $userModelInstance->getAuthIdentifierName();

        } catch (\Exception $e) {
             error_log("Error getting user model class or identifier name: " . $e->getMessage());
             return false;
        }

        $identifierValue = $cookieUserData[$identifierName] ?? null;

        if ($identifierValue === null) {
            Cookie::delete('user');
            return false;
        }

        // Fetch user from DB
        try {
             $dbUser = $modelClass::query()->where($identifierName, '=', $identifierValue)->first();
        } catch (\Exception $e) {
             error_log("Error fetching user during isAuthenticated check: " . $e->getMessage());
             return false; // DB error
        }


        if ($dbUser instanceof AuthenticatableModel) { // Check against base model
            // Optional: More robust check
            // if ($cookieUserData['some_hash'] !== $dbUser->getValidationHash()) { ... }

            // Simple check (less secure):
            // if (($dbUser instanceof Model) && ($cookieUserData != $dbUser->toArray())) { ... }

            // If checks pass, store the user and return true
            self::$authenticatedUser = $dbUser;
            return true;

        } else {
            // User not found in DB or invalid type
            Cookie::delete('user');
            return false;
        }
    }


    /**
     * Retrieves the authenticated user instance.
     *
     * @return AuthenticatableModel|null The authenticated user object or null if not authenticated. // Updated return type
     */
    public static function user(): ?AuthenticatableModel // Updated return type hint
    {
        // Attempt to authenticate if not already done in this request cycle
        if (self::$authenticatedUser === null) {
             static::isAuthenticated(); // This will populate self::$authenticatedUser if successful
        }
        return self::$authenticatedUser;
    }

    /**
     * Checks if the authenticated user has the 'admin' role.
     *
     * @return bool
     */
    public static function isAdmin(): bool
    {
        $user = self::user(); // Get the authenticated user instance (or null)
        // Check if user exists and has the 'role' property/attribute set to 'admin'
        // Accessing 'role' directly assumes it's a public property or uses __get magic method
        return ($user && ($user->role ?? null) === 'admin');
    }

    /**
     * Get the class name of the authenticatable model.
     *
     * @return string
     * @throws \RuntimeException If the model class is not configured or invalid.
     */
    protected static function getUserModelClass(): string
    {
        // Get the configured user model class name from config.
        $modelClass = config('auth.model'); // Get from config

        if (empty($modelClass)) {
             throw new \RuntimeException("Authenticatable model class is not configured in config/auth.php or .env (AUTH_MODEL).");
        }

        if (!class_exists($modelClass)) {
            throw new \RuntimeException("Authenticatable model class '{$modelClass}' configured in config/auth.php not found.");
        }

        // Check if the class extends AuthenticatableModel
        if ($modelClass !== AuthenticatableModel::class && !is_subclass_of($modelClass, AuthenticatableModel::class)) {
             throw new \RuntimeException("Authenticatable model class '{$modelClass}' must extend AuthenticatableModel.");
        }

        return $modelClass;
    }
}