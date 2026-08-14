<?php

namespace SwallowPHP\Framework\Auth;

use SwallowPHP\Framework\Database\Model;
use SwallowPHP\Framework\Auth\AuthenticatableModel;
use SwallowPHP\Framework\Session\SessionManager;
use RuntimeException;
use SwallowPHP\Framework\Exceptions\AuthenticationLockoutException;
use SwallowPHP\Framework\Contracts\CacheInterface;
use SwallowPHP\Framework\Foundation\App;
use Psr\Log\LoggerInterface;
use SwallowPHP\Framework\Http\Cookie;

class Auth
{
    /**
     * The currently authenticated user instance.
     * @var AuthenticatableModel|null
     */
    private static ?AuthenticatableModel $authenticatedUser = null;

    /**
     * The session key used to store the authenticated user's ID.
     */
    private const AUTH_SESSION_KEY = 'auth_user_id';

    /**
     * The cached shared logger instance.
     * @var LoggerInterface|null
     */
    private static ?LoggerInterface $loggerInstance = null;

    /**
     * Get the shared logger instance from the container.
     *
     * The DI container (App.php) is responsible for guaranteeing
     * that this method *always* returns a valid LoggerInterface.
     *
     * @return LoggerInterface
     */
    private static function logger(): LoggerInterface
    {
        // Cache the instance locally to avoid repeated container 'get' calls.
        if (self::$loggerInstance === null) {
            self::$loggerInstance = App::container()->get(LoggerInterface::class);
        }
        return self::$loggerInstance;
    }

    /**
     * Log the current user out.
     *
     * Security-critical — three invariants this method must preserve:
     *
     * 1. Capture $user via self::user() BEFORE any session/cookie mutation
     *    so that any lazy-resolution side effect in user() (which can itself
     *    trigger session-regeneration when resolving a remember-me cookie)
     *    runs first and cleanly, with the session removal/regeneration and
     *    remember-token clearing happening in stable order afterward.
     *
     * 2. Token revocation runs OUTSIDE the session-teardown try/catch, in
     *    its own try/catch with a guaranteed cleanup. If the session
     *    container throws during remove()/regenerate(), the outer catch
     *    must NOT skip the token revocation — a remembered-me cookie
     *    captured before logout still authenticates against the un-rotated
     *    DB token otherwise.
     *
     * 3. Token revocation uses an UNCONDITIONAL UPDATE on the
     *    remember_token column, NOT $user->save(). Model::save() uses
     *    getDirty() which compares $attributes to $original — if the
     *    loaded user had remember_token=null in $original (e.g. pre-login
     *    snapshot, or fresh user) and a concurrent login wrote a token to
     *    the DB, then setRememberToken(null) writes null to $attributes
     *    (no change), getDirty() returns empty for remember_token, and
     *    save() is a no-op — the concurrently-written token stays valid.
     *    Bypass dirty tracking entirely with a direct conditional UPDATE.
     *
     * Invalidates the server-side remember-me token so a cookie captured
     * before logout cannot re-authenticate the attacker after the legitimate
     * user logs out.
     */
    public static function logout(): void
    {
        // Step 1: capture $user BEFORE any session/cookie mutation so any
        // lazy-resolution side effect in user() runs first and cleanly.
        $user = self::user();

        // Step 2: tear down session/cookie. A failure here must NOT skip
        // the token revocation below — log and continue.
        try {
            $session = App::container()->get(SessionManager::class);
            $session->remove(self::AUTH_SESSION_KEY);
            $session->regenerate(true);

            Cookie::delete('remember_me');
        } catch (\Throwable $e) {
            $message = "Logout error: Failed to access session, regenerate ID, or delete cookie.";
            self::logger()->error($message, ['exception' => $e]);
        }

        // Step 3: unconditional token revocation. OUTSIDE the try/catch
        // above so a session-teardown exception does not skip this
        // critical security step. Wrapped in its own try/catch so a DB
        // failure here is logged but does not propagate to the caller —
        // logout is best-effort, but token invalidation is mandatory.
        if ($user !== null) {
            try {
                $tokenColumn = $user->getRememberTokenName();
                $idColumn = $user->getAuthIdentifierName();
                $idValue = $user->getAuthIdentifier();
                $modelClass = $user::class;

                // Direct conditional UPDATE on the token column. Use
                // Model::query() so the framework's table/connection
                // resolution path is respected. The WHERE clause keys on
                // the user's authenticatable identifier — same lookup
                // key the remember-me path uses to load the user.
                $updateResult = $modelClass::query()
                    ->where($idColumn, '=', $idValue)
                    ->update([$tokenColumn => null]);

                if ($updateResult === false) {
                    // DB UPDATE returned false (PDOException was caught
                    // and logged by Database::update). The token may or
                    // may not have been cleared — log explicitly so the
                    // operator can investigate.
                    self::logger()->error("Failed to clear remember token on logout (DB update returned false).", [
                        'user_id' => $idValue,
                    ]);
                }
            } catch (\Throwable $tokenErr) {
                self::logger()->error("Failed to clear remember token on logout.", ['exception' => $tokenErr, 'user_id' => $user->getAuthIdentifier()]);
            }
        }

        self::$authenticatedUser = null;
    }

