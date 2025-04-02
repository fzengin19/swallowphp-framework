<?php

namespace SwallowPHP\Framework\Foundation;

use RuntimeException;

class Config
{
    /**
     * All of the configuration items.
     *
     * @var array
     */
    protected array $items = [];

    /**
     * The base path of the framework configuration files.
     *
     * @var string
     */
    protected string $frameworkConfigPath;

    /**
     * The base path of the application configuration files.
     *
     * @var string|null
     */
    protected ?string $appConfigPath;

    /**
     * Create a new configuration repository.
     *
     * @param string|null $frameworkConfigPath Path to the framework configuration directory.
     * @param string|null $appConfigPath Path to the application configuration directory.
     */
    public function __construct(?string $frameworkConfigPath = null, ?string $appConfigPath = null) // Pass both paths
    {
        $this->frameworkConfigPath = $frameworkConfigPath ?: $this->getDefaultFrameworkConfigPath(); // Set framework path
        $this->appConfigPath = $appConfigPath; // Set app path (can be null)
        $this->loadConfigurationFiles(); // Load and merge configs
    }

    /**
     * Get the default configuration path.
     *
     * @return string
     */
    protected function getDefaultFrameworkConfigPath(): string
    {
        // Assumes this class is in src/Foundation, so go up 2 levels for project root
        $frameworkBasePath = dirname(__DIR__, 2);
        return $frameworkBasePath . '/src/Config'; // Point to framework's src/Config
    }

    /**
     * Load and merge configuration items from framework and application paths.
     * Application config overrides framework config.
     *
     * @return void
     */
    protected function loadConfigurationFiles(): void
    {
        // Load framework config first
        $frameworkItems = $this->loadFromPath($this->frameworkConfigPath);
        // Load app config if path is provided
        $appItems = $this->appConfigPath ? $this->loadFromPath($this->appConfigPath) : [];

        // Merge framework and application configs, app overrides framework
        // Use array_replace_recursive to merge nested arrays correctly
        $this->items = array_replace_recursive($frameworkItems, $appItems);
    }

    /**
     * Load configuration items from a given directory path.
     *
     * @param string $configPath
     * @return array The loaded configuration items.
     */
    protected function loadFromPath(string $configPath): array
    {
        $items = [];
        if (!is_dir($configPath)) {
             error_log("Configuration directory not found or not readable: {$configPath}");
             return []; // Return empty array if path is invalid
        }

        foreach (glob($configPath . '/*.php') as $file) {
            $key = basename($file, '.php');
            try {
                $configData = require $file;
                if (is_array($configData)) {
                    $items[$key] = $configData; // Add to items array for this path
                } else {
                     error_log("Config file did not return an array: {$file}");
                }
            } catch (\Throwable $e) {
                 error_log("Error loading config file {$file}: " . $e->getMessage());
            }
        }

        return $items; // Return the items loaded from this specific path
    }

    /**
     * Determine if the given configuration value exists.
     * Uses "dot" notation.
     *
     * @param  string  $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Get the specified configuration value.
     * Uses "dot" notation (e.g., 'app.name', 'database.connections.mysql.host').
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $array = $this->items;
        $keys = explode('.', $key);

        foreach ($keys as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default; // Key not found
            }
        }

        return $array;
    }

    /**
     * Set a given configuration value.
     * Uses "dot" notation.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $array = &$this->items;
        $keys = explode('.', $key);

        while (count($keys) > 1) {
            $segment = array_shift($keys);

            // If the key doesn't exist at this depth, we will just create an empty array
            // to hold the next value, allowing us to create the arrays to hold final
            // values at the correct depth. Then we'll keep digging into the array.
            if (!isset($array[$segment]) || !is_array($array[$segment])) {
                $array[$segment] = [];
            }

            $array = &$array[$segment];
        }

        $array[array_shift($keys)] = $value;
    }

    /**
     * Get all of the configuration items.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->items;
    }
}