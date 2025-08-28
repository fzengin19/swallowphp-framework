<?php

namespace SwallowPHP\Framework\Session;

use SessionHandlerInterface;
use SwallowPHP\Framework\Session\Handler\FileSessionHandler; // Import FileSessionHandler
use SwallowPHP\Framework\Foundation\App; // Needed for config/logger access
use SwallowPHP\Framework\Foundation\Config; // Import Config for type hint
use Psr\Log\LoggerInterface; // Import LoggerInterface
use SwallowPHP\Framework\Http\Request; // Import Request for HTTPS check

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

    /** @var LoggerInterface|null Logger instance. */
    protected ?LoggerInterface $logger = null; // Added logger property

    /**
     * Constructor - Get logger instance if available
     */
    public function __construct()
    {
        try {
            $this->logger = App::container()->get(LoggerInterface::class);
        } catch (\Throwable $e) { /* Ignore if logger cannot be resolved */
        }
    }


    /**
     * Start the session, registering the custom handler if needed.
     *
     * @return bool True if session is active, false otherwise.
     */
    public function start(): bool
    {
        // If session is already active, no need to do anything.
        // It's important to check if session_start() has been called before.
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!$this->sessionStarted) {
                $this->sessionStarted = true;
                $this->ageFlashData();
            }
            return true;
        }

        // If headers have already been sent, session cannot be started.
        if (headers_sent($file, $line)) {
            $logMsg = "Session could not be started because headers are already sent.";
            if ($this->logger) $this->logger->warning($logMsg, ['output_started_at' => "{$file}:{$line}"]);
            else error_log("Warning: " . $logMsg);
            return false;
        }

        try {
            // Only once, register the custom handler before session starts.
            if (!$this->handlerRegistered) {
                $this->registerSaveHandler();
                $this->handlerRegistered = true;
            }

            // Set session cookie parameters from config.
            $this->configureSessionCookie();

            // Start the session
            if (session_start()) {
                $this->sessionStarted = true;
                $this->ageFlashData(); // Age flash data immediately.
                return true;
            } else {
                 $logMsg = "session_start() failed to initiate session.";
                 if ($this->logger) $this->logger->error($logMsg); else error_log("Error: " . $logMsg);
                 return false;
            }
        } catch (\Throwable $e) {
             $logMsg = "Exception during session start process.";
             if ($this->logger) $this->logger->critical($logMsg, ['exception' => $e]);
             else error_log($logMsg . " " . $e->getMessage());
             return false;
        }
    }

    /**
     * Register the session save handler based on configuration.
     * @throws \RuntimeException If configuration is invalid or handler cannot be created.
     */
    protected function registerSaveHandler(): void
    {
        $config = App::container()->get(Config::class); // Use Config::class
        $driver = $config->get('session.driver', 'file');

        $this->handler = match (strtolower($driver)) {
            'file' => $this->createFileHandler($config),
            default => throw new \RuntimeException("Unsupported session driver configured: [{$driver}]"),
        };

        if (!session_set_save_handler($this->handler, true)) {
            throw new \RuntimeException("Failed to register session save handler for driver [{$driver}].");
        }

        // Register shutdown function to write session data
        register_shutdown_function('session_write_close');
    }

    /**
     * Create the file session handler.
     * @param Config $config
     * @return FileSessionHandler
     */
    protected function createFileHandler(Config $config): FileSessionHandler // Added type hint
    {
        $path = $config->get('session.files');
        if (!$path) {
            $logMsg = "Session 'file' driver path not configured.";
            if ($this->logger) $this->logger->critical($logMsg, ['config_key' => 'session.files']);
            else error_log("CRITICAL: " . $logMsg); // Fallback
            throw new \RuntimeException("Session 'file' driver path not configured in config/session.php (session.files).");
        }
        // Permissions could also be configurable via session.php
        $permissions = $config->get('session.file_permission', 0600);
        // Pass the logger instance to the handler
        return new FileSessionHandler($path, $this->logger, $permissions);
    }

    /**
     * Configure session cookie parameters based on config.
     */
    protected function configureSessionCookie(): void
    {
        $config = App::container()->get(Config::class); // Use Config::class
        $request = App::container()->get(Request::class); // Get the request instance

        session_name($config->get('session.cookie', 'swallow_session'));

        $lifetime = (int) $config->get('session.lifetime', 120) * 60;
        $path = $config->get('session.path', '/');
        $domain = $config->get('session.domain', null);
        $httpOnly = $config->get('session.http_only', true);
        $sameSite = $config->get('session.same_site', 'Lax');

        // Determine the 'secure' flag based on both config and the current request protocol.
        $secureConfig = $config->get('session.secure', null);
        $secureDefault = ($config->get('app.env') === 'production');
        $secure = $secureConfig ?? $secureDefault;

        // If the cookie is configured to be secure, but the current request is not HTTPS,
        // we must override the flag to false to prevent cookie loss.
        if ($secure && $request->getScheme() !== 'https') {
            $secure = false;
             if ($this->logger) {
                 $this->logger->warning('Session cookie security flag was overridden to FALSE because the current request is not HTTPS. Check your `session.secure` and `app.env` configurations.');
             }
        }

        $cookieLifetime = ($lifetime === 0 || $config->get('session.expire_on_close', false)) ? 0 : $lifetime;

        session_set_cookie_params([
            'lifetime' => $cookieLifetime,
            'path' => $path,
            'domain' => $domain ?? '',
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => ucfirst(strtolower($sameSite))
        ]);
    }


    /** Get an item from the session. */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureSessionStarted();
        return $_SESSION[$key] ?? $default;
    }

    /** Put an item into the session. */
    public function put(string $key, mixed $value): void
    {
        $this->ensureSessionStarted();
        $_SESSION[$key] = $value;
    }

    /** Check if an item exists in the session. */
    public function has(string $key): bool
    {
        $this->ensureSessionStarted();
        return isset($_SESSION[$key]);
    }

    /** Remove an item from the session. */
    public function remove(string $key): void
    {
        $this->ensureSessionStarted();
        unset($_SESSION[$key]);
    }

    /** Flash a key/value pair to the session. */
    public function flash(string $key, mixed $value): void
    {
        $this->ensureSessionStarted();
        $_SESSION[self::FLASH_NEW_KEY][$key] = $value;
    }

    /** Get a flashed item from the session (from old flash data). */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $this->ensureSessionStarted();
        return $_SESSION[self::FLASH_OLD_KEY][$key] ?? $default;
    }

    /** Check if an old flashed item exists. */
    public function hasFlash(string $key): bool
    {
        $this->ensureSessionStarted();
        return isset($_SESSION[self::FLASH_OLD_KEY][$key]);
    }

    /** Reflash all existing flash messages. */
    public function reflash(): void
    {
        $this->ensureSessionStarted();
        $oldFlash = $_SESSION[self::FLASH_OLD_KEY] ?? [];
        $_SESSION[self::FLASH_NEW_KEY] = array_merge($_SESSION[self::FLASH_NEW_KEY] ?? [], $oldFlash);
        $this->remove(self::FLASH_OLD_KEY);
    }

    /** Keep only specific flash messages. */
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


    /** Age the flash data. Should be called ONCE per request. */
    public function ageFlashData(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $this->remove(self::FLASH_OLD_KEY);
        if (isset($_SESSION[self::FLASH_NEW_KEY])) {
            $_SESSION[self::FLASH_OLD_KEY] = $_SESSION[self::FLASH_NEW_KEY];
        }
        $this->remove(self::FLASH_NEW_KEY);
    }

    /** Get all session data. */
    public function all(): array
    {
        $this->ensureSessionStarted();
        return $_SESSION ?? [];
    }

    /** Regenerate the session ID. */
    public function regenerate(bool $deleteOldSession = true): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_regenerate_id($deleteOldSession);
        }
        return false;
    }

    /** Destroy the current session. */
    public function destroy(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionId = session_id();
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            $destroyed = session_destroy();
            $this->sessionStarted = false;
            return $destroyed;
        }
        return false;
    }
    /** Ensures session is started before performing operations. */
    protected function ensureSessionStarted(): void
    {
        // If session object (i.e., $_SESSION) doesn't exist, try to start the session.
        // This checks if session_id() exists even if session_status() is PHP_SESSION_ACTIVE.
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            if (!$this->start()) {
                $logMsg = "Session could not be started. Headers may already be sent or handler registration failed.";
                if ($this->logger) $this->logger->error($logMsg);
                else error_log($logMsg);
                throw new \RuntimeException($logMsg);
            }
        }
    }
}
