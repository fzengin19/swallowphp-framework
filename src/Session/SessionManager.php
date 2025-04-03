<?php

namespace SwallowPHP\Framework\Session;

/**
 * Manages session data, including flash messages.
 */
class SessionManager
{
    protected const FLASH_NEW_KEY = '_flash.new';
    protected const FLASH_OLD_KEY = '_flash.old';

    /**
     * Ensures the session has been started.
     * Should be called before any session operations.
     *
     * @return bool True if session is active, false otherwise (e.g., headers sent).
     */
    public function start(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (headers_sent()) {
            // Cannot start session if headers are already sent
            // Log this situation?
             error_log("Warning: Session could not be started because headers are already sent.");
            return false;
        }
        return session_start();
    }

    /**
     * Get an item from the session.
     *
     * @param string $key The key of the item to retrieve.
     * @param mixed $default The default value if the key doesn't exist.
     * @return mixed The value from the session or the default value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureSessionStarted();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Put an item into the session.
     *
     * @param string $key The key to store the item under.
     * @param mixed $value The value to store.
     * @return void
     */
    public function put(string $key, mixed $value): void
    {
        $this->ensureSessionStarted();
        $_SESSION[$key] = $value;
    }

    /**
     * Check if an item exists in the session.
     *
     * @param string $key The key to check.
     * @return bool True if the key exists, false otherwise.
     */
    public function has(string $key): bool
    {
        $this->ensureSessionStarted();
        return isset($_SESSION[$key]);
    }

    /**
     * Remove an item from the session.
     *
     * @param string $key The key to remove.
     * @return void
     */
    public function remove(string $key): void
    {
        $this->ensureSessionStarted();
        unset($_SESSION[$key]);
    }

    /**
     * Flash a key/value pair to the session (available on next request).
     *
     * @param string $key The key for the flash message.
     * @param mixed $value The message or data to flash.
     * @return void
     */
    public function flash(string $key, mixed $value): void
    {
        $this->ensureSessionStarted();
        $_SESSION[self::FLASH_NEW_KEY][$key] = $value;
    }

    /**
     * Get a flashed item from the session (retrieves from old flash data).
     * The item is typically removed after retrieval by the aging process.
     *
     * @param string $key The key of the flash item.
     * @param mixed $default Default value if not found.
     * @return mixed
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $this->ensureSessionStarted();
        return $_SESSION[self::FLASH_OLD_KEY][$key] ?? $default;
    }

     /**
      * Check if an old flashed item exists in the session.
      *
      * @param string $key The key to check.
      * @return bool True if the key exists in old flash data, false otherwise.
      */
     public function hasFlash(string $key): bool
     {
         $this->ensureSessionStarted();
         return isset($_SESSION[self::FLASH_OLD_KEY][$key]);
     }

    /**
     * Reflash all existing flash messages (keep them for one more request).
     * Moves old flash data back to new flash data.
     *
     * @return void
     */
    public function reflash(): void
    {
        $this->ensureSessionStarted();
        $oldFlash = $_SESSION[self::FLASH_OLD_KEY] ?? [];
        $_SESSION[self::FLASH_NEW_KEY] = array_merge($_SESSION[self::FLASH_NEW_KEY] ?? [], $oldFlash);
        $this->remove(self::FLASH_OLD_KEY); // Clear old immediately after reflash
    }

    /**
     * Keep only specific flash messages for one more request.
     *
     * @param string|array $keys Key or array of keys to keep.
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
                unset($oldFlash[$key]); // Remove from old as it's moved to new
            }
        }

        $_SESSION[self::FLASH_NEW_KEY] = $newFlash;
        $_SESSION[self::FLASH_OLD_KEY] = $oldFlash; // Update old flash with remaining items
    }


    /**
     * Age the flash data (move new to old, clear old).
     * This should be called once per request, typically at the beginning
     * via middleware or the session start process.
     *
     * @return void
     */
    public function ageFlashData(): void
    {
        $this->ensureSessionStarted();
        // Remove data flashed in the previous request ('old' flash)
        $this->remove(self::FLASH_OLD_KEY);

        // Move data flashed in the current request ('new' flash) to 'old' for the next request
        if (isset($_SESSION[self::FLASH_NEW_KEY])) {
            $_SESSION[self::FLASH_OLD_KEY] = $_SESSION[self::FLASH_NEW_KEY];
        }
        $this->remove(self::FLASH_NEW_KEY);
    }

    /**
     * Get all session data.
     *
     * @return array
     */
    public function all(): array
    {
        $this->ensureSessionStarted();
        return $_SESSION ?? [];
    }

    /**
     * Regenerate the session ID.
     * Helps prevent session fixation attacks.
     *
     * @param bool $deleteOldSession Whether to delete the old session data file.
     * @return bool True on success, false on failure.
     */
    public function regenerate(bool $deleteOldSession = true): bool
    {
        $this->ensureSessionStarted();
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Destroy the current session.
     *
     * @return bool True on success, false on failure.
     */
    public function destroy(): bool
    {
        $this->ensureSessionStarted();
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Unset all session variables
            $_SESSION = [];

            // Delete the session cookie if used
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                // Use Cookie helper for consistency? Or direct setcookie? Direct for now.
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }

            // Destroy the session
            return session_destroy();
        }
        return false;
    }

    /**
     * Ensures session is started before performing operations.
     * Throws exception if session cannot be started (e.g., headers sent).
     *
     * @throws \RuntimeException If session cannot be started when needed.
     */
    protected function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!$this->start()) {
                 // Maybe log this specific error with LoggerInterface if available?
                 throw new \RuntimeException("Session could not be started. Headers may already be sent.");
            }
             // After starting, immediately age flash data for the new request
             $this->ageFlashData();
        }
        // If session was already active, age flash data as well
        // This needs careful consideration - should aging happen only once per request?
        // A middleware approach for aging is cleaner.
        // For now, let's assume aging happens on first access that ensures start.
    }
}