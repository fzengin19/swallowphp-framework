<?php

namespace SwallowPHP\Framework\Http;

use RuntimeException; // Use a more specific exception for internal errors

class Cookie
{
    /**
     * Set a secure, encrypted, and HMAC'd cookie.
     *
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
        int $days = 0, // Default to session cookie if days = 0
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        ?bool $httpOnly = null,
        ?string $sameSite = null
    ): bool
    {
        $encryptionKey = static::getDecodedAppKey(); // Get and decode the key
        if ($encryptionKey === false) {
            // Error already logged in getDecodedAppKey
            return false;
        }

        // Calculate expiration timestamp (0 for session cookie)
        $expires = ($days > 0) ? strtotime("+{$days} days") : 0;
        if ($days > 0 && $expires === false) {
            error_log("Cookie setting failed for '{$name}': Could not calculate expiration time for {$days} days.");
            return false;
        }

        // Generate a unique IV for each encryption
        try {
             $iv = random_bytes(16); // AES-256-CBC uses 16-byte IV
        } catch (\Exception $e) {
             error_log("Cookie setting failed for '{$name}': Could not generate random bytes for IV. Ensure OpenSSL is configured correctly. Error: " . $e->getMessage());
             return false;
        }

        $payload = static::encrypt($value, $encryptionKey, $iv); // Encrypt and MAC the value

        if ($payload === false) {
             error_log("Cookie setting failed for '{$name}': Encryption process failed.");
            return false; // Encryption failed
        }

        // Determine defaults from config if not explicitly passed
        $secure = $secure ?? config('session.secure', config('app.env') === 'production');
        $path = $path ?? config('session.path', '/');
        $domain = $domain ?? config('session.domain', ''); // Use empty string if null from config
        $httpOnly = $httpOnly ?? config('session.http_only', true);
        $sameSite = $sameSite ?? config('session.same_site', 'Lax');

        // Validate and adjust SameSite
        $sameSite = ucfirst(strtolower($sameSite));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'])) {
            $sameSite = 'Lax';
        }
        if ($sameSite === 'None' && !$secure) {
             error_log("Cookie setting warning for '{$name}': SameSite=None requires the 'secure' attribute to be TRUE. Forcing secure flag.");
             $secure = true;
        }

        // Add __Secure- prefix if applicable
        $cookieName = ($secure ? '__Secure-' : '') . $name;

        $options = [
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite
        ];

        // Attempt to set the cookie
        try {
             return setcookie($cookieName, $payload, $options);
        } catch (\Throwable $t) {
             // Catch potential errors during setcookie (less common)
             error_log("Failed to set cookie '{$cookieName}': " . $t->getMessage());
             return false;
        }
    }

    /**
     * Get a cookie value, decrypting and verifying it.
     *
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

        // Check for prefixed and non-prefixed cookie names
        $securePrefix = '__Secure-';
        $cookieName = $name;
        $prefixedCookieName = $securePrefix . $name;
        $payload = null;

        if (isset($_COOKIE[$prefixedCookieName])) {
            $payload = $_COOKIE[$prefixedCookieName];
        } elseif (isset($_COOKIE[$cookieName])) {
            // Consider logging a warning if non-prefixed cookie found in secure context
            // if (config('session.secure', config('app.env') === 'production')) {
            //     error_log("Warning: Non-prefixed cookie '{$cookieName}' retrieved in a potentially secure context.");
            // }
            $payload = $_COOKIE[$cookieName];
        }

        if ($payload === null || !is_string($payload) || $payload === '') {
            return $default;
        }

        $decryptedValue = static::decrypt($payload, $encryptionKey); // Decrypt and verify

        // decrypt returns null on failure
        return $decryptedValue ?? $default;
    }

    /**
     * Delete a cookie.
     *
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
        // Get defaults from config
        $path = $path ?? config('session.path', '/');
        $domain = $domain ?? config('session.domain', ''); // Use empty string if null
        $deleted = false;
        $cookieName = $name;
        $prefixedCookieName = '__Secure-' . $name;

        // Expire non-prefixed version
        if (isset($_COOKIE[$cookieName])) {
            unset($_COOKIE[$cookieName]);
            $deleted = setcookie($cookieName, '', time() - 3600, $path, $domain);
        }
        // Expire prefixed version
        if (isset($_COOKIE[$prefixedCookieName])) {
             unset($_COOKIE[$prefixedCookieName]);
             // Use secure=true and potentially SameSite=None when deleting __Secure- cookie?
             // For simplicity, use standard deletion first. If issues arise, adjust options.
             $deleted = setcookie($prefixedCookieName, '', time() - 3600, $path, $domain) || $deleted;
        }

        return $deleted; // Return true if any deletion was attempted
    }

    /**
     * Check if a cookie exists (either prefixed or non-prefixed).
     *
     * @param string $name The name of the cookie.
     * @return bool True if the cookie exists.
     */
    public static function has(string $name): bool
    {
        return isset($_COOKIE[$name]) || isset($_COOKIE['__Secure-' . $name]);
    }