    /**
     * Attempt to authenticate a user using the given credentials.
     *
     * @param string $email
     * @param string $password
     * @param bool $remember
     * @return bool
     */
    public static function authenticate(string $email, string $password, bool $remember = false): bool
    {
        $cache = null;
        $lockoutKey = null;
        $attemptKey = null;
        $rawIp = 'unknown_ip';

        // --- Brute-Force Check ---
        try {
            $rawIp = \SwallowPHP\Framework\Http\Request::getClientIp() ?? 'unknown_ip';
            $ipForKey = str_replace(':', '-', $rawIp);
            $cache = App::container()->get(CacheInterface::class);
            $attemptKey = 'login_attempt_' . $ipForKey . '_' . sha1($email);
            $lockoutKey = 'login_lockout_' . $ipForKey . '_' . sha1($email);

            if ($cache->has($lockoutKey)) {
                $logContext = ['email' => $email, 'ip' => $rawIp];
                self::logger()->warning("Authentication lockout: Too many attempts.", $logContext);
                throw new AuthenticationLockoutException("Too many login attempts for {$email} from {$rawIp}. Account locked.");
            }
        } catch (AuthenticationLockoutException $e) {
            throw $e; // Re-throw lockout
        } catch (\Throwable $e) {
            $message = "Brute-force check failed during authentication.";
            self::logger()->error($message, ['exception' => $e, 'email' => $email, 'ip' => $rawIp]);
            $cache = null; // Continue without cache
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

                // Clear login attempts on success
                if ($cache) {
                    if ($attemptKey) $cache->delete($attemptKey);
                    if ($lockoutKey) $cache->delete($lockoutKey);
                }

                self::$authenticatedUser = $user;

                // --- Handle Remember Me ---
                if ($remember) {
                    try {
                        $rawToken = bin2hex(random_bytes(32)); // Secure RAW token for cookie
                        $hashedToken = hash('sha256', $rawToken); // HASHED token for DB

                        $user->setRememberToken($hashedToken);
                        $saveResult = $user->save();

                        if ($saveResult === false) {
                            throw new RuntimeException("Failed to save remember token for user ID: " . $user->getAuthIdentifier());
                        }

                        $cookieValue = $user->getAuthIdentifier() . '|' . $rawToken;
                        // Config value is in minutes (default: 43200 = 30 days)
                        $lifetimeMinutes = (int) config('auth.remember_lifetime', 43200);
                        // Cookie::set() expects days, so convert minutes -> days (ceil).
                        // If lifetime is <= 0, fall back to a session cookie (0).
                        $lifetimeDays = $lifetimeMinutes > 0 ? (int) ceil($lifetimeMinutes / 1440) : 0;

                        // Set secure cookie options from config
                        $cookieSet = Cookie::set(
                            'remember_me',
                            $cookieValue,
                            $lifetimeDays,
                            config('cookie.path', config('session.path', '/')),
                            config('cookie.domain', config('session.domain', null)),
                            config('cookie.secure', config('session.secure', false)),
                            true, // httpOnly: Force true for security
                            config('cookie.samesite', config('session.same_site', 'Lax'))
                        );

                        if (!$cookieSet) {
                            self::logger()->error("Failed to set remember me cookie.", ['user_id' => $user->getAuthIdentifier()]);
                        } else {
                            self::logger()->debug("Remember me cookie set.", [
                                'user_id' => $user->getAuthIdentifier(),
                                'lifetime_minutes' => $lifetimeMinutes,
                                'lifetime_days' => $lifetimeDays,
                            ]);
                        }
                    } catch (\Throwable $rememberError) {
                        $rememberMessage = "Failed to set remember me token/cookie.";
                        self::logger()->error($rememberMessage, ['exception' => $rememberError, 'user_id' => $user->getAuthIdentifier()]);
                    }
                }
                // --- End Handle Remember Me ---

                return true;
            } catch (\Throwable $e) {
                $message = "Session/Cache/DB error during successful authentication.";
                self::logger()->critical($message, ['exception' => $e, 'email' => $email]);
                
                try {
                    App::container()->get(SessionManager::class)->remove(self::AUTH_SESSION_KEY);
                } catch (\Throwable $_) {} // Ignore cleanup errors
                
                self::$authenticatedUser = null;
                throw new RuntimeException("Session or Cache error prevented login completion.", 0, $e);
            }
        } else {
            // --- Failed Login ---
            // Equalise response time so a missing user cannot be distinguished from a
            // wrong password (mitigates user enumeration via timing). The hash below is
            // a fixed, well-formed bcrypt digest; the result is intentionally discarded.
            if (!($user instanceof AuthenticatableModel)) {
                password_verify($password, '$2y$12$5HxC/nsKLuNSBkFXgqLbCuYXc18X6zgFSu6uLWM81RloqYoxPg1Iq');
            }

            $logContext = ['email' => $email, 'ip' => $rawIp];
            self::logger()->warning("Login attempt failed: Invalid credentials.", $logContext);

            if ($cache && $attemptKey && $lockoutKey) {
                $attempts = (int) $cache->get($attemptKey, 0);
                $attempts++;
                $lockoutTime = config('auth.lockout_time', 900);
                $cache->set($attemptKey, $attempts, $lockoutTime + 60);

                $maxAttempts = config('auth.max_attempts', 5);
                if ($attempts >= $maxAttempts) {
                    $lockContext = ['email' => $email, 'ip' => $rawIp, 'lockout_time' => $lockoutTime];
                    self::logger()->warning("Account locked due to too many failed login attempts.", $lockContext);
                    $cache->set($lockoutKey, time(), $lockoutTime);
                }
            }
            return false;
        }
    }

    /**
     * Check if the user is currently authenticated.
     *
     * @return bool
     */
    public static function isAuthenticated(): bool
    {
        if (self::$authenticatedUser !== null) {
            return true;
        }

        $session = null;
        try {
            $session = App::container()->get(SessionManager::class);
        } catch (\Throwable $e) {
            $message = "Error getting SessionManager in isAuthenticated.";
            self::logger()->error($message, ['exception' => $e]);
            return false;
        }

        // --- Check Remember Me Cookie FIRST ---
        $rememberCookie = Cookie::get('remember_me');
        if ($rememberCookie && is_string($rememberCookie) && str_contains($rememberCookie, '|')) {
            list($cookieIdentifier, $cookieToken) = explode('|', $rememberCookie, 2);

            if (!empty($cookieIdentifier) && !empty($cookieToken)) {
                try {
                    $modelClass = self::getUserModelClass();
                    
                    // Create an instance to call the identifier method for flexibility
                    $userModelInstance = new $modelClass();
                    $identifierName = $userModelInstance->getAuthIdentifierName();

                    // Find user by ID from cookie
                    $dbUser = $modelClass::query()->where($identifierName, '=', $cookieIdentifier)->first();

                    // Compare the HASHED token from DB with the HASH of the RAW token from cookie
                    $dbTokenHash = $dbUser ? $dbUser->getRememberToken() : null;
                    $cookieTokenHash = hash('sha256', $cookieToken);

                    if (
                        $dbUser instanceof AuthenticatableModel &&
                        !empty($dbTokenHash) &&
                        !empty($cookieToken) &&
                        hash_equals($dbTokenHash, $cookieTokenHash) // Timing-attack-safe comparison
                    ) {
                        // Valid remember me cookie. Log the user in.
                        // SADECE session'da henüz user yoksa session regenerate et
                        // Bu, her sayfa yüklemesinde session ID'nin değişmesini önler
                        $currentSessionUserId = $session->get(self::AUTH_SESSION_KEY);
                        if ($currentSessionUserId !== $dbUser->getAuthIdentifier()) {
                            // Session'da farklı user var veya hiç yok, regenerate et
                            if (!$session->regenerate(true)) {
                                throw new RuntimeException("Failed to regenerate session ID during remember me login.");
                            }
                            $session->put(self::AUTH_SESSION_KEY, $dbUser->getAuthIdentifier());
                        }
                        // Session zaten doğru user'ı içeriyorsa regenerate etmiyoruz
                        self::$authenticatedUser = $dbUser;
                        return true;
                    } else {
                        // Invalid cookie (user not found, token mismatch, or token empty)
                        self::logger()->warning("Invalid remember me cookie detected. Deleting.", ['cookie_identifier' => $cookieIdentifier]);
                        Cookie::delete('remember_me');
                    }
                } catch (\Throwable $e) {
                    // Error during remember me check (e.g., DB error)
                    $message = "Error processing remember me cookie.";
                    $errorContext = [
                        'exception_message' => $e->getMessage(),
                        'cookie_identifier' => $cookieIdentifier ?? 'N/A'
                    ];
                    self::logger()->error($message, $errorContext);
                    Cookie::delete('remember_me'); // Delete problematic cookie
                }
            } else {
                // Malformed cookie value (e.g., missing '|')
                self::logger()->warning("Malformed remember me cookie value detected. Deleting.", ['value' => substr($rememberCookie, 0, 10) . '...']);
                Cookie::delete('remember_me');
            }
        }
        // --- End Check Remember Me Cookie ---

        // Proceed with session check ONLY if not authenticated via cookie
        if (!$session->has(self::AUTH_SESSION_KEY)) {
            return false;
        }
        
        $userId = $session->get(self::AUTH_SESSION_KEY);
        if (empty($userId)) {
            $session->remove(self::AUTH_SESSION_KEY);
            return false;
        }

        try {
            $modelClass = self::getUserModelClass();
            
            // Create an instance to call the identifier method for flexibility
            $userModelInstance = new $modelClass();
            $identifierName = $userModelInstance->getAuthIdentifierName();
            $dbUser = $modelClass::query()->where($identifierName, '=', $userId)->first();
        } catch (\Throwable $e) {
            $message = "Error fetching user from session ID during isAuthenticated check.";
            self::logger()->error($message, ['exception' => $e, 'user_id' => $userId]);
            return false;
        }

        if ($dbUser instanceof AuthenticatableModel) {
            self::$authenticatedUser = $dbUser; // Cache user instance
            return true;
        } else {
            // User ID was in session, but no longer in database
            self::logger()->warning("User ID found in session but user not found in DB.", ['user_id' => $userId]);
            $session->remove(self::AUTH_SESSION_KEY);
            return false;
        }
    }

    /**
     * Get the currently authenticated user.
     *
     * @return AuthenticatableModel|null
     */
    public static function user(): ?AuthenticatableModel
    {
        if (self::$authenticatedUser === null) {
            self::isAuthenticated(); // Attempt to load user if not already loaded
        }
        return self::$authenticatedUser;
    }

    // Note: isAdmin() method was removed.
    // Authorization checks (like 'isAdmin') do not belong in the
    // Authentication class; they belong in the User model or a dedicated Policy/Gate class.

    /**
     * Get the configured user model class name.
     *
     * @return string
     * @throws \RuntimeException
     */
    protected static function getUserModelClass(): string
    {
        // Standardize on a single config key
        $modelClass = config('auth.model');

        if (empty($modelClass)) {
            throw new \RuntimeException("Authenticatable model class is not configured in config/auth.php (auth.model).");
        }
        if (!class_exists($modelClass)) {
            throw new \RuntimeException("Authenticatable model class '{$modelClass}' configured not found.");
        }
        // Ensure the model is the base class or a subclass of it
        if ($modelClass !== AuthenticatableModel::class && !is_subclass_of($modelClass, AuthenticatableModel::class)) {
            throw new \RuntimeException("Authenticatable model class '{$modelClass}' must extend AuthenticatableModel.");
        }
        return $modelClass;
    }
}
