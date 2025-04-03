<?php

namespace SwallowPHP\Framework\Cache;

use SwallowPHP\Framework\Contracts\CacheInterface;
use SwallowPHP\Framework\Foundation\Config; // Use Config (via helper)
use RuntimeException; // For configuration errors
use SwallowPHP\Framework\Foundation\App; // Needed for logger access
use Psr\Log\LoggerInterface; // Import Logger

class CacheManager
{
    /** @var array<string, CacheInterface> The array of resolved cache drivers. */
    protected static array $drivers = [];

    /** Get logger instance helper */
    private static function logger(): ?LoggerInterface
    {
        try {
            // Ensure container is initialized before getting logger
            if (App::container()) {
                return App::container()->get(LoggerInterface::class);
            }
        } catch (\Throwable $e) {
            error_log("CRITICAL: Could not resolve LoggerInterface in CacheManager: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get a cache driver instance.
     * @param string|null $driver The specific driver name. If null, uses default.
     * @return CacheInterface
     * @throws RuntimeException If the driver configuration is invalid or driver cannot be created.
     */
    public static function driver(?string $driver = null): CacheInterface
    {
        $driverName = $driver ?: static::getDefaultDriver(); // Use a different variable name
        $driverName = strtolower($driverName); // Ensure lowercase

        if (!isset(static::$drivers[$driverName])) {
            static::$drivers[$driverName] = static::resolve($driverName);
        }

        return static::$drivers[$driverName];
    }

    /**
     * Resolve the given cache driver instance.
     * @param string $driver
     * @return CacheInterface
     * @throws RuntimeException
     */
    protected static function resolve(string $driver): CacheInterface
    {
        $method = 'create' . ucfirst($driver) . 'Driver'; // Ensure driver name used for method is consistent case

        if (method_exists(static::class, $method)) {
            // Call the specific driver creation method
            return static::$method();
        } else {
            throw new RuntimeException("Unsupported cache driver [{$driver}].");
        }
    }

    /**
     * Create an instance of the file cache driver.
     * @return FileCache
     * @throws RuntimeException
     */
    protected static function createFileDriver(): FileCache
    {
        $logger = self::logger();
        try {
            // Get config values safely
            $storagePath = config('app.storage_path');
            $relativePath = config('cache.stores.file.path', 'cache/data.json');
            $maxSize = config('cache.stores.file.max_size', 50 * 1024 * 1024);

            // Ensure storage path is resolved correctly
            if (!$storagePath || !is_dir(dirname($storagePath))) {
                 $potentialBasePath = defined('BASE_PATH') ? constant('BASE_PATH') : dirname(__DIR__, 3);
                 $storagePath = $potentialBasePath . '/storage';
                 if ($logger) $logger->warning("Config 'app.storage_path' not found or invalid, using fallback.", ['path' => $storagePath]);
                 else error_log("Warning: Config 'app.storage_path' not found or invalid, using fallback: ".$storagePath);
                 if (!is_dir($storagePath)) @mkdir($storagePath, 0755, true);
            }

            $cachePath = rtrim($storagePath, '/\\') . '/' . ltrim($relativePath, '/\\');

            return new FileCache($cachePath, $maxSize);

        } catch (\Exception $e) {
             $message = "Could not create file cache driver. Check path and permissions.";
             if ($logger) $logger->critical($message, ['exception' => $e, 'path' => $cachePath ?? 'N/A']);
             else error_log("Failed to create FileCache driver: " . $e->getMessage()); // Fallback log
             throw new RuntimeException($message, 0, $e);
        }
    }

    /**
     * Create an instance of the SQLite cache driver.
     * @return SqliteCache
     * @throws RuntimeException
     */
    protected static function createSqliteDriver(): SqliteCache
    {
        $logger = self::logger();
        try {
            $storagePath = config('app.storage_path');
            $relativePath = config('cache.stores.sqlite.path', 'cache/database.sqlite');
            $tableName = config('cache.stores.sqlite.table', 'cache'); // Get table name from config

            if (!$storagePath || !is_dir(dirname($storagePath))) {
                 $potentialBasePath = defined('BASE_PATH') ? constant('BASE_PATH') : dirname(__DIR__, 3);
                 $storagePath = $potentialBasePath . '/storage';
                 if ($logger) $logger->warning("Config 'app.storage_path' not found or invalid, using fallback.", ['path' => $storagePath]);
                 else error_log("Warning: Config 'app.storage_path' not found or invalid, using fallback: ".$storagePath);
                 if (!is_dir($storagePath)) @mkdir($storagePath, 0755, true);
            }

            $dbPath = rtrim($storagePath, '/\\') . '/' . ltrim($relativePath, '/\\');

            return new SqliteCache($dbPath, $tableName); // Pass table name

        } catch (\Exception $e) {
             $message = "Could not create SQLite cache driver. Check path and permissions.";
             if ($logger) $logger->critical($message, ['exception' => $e, 'path' => $dbPath ?? 'N/A']);
             else error_log("Failed to create SqliteCache driver: " . $e->getMessage()); // Fallback log
             throw new RuntimeException($message, 0, $e);
        }
    }

    /**
     * Get the default cache driver name.
     * @return string
     */
    public static function getDefaultDriver(): string
    {
        return strtolower(config('cache.default', 'file'));
    }

    /**
     * Dynamically call the default driver instance.
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return static::driver()->$method(...$parameters);
    }
}