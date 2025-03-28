<?php

namespace SwallowPHP\Framework\Http;

class Request
{
    // Store core request components as properties
    public string $uri;
    public string $method;
    public array $query = []; // Query parameters (?foo=bar)
    public array $request = []; // Parsed body parameters (POST, JSON etc.)
    public array $headers = [];
    public array $server = []; // Subset of $_SERVER relevant to the request
    public string $rawInput = '';

    /**
     * Private constructor. Use createFromGlobals() to instantiate.
     *
     * @param string $uri
     * @param string $method
     * @param array $query
     * @param array $request Parsed request body
     * @param array $headers
     * @param array $server
     * @param string $rawInput
     */
    protected function __construct(
        string $uri,
        string $method,
        array $query,
        array $request,
        array $headers,
        array $server,
        string $rawInput
    ) {
        $this->uri = $uri;
        $this->method = $method;
        $this->query = $this->sanitizeData($query); // Sanitize query params
        $this->request = $this->sanitizeData($request); // Sanitize body params
        $this->headers = $headers; // Headers are sanitized during creation
        $this->server = $server;
        $this->rawInput = $rawInput; // Raw input is not sanitized by default
    }

    /**
     * Creates a new Request instance from PHP global variables.
     * This should be the primary way to create a Request object.
     *
     * @return static
     */
    public static function createFromGlobals(): static
    {
        $server = $_SERVER; // Capture server variables
        $uri = $server['REQUEST_URI'] ?? '/';
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $rawInput = file_get_contents('php://input') ?: '';

        // Parse Headers using a reliable method
        $headers = static::parseHeadersFromServer($server);

        // Parse Query String
        $queryString = parse_url($uri, PHP_URL_QUERY);
        $query = [];
        if ($queryString) {
            parse_str($queryString, $query);
        }

        // Parse Request Body (POST, JSON, etc.)
        $requestData = [];
        $contentType = strtolower($headers['content-type'] ?? '');

        if ($method === 'POST' && str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $requestData = $_POST; // Use $_POST for form data
        } elseif (str_contains($contentType, 'application/json') && !empty($rawInput)) {
            $jsonData = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $requestData = $jsonData;
            } else {
                // Handle JSON decode error? Log it?
                error_log("Request creation: Invalid JSON received.");
            }
        }
        // Add handling for other content types like multipart/form-data if needed (using $_FILES)

