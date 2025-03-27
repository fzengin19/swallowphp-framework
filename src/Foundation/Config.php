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
     * The base path of the configuration files.
     *
     * @var string
     */
    protected string $configPath;

    /**
     * Create a new configuration repository.
     *
     * @param string|null $configPath Path to the configuration directory.
     */
    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?: $this->getDefaultConfigPath();
        $this->loadConfigurationFiles();
    }

    /**
     * Get the default configuration path.
     *
     * @return string
     */
    protected function getDefaultConfigPath(): string
    {
        // Assumes this class is in src/Foundation, so go up 2 levels for project root
        $basePath = dirname(__DIR__, 2);
        return $basePath . '/src/Config'; // Point to src/Config
    }

    /**
     * Load the configuration items from all of the files.
     *
     * @return void
     */
    protected function loadConfigurationFiles(): void
    {
        if (!is_dir($this->configPath)) {
             // Optionally log a warning if config path doesn't exist
             // error_log("Configuration directory not found: {$this->configPath}");
             return; // No config files to load
        }

        foreach (glob($this->configPath . '/*.php') as $file) {
            $key = basename($file, '.php');
            try {
                $configData = require $file;
                if (is_array($configData)) {
                    $this->items[$key] = $configData;
                } else {
                     error_log("Config file did not return an array: {$file}");
                }
            } catch (\Throwable $e) {
                 error_log("Error loading config file {$file}: " . $e->getMessage());
            }
        }
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