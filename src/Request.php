<?php

namespace SwallowPHP\Framework;


class Request
{
    private $data = [];
    /**
     * Returns the full URL of the current request including scheme, host, path, and query string.
     *
     * @return string The full URL of the current request.
     */
    public function fullUrl()
    {
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $uri = $_SERVER['REQUEST_URI'];

        return $scheme . '://' . $host . $uri;
    }

    /**
     * Initializes a new instance of the class and sets its data property to the value of the $_REQUEST superglobal.
     */
    public function __construct()
    {
        $this->data = $_REQUEST;
    }

    /**
     * Returns all the non-null data stored in the current object.
     *
     * @return array The non-null data stored in the object.
     */
    public function all()
    {
        return $this->data;
    }

    /**
     * Returns an array of query parameters parsed from the request URI.
     *
     * @return array The query parameters parsed from the request URI.
     */
    public function query()
    {
        // Parse the query string from the request URI
        $queryString = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);

        // Initialize an empty array to store the query parameters
        $queryParams = [];

        // If there are query parameters in the URL, parse them and store in the array
        if ($queryString) {
            parse_str($queryString, $queryParams);
        }

        return $queryParams;
    }


    /**
     * Returns the value associated with the given key in the data array, or the provided default value if the key is not found.
     *
     * @param string $key The key to retrieve the value for.
     * @param mixed $default The value to return if the key is not found in the data array.
     * @return mixed The value associated with the given key in the data array, or the provided default value if the key is not found.
     */
    public function get($key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Sets a value in the data array with the given key.
     *
     * @param string $key The key to set the value for.
     * @param mixed $value The value to set for the given key.
     * @return void
     */
    public function set($key, $value)
    {
        if ($key != '_method')
            $this->data[$key] = $value;
    }

    /**
     * Set all the data in the class.
     *
     * @param mixed $data The data to be set.
     * @return void
     */
    public function setAll($data)
    {
        $this->data =   $data;
        unset($this->data['_method']);
    }

    /**
     * Creates a new instance of this class based on the current request globals.
     *
     * This method reads the current HTTP request method, URI, headers, and body
     * from the $_SERVER, getallheaders(), and php://input globals, respectively.
     * It then uses these values to create and return a new instance of this class.
     *
     * @return self A new instance of this class representing the current request.
     */
    public static function createFromGlobals()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];
        $headers = getallheaders();
        $body = file_get_contents('php://input');

        return new static($method, $uri, $headers, $body);
    }

    /**
     * Returns the HTTP request method.
     *
     * @return string The HTTP request method.
     */
    public function getMethod()
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    /**
     * Returns the HTTP request method.
     *
     * @return string The HTTP request method.
     */
    public function getUri()
    {
        return $_SERVER['REQUEST_URI'];
    }


    /**
     * Returns the client's IP address based on various HTTP headers.
     *
     * @return string|null The client's IP address or null if it could not be determined.
     */
    public static function getClientIp()
    {
        $ip = null;
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ip = $_SERVER['HTTP_FORWARDED'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }
}
