<?php

namespace SwallowPHP\Framework\Foundation;

use RuntimeException; // Keep for potential future use if needed

class Config
{
    /** @var array All configuration items. */
    protected array $items = [];

    /** @var string Framework config path. */
    protected string $frameworkConfigPath;

    /** @var string|null Application config path. */
    protected ?string $appConfigPath;

    /**
     * Create a new configuration repository.
     * @param string|null $frameworkConfigPath
     * @param string|null $appConfigPath
     */
    public function __construct(?string $frameworkConfigPath = null, ?string $appConfigPath = null)
    {
        $this->frameworkConfigPath = $frameworkConfigPath ?: $this->getDefaultFrameworkConfigPath();
        $this->appConfigPath = $appConfigPath;
        $this->loadConfigurationFiles();
    }

    /** Get default framework config path. */
    protected function getDefaultFrameworkConfigPath(): string
    {
        $frameworkBasePath = dirname(__DIR__, 2);
        return $frameworkBasePath . '/src/Config';
    }

    /** Load and merge configuration files. */
    protected function loadConfigurationFiles(): void
    {
        $frameworkItems = $this->loadFromPath($this->frameworkConfigPath);
        $appItems = $this->appConfigPath ? $this->loadFromPath($this->appConfigPath) : [];
        $this->items = array_replace_recursive($frameworkItems, $appItems);
    }

    /**
     * Load configuration items from a given directory path.
     * @param string $configPath
     * @return array
     */
    protected function loadFromPath(string $configPath): array
    {
        $items = [];
        // Check if directory exists and is readable, return empty if not.
        // Logging this early might be problematic due to dependency issues.
        // Failure here will likely cause errors later when config values are accessed.
        if (!is_dir($configPath)) {
            // Log if directory doesn't exist (might be optional like app config path)
            // error_log("Configuration directory not found: {$configPath}");
            return [];
        }
        if (!is_readable($configPath)) {
             error_log("Configuration directory not readable: {$configPath}");
             return []; // Cannot proceed if not readable
        }

        foreach (glob($configPath . '/*.php') as $file) {
            $key = basename($file, '.php');
            try {
                // Use include instead of require to prevent fatal error on failure
                $configData = include $file;
                // Check if include succeeded and returned an array
                if ($configData !== false && is_array($configData)) {
                    $items[$key] = $configData;
                } else {
                    // Log files that don't return arrays or fail include (but don't stop loading)
                    error_log("Config file did not return an array or failed to include (returned: " . gettype($configData) . "): {$file}");
                }
            } catch (\Throwable $e) {
                 // Log exceptions during include (but don't stop loading)
                 error_log("Error loading config file {$file}: " . $e->getMessage());
            }
        }
        return $items;
    }

    /** Check if config key exists. */
    public function has(string $key): bool
    {
        return $this->get($key, '__DEFAULT_NOT_FOUND__') !== '__DEFAULT_NOT_FOUND__';
        // Using a unique default value is slightly more robust than checking for null,
        // in case null is a valid stored config value.
    }

    /** Get config value using dot notation. */
    public function get(string $key, mixed $default = null): mixed
    {
        $array = $this->items;
        $keys = explode('.', $key);
        foreach ($keys as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }
        return $array;
    }

    /** Set config value using dot notation. */
    public function set(string $key, mixed $value): void
    {
        $array = &$this->items;
        $keys = explode('.', $key);
        while (count($keys) > 1) {
            $segment = array_shift($keys);
            if (!isset($array[$segment]) || !is_array($array[$segment])) {
                $array[$segment] = [];
            }
            $array = &$array[$segment];
        }
        $array[array_shift($keys)] = $value;
    }

    /** Get all configuration items. */
    public function all(): array
    {
        return $this->items;
    }
}