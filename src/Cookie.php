<?php

namespace SwallowPHP\Framework;

class Cookie
{
    /**
     * Set a secure, encrypted, and HMAC'd cookie.
     *
     * @param string $name The name of the cookie.
     * @param mixed $value The value to store (will be json_encoded).
     * @param int $days The number of days until the cookie expires.
     * @param string $path The path on the server in which the cookie will be available on.
     * @param string $domain The (sub)domain that the cookie is available to.
     * @param bool|null $secure Indicates that the cookie should only be transmitted over a secure HTTPS connection. Defaults to env('APP_ENV') === 'production'.
     * @param bool $httpOnly When TRUE the cookie will be made accessible only through the HTTP protocol.
     * @param string $sameSite Declares that the cookie should be restricted to a first-party or same-site context ('Lax' or 'Strict').
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public static function set(
        string $name,
        mixed $value,
        int $days = 1,
        string $path = '/',
        string $domain = '',
        ?bool $secure = null,
        bool $httpOnly = true,
        string $sameSite = 'Lax' // Default to Lax for better usability
    ): bool
    {
        $key = env('APP_KEY');
        if (empty($key)) {
            error_log("Cookie setting failed: APP_KEY is not set in environment.");
            return false;
        }
        if (strlen($key) < 32) {
             error_log("Cookie setting failed: APP_KEY must be at least 32 bytes long.");
             return false;
        }

        // Use strtotime for safer expiration calculation
        $expires = strtotime("+{$days} days");
        if ($expires === false) {
            error_log("Cookie setting failed: Could not calculate expiration time for {$days} days.");
            return false;
        }

        $iv = random_bytes(16); // Generate a random 16-byte IV
        $payload = static::encrypt($value, $key, $iv); // Encrypt and MAC the value

        if ($payload === false) {
            return false; // Encryption failed
        }

        // Determine secure flag based on environment if not explicitly set
        if (is_null($secure)) {
            $secure = env('APP_ENV') === 'production';
        }

        // Validate SameSite attribute
        $sameSite = ucfirst(strtolower($sameSite));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'])) {
            $sameSite = 'Lax'; // Default to Lax if invalid
        }
        // 'None' requires the 'secure' flag
        if ($sameSite === 'None' && !$secure) {
             error_log("Cookie setting warning: SameSite=None requires the 'secure' attribute to be TRUE.");
             // Optionally force secure or change SameSite to Lax
             $secure = true;
        }

        // Add __Secure- prefix if the cookie is secure
        $cookieName = ($secure ? '__Secure-' : '') . $name;

        $options = [
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain ?: '', // Use empty string if not set
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite
        ];

        return setcookie($cookieName, $payload, $options);
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
        $key = env('APP_KEY');
        if (empty($key)) {
            error_log("Cookie getting failed: APP_KEY is not set in environment.");
            return $default;
        }

        // Check for prefixed and non-prefixed cookie names
        $securePrefix = '__Secure-';
        $cookieName = $name;
        $prefixedCookieName = $securePrefix . $name;
        $payload = null;

        if (isset($_COOKIE[$prefixedCookieName])) {
            $payload = $_COOKIE[$prefixedCookieName];
        } elseif (isset($_COOKIE[$cookieName])) {
            // If found without prefix, maybe log a warning if secure context expected?
            $payload = $_COOKIE[$cookieName];
        }

        if ($payload === null) {
            return $default;
        }

        $decryptedValue = static::decrypt($payload, $key); // Decrypt and verify

        // decrypt returns null on failure
        return $decryptedValue ?? $default;
    }

    /**
     * Delete a cookie.
     *
     * @param string $name The name of the cookie.
     * @param string $path The path of the cookie.
     * @param string $domain The domain of the cookie.
     * @return bool Returns true if a deletion attempt was made for any version of the cookie.
     */
    public static function delete(string $name, string $path = '/', string $domain = ''): bool
    {
        // Try deleting both prefixed and non-prefixed versions
        $deleted = false;
        $cookieName = $name;
        $prefixedCookieName = '__Secure-' . $name;

        if (isset($_COOKIE[$cookieName])) {
            unset($_COOKIE[$cookieName]);
            // Set cookie with past expiration date
            $deleted = setcookie($cookieName, '', time() - 3600, $path, $domain ?: '');
        }
        if (isset($_COOKIE[$prefixedCookieName])) {
             unset($_COOKIE[$prefixedCookieName]);
             // Set cookie with past expiration date
             $deleted = setcookie($prefixedCookieName, '', time() - 3600, $path, $domain ?: '') || $deleted;
        }

        return $deleted; // Return true if any version was attempted to be deleted
    }

    /**
     * Check if a cookie exists.
     *
     * @param string $name The name of the cookie.
     * @return bool True if the cookie exists (either prefixed or non-prefixed).
     */
    public static function has($name)
    {
        // Check for both prefixed and non-prefixed cookie
        return isset($_COOKIE[$name]) || isset($_COOKIE['__Secure-' . $name]);
    }

    /**
     * Encrypt the data using AES-256-CBC and generate an HMAC.
     *
     * @param mixed $data The data to encrypt (will be json_encoded).
     * @param string $key The encryption key.
     * @param string $iv The initialization vector.
     * @return string|false The base64 encoded payload (iv.ciphertext.mac) or false on failure.
     */
    private static function encrypt(mixed $data, string $key, string $iv): string|false
    {
        $jsonData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            error_log("Cookie encryption failed: Could not json_encode data.");
            return false;
        }

        $ciphertext = openssl_encrypt($jsonData, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
             error_log("Cookie encryption failed: openssl_encrypt failed.");
            return false;
        }

        // Calculate HMAC on IV + ciphertext
        $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true); // true for raw output

        // Return base64 encoded IV + ciphertext + MAC
        return base64_encode($iv . $ciphertext . $mac);
    }

    /**
     * Decrypt the data after verifying the HMAC.
     *
     * @param string $payload The base64 encoded payload (iv.ciphertext.mac).
     * @param string $key The encryption key.
     * @return mixed The original data or null on failure (HMAC mismatch or decryption error).
     */
    private static function decrypt(string $payload, string $key): mixed
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < 48) { // 16 bytes IV + 32 bytes HMAC = 48 bytes minimum
            error_log("Cookie decryption failed: Invalid payload or too short.");
            return null;
        }

        $iv = substr($decoded, 0, 16);
        $mac = substr($decoded, -32); // Last 32 bytes are the HMAC
        $ciphertext = substr($decoded, 16, -32); // Ciphertext is between IV and HMAC

        // Calculate expected MAC
        $expectedMac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        // Verify MAC (timing attack safe comparison)
        if (!hash_equals($expectedMac, $mac)) {
            error_log("Cookie decryption failed: HMAC verification failed.");
            return null; // MAC mismatch, data tampered!
        }

        // Decrypt if MAC is valid
        $decryptedJson = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($decryptedJson === false) {
            error_log("Cookie decryption failed: openssl_decrypt failed after HMAC verification.");
            return null; // Decryption failed
        }

        $data = json_decode($decryptedJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
             error_log("Cookie decryption failed: Could not json_decode decrypted data. Error: " . json_last_error_msg());
             return null; // JSON decode failed
        }

        return $data;
    }
}
