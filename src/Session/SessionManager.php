<?php

namespace SwallowPHP\Framework\Session;

use SessionHandlerInterface;
use SwallowPHP\Framework\Session\Handler\FileSessionHandler; // Import FileSessionHandler
use SwallowPHP\Framework\Foundation\App; // Needed for config access

/**
 * Manages session data, including flash messages and custom handlers.
 */
class SessionManager
{
    protected const FLASH_NEW_KEY = '_flash.new';
    protected const FLASH_OLD_KEY = '_flash.old';

    /** @var bool Tracks if the session handler has been registered for this request. */
    protected bool $handlerRegistered = false;

    /** @var bool Tracks if the session has been started for this request. */
    protected bool $sessionStarted = false;

    /** @var SessionHandlerInterface|null The active session handler instance. */
    protected ?SessionHandlerInterface $handler = null;

    /**
     * Start the session, registering the custom handler if needed.
     *
     * @return bool True if session is active, false otherwise.
     */
    public function start(): bool
    {
        if ($this->sessionStarted) {
            return true;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->sessionStarted = true;
            // If session was already active externally, ensure flash is aged
            // Note: This might age flash twice if ensureSessionStarted is called later,
            // which is why a middleware approach for aging is generally better.
            $this->ageFlashData();
            return true;
        }

        if (headers_sent()) {
            error_log("Warning: Session could not be started because headers are already sent.");
            return false;
        }

        // Register the custom handler before starting the session
        if (!$this->handlerRegistered) {
            $this->registerSaveHandler();
            $this->handlerRegistered = true;
        }

        // Set session cookie parameters from config
        $this->configureSessionCookie();

        // Start the session
        if (session_start()) {
            $this->sessionStarted = true;
            // Age flash data immediately after starting the session
            $this->ageFlashData();
            return true;
        } else {
             error_log("Error: session_start() failed to initiate session.");
             return false;
        }
    }

    /**
     * Register the session save handler based on configuration.
     * @throws \RuntimeException If configuration is invalid or handler cannot be created.
     */
    protected function registerSaveHandler(): void
    {
        $config = App::container()->get(\SwallowPHP\Framework\Foundation\Config::class); // Get config instance
        $driver = $config->get('session.driver', 'file');

        $this->handler = match (strtolower($driver)) {
            'file' => $this->createFileHandler($config),
            // 'database' => $this->createDatabaseHandler($config), // Example
            // 'redis' => $this->createRedisHandler($config), // Example
            default => throw new \RuntimeException("Unsupported session driver configured: [{$driver}]"),
        };

        if (!session_set_save_handler($this->handler, true)) {
             throw new \RuntimeException("Failed to register session save handler for driver [{$driver}].");
        }

        // Register garbage collection
        // Note: session.gc_probability / session.gc_divisor handle triggering
        // We just need to ensure gc() can be called.
        register_shutdown_function('session_write_close');
    }

    /**
     * Create the file session handler.
     * @param \SwallowPHP\Framework\Foundation\Config $config
     * @return FileSessionHandler
     */
    protected function createFileHandler($config): FileSessionHandler
    {
        $path = $config->get('session.files');
        if (!$path) {
             throw new \RuntimeException("Session 'file' driver path not configured in config/session.php (session.files).");
        }
        // Permissions could also be configurable
        return new FileSessionHandler($path);
    }

    /**
     * Configure session cookie parameters based on config.
     */
    protected function configureSessionCookie(): void
    {
         $config = App::container()->get(\SwallowPHP\Framework\Foundation\Config::class);
         session_name($config->get('session.cookie', 'swallow_session'));

         $lifetime = (int) $config->get('session.lifetime', 120) * 60; // Lifetime in seconds
         $path = $config->get('session.path', '/');
         $domain = $config->get('session.domain', null);
         $secure = $config->get('session.secure', null);
         $httpOnly = $config->get('session.http_only', true);
         $sameSite = $config->get('session.same_site', 'Lax');

         // If lifetime is 0 and expire_on_close is true, set lifetime to 0 for session cookie
         if ($lifetime === 0 || $config->get('session.expire_on_close', false)) {
              $cookieLifetime = 0;
         } else {
              $cookieLifetime = $lifetime;
         }

         // Set ini settings before session_start() if possible,
         // otherwise use session_set_cookie_params().
         // Note: Some ini settings might not be changeable after startup.
         session_set_cookie_params([
             'lifetime' => $cookieLifetime,
             'path' => $path,
             'domain' => $domain ?? '', // Use empty string if null
             'secure' => $secure ?? (App::container()->get(\SwallowPHP\Framework\Foundation\Config::class)->get('app.env') === 'production'), // Default secure based on env
             'httponly' => $httpOnly,
             'samesite' => ucfirst(strtolower($sameSite)) // Ensure correct casing
         ]);
    }


