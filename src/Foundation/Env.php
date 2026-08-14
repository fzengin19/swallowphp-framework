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

        return dirname(__DIR__, 5);
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

            // Skip malformed lines:
            //  - empty/whitespace-only name (e.g. "=value" — `=value` after
            //    explode+trim leaves an empty $name; putenv("=value") throws
            //    \ValueError on PHP 8+ and would abort the whole load loop);
            //  - name containing internal whitespace (e.g. "BAD NAME=val").
            //    trim() only strips leading/trailing whitespace, so a name
            //    like "INTERNAL SPACE=val" survives trim as "INTERNAL SPACE"
            //    and is a malformed env var by POSIX convention even though
            //    PHP's putenv() happens to accept it silently. We reject it
            //    defensively so callers can't later index $_ENV/getenv() with
            //    a key that contains whitespace.
            // In both cases we log via error_log() and `continue` so a single
            // bad line does NOT abort loading of all lines after it.
            if ($name === '' || preg_match('/\s/', $name) === 1) {
                error_log("Warning: Skipping malformed .env line (invalid variable name): " . $line);
                continue;
            }

            putenv("$name=$value");
            $_ENV[$name] = $value;
        }

        // Bonus: set BASE_PATH as env var
        $base = self::getBasePath();
        putenv("BASE_PATH=$base");
        $_ENV['BASE_PATH'] = $base;
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
