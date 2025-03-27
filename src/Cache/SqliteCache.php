<?php

namespace SwallowPHP\Framework\Cache;

use SwallowPHP\Framework\Contracts\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;
use PDO;
use PDOException;
use DateInterval;
use DateTimeImmutable;
use Exception; // For internal errors

class SqliteCache implements CacheInterface
{
    private ?PDO $db = null;
    private string $dbPath;
    private string $tableName;

    /**
     * SqliteCache constructor.
     *
     * @param string $dbPath Absolute path to the SQLite database file.
     */
    public function __construct(string $dbPath, string $tableName = 'cache')
    {
        if (empty($dbPath)) {
            throw new Psr16InvalidArgumentException("SQLite database path cannot be empty.");
        }
        $this->dbPath = $dbPath;
        $this->tableName = $tableName; // Set table name
        // Ensure directory exists
        $dbDir = dirname($this->dbPath);
        if (!is_dir($dbDir)) {
            if (!@mkdir($dbDir, 0750, true)) { // Use appropriate permissions
                throw new \RuntimeException("Failed to create SQLite cache directory: {$dbDir}");
            }
        }
         if (!is_writable($dbDir)) {
             throw new \RuntimeException("SQLite cache directory is not writable: {$dbDir}");
         }
        $this->connect(); // Connect and ensure table exists on instantiation
    }