    /**
     * Get an item from the session.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureSessionStarted();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Put an item into the session.
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function put(string $key, mixed $value): void
    {
        $this->ensureSessionStarted();
        $_SESSION[$key] = $value;
    }

    /**
     * Check if an item exists in the session.
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $this->ensureSessionStarted();
        return isset($_SESSION[$key]);
    }

    /**
     * Remove an item from the session.
     * @param string $key
     * @return void
     */
    public function remove(string $key): void
    {
        $this->ensureSessionStarted();
        unset($_SESSION[$key]);
    }

    /**
     * Flash a key/value pair to the session.
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function flash(string $key, mixed $value): void
    {
        $this->ensureSessionStarted();
        $_SESSION[self::FLASH_NEW_KEY][$key] = $value;
    }

    /**
     * Get a flashed item from the session (from old flash data).
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $this->ensureSessionStarted();
        // Read from 'old' flash data, which was aged at the start of the request
        return $_SESSION[self::FLASH_OLD_KEY][$key] ?? $default;
    }

     /**
      * Check if an old flashed item exists.
      * @param string $key
      * @return bool
      */
     public function hasFlash(string $key): bool
     {
         $this->ensureSessionStarted();
         return isset($_SESSION[self::FLASH_OLD_KEY][$key]);
     }

    /**
     * Reflash all existing flash messages.
     * @return void
     */
    public function reflash(): void
    {
        $this->ensureSessionStarted();
        $oldFlash = $_SESSION[self::FLASH_OLD_KEY] ?? [];
        $_SESSION[self::FLASH_NEW_KEY] = array_merge($_SESSION[self::FLASH_NEW_KEY] ?? [], $oldFlash);
        $this->remove(self::FLASH_OLD_KEY);
    }

    /**
     * Keep only specific flash messages.
     * @param string|array $keys
     * @return void
     */
    public function keep(string|array $keys): void
    {
        $this->ensureSessionStarted();
        $keys = (array) $keys;
        $oldFlash = $_SESSION[self::FLASH_OLD_KEY] ?? [];
        $newFlash = $_SESSION[self::FLASH_NEW_KEY] ?? [];

        foreach ($keys as $key) {
            if (isset($oldFlash[$key])) {
                $newFlash[$key] = $oldFlash[$key];
                unset($oldFlash[$key]);
            }
        }

        $_SESSION[self::FLASH_NEW_KEY] = $newFlash;
        $_SESSION[self::FLASH_OLD_KEY] = $oldFlash;
    }


    /**
     * Age the flash data. Should be called ONCE per request.
     * @return void
     */
    public function ageFlashData(): void
    {
        // This method should only be called if the session is active.
        // Called by start() method after session is successfully started.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $this->remove(self::FLASH_OLD_KEY);
        if (isset($_SESSION[self::FLASH_NEW_KEY])) {
            $_SESSION[self::FLASH_OLD_KEY] = $_SESSION[self::FLASH_NEW_KEY];
        }
        $this->remove(self::FLASH_NEW_KEY);
    }

    /**
     * Get all session data.
     * @return array
     */
    public function all(): array
    {
        $this->ensureSessionStarted();
        return $_SESSION ?? [];
    }

    /**
     * Regenerate the session ID.
     * @param bool $deleteOldSession
     * @return bool
     */
    public function regenerate(bool $deleteOldSession = true): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_regenerate_id($deleteOldSession);
        }
        // Cannot regenerate if session is not active
        return false;
    }

    /**
     * Destroy the current session.
     * @return bool
     */
    public function destroy(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionId = session_id(); // Get ID before destroying
            $_SESSION = []; // Clear the array

            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            // Destroy session data on server
            $destroyed = session_destroy();
            // Ensure handler's destroy method is also called if needed (session_destroy should trigger it)
            // if ($destroyed && $this->handler instanceof SessionHandlerInterface && $sessionId) {
            //     $this->handler->destroy($sessionId);
            // }
            $this->sessionStarted = false; // Mark as stopped
            return $destroyed;
        }
        return false;
    }

    /**
     * Ensures session is started before performing operations.
     * @throws \RuntimeException If session cannot be started when needed.
     */
    protected function ensureSessionStarted(): void
    {
        if (!$this->sessionStarted) {
            if (!$this->start()) {
                 throw new \RuntimeException("Session could not be started. Headers may already be sent or handler registration failed.");
            }
        }
    }
}