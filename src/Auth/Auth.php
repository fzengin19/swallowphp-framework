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
        try {
             $modelClass = self::getUserModelClass();
             $user = $modelClass::query()->where('email', '=', $email)->first();
        } catch (\Exception $e) {
             $message = "Database error during authentication.";
             if ($logger) $logger->critical($message, ['exception' => $e, 'email' => $email]);
             else error_log($message . " " . $e->getMessage()); // Fallback
             throw new RuntimeException($message, 0, $e);
        }

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
                 if ($logger) $logger->info("User authenticated successfully.", ['user_id' => $user->getAuthIdentifier(), 'email' => $email]);
                 return true;

             } catch (\Throwable $e) {
                  $message = "Session/Cache error during successful authentication.";
                  if ($logger) $logger->critical($message, ['exception' => $e, 'email' => $email]);
                  else error_log($message . " " . $e->getMessage()); // Fallback
                  try { App::container()->get(SessionManager::class)->remove(self::AUTH_SESSION_KEY); } catch (\Throwable $_){}
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