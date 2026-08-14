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

    /**
     * @var bool Tracks if the session handler has been registered for this request.
     *
     * STATIC: PHP's session_set_save_handler() / session_start() state is
     * request-global (one PHP session engine per request, not per
     * SessionManager instance). The "handler is registered" fact is
     * therefore also request-global — keeping this as an instance
     * property would let a SECOND SessionManager instance, in the same
     * request, believe no handler was ever registered and log the
     * "default handler in effect" warning against an actually-registered
     * custom handler. Static state aligns the flag's lifetime with the
     * underlying PHP state it tracks.
     */
    protected static bool $handlerRegistered = false;

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
            // If the session is active but we never registered our custom
            // save handler, it means something (session.auto_start=1, an
            // unrelated session_start() call, ...) started PHP's session
            // engine BEFORE any SessionManager method ran. We cannot
            // retroactively register our handler (PHP requires
            // session_set_save_handler() to run BEFORE session_start()),
            // but the honest fix is to log a clear warning so an operator
            // debugging "why isn't my custom session handler being used"
            // gets a real diagnostic instead of silent fallback to PHP's
            // default handler.
            if (!self::$handlerRegistered) {
                $logMsg = "SessionManager::start() found an already-active PHP session that "
                    . "was not started via this SessionManager — the custom save handler "
                    . "(FileSessionHandler) could not be registered (PHP requires "
                    . "session_set_save_handler() before session_start()). PHP's default "
                    . "session handler is in effect for this request instead.";
                if ($this->logger) $this->logger->warning($logMsg);
                else error_log("Warning: " . $logMsg);
            }
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
            if (!self::$handlerRegistered) {
                $this->registerSaveHandler();
                self::$handlerRegistered = true;
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

        // session_set_cookie_params() raises a benign warning in CLI SAPI
        // when session.use_cookies=0 ("Session cookies cannot be used when
        // session.use_cookies is disabled") — not actionable in CLI test
        // contexts and pollutes test output. PHP's `@` operator cannot
        // suppress this one because PHPUnit's ErrorHandler still captures
        // it as a test warning regardless of suppression. Skip the call
        // entirely when cookies are disabled; PHP will not use cookie
        // params anyway in that mode (it's a no-op in that configuration).
        if (!ini_get('session.use_cookies')) {
            return;
        }

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
        $newFlash = $_SESSION[self::FLASH_NEW_KEY] ?? [];
        // Use the `+` operator (NOT array_merge): `+` preserves the LEFT
        // operand's keys verbatim, so numeric-string keys like '0' stay
        // '0' (array_merge() would reindex them to 0, 1, 2... and let
        // a stale same-numeric-key value silently win by coming later).
        // The LEFT operand also wins on key collisions, so a fresh
        // same-key value in $newFlash overwrites any stale same-key
        // value promoted from $oldFlash — the behavior we want.
        $_SESSION[self::FLASH_NEW_KEY] = $newFlash + $oldFlash;
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
                // Only promote the stale value if this key wasn't already
                // freshly flashed this request — unconditionally clobbering
                // it would let a stale same-key value overwrite a fresh one.
                if (!array_key_exists($key, $newFlash)) {
                    $newFlash[$key] = $oldFlash[$key];
                }
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
        // Match the established pattern of every other accessor in this
        // class (hasFlash()/reflash()/keep()/all()/...) — lazily start
        // the session if it isn't already active, throwing a clear
        // \RuntimeException if start() fails (e.g. headers already
        // sent). Without this, regenerate() silently returns false
        // when called before the session is active, which is a
        // inconsistent failure mode vs the rest of the class.
        $this->ensureSessionStarted();
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
        // BOTH conditions matter — checking only `!isset($_SESSION) ||
        // !is_array($_SESSION)` is insufficient: after session_write_close()
        // the PHP session engine is inactive (session_status() returns
        // PHP_SESSION_NONE), but $_SESSION survives as a plain PHP
        // variable still shaped as an array. A subsequent accessor
        // (regenerate(), get(), put(), ...) on the closed session would
        // see $_SESSION as a populated array and skip the start() call,
        // then silently fail at the operation (regenerate() returns
        // false; $_SESSION writes would be silently lost because PHP
        // has no session to persist them on). The session_status()
        // check catches that case and forces a real session_start().
        if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION) || !is_array($_SESSION)) {
            if (!$this->start()) {
                $logMsg = "Session could not be started. Headers may already be sent or handler registration failed.";
                if ($this->logger) $this->logger->error($logMsg);
                else error_log($logMsg);
                throw new \RuntimeException($logMsg);
            }
        }
    }
}
