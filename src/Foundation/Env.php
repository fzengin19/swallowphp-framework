<?php

namespace SwallowPHP\Framework\Foundation;

class Env
{
    /**
     * Retrieves the value of an environment variable.
     *
     * @param string $key The name of the environment variable to retrieve.
     * @param mixed|null $default The default value to return if the variable is not set.
     *
     * @return mixed The value of the environment variable, or the default value if not set.
     */
    public static function get($key, $default = null)
    {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }

    /**
     * Gets the environment variables as JSON.
     *
     * @return string JSON representation of environment variables.
     */
    public static function getAsJson($environmentFile = null) {
        if ($environmentFile === null) {
            // Use the same reliable path finding as load()
            $basePath = dirname(__DIR__, 2); // src/Foundation -> src -> project_root
            $environmentFile = $basePath . '/.env';
        }
        $envArray = [];

        if (file_exists( $environmentFile)) {
            $lines = file($environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments and invalid lines (same logic as load())
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                    continue;
                }

                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                // Remove surrounding quotes
                if (strlen($value) > 1 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                    $value = substr($value, 1, -1);
                } elseif (strlen($value) > 1 && $value[0] === '\'' && $value[strlen($value) - 1] === '\'') {
                    $value = substr($value, 1, -1);
                }

                // Remove potential 'export ' prefix
                if (str_starts_with($name, 'export ')) {
                    $name = trim(substr($name, 7));
                }

                $envArray[$name] = $value;
            }
        }

        return json_encode($envArray);
    }


    public static function load($environmentFile = null) {
        if ($environmentFile === null) {
            // Search for .env file starting from the project root
            $basePath = dirname(__DIR__, 2); // src/Foundation -> src -> project_root
            $environmentFile = $basePath . '/.env';
        }

        if (file_exists( $environmentFile)) {
            // Read file line by line for better parsing control
            $lines = file($environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                // Skip comments
                if (str_starts_with(trim($line), '#')) {
                    continue;
                }

                // Skip lines without '='
                if (!str_contains($line, '=')) {
                    continue;
                }

                // Split into name and value
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                // Remove surrounding quotes (single or double)
                if (strlen($value) > 1 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                    $value = substr($value, 1, -1);
                } elseif (strlen($value) > 1 && $value[0] === '\'' && $value[strlen($value) - 1] === '\'') {
                    $value = substr($value, 1, -1);
                }

                // Remove potential 'export ' prefix from name
                if (str_starts_with($name, 'export ')) {
                    $name = trim(substr($name, 7));
                }

                // Set environment variables (putenv, $_ENV, $_SERVER)
                // Check if variable already exists to avoid overwriting system vars? Optional.
                if (!getenv($name) && !isset($_ENV[$name]) && !isset($_SERVER[$name])) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value; // Less common, but some code might check $_SERVER
                }
            }
        }
    }
}