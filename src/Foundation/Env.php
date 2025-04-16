<?php

namespace SwallowPHP\Framework\Foundation;

class Env
{
    protected static ?string $basePath = null;

    /**
     * Set the base path for the application.
     *
     * @param string $path
     * @return void
     */
    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Get the base path. If not set, fallback to autodetect.
     *
     * @return string
     */
    public static function getBasePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        // fallback: guess from current file location (4 levels up)
        return dirname(__DIR__, 4);
    }

    /**
     * Get environment variable value.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        $value = getenv($key);
        return $value !== false ? $value : $default;
    }

    /**
     * Load environment variables from .env file inside basePath.
     *
     * @return void
     */
    public static function load(): void
    {
        $envPath = self::getBasePath() . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($envPath)) {
            error_log("Warning: .env file not found at: " . $envPath);
            return;
        }

        if (!is_readable($envPath)) {
            error_log("Warning: .env file is not readable at: " . $envPath);
            return;
        }
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (strlen($value) > 1 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === '\'' && $value[-1] === '\''))) {
                $value = substr($value, 1, -1);
            }

            if (str_starts_with($name, 'export ')) {
                $name = trim(substr($name, 7));
            }

            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        // Bonus: set BASE_PATH as env var
        $base = self::getBasePath();
        putenv("BASE_PATH=$base");
        $_ENV['BASE_PATH'] = $base;
        $_SERVER['BASE_PATH'] = $base;
    }

    /**
     * Get environment variables from .env as JSON (inside basePath).
     *
     * @return string
     */
    public static function getAsJson(): string
    {
        $envPath = self::getBasePath() . DIRECTORY_SEPARATOR . '.env';
        $envArray = [];

        if (!file_exists($envPath)) {
            return json_encode($envArray);
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (strlen($value) > 1 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === '\'' && $value[-1] === '\''))) {
                $value = substr($value, 1, -1);
            }

            if (str_starts_with($name, 'export ')) {
                $name = trim(substr($name, 7));
            }

            $envArray[$name] = $value;
        }

        return json_encode($envArray);
    }
}