        // Create instance with parsed data
        return new static($uri, $method, $query, $requestData, $headers, $server, $rawInput);
    }

    /**
     * Parses HTTP headers from the $_SERVER array.
     *
     * @param array $server The $_SERVER array.
     * @return array Parsed headers.
     */
    protected static function parseHeadersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerKey = substr($key, 5);
                $headerKey = str_replace('_', ' ', strtolower($headerKey));
                $headerKey = str_replace(' ', '-', ucwords($headerKey));
                $headers[strtolower($headerKey)] = $value; // Store keys as lower-case
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                 // Handle CGI-specific headers
                 $headerKey = str_replace('_', '-', strtolower($key));
                 $headers[$headerKey] = $value;
            }
        }
        // Basic Auth?
        if (isset($server['PHP_AUTH_USER'])) {
             $headers['php-auth-user'] = $server['PHP_AUTH_USER'];
             $headers['php-auth-pw'] = $server['PHP_AUTH_PW'] ?? '';
        } elseif (isset($server['HTTP_AUTHORIZATION'])) {
             if (str_starts_with(strtolower($server['HTTP_AUTHORIZATION']),'basic')) {
                  $decoded = base64_decode(substr($server['HTTP_AUTHORIZATION'], 6));
                  if ($decoded && str_contains($decoded, ':')) {
                       list($user, $pw) = explode(':', $decoded, 2);
                       $headers['php-auth-user'] = $user;
                       $headers['php-auth-pw'] = $pw;
                  }
             }
        }

        // Sanitize headers after parsing
        $sanitizedHeaders = [];
        $tempInstance = new static('/', 'GET', [], [], [], [], ''); // Create temp instance for sanitizeData
        foreach ($headers as $key => $value) {
             $sanitizedHeaders[$key] = $tempInstance->sanitizeData($value);
        }

        return $sanitizedHeaders;
    }

    /**
     * Sanitizes input data recursively.
     * Applies htmlspecialchars to strings.
     *
     * @param mixed $data
     * @return mixed
     */
    protected function sanitizeData(mixed $data): mixed
    {
        if (is_array($data)) {
            // Use $this->sanitizeData for recursive call
            return array_map([$this, 'sanitizeData'], $data);
        }
        if (is_string($data)) {
            // Remove null bytes and sanitize
            $data = str_replace(chr(0), '', $data);
            // Consider context. htmlspecialchars is good for HTML output,
            // but might not be ideal for DB or other contexts.
            // For general input sanitization, maybe just null byte removal is enough here?
            // Let's keep htmlspecialchars for now as a basic XSS measure on input.
            return htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return $data;
    }

    /**
     * Get all combined input data (query + request body).
     *
     * @return array
     */
    public function all(): array
    {
        // Merge query and request body parameters. Request body takes precedence.
        return array_merge($this->query, $this->request);
    }

    /**
     * Get query parameters.
     *
     * @return array
     */
    public function query(): array
    {
        return $this->query;
    }

     /**
      * Get request body parameters.
      *
      * @return array
      */
     public function request(): array
     {
         return $this->request;
     }

    /**
     * Get a specific input value (checks request body first, then query parameters).
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $this->query[$key] ?? $default;
    }

     /**
      * Get a specific query parameter value.
      *
      * @param string $key
      * @param mixed $default
      * @return mixed
      */
     public function getQuery(string $key, mixed $default = null): mixed
     {
         return $this->query[$key] ?? $default;
     }

     /**
      * Get a specific request body parameter value.
      *
      * @param string $key
      * @param mixed $default
      * @return mixed
      */
     public function getRequestValue(string $key, mixed $default = null): mixed
     {
         return $this->request[$key] ?? $default;
     }

    /**
     * Sets a value in the request body data array.
     * Note: This modifies the request data, use with caution.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        // Prevent method override injection (already handled by getMethod)
        // if ($key === '_method') { ... }
        $this->request[$key] = $this->sanitizeData($value);
    }

    /**
     * Set all request body data.
     * Note: This overwrites existing request body data.
     *
     * @param array $data
     * @return void
     */
    public function setAll(array $data): void
    {
        $this->request = $this->sanitizeData($data);
        // unset($this->request['_method']); // _method is not part of request body data
    }

    /**
     * Get the request URI (path + query string).
     *
     * @return string
     */
    public function getUri(): string
    {
        return $this->uri;
    }

     /**
      * Get the request path (URI without query string).
      *
      * @return string
      */
     public function getPath(): string
     {
         return parse_url($this->uri, PHP_URL_PATH) ?: '/';
     }

    /**
     * Get the request method. Handles method overriding (_method).
     *
     * @return string
     */
    public function getMethod(): string
    {
        // Check for _method override in POST requests (from request body data)
        $override = $this->request['_method'] ?? null;
        if ($this->method === 'POST' && is_string($override)) {
             $upperOverride = strtoupper($override);
             // Use a constant or configurable list of allowed methods
             $allowedMethods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
             if (in_array($upperOverride, $allowedMethods)) {
                 return $upperOverride;
             }
        }
        return $this->method;
    }

    /**
     * Get the request scheme (http or https).
     *
     * @return string
     */
    public function getScheme(): string
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    /**
     * Get the host name for the request.
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->server['HTTP_HOST'] ?? '';
    }

    /**
     * Get the full URL including scheme, host, and URI.
     *
     * @return string
     */
    public function fullUrl(): string
    {
        return $this->getScheme() . '://' . $this->getHost() . $this->getUri();
    }

    /**
     * Retrieve a header from the request.
     *
     * @param string $key Header name (case-insensitive).
     * @param string|null $default
     * @return string|null
     */
    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

     /**
      * Get all headers.
      *
      * @return array
      */
     public function headers(): array
     {
         return $this->headers;
     }

    /**
     * Get the raw request body content.
     *
     * @return string
     */
    public function rawInput(): string
    {
        return $this->rawInput;
    }

    /**
     * Get a value from the server parameters.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function server(string $key, mixed $default = null): mixed
    {
         return $this->server[strtoupper($key)] ?? $this->server[$key] ?? $default; // Check both upper and original case
    }

    /**
     * Get the client's IP address.
     * Handles various proxy headers.
     *
     * @return string|null
     */
    public static function getClientIp(): ?string
    {
        // Use the instance method if available (requires Request instance)
        // If called statically, fallback to direct $_SERVER check (less ideal)
        // This static method might be better placed elsewhere or removed if Request is always available via DI.

        $server = $_SERVER; // Use captured server array if possible, else fallback to global

        $ipHeaders = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR', // Check this first as it can contain multiple IPs
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($server[$header])) {
                // Handle X-Forwarded-For potentially containing multiple IPs
                if ($header === 'HTTP_X_FORWARDED_FOR') {
                    $ips = explode(',', $server[$header]);
                    $ip = trim($ips[0]); // Take the first IP in the list
                } else {
                    $ip = $server[$header];
                }

                // Validate the IP address (excluding private/reserved ranges)
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                // If validation fails for proxy headers, continue checking others
                // If REMOTE_ADDR fails validation (unlikely), return it anyway as last resort?
                if ($header === 'REMOTE_ADDR') {
                     // Validate REMOTE_ADDR without range flags if needed, or just return it
                     if (filter_var($ip, FILTER_VALIDATE_IP)) {
                          return $ip;
                     }
                }
            }
        }

        return null; // Could not determine valid IP
    }
}