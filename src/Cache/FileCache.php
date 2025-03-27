<?php

namespace SwallowPHP\Framework\Cache;

use SwallowPHP\Framework\Contracts\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException; // PSR-16 exception
use DateInterval;
use DateTimeImmutable; // Use immutable for safety
use Exception; // For internal errors

class FileCache implements CacheInterface
{
    private string $cacheFile;
    private array $cache = [];
    private bool $loaded = false; // Flag to load cache only once per instance lifecycle
    private int $maxCacheSize;
    private int $filePermissions;
    private int $dirPermissions;

    /**
     * FileCache constructor.
     *
     * @param string $cacheFilePath Absolute path to the cache file.
     * @param int $maxSizeBytes Maximum cache size in bytes (approximate). Default 50MB.
     * @param int $filePermissions File permissions for the cache file. Default 0600.
     * @param int $dirPermissions Directory permissions if cache directory needs creation. Default 0750.
     */
    public function __construct(
        string $cacheFilePath,
        int $maxSizeBytes = 52428800, // 50MB
        int $filePermissions = 0600,
        int $dirPermissions = 0750
    ) {
        if (empty($cacheFilePath)) {
            // Use a more specific exception if available, or standard InvalidArgumentException
            throw new Psr16InvalidArgumentException("Cache file path cannot be empty.");
        }
        $this->cacheFile = $cacheFilePath;
        $this->maxCacheSize = $maxSizeBytes;
        $this->filePermissions = $filePermissions;
        $this->dirPermissions = $dirPermissions;

        // Ensure cache directory exists
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            if (!@mkdir($cacheDir, $this->dirPermissions, true)) {
                 // Use a more specific exception if available
                throw new \RuntimeException("Failed to create cache directory: {$cacheDir}");
            }
        }
         if (!is_writable($cacheDir)) {
             throw new \RuntimeException("Cache directory is not writable: {$cacheDir}");
         }
    }

    // PSR-16 Methods Implementation

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $this->loadCacheIfNeeded();

        if ($this->has($key)) { // has() already checks expiration
            $item = $this->cache[$key];
             // Basic data structure validation
             if (!array_key_exists('value', $item)) {
                 $this->delete($key); // Remove corrupted item
                 return $default;
             }
            return $item['value'];
        }

        return $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $this->loadCacheIfNeeded();

        $expirationTimestamp = $this->ttlToTimestamp($ttl);

        // Basic check for serializability (json_encode handles most cases)
        // More complex checks might be needed depending on requirements
        if (is_resource($value)) {
             error_log("Cache error in set(): Resources cannot be cached.");
             return false;
        }

        try {
             // Check if encoding works before adding to cache array
             if (json_encode($value) === false) {
                 throw new Exception("Failed to json_encode value for key '{$key}'. Error: " . json_last_error_msg());
             }

            $this->cache[$key] = [
                'value' => $value,
                'expiration' => $expirationTimestamp,
                'created_at' => time() // Store creation time for pruning
            ];

            return $this->saveCache();
        } catch (\Exception $e) {
            error_log("Cache error in set() for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->loadCacheIfNeeded();

        if (array_key_exists($key, $this->cache)) {
            unset($this->cache[$key]);
            return $this->saveCache();
        }

        return true; // Key didn't exist, considered a success by PSR-16
    }

    public function clear(): bool
    {
        $this->cache = [];
        $this->loaded = true; // Mark as loaded even if empty
        return $this->saveCache();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        // Validate all keys first
        foreach ($keys as $key) {
             if (!is_string($key)) {
                  throw new Psr16InvalidArgumentException("Cache keys must be strings.");
             }
            $this->validateKey($key);
        }

        $this->loadCacheIfNeeded();
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
         if (!is_array($values) && !$values instanceof \Traversable) {
             throw new Psr16InvalidArgumentException("Cache values must be an array or Traversable.");
         }

        $this->loadCacheIfNeeded();
        $expirationTimestamp = $this->ttlToTimestamp($ttl);
        $success = true;

        foreach ($values as $key => $value) {
             if (!is_string($key)) {
                  throw new Psr16InvalidArgumentException("Cache keys must be strings.");
             }
            $this->validateKey($key);

             if (is_resource($value)) {
                 error_log("Cache error in setMultiple(): Resources cannot be cached (key: '{$key}').");
                 $success = false;
                 continue; // Skip this item
             }
             try {
                 if (json_encode($value) === false) {
                     throw new Exception("Failed to json_encode value for key '{$key}'. Error: " . json_last_error_msg());
                 }
                $this->cache[$key] = [
                    'value' => $value,
                    'expiration' => $expirationTimestamp,
                    'created_at' => time()
                ];
             } catch (\Exception $e) {
                 error_log("Cache error in setMultiple() for key '{$key}': " . $e->getMessage());
                 $success = false; // Mark overall operation as failed but continue trying others
             }
        }

        return $this->saveCache() && $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
         if (!is_array($keys) && !$keys instanceof \Traversable) {
             throw new Psr16InvalidArgumentException("Cache keys must be an array or Traversable.");
         }

        $this->loadCacheIfNeeded();
        $success = true;

        foreach ($keys as $key) {
             if (!is_string($key)) {
                  throw new Psr16InvalidArgumentException("Cache keys must be strings.");
             }
            $this->validateKey($key);
            if (array_key_exists($key, $this->cache)) {
                unset($this->cache[$key]);
            }
        }

        return $this->saveCache() && $success; // PSR-16 expects true even if some keys didn't exist
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        $this->loadCacheIfNeeded();

        return array_key_exists($key, $this->cache) && !$this->isExpired($key);
    }

    // Helper Methods

    /**
     * Loads cache from file if not already loaded.
     */
    private function loadCacheIfNeeded(): void
    {
        if ($this->loaded) {
            return;
        }

        if (!file_exists($this->cacheFile)) {
             // Attempt to create the file if it doesn't exist
             if (touch($this->cacheFile)) {
                 chmod($this->cacheFile, $this->filePermissions);
             } else {
                  throw new \RuntimeException("Cache file does not exist and could not be created: {$this->cacheFile}");
             }
             $this->cache = [];
             $this->loaded = true;
             return;
        }

        if (!is_readable($this->cacheFile)) {
             throw new \RuntimeException("Cache file is not readable: {$this->cacheFile}");
        }

        $fp = @fopen($this->cacheFile, 'r');
        if (!$fp) {
             throw new \RuntimeException("Could not open cache file for reading: {$this->cacheFile}");
        }

        try {
            if (flock($fp, LOCK_SH)) {
                $json = stream_get_contents($fp);
                flock($fp, LOCK_UN);

                if (!empty($json)) {
                    $data = json_decode($json, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                        $this->cache = $data;
                    } else {
                        error_log("Cache file corruption detected: " . json_last_error_msg() . " in {$this->cacheFile}");
                        $this->cache = []; // Reset cache on corruption
                    }
                } else {
                     $this->cache = []; // Empty file
                }
            } else {
                error_log("Could not acquire shared lock for cache file: {$this->cacheFile}");
                // Decide behavior: throw exception or return empty cache? Returning empty might hide issues.
                 throw new \RuntimeException("Could not acquire shared lock for cache file: {$this->cacheFile}");
                // $this->cache = [];
            }
        } finally {
             fclose($fp);
        }

        $this->loaded = true;
    }

    /**
     * Saves the current cache state to the file.
     * Handles pruning if cache exceeds max size.
     *
     * @return bool True on success, false on failure.
     */
    private function saveCache(): bool
    {
         // Prune cache if needed before encoding
         $this->pruneCacheIfNeeded();

        $fp = @fopen($this->cacheFile, 'c+'); // Open for reading/writing; create if not exists; truncate to zero length
         if (!$fp) {
             error_log("Could not open cache file for writing: {$this->cacheFile}");
             return false;
         }

        $json = json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
         if ($json === false) {
             error_log("Failed to encode cache data to JSON: " . json_last_error_msg());
             fclose($fp);
             return false;
         }

        $success = false;
        try {
            if (flock($fp, LOCK_EX)) {
                if (ftruncate($fp, 0) && rewind($fp)) {
                     $bytesWritten = fwrite($fp, $json);
                     if ($bytesWritten !== false) {
                         fflush($fp); // Ensure data is written to disk
                         $success = true;
                     } else {
                          error_log("Failed to write to cache file: {$this->cacheFile}");
                     }
                } else {
                     error_log("Failed to truncate or rewind cache file: {$this->cacheFile}");
                }
                flock($fp, LOCK_UN);
            } else {
                error_log("Could not acquire exclusive lock for cache file: {$this->cacheFile}");
            }
        } finally {
            fclose($fp);
        }
        return $success;
    }

     /**
      * Prunes the cache if its size exceeds the maximum allowed size.
      * Removes items based on their creation time (oldest first).
      */
     private function pruneCacheIfNeeded(): void
     {
         // Estimate size without encoding everything repeatedly if cache is large
         if (count($this->cache) < 1000) { // Heuristic threshold
              $currentSize = strlen(json_encode($this->cache));
         } else {
              // Approximate size for large caches to avoid performance hit
              $currentSize = 0;
              foreach ($this->cache as $key => $item) {
                   $currentSize += strlen($key) + strlen(json_encode($item['value'])) + 50; // Rough estimate per item
              }
         }


         if ($currentSize > $this->maxCacheSize) {
             // Sort items by creation time (oldest first)
             uasort($this->cache, function ($a, $b) {
                 return ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0);
             });

             // Remove items until size is below limit
             while ($currentSize > $this->maxCacheSize && !empty($this->cache)) {
                 $keyToRemove = array_key_first($this->cache); // Get key of the first (oldest) element
                 if ($keyToRemove !== null) {
                      $removedItemSize = strlen($keyToRemove) + strlen(json_encode($this->cache[$keyToRemove]['value'])) + 50; // Estimate
                      unset($this->cache[$keyToRemove]);
                      $currentSize -= $removedItemSize; // Adjust estimated size
                 } else {
                      break; // Should not happen if cache is not empty
                 }
             }

             // Optional: Log that pruning occurred
             // error_log("Cache pruned due to size limit.");
         }
     }


    /**
     * Checks if a cached item is expired.
     *
     * @param string $key
     * @return bool
     */
    private function isExpired(string $key): bool
    {
        // Assumes $this->cache is loaded and key exists
        $expiration = $this->cache[$key]['expiration'] ?? null;
        return $expiration !== null && time() >= $expiration;
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
                // Expired or invalid TTL, treat as expired now for set, effectively deleting?
                // PSR-16: "If the TTL value is 0 or less, the item MUST be deleted from the cache if it exists"
                // For set, we can return an immediate expiration time.
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
}