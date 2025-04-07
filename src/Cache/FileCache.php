<?php

namespace SwallowPHP\Framework\Cache;

use SwallowPHP\Framework\Contracts\CacheInterface;
// PSR-16 Arayüzünü implemente ediyoruz, ancak exception için standart olanı kullanacağız.
// use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;
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
     * @throws \InvalidArgumentException If path is empty.
     * @throws \RuntimeException If directory cannot be created or is not writable.
     */
    public function __construct(
        string $cacheFilePath,
        int $maxSizeBytes = 52428800, // 50MB
        int $filePermissions = 0600,
        int $dirPermissions = 0750
    ) {
        if (empty($cacheFilePath)) {
            throw new \InvalidArgumentException("Cache file path cannot be empty."); // Use standard PHP exception
        }
        $this->cacheFile = $cacheFilePath;
        $this->maxCacheSize = $maxSizeBytes;
        $this->filePermissions = $filePermissions;
        $this->dirPermissions = $dirPermissions;

        // Ensure cache directory exists
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            // Use correct logical AND operator '&&'
            if (!@mkdir($cacheDir, $this->dirPermissions, true) && !is_dir($cacheDir)) { // Check mkdir result and existence
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
        $this->validateKey($key); // Throws InvalidArgumentException on invalid key
        $this->loadCacheIfNeeded();

        if ($this->has($key)) { // has() checks existence and expiration
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

        // Handle immediate deletion for expired TTL on set, as per PSR-16
        // Use correct logical AND operator '&&'
        if ($expirationTimestamp !== null && $expirationTimestamp < time()) {
             return $this->delete($key);
        }

        // Prevent caching resources
        if (is_resource($value)) {
             error_log("Cache error in set(): Resources cannot be cached (key: '{$key}').");
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
        $this->loaded = true;
        return $this->saveCache();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $validKeys = [];
        foreach ($keys as $key) {
             if (!is_string($key)) {
                  throw new \InvalidArgumentException("Cache keys must be strings.");
             }
            $this->validateKey($key); // Reuse validation
            $validKeys[] = $key;
        }

        // Return early if no valid keys provided
        if (empty($validKeys)) {
             return [];
        }

        $this->loadCacheIfNeeded();
        $results = [];
        foreach ($validKeys as $key) {
            // Reuse get method logic (which includes expiration check)
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
         if (!is_iterable($values)) {
             throw new \InvalidArgumentException("Cache values must be an array or Traversable.");
         }

        $this->loadCacheIfNeeded();
        $expirationTimestamp = $this->ttlToTimestamp($ttl);
        $success = true;

        // Handle immediate deletion for expired TTL on set
        // Use correct logical AND operator '&&'
        if ($expirationTimestamp !== null && $expirationTimestamp < time()) {
             $keysToDelete = [];
             foreach ($values as $key => $value) {
                 if (is_string($key)) $keysToDelete[] = $key;
             }
             return $this->deleteMultiple($keysToDelete);
        }

        foreach ($values as $key => $value) {
             if (!is_string($key)) {
                  error_log("Cache error in setMultiple(): Invalid key type provided.");
                  $success = false;
                  continue;
             }
            try {
                $this->validateKey($key);

                if (is_resource($value)) {
                    error_log("Cache error in setMultiple(): Resources cannot be cached (key: '{$key}').");
                    $success = false;
                    continue;
                }
                if (json_encode($value) === false) {
                    throw new Exception("Failed to json_encode value. Error: " . json_last_error_msg());
                }

                $this->cache[$key] = [
                    'value' => $value,
                    'expiration' => $expirationTimestamp,
                    'created_at' => time()
                ];
             } catch (\Exception $e) { // Catches InvalidArgumentException or Exception
                 error_log("Cache error in setMultiple() for key '{$key}': " . $e->getMessage());
                 $success = false;
             }
        }

        // Use correct logical AND operator '&&'
        return $this->saveCache() && $success; // Ensure saveCache() also succeeded
    }

    public function deleteMultiple(iterable $keys): bool
    {
         if (!is_iterable($keys)) {
             throw new \InvalidArgumentException("Cache keys must be an array or Traversable.");
         }

        $this->loadCacheIfNeeded();
        $keysWerePresent = false;

        foreach ($keys as $key) {
             if (!is_string($key)) {
                  throw new \InvalidArgumentException("Cache keys must be strings.");
             }
            try {
                $this->validateKey($key);
                if (array_key_exists($key, $this->cache)) {
                    unset($this->cache[$key]);
                    $keysWerePresent = true;
                }
             } catch (\InvalidArgumentException $e) {
                 throw $e;
             }
        }

        return $keysWerePresent ? $this->saveCache() : true;
    }

    public function has(string $key): bool
    {
        try {
             $this->validateKey($key);
        } catch (\InvalidArgumentException $e) {
             return false;
        }

        $this->loadCacheIfNeeded();

        // Use correct logical AND operator '&&'
        return array_key_exists($key, $this->cache) && !$this->isExpired($key);
    }


    // Non-PSR-16 addition (common extension)

    public function increment(string $key, int $step = 1): int|false
    {
        $this->validateKey($key);
        $fp = @fopen($this->cacheFile, 'c+'); // Open for read/write, create if not exist
        if (!$fp) {
            error_log("Cache error in increment(): Could not open file '{$this->cacheFile}'");
            return false;
        }

        try {
            if (!@flock($fp, LOCK_EX)) {
                error_log("Cache error in increment(): Could not acquire lock for file '{$this->cacheFile}'");
                return false;
            }

            // Reload cache data inside the lock
            $this->loaded = false; // Force reload within lock
            $this->loadCacheIfNeeded();

            $item = $this->cache[$key] ?? null;
            $currentValue = 0;

            if ($item !== null && !$this->isExpired($key) && isset($item['value']) && is_numeric($item['value'])) {
                $currentValue = (int)$item['value'];
            } elseif ($item !== null && !$this->isExpired($key)) {
                 // Item exists but is not numeric, cannot increment
                 error_log("Cache error in increment(): Value for key '{$key}' is not numeric.");
                 flock($fp, LOCK_UN);
                 return false;
            }
            // If item doesn't exist or is expired, start from 0

            $newValue = $currentValue + $step;

            // Save the new value (using set logic without reload)
            $expirationTimestamp = $item['expiration'] ?? $this->ttlToTimestamp(config('cache.ttl'));
            $this->cache[$key] = [
                'value' => $newValue,
                'expiration' => $expirationTimestamp,
                'created_at' => $item['created_at'] ?? time()
            ];

            if (!$this->saveCache()) { // saveCache handles prune and writing
                 flock($fp, LOCK_UN); // Ensure unlock on save failure
                 return false;
            }

            flock($fp, LOCK_UN);
            return $newValue;

        } catch (\Throwable $e) {
            error_log("Cache error in increment() for key '{$key}': " . $e->getMessage());
            if (isset($fp) && is_resource($fp)) {
                 @flock($fp, LOCK_UN);
            }
            return false;
        } finally {
             if (isset($fp) && is_resource($fp)) {
                 @fclose($fp);
             }
        }
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return $this->increment($key, -$step);
    }


    // Helper Methods

    /** Loads cache from file if not already loaded. */
    private function loadCacheIfNeeded(): void
    {
        if ($this->loaded) {
            return;
        }

        if (!file_exists($this->cacheFile)) {
             if (@touch($this->cacheFile)) {
                 @chmod($this->cacheFile, $this->filePermissions);
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
            if (@flock($fp, LOCK_SH)) {
                $json = @stream_get_contents($fp, $this->maxCacheSize + 1024);
                flock($fp, LOCK_UN);

                if ($json === false) {
                     throw new \RuntimeException("Failed to read cache file content: {$this->cacheFile}");
                }

                if (strlen($json) > $this->maxCacheSize) {
                     error_log("Cache file exceeded max size ({$this->maxCacheSize} bytes): {$this->cacheFile}. Cache cleared.");
                     $this->cache = [];
                } elseif (!empty($json)) {
                    $data = json_decode($json, true);
                    // Use correct logical AND operator '&&'
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                        $this->cache = $data;
                    } else {
                        error_log("Cache file corruption detected: " . json_last_error_msg() . " in {$this->cacheFile}");
                        $this->cache = [];
                    }
                } else {
                     $this->cache = [];
                }
            } else {
                error_log("Could not acquire shared lock for cache file: {$this->cacheFile}");
                 throw new \RuntimeException("Could not acquire shared lock for cache file: {$this->cacheFile}");
            }
        } finally {
             @fclose($fp);
        }

        $this->loaded = true;
    }

    /** Saves the current cache state to the file. */
    private function saveCache(): bool
    {
         $this->pruneCacheIfNeeded();
        $tempFile = $this->cacheFile . '.' . uniqid(mt_rand(), true) . '.tmp';
        $json = json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
         if ($json === false) {
             error_log("Failed to encode cache data to JSON: " . json_last_error_msg());
             return false;
         }
        if (@file_put_contents($tempFile, $json, LOCK_EX) === false) {
            error_log("Failed to write to temporary cache file: {$tempFile}");
            @unlink($tempFile);
            return false;
        }
        @chmod($tempFile, $this->filePermissions);
        if (@rename($tempFile, $this->cacheFile)) {
            return true;
        } else {
            error_log("Failed to rename temporary cache file to final destination: {$this->cacheFile}");
            @unlink($tempFile);
            return false;
        }
    }

     /** Prunes the cache if needed. */
     private function pruneCacheIfNeeded(): void
     {
         $estimatedSize = strlen(json_encode($this->cache));
         if ($estimatedSize > $this->maxCacheSize) {
             uasort($this->cache, function ($a, $b) {
                 return ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0);
             });
             $targetSize = $this->maxCacheSize * 0.9;
             // Use correct logical AND operator '&&'
             while ($estimatedSize > $targetSize && !empty($this->cache)) {
                 $keyToRemove = array_key_first($this->cache);
                 if ($keyToRemove !== null) {
                      unset($this->cache[$keyToRemove]);
                      if (mt_rand(1, 10) === 1) {
                          $estimatedSize = strlen(json_encode($this->cache));
                      }
                 } else {
                      break;
                 }
             }
             $estimatedSize = strlen(json_encode($this->cache));
             error_log("Cache pruned: size reduced to ~{$estimatedSize} bytes.");
         }
     }


    /** Checks if a cached item is expired. */
    private function isExpired(string $key): bool
    {
        $expiration = $this->cache[$key]['expiration'] ?? null;
        // Use correct logical AND operator '&&'
        return $expiration !== null && time() >= $expiration;
    }

    /** Validates a cache key. */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException("Cache key cannot be empty.");
        }
        if (preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new \InvalidArgumentException("Cache key '{$key}' contains reserved characters: {}()/\@:");
        }
    }

    /** Converts TTL to timestamp. */
    private function ttlToTimestamp(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) return null;
        if (is_int($ttl)) {
            return ($ttl > 0) ? time() + $ttl : time() - 1;
        }
        if ($ttl instanceof \DateInterval) {
             try {
                 return (new DateTimeImmutable())->add($ttl)->getTimestamp();
             } catch (\Exception $e) {
                  throw new \InvalidArgumentException("Invalid DateInterval provided for TTL.", 0, $e);
             }
        }
        throw new \InvalidArgumentException("Invalid TTL value provided. Must be null, int (seconds), or DateInterval.");
    }
}