    /**
     * Encrypt the data using AES-256-CBC and generate an HMAC.
     *
     * @param mixed $data The data to encrypt (will be json_encoded).
     * @param string $key The raw binary encryption key (32 bytes).
     * @param string $iv The raw binary initialization vector (16 bytes).
     * @return string|false The base64 encoded payload (iv.ciphertext.mac) or false on failure.
     */
    private static function encrypt(mixed $data, string $key, string $iv): string|false
    {
        try {
            $jsonData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log("Cookie encryption failed: Could not json_encode data. Error: " . $e->getMessage());
            return false;
        }

        $ciphertext = openssl_encrypt($jsonData, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
             $error = openssl_error_string();
             error_log("Cookie encryption failed: openssl_encrypt failed. OpenSSL Error: " . ($error ?: 'N/A'));
            return false;
        }

        // Calculate HMAC on IV + ciphertext for Authenticated Encryption
        $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true); // true for raw output

        // Return base64 encoded IV + ciphertext + MAC
        return base64_encode($iv . $ciphertext . $mac);
    }

    /**
     * Decrypt the data after verifying the HMAC.
     *
     * @param string $payload The base64 encoded payload (iv.ciphertext.mac).
     * @param string $key The raw binary encryption key (32 bytes).
     * @return mixed The original data or null on failure (HMAC mismatch, decryption error, json decode error).
     */
    private static function decrypt(string $payload, string $key): mixed
    {
        $decoded = base64_decode($payload, true);
        // IV (16) + MAC (32) = 48 bytes minimum overhead. Ciphertext can be empty.
        if ($decoded === false || strlen($decoded) < 48) {
            error_log("Cookie decryption failed: Invalid base64 payload or too short.");
            return null;
        }

        $iv = substr($decoded, 0, 16);
        $mac = substr($decoded, -32); // Last 32 bytes (SHA256 HMAC)
        $ciphertext = substr($decoded, 16, -32); // Ciphertext is in the middle

        // Calculate expected MAC based on received IV and ciphertext
        $expectedMac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        // Verify MAC using timing attack safe comparison
        if (!hash_equals($expectedMac, $mac)) {
            error_log("Cookie decryption failed: HMAC verification failed (MAC mismatch). Cookie may have been tampered with.");
            return null;
        }

        // Decrypt only if MAC is valid
        $decryptedJson = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($decryptedJson === false) {
             $error = openssl_error_string();
             error_log("Cookie decryption failed: openssl_decrypt failed after HMAC verification. OpenSSL Error: " . ($error ?: 'N/A'));
            return null;
        }

        // Decode the JSON data
        try {
             $data = json_decode($decryptedJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
             error_log("Cookie decryption failed: Could not json_decode decrypted data. Error: " . $e->getMessage());
             return null;
        }

        return $data;
    }

     /**
      * Gets and decodes the application key from the configuration.
      * Handles the 'base64:' prefix and checks length.
      * Logs errors and returns false on failure.
      *
      * @return string|false The raw binary encryption key (exactly 32 bytes) or false on error.
      */
     private static function getDecodedAppKey(): string|false
     {
         $key = config('app.key');
         if (empty($key) || !is_string($key)) {
             error_log("Cookie operation failed: APP_KEY is not set or is not a string.");
             return false;
         }

         // Check for base64 prefix and decode if present
         if (str_starts_with($key, 'base64:')) {
             $key = base64_decode(substr($key, 7), true); // Use strict mode
             if ($key === false) {
                  error_log("Cookie operation failed: APP_KEY is prefixed with base64: but failed to decode.");
                  return false;
             }
         }
         // If no base64 prefix, assume the key is the raw key (legacy or direct input)
         // This might be insecure if the key wasn't generated properly.

         // Check the length of the raw key (MUST be 32 bytes for AES-256)
         if (strlen($key) !== 32) {
              error_log("Cookie operation failed: Decoded APP_KEY must be exactly 32 bytes long for AES-256-CBC. Current length: " . strlen($key));
              return false;
         }

         return $key;
     }
}