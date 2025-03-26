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
     * Initializes a new instance of the class and sets its data property to the value of the request body.
     */
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private $headers = [];
    private $rawInput;

    public function __construct()
    {
        // Store raw input securely
        $this->rawInput = file_get_contents('php://input');
        
        // Parse and sanitize input data
        $jsonData = $this->rawInput ? json_decode($this->rawInput, true) : null;
        $this->data = $this->sanitizeData($jsonData ?? $_REQUEST);
        
        // Parse headers
        $this->headers = $this->getRequestHeaders();
    }

    private function sanitizeData($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeData'], $data);
        }
        if (is_string($data)) {
            // Remove null bytes and sanitize
            $data = str_replace(chr(0), '', $data);
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        }
        return $data;
    }

    private function getRequestHeaders()
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) === 'HTTP_') {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $this->sanitizeData($value);
            }
        }
        return $headers;
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
        // Prevent method override injection
        if ($key === '_method') {
            if (!in_array(strtoupper($value), self::ALLOWED_METHODS)) {
                return;
            }
        }
        $this->data[$key] = $this->sanitizeData($value);
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
     * @return self A new instance of this class representing the current request.
     */
    public static function createFromGlobals()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];
        $headers = getallheaders();
        $body = file_get_contents('php://input');

        $request = new static();
        $request->data['_method'] = $method;
        return $request;
    }

    /**
     * Returns the HTTP request method.
     *
     * @return string The HTTP request method.
     */
    public function getMethod()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $override = $this->get('_method');
        
        if ($method === 'POST' && $override && in_array(strtoupper($override), self::ALLOWED_METHODS)) {
            return strtoupper($override);
        }
        
        return $method;
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

        // Check for proxy-forwarded IP
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && 
            filter_var($_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        // Check for client IP
        if (isset($_SERVER['HTTP_CLIENT_IP']) && 
            filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
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

    /**
     * Retrieve a header from the request.
     *
     * @param  string  $key
     * @param  string|array|null  $default
     * @return string|array|null
     */
    public function header($key, $default = null)
    {
        $key = str_replace('_', '-', strtolower($key)); // Normalize key (e.g., x_csrf_token -> x-csrf-token)
        foreach ($this->headers as $headerKey => $value) {
            if (strtolower($headerKey) === $key) {
                return $value;
            }
        }
        return $default;
    }

}
