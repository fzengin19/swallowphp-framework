<?php

namespace SwallowPHP\Framework\Http;

use RuntimeException; // Use a more specific exception for internal errors
use SwallowPHP\Framework\Foundation\App; // For logger access
use Psr\Log\LoggerInterface; // For logger type hint
use Psr\Log\LogLevel; // For log levels

class Cookie
{
    /** Get logger instance helper */
    private static function logger(): ?LoggerInterface
    {
        try {
            // Check if container is initialized before getting logger
            if (App::container()) {
                return App::container()->get(LoggerInterface::class);
            }
        } catch (\Throwable $e) {
            // Fallback if logger cannot be resolved during critical cookie operations
            error_log("CRITICAL: Could not resolve LoggerInterface in Cookie class: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Set a secure, encrypted, and HMAC'd cookie.
     * @param string $name The name of the cookie.
     * @param mixed $value The value to store (will be json_encoded).
     * @param int $days The number of days until the cookie expires (0 for session).
     * @param string|null $path The path on the server. Defaults to config.
     * @param string|null $domain The domain the cookie is available to. Defaults to config.
     * @param bool|null $secure Transmit only over HTTPS. Defaults based on config/env.
     * @param bool|null $httpOnly Accessible only through HTTP protocol. Defaults to config.
     * @param string|null $sameSite Same-site policy ('Lax', 'Strict', 'None'). Defaults to config.
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public static function set(
        string $name,
        mixed $value,
        int $days = 0,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        ?bool $httpOnly = null,
        ?string $sameSite = null
    ): bool
    {
        $logger = self::logger(); // Get logger instance

        $encryptionKey = static::getDecodedAppKey();
        if ($encryptionKey === false) {
            // Error already logged by getDecodedAppKey (using logger if possible)
            return false;
        }

        $expires = ($days > 0) ? strtotime("+{$days} days") : 0;
        if ($days > 0 && $expires === false) {
            $logMsg = "Cookie setting failed for '{$name}': Could not calculate expiration time.";
            if ($logger) $logger->error($logMsg, ['days' => $days]); else error_log($logMsg);
            return false;
        }

        try {
             $iv = random_bytes(16);
        } catch (\Exception $e) {
             $logMsg = "Cookie setting failed for '{$name}': Could not generate random bytes for IV.";
             if ($logger) $logger->error($logMsg, ['exception' => $e]); else error_log($logMsg . " Error: " . $e->getMessage());
             return false;
        }

        $payload = static::encrypt($value, $encryptionKey, $iv);

        if ($payload === false) {
             // Error already logged by encrypt method
             return false;
        }

        // Determine defaults
        $secure = $secure ?? config('session.secure', config('app.env') === 'production');
        $path = $path ?? config('session.path', '/');
        $domain = $domain ?? config('session.domain', '');
        $httpOnly = $httpOnly ?? config('session.http_only', true);
        $sameSite = $sameSite ?? config('session.same_site', 'Lax');

        // Validate and adjust SameSite
        $sameSite = ucfirst(strtolower($sameSite));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'])) {
            $sameSite = 'Lax';
        }
        if ($sameSite === 'None' && !$secure) {
             $logMsg = "Cookie setting warning for '{$name}': SameSite=None requires the 'secure' attribute to be TRUE. Forcing secure flag.";
             if ($logger) $logger->warning($logMsg); else error_log($logMsg);
             $secure = true;
        }

        $cookieName = ($secure ? '__Secure-' : '') . $name;

        $options = [
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite
        ];

        try {
             return setcookie($cookieName, $payload, $options);
        } catch (\Throwable $t) {
             $logMsg = "Failed to set cookie '{$cookieName}'";
             if ($logger) $logger->error($logMsg, ['exception' => $t, 'options' => $options]); else error_log($logMsg . ": " . $t->getMessage());
             return false;
        }
    }

    /**
     * Get a cookie value, decrypting and verifying it.
     * @param string $name The name of the cookie.
     * @param mixed $default The default value to return if the cookie doesn't exist or is invalid.
     * @return mixed The decrypted cookie value or the default value.
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        $encryptionKey = static::getDecodedAppKey();
        if ($encryptionKey === false) {
            return $default; // Error logged
        }

        $securePrefix = '__Secure-';
        $cookieName = $name;
        $prefixedCookieName = $securePrefix . $name;
        $payload = null;

        if (isset($_COOKIE[$prefixedCookieName])) {
            $payload = $_COOKIE[$prefixedCookieName];
        } elseif (isset($_COOKIE[$cookieName])) {
            $payload = $_COOKIE[$cookieName];
        }

        if ($payload === null || !is_string($payload) || $payload === '') {
            return $default;
        }

        $decryptedValue = static::decrypt($payload, $encryptionKey);

        return $decryptedValue ?? $default; // decrypt returns null on failure
    }

    /**
     * Delete a cookie.
     * @param string $name The name of the cookie.
     * @param string|null $path The path of the cookie. Defaults to config.
     * @param string|null $domain The domain of the cookie. Defaults to config.
     * @return bool Returns true if a deletion attempt was made for any version of the cookie.
     */
    public static function delete(
        string $name,
        ?string $path = null,
        ?string $domain = null
    ): bool
    {
        $path = $path ?? config('session.path', '/');
        $domain = $domain ?? config('session.domain', '');
        $deleted = false;
        $cookieName = $name;
        $prefixedCookieName = '__Secure-' . $name;

        if (isset($_COOKIE[$cookieName])) {
            unset($_COOKIE[$cookieName]);
            $deleted = setcookie($cookieName, '', time() - 3600, $path, $domain);
        }
        if (isset($_COOKIE[$prefixedCookieName])) {
             unset($_COOKIE[$prefixedCookieName]);
             $deleted = setcookie($prefixedCookieName, '', time() - 3600, $path, $domain) || $deleted;
        }
        return $deleted;
    }

    /**
     * Check if a cookie exists (either prefixed or non-prefixed).
     * @param string $name The name of the cookie.
     * @return bool True if the cookie exists.
     */
    public static function has(string $name): bool
    {
        return isset($_COOKIE[$name]) || isset($_COOKIE['__Secure-' . $name]);
    }

    /**
     * Encrypt the data using AES-256-CBC and generate an HMAC.
     * @param mixed $data The data to encrypt (will be json_encoded).
     * @param string $key The raw binary encryption key (32 bytes).
     * @param string $iv The raw binary initialization vector (16 bytes).
     * @return string|false The base64 encoded payload (iv.ciphertext.mac) or false on failure.
     */
    private static function encrypt(mixed $data, string $key, string $iv): string|false
    {
        $logger = self::logger();
        try {
            $jsonData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $logMsg = "Cookie encryption failed: Could not json_encode data.";
            if ($logger) $logger->error($logMsg, ['error' => $e->getMessage()]); else error_log($logMsg . " Error: " . $e->getMessage());
            return false;
        }

        $ciphertext = openssl_encrypt($jsonData, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
             $error = openssl_error_string();
             $logMsg = "Cookie encryption failed: openssl_encrypt failed.";
             if ($logger) $logger->error($logMsg, ['openssl_error' => $error ?: 'N/A']); else error_log($logMsg . " OpenSSL Error: " . ($error ?: 'N/A'));
            return false;
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        return base64_encode($iv . $ciphertext . $mac);
    }

    /**
     * Decrypt the data after verifying the HMAC.
     * @param string $payload The base64 encoded payload (iv.ciphertext.mac).
     * @param string $key The raw binary encryption key (32 bytes).
     * @return mixed The original data or null on failure.
     */
    private static function decrypt(string $payload, string $key): mixed
    {
        $logger = self::logger();
        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < 48) {
            $logMsg = "Cookie decryption failed: Invalid base64 payload or too short.";
            if ($logger) $logger->warning($logMsg, ['payload_start' => substr($payload, 0, 10)]); else error_log($logMsg);
            return null;
        }

        $iv = substr($decoded, 0, 16);
        $mac = substr($decoded, -32);
        $ciphertext = substr($decoded, 16, -32);
        $expectedMac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        if (!hash_equals($expectedMac, $mac)) {
            $logMsg = "Cookie decryption failed: HMAC verification failed (MAC mismatch). Cookie may have been tampered with.";
            if ($logger) $logger->warning($logMsg); else error_log($logMsg);
            return null;
        }

        $decryptedJson = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($decryptedJson === false) {
             $error = openssl_error_string();
             $logMsg = "Cookie decryption failed: openssl_decrypt failed after HMAC verification.";
             if ($logger) $logger->error($logMsg, ['openssl_error' => $error ?: 'N/A']); else error_log($logMsg . " OpenSSL Error: " . ($error ?: 'N/A'));
            return null;
        }

        try {
             $data = json_decode($decryptedJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
             $logMsg = "Cookie decryption failed: Could not json_decode decrypted data.";
             if ($logger) $logger->error($logMsg, ['error' => $e->getMessage()]); else error_log($logMsg . " Error: " . $e->getMessage());
             return null;
        }
        return $data;
    }

     /** Gets and decodes the application key from the configuration. */
     private static function getDecodedAppKey(): string|false
     {
         $logger = self::logger(); // Get logger for this method too
         $key = config('app.key');
         if (empty($key) || !is_string($key)) {
             $logMsg = "Cookie operation failed: APP_KEY is not set or is not a string.";
             if ($logger) $logger->critical($logMsg); else error_log($logMsg);
             return false;
         }

         if (str_starts_with($key, 'base64:')) {
             $key = base64_decode(substr($key, 7), true);
             if ($key === false) {
                  $logMsg = "Cookie operation failed: APP_KEY is prefixed with base64: but failed to decode.";
                  if ($logger) $logger->critical($logMsg); else error_log($logMsg);
                  return false;
             }
         }

         if (strlen($key) !== 32) {
              $logMsg = "Cookie operation failed: Decoded APP_KEY must be exactly 32 bytes long for AES-256-CBC.";
              $context = ['current_length' => strlen($key)];
              if ($logger) $logger->critical($logMsg, $context); else error_log($logMsg . " Current length: " . strlen($key));
              return false;
         }

         return $key;
     }
}