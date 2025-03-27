<?php

namespace SwallowPHP\Framework\Cache;

use SwallowPHP\Framework\Contracts\CacheInterface;
use SwallowPHP\Framework\Foundation\Config; // Use Config (via helper)
use RuntimeException; // For configuration errors

class CacheManager
{
    /**
     * The array of resolved cache drivers.
     *
     * @var array<string, CacheInterface>
     */
    protected static array $drivers = [];

    /**
     * Get a cache driver instance.
     *
     * @param string|null $driver The specific driver name (e.g., 'file', 'sqlite'). If null, uses default from env.
     * @return CacheInterface
     * @throws RuntimeException If the driver configuration is invalid or driver cannot be created.
     */
    public static function driver(?string $driver = null): CacheInterface
    {
        $driver = $driver ?: static::getDefaultDriver();

        if (!isset(static::$drivers[$driver])) {
            static::$drivers[$driver] = static::resolve($driver);
        }

        return static::$drivers[$driver];
    }

    /**
     * Resolve the given cache driver instance.
     *
     * @param string $driver
     * @return CacheInterface
     * @throws RuntimeException
     */
    protected static function resolve(string $driver): CacheInterface
    {
        $method = 'create' . ucfirst(strtolower($driver)) . 'Driver';

        if (method_exists(static::class, $method)) {
            return static::$method();
        } else {
            throw new RuntimeException("Unsupported cache driver [{$driver}].");
        }
    }

    /**
     * Create an instance of the file cache driver.
     *
     * @return \SwallowPHP\Framework\Cache\FileCache
     * @throws RuntimeException
     */
    protected static function createFileDriver(): FileCache
    {
        // Determine cache file path (needs improvement - should not rely on DOCUMENT_ROOT)
        // TODO: Define a proper base path for storage/cache in configuration or App
        // Find the directory containing composer.json (usually the project root)
        // $basePath = dirname(__DIR__, 3); // No longer needed directly here
        $storagePath = config('app.storage_path', dirname(__DIR__, 3) . '/storage'); // Get storage path from config
        $relativePath = config('cache.stores.file.path', 'cache/data.json'); // Get relative path from cache config
        $cachePath = $storagePath . '/' . ltrim($relativePath, '/');
        $maxSize = config('cache.stores.file.max_size', 52428800); // Get max size from config

        try {
            // Pass max size to constructor
            return new FileCache($cachePath, $maxSize);
        } catch (\Exception $e) {
             // Log the specific error
             error_log("Failed to create FileCache driver: " . $e->getMessage());
             throw new RuntimeException("Could not create file cache driver. Check path and permissions.", 0, $e);
        }
    }

    /**
     * Create an instance of the SQLite cache driver.
     *
     * @return \SwallowPHP\Framework\Cache\SqliteCache
     * @throws RuntimeException
     */
    protected static function createSqliteDriver(): SqliteCache
    {
         // Determine SQLite DB path
         // Find the directory containing composer.json (usually the project root)
         // $basePath = dirname(__DIR__, 3); // No longer needed directly here
         $storagePath = config('app.storage_path', dirname(__DIR__, 3) . '/storage'); // Get storage path from config
         $relativePath = config('cache.stores.sqlite.path', 'cache/database.sqlite'); // Get relative path from cache config
         $dbPath = $storagePath . '/' . ltrim($relativePath, '/');

        try {
            return new SqliteCache($dbPath);
        } catch (\Exception $e) {
             error_log("Failed to create SqliteCache driver: " . $e->getMessage());
             throw new RuntimeException("Could not create SQLite cache driver. Check path and permissions.", 0, $e);
        }
    }

    /**
     * Get the default cache driver name.
     *
     * @return string
     */
    public static function getDefaultDriver(): string
    {
        // Get default driver from cache config
        return strtolower(config('cache.default', 'file'));
    }

    /**
     * Dynamically call the default driver instance.
     * Provides a facade-like access: CacheManager::get('key')
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return static::driver()->$method(...$parameters);
    }
}