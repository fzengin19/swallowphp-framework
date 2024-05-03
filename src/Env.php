<?php

namespace SwallowPHP\Framework;

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
    public static function getAsJson()
    {
        $envArray = [];

        if (file_exists( '../.env')) {
            $lines = file(  '../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                list($name, $value) = explode('=', $line, 2);
                $value = trim($value, "\"");
                $envArray[$name] = $value;
            }
        }

        return json_encode($envArray);
    }


    public static function load($environmentFile = __DIR__. '/../.env')
    {
       
        if (file_exists( $environmentFile)) {
            $lines = file(  $environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                list($name, $value) = explode('=', $line, 2);
                $value = trim($value, "\"");
                putenv("$name=$value");
            }
        }
    }
}