    /**
     * Establishes the database connection if not already connected.
     */
    private function connect(): void
    {
        if ($this->db === null) {
            try {
                // Use WAL mode for better concurrency if possible (requires SQLite >= 3.7.0)
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5, // Set a timeout
                ];
                $this->db = new PDO('sqlite:' . $this->dbPath, null, null, $options);
                $this->db->exec('PRAGMA journal_mode = WAL;'); // Enable WAL mode
                $this->db->exec("CREATE TABLE IF NOT EXISTS `{$this->tableName}` (key TEXT PRIMARY KEY, value TEXT, expiration INTEGER)");
            } catch (PDOException $e) {
                // Log the error appropriately
                error_log("SQLite Cache connection failed: " . $e->getMessage());
                throw new \RuntimeException("Could not connect to SQLite cache database: " . $e->getMessage(), 0, $e);
            }
        }
    }

    // PSR-16 Methods Implementation

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        try {
            $stmt = $this->db->prepare("SELECT value, expiration FROM `{$this->tableName}` WHERE key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result === false) {
                return $default; // Not found
            }

            if ($this->isRowExpired($result)) {
                $this->delete($key); // Delete expired item
                return $default;
            }

            // Use json_decode instead of unserialize
            $value = json_decode($result['value'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                 error_log("SQLite Cache: Failed to json_decode value for key '{$key}'. Error: " . json_last_error_msg());
                 $this->delete($key); // Delete corrupted item
                 return $default;
            }
            return $value;

        } catch (PDOException $e) {
            error_log("SQLite Cache error in get() for key '{$key}': " . $e->getMessage());
            return $default; // Return default on DB error
        }
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        // Handle immediate deletion for invalid/expired TTL
        if (($ttl instanceof \DateInterval && $this->ttlToTimestamp($ttl) < time()) || (is_int($ttl) && $ttl <= 0)) {
             return $this->delete($key);
        }

        // Use json_encode instead of serialize
        $encodedValue = json_encode($value);
        if ($encodedValue === false) {
             error_log("SQLite Cache: Failed to json_encode value for key '{$key}'. Error: " . json_last_error_msg());
             return false;
        }

        $expirationTimestamp = $this->ttlToTimestamp($ttl);

        try {
            // Use transaction for INSERT OR REPLACE
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("INSERT OR REPLACE INTO `{$this->tableName}` (key, value, expiration) VALUES (?, ?, ?)");
            $success = $stmt->execute([
                $key,
                $encodedValue,
                $expirationTimestamp
            ]);
            $this->db->commit();
            return $success;
        } catch (PDOException $e) {
            // Rollback transaction on error
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("SQLite Cache error in set() for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        try {
            $stmt = $this->db->prepare("DELETE FROM `{$this->tableName}` WHERE key = ?");
            return $stmt->execute([$key]);
        } catch (PDOException $e) {
            error_log("SQLite Cache error in delete() for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            // Use DELETE instead of TRUNCATE for better compatibility and WAL mode
            $affectedRows = $this->db->exec("DELETE FROM `{$this->tableName}`");
            // Vacuum might be needed periodically to reclaim space, but not here.
            // $this->db->exec("VACUUM");
            return $affectedRows !== false;
        } catch (PDOException $e) {
            error_log("SQLite Cache error in clear(): " . $e->getMessage());
            return false;
        }
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        // Validate keys first
        $validKeys = [];
        foreach ($keys as $key) {
             if (!is_string($key)) {
                  throw new Psr16InvalidArgumentException("Cache keys must be strings.");
             }
            $this->validateKey($key);
            $validKeys[] = $key;
        }

        if (empty($validKeys)) {
            return [];
        }

        $results = array_fill_keys($validKeys, $default);

        try {
            // Create placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($validKeys), '?'));
            $stmt = $this->db->prepare("SELECT key, value, expiration FROM `{$this->tableName}` WHERE key IN ({$placeholders})");
            $stmt->execute($validKeys);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!$this->isRowExpired($row)) {
                     $value = json_decode($row['value'], true);
                     if (json_last_error() === JSON_ERROR_NONE) {
                         $results[$row['key']] = $value;
                     } else {
                          error_log("SQLite Cache: Failed to json_decode value for key '{$row['key']}' in getMultiple(). Error: " . json_last_error_msg());
                          // Keep default value for this key
                          $this->delete($row['key']); // Delete corrupted
                     }
                } else {
                     $this->delete($row['key']); // Delete expired
                }
            }
        } catch (PDOException $e) {
            error_log("SQLite Cache error in getMultiple(): " . $e->getMessage());
            // Return defaults for all keys on DB error
            return array_fill_keys($validKeys, $default);
        }

        return $results;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
         if (!is_array($values) && !$values instanceof \Traversable) {
             throw new Psr16InvalidArgumentException("Cache values must be an array or Traversable.");
         }

        $expirationTimestamp = $this->ttlToTimestamp($ttl);
        $success = true;

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("INSERT OR REPLACE INTO `{$this->tableName}` (key, value, expiration) VALUES (?, ?, ?)");

            foreach ($values as $key => $value) {
                 if (!is_string($key)) {
                      $this->db->rollBack(); // Rollback on invalid key
                      throw new Psr16InvalidArgumentException("Cache keys must be strings.");
                 }
                $this->validateKey($key);

                 // Handle immediate deletion for invalid/expired TTL for this item
                 if (($ttl instanceof \DateInterval && $expirationTimestamp < time()) || (is_int($ttl) && $ttl <= 0)) {
                      // Need separate delete statement within transaction
                      $deleteStmt = $this->db->prepare("DELETE FROM `{$this->tableName}` WHERE key = ?");
                      $deleteStmt->execute([$key]);
                      continue; // Skip setting this item
                 }

                $encodedValue = json_encode($value);
                 if ($encodedValue === false) {
                     error_log("SQLite Cache: Failed to json_encode value for key '{$key}' in setMultiple(). Error: " . json_last_error_msg());
                     $success = false; // Mark as failed but continue
                     continue;
                 }

                if (!$stmt->execute([$key, $encodedValue, $expirationTimestamp])) {
                    $success = false; // Mark as failed if any execute fails
                }
            }
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("SQLite Cache error in setMultiple(): " . $e->getMessage());
            return false;
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
         if (!is_array($keys) && !$keys instanceof \Traversable) {
             throw new Psr16InvalidArgumentException("Cache keys must be an array or Traversable.");
         }

        $validKeys = [];
        foreach ($keys as $key) {
             if (!is_string($key)) {
                  throw new Psr16InvalidArgumentException("Cache keys must be strings.");
             }
            $this->validateKey($key);
            $validKeys[] = $key;
        }

        if (empty($validKeys)) {
            return true; // No keys to delete, considered success
        }

        try {
            $placeholders = implode(',', array_fill(0, count($validKeys), '?'));
            $stmt = $this->db->prepare("DELETE FROM `{$this->tableName}` WHERE key IN ({$placeholders})");
            return $stmt->execute($validKeys);
        } catch (PDOException $e) {
            error_log("SQLite Cache error in deleteMultiple(): " . $e->getMessage());
            return false;
        }
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        try {
            $stmt = $this->db->prepare("SELECT expiration FROM `{$this->tableName}` WHERE key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return ($result !== false && !$this->isRowExpired($result));

        } catch (PDOException $e) {
            error_log("SQLite Cache error in has() for key '{$key}': " . $e->getMessage());
            return false; // Return false on DB error
        }
    }

    // Helper Methods

    /**
     * Checks if a fetched row is expired.
     *
     * @param array $row Row containing 'expiration' column.
     * @return bool
     */
    private function isRowExpired(array $row): bool
    {
        $expiration = $row['expiration'] ?? null;
        // Check if expiration is set and is in the past
        return $expiration !== null && time() >= (int)$expiration;
    }

    /**
     * Validates a cache key according to PSR-16 rules.
     *
     * @param string $key
     * @throws Psr16InvalidArgumentException If the key is invalid.
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new Psr16InvalidArgumentException("Cache key cannot be empty.");
        }
        // PSR-16 reserved characters: {}()/\@:
        if (preg_match('/[{}()\/\\@:]/', $key)) {
            throw new Psr16InvalidArgumentException("Cache key '{$key}' contains reserved characters.");
        }
    }

    /**
     * Converts TTL value to an absolute Unix timestamp.
     *
     * @param null|int|\DateInterval $ttl
     * @return null|int Absolute expiration timestamp or null for indefinite cache.
     * @throws Psr16InvalidArgumentException for invalid TTL types.
     */
    private function ttlToTimestamp(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null; // Cache forever
        }

        if (is_int($ttl)) {
            if ($ttl <= 0) {
                // PSR-16: "If the TTL value is 0 or less, the item MUST be deleted from the cache if it exists"
                // For set, we return an immediate expiration time.
                return time() - 1; // Expired in the past
            }
            return time() + $ttl;
        }

        if ($ttl instanceof \DateInterval) {
             try {
                 // Use DateTimeImmutable for safety
                 return (new DateTimeImmutable())->add($ttl)->getTimestamp();
             } catch (\Exception $e) {
                  throw new Psr16InvalidArgumentException("Invalid DateInterval provided for TTL.", 0, $e);
             }
        }

        // Should not happen with type hinting, but as fallback
        throw new Psr16InvalidArgumentException("Invalid TTL value provided. Must be null, int (seconds), or DateInterval.");
    }

     /**
      * Close the database connection on destruct.
      */
     public function __destruct()
     {
         $this->db = null;
     }
}