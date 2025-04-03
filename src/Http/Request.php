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
     * Protected constructor. Use createFromGlobals() to instantiate.
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
        $this->query = $this->sanitizeData($query);
        $this->request = $this->sanitizeData($request);
        $this->headers = $headers; // Headers are sanitized during creation
        $this->server = $server;
        $this->rawInput = $rawInput;
    }

    /**
     * Creates a new Request instance from PHP global variables.
     * @return static
     */
    public static function createFromGlobals(): static
    {
        $server = $_SERVER;
        $uri = $server['REQUEST_URI'] ?? '/';
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        // Suppress errors in case php://input is not available (e.g., CLI without stdin)
        $rawInput = @file_get_contents('php://input') ?: '';

        $headers = static::parseHeadersFromServer($server);

        // Parse Query String
        $queryString = parse_url($uri, PHP_URL_QUERY);
        $query = [];
        if ($queryString) {
            parse_str($queryString, $query);
        }

        // Parse Request Body
        $requestData = [];
        $contentType = strtolower($headers['content-type'] ?? '');

        // Handle form-urlencoded data
        if (($method === 'POST' || $method === 'PUT' || $method === 'PATCH') && str_contains($contentType, 'application/x-www-form-urlencoded')) {
             // If rawInput is empty but method is POST/PUT/PATCH, PHP might have already parsed into $_POST
             if (empty($rawInput) && !empty($_POST)) {
                  $requestData = $_POST;
             } elseif(!empty($rawInput)) {
                 // Try parsing raw input if $_POST is empty (might happen with some server configs)
                 parse_str($rawInput, $requestData);
             }
        }
        // Handle JSON data
        elseif (str_contains($contentType, 'application/json') && !empty($rawInput)) {
            $jsonData = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $requestData = $jsonData;
            } else {
                // Invalid JSON received. Do not log here as logger might not be available.
                // Controller or middleware should handle the empty $requestData if needed.
                // Removed: error_log("Request creation: Invalid JSON received.");
            }
        }
        // TODO: Add handling for multipart/form-data ($_FILES and potentially combining with $_POST)

        return new static($uri, $method, $query, $requestData, $headers, $server, $rawInput);
    }

    /**
     * Parses HTTP headers from the $_SERVER array.
     * @param array $server The $_SERVER array.
     * @return array Parsed headers (keys are lower-case).
     */
    protected static function parseHeadersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerKey = substr($key, 5);
                $headerKey = str_replace('_', ' ', strtolower($headerKey));
                $headerKey = str_replace(' ', '-', ucwords($headerKey));
                $headers[strtolower($headerKey)] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                 $headerKey = str_replace('_', '-', strtolower($key));
                 $headers[$headerKey] = $value;
            }
        }
        // Basic Auth handling
        if (isset($server['PHP_AUTH_USER'])) {
             $headers['php-auth-user'] = $server['PHP_AUTH_USER'];
             $headers['php-auth-pw'] = $server['PHP_AUTH_PW'] ?? '';
        } elseif (isset($server['HTTP_AUTHORIZATION'])) {
             if (str_starts_with(strtolower($server['HTTP_AUTHORIZATION']),'basic ')) { // Note space after basic
                  $decoded = base64_decode(substr($server['HTTP_AUTHORIZATION'], 6), true); // Use strict
                  if ($decoded && str_contains($decoded, ':')) {
                       list($user, $pw) = explode(':', $decoded, 2);
                       $headers['php-auth-user'] = $user;
                       $headers['php-auth-pw'] = $pw;
                  }
             }
             // Add handling for other Authorization types like Bearer if needed
             // elseif (str_starts_with(strtolower($server['HTTP_AUTHORIZATION']),'bearer ')) { ... }
        }

        // Sanitize header values (consider if this is always desired)
        $sanitizedHeaders = [];
        $tempInstance = new static('/', 'GET', [], [], [], [], '');
        foreach ($headers as $key => $value) {
             $sanitizedHeaders[$key] = $tempInstance->sanitizeData($value);
        }

        return $sanitizedHeaders;
    }

    /**
     * Sanitizes input data recursively (basic XSS protection for string data).
     * @param mixed $data
     * @return mixed
     */
    protected function sanitizeData(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeData'], $data);
        }
        if (is_string($data)) {
            $data = str_replace(chr(0), '', $data); // Remove null bytes
            return htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return $data;
    }

    /** Get all combined input data (query + request body). */
    public function all(): array
    {
        return array_merge($this->query, $this->request);
    }

    /** Get query parameters. */
    public function query(): array
    {
        return $this->query;
    }

     /** Get request body parameters. */
     public function request(): array
     {
         return $this->request;
     }

    /** Get a specific input value (checks body first, then query). */
    public function get(string $key, mixed $default = null): mixed
    {
        // Check request body first, then query parameters
        return $this->request[$key] ?? $this->query[$key] ?? $default;
    }

     /** Get a specific query parameter value. */
     public function getQuery(string $key, mixed $default = null): mixed
     {
         return $this->query[$key] ?? $default;
     }

     /** Get a specific request body parameter value. */
     public function getRequestValue(string $key, mixed $default = null): mixed
     {
         return $this->request[$key] ?? $default;
     }

    /** Sets a value in the request body data array (use with caution). */
    public function set(string $key, mixed $value): void
    {
        // Sanitize value before setting
        $this->request[$key] = $this->sanitizeData($value);
    }

    /** Set all request body data (overwrites existing, use with caution). */
    public function setAll(array $data): void
    {
        $this->request = $this->sanitizeData($data);
    }

    /** Get the request URI (path + query string). */
    public function getUri(): string
    {
        return $this->uri;
    }

     /** Get the request path (URI without query string). */
     public function getPath(): string
     {
         return parse_url($this->uri, PHP_URL_PATH) ?: '/';
     }

    /** Get the request method (handles method overriding). */
    public function getMethod(): string
    {
        // Check for _method override in POST requests (from request body data)
        // Note: This uses $this->request which is sanitized. If _method needs raw value, adjust.
        $override = $this->request['_method'] ?? $this->query['_method'] ?? null; // Check query too? Less common.
        if ($this->method === 'POST' && is_string($override)) {
             $upperOverride = strtoupper($override);
             $allowedMethods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
             if (in_array($upperOverride, $allowedMethods)) {
                 return $upperOverride;
             }
        }
        return $this->method;
    }

    /** Get the request scheme (http or https). */
    public function getScheme(): string
    {
        // Check HTTPS status, considering potential proxy headers if needed later
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
               || ($this->server['SERVER_PORT'] ?? 80) == 443 // Basic check for standard HTTPS port
               ? 'https' : 'http';
    }

    /** Get the host name for the request. */
    public function getHost(): string
    {
        // Prefer HTTP_HOST, fallback to SERVER_NAME
        return $this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? '';
    }

    /** Get the full URL including scheme, host, and URI. */
    public function fullUrl(): string
    {
        return $this->getScheme() . '://' . $this->getHost() . $this->getUri();
    }

    /** Retrieve a header from the request (case-insensitive). */
    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

     /** Get all headers. */
     public function headers(): array
     {
         return $this->headers;
     }

    /** Get the raw request body content. */
    public function rawInput(): string
    {
        return $this->rawInput;
    }

    /** Get a value from the server parameters. */
    public function server(string $key, mixed $default = null): mixed
    {
         // Try uppercase first, then original case
         return $this->server[strtoupper($key)] ?? $this->server[$key] ?? $default;
    }

    /**
     * Get the client's IP address. Handles common proxy headers.
     * @return string|null
     */
    public static function getClientIp(): ?string
    {
        // Use $_SERVER directly as this might be called before Request instance is fully available
        // or in contexts without a Request object.
        $server = $_SERVER;

        // Order matters: Check trusted proxy headers first if configured,
        // then common headers, finally REMOTE_ADDR.
        // For simplicity, using a common order without trusted proxy config:
        $ipHeaders = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($server[$header])) {
                // If X-Forwarded-For, take the first IP in the list
                $ip = $server[$header];
                if ($header === 'HTTP_X_FORWARDED_FOR') {
                    $parts = explode(',', $ip);
                    $ip = trim($parts[0]);
                }

                // Basic IP validation
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    // Optionally filter private/reserved ranges, but REMOTE_ADDR might be private
                    // if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    //     return $ip; // Found valid public IP from proxy header
                    // } elseif ($header === 'REMOTE_ADDR') {
                    //     return $ip; // Return REMOTE_ADDR even if private
                    // }
                    return $ip; // Return first valid IP found
                }
            }
        }
        return null; // Could not determine IP
    }
}