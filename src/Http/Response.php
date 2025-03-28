<?php

namespace SwallowPHP\Framework\Http;

use InvalidArgumentException;

class Response
{
    /**
     * Response content.
     * @var mixed
     */
    protected mixed $content = '';

    /**
     * HTTP status code.
     * @var int
     */
    protected int $statusCode = 200;

    /**
     * Response headers.
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * Standard HTTP status codes and texts.
     * @var array<int, string>
     */
    public const STATUS_TEXTS = [
        // Informational
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing',
        // Success
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information',
        204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content',
        // Redirection
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        304 => 'Not Modified', 305 => 'Use Proxy', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        // Client Error
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required',
        408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required',
        412 => 'Precondition Failed', 413 => 'Payload Too Large', 414 => 'URI Too Long', 415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable', 417 => 'Expectation Failed', 421 => 'Misdirected Request',
        422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Too Early',
        426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
        // Server Error
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage', 508 => 'Loop Detected', 510 => 'Not Extended', 511 => 'Network Authentication Required',
    ];

    /**
     * Constructor.
     *
     * @param mixed $content The response content.
     * @param int $status The response status code.
     * @param array $headers An array of response headers.
     */
    public function __construct(mixed $content = '', int $status = 200, array $headers = [])
    {
        $this->setContent($content);
        $this->setStatusCode($status);

        // Default security headers (can be overridden by $headers)
        $defaultHeaders = [
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN', // SAMEORIGIN is often more flexible than DENY
            'x-xss-protection' => '1; mode=block', // Deprecated but still provides fallback protection
            'referrer-policy' => 'strict-origin-when-cross-origin',
            // 'content-security-policy' => "default-src 'self'", // CSP is complex, better configured per-app
            // 'strict-transport-security' => 'max-age=31536000; includeSubDomains', // Only if HTTPS is enforced
        ];

        // Merge defaults with provided headers, normalizing keys
        $this->headers = array_merge(
            $defaultHeaders,
            array_change_key_case($headers) // Normalize provided header keys
        );

        // Set default Content-Type based on initial content if not provided
        if (!isset($this->headers['content-type'])) {
             if (is_string($content) || is_numeric($content) || is_null($content) || (is_object($content) && method_exists($content, '__toString'))) {
                 $this->header('Content-Type', 'text/html; charset=UTF-8');
             } elseif (is_array($content) || is_object($content)) { // Assume JSON for arrays/objects
                 $this->header('Content-Type', 'application/json');
             }
        }
    }

    /**
     * Set the response content.
     *
     * @param mixed $content
     * @return $this
     */
    public function setContent(mixed $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Get the response content.
     *
     * @return mixed
     */
    public function getContent(): mixed
    {
        return $this->content;
    }

    /**
     * Set the HTTP status code.
     *
     * @param int $code
     * @param string|null $text Optional status text, defaults to standard text for the code.
     * @return $this
     * @throws InvalidArgumentException If the status code is not valid.
     */
    public function setStatusCode(int $code, ?string $text = null): self
    {
        if (!isset(self::STATUS_TEXTS[$code])) {
            throw new InvalidArgumentException("Invalid HTTP status code: {$code}");
        }
        $this->statusCode = $code;
        // We don't typically set the status text header directly, http_response_code handles it.
        return $this;
    }

    /**
     * Get the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Set a response header.
     *
     * @param string $key The header name.
     * @param string $value The header value.
     * @param bool $replace Replace existing header with the same name?
     * @return $this
     */
    public function header(string $key, string $value, bool $replace = true): self
    {
        $key = strtolower($key); // Normalize key
        if ($replace || !isset($this->headers[$key])) {
            $this->headers[$key] = $value;
        }
        return $this;
    }

    /**
     * Get a response header.
     *
     * @param string $key
     * @param mixed $default
     * @return string|null
     */
    public function getHeader(string $key, mixed $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    /**
     * Get all response headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Sends HTTP headers and content.
     *
     * @return $this
     */
    public function send(): self
    {
        $this->sendHeaders();
        $this->sendContent();
        return $this;
    }

    /**
     * Sends HTTP headers.
     *
     * @return $this
     */
    public function sendHeaders(): self
    {
        if (headers_sent()) {
            error_log("Response::sendHeaders - Cannot send headers, already sent.");
            return $this;
        }

        // Set status code
        http_response_code($this->statusCode);

        // Set headers
        foreach ($this->headers as $name => $value) {
            header(ucwords($name, '-') . ': ' . $value, true, $this->statusCode);
        }

        return $this;
    }

    /**
     * Sends content for the current web response.
     *
     * @return $this
     */
    public function sendContent(): self
    {
        $output = '';
        $contentType = $this->getHeader('content-type', '');

        try {
            if (str_contains($contentType, 'application/json')) {
                // Encode arrays or objects to JSON
                if (is_array($this->content) || is_object($this->content)) {
                     $output = json_encode($this->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                } else {
                     // If content is not array/object but Content-Type is JSON, try to encode anyway or log error?
                     error_log("Response::sendContent - Content type is JSON but content is not array/object.");
                     $output = json_encode(['error' => 'Invalid JSON response content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                     if (!headers_sent()) http_response_code(500);
                }
            } elseif (is_scalar($this->content) || is_null($this->content)) {
                // Output scalar values or null directly
                $output = (string) $this->content;
            } elseif (is_object($this->content) && method_exists($this->content, '__toString')) {
                // Output objects with __toString method
                $output = (string) $this->content;
            } else {
                 // Handle unsupported content types for direct output
                 error_log('Response::sendContent - Unsupported content type for output: ' . gettype($this->content));
                 $output = 'Internal Server Error: Cannot output response content.';
                 if (!headers_sent()) http_response_code(500);
                 // Ensure Content-Type is plain text for error message
                 if (!headers_sent()) header('Content-Type: text/plain; charset=UTF-8');
            }
        } catch (\JsonException $e) {
             error_log('Response::sendContent - JSON encoding error: ' . $e->getMessage());
             $output = '{"error": "Internal Server Error during JSON encoding"}';
             if (!headers_sent()) {
                  http_response_code(500);
                  header('Content-Type: application/json'); // Ensure correct header for error
             }
        } catch (\Throwable $e) {
             error_log('Response::sendContent - Unexpected error during content preparation: ' . $e->getMessage());
             $output = 'Internal Server Error during content preparation.';
             if (!headers_sent()) {
                  http_response_code(500);
                  header('Content-Type: text/plain; charset=UTF-8');
             }
        }

        echo $output;

        return $this;
    }

    // --- Static Factory Methods ---

    /**
     * Create a new JSON response.
     *
     * @param mixed $data The data to encode.
     * @param int $status
     * @param array $headers
     * @return static
     */
    public static function json(mixed $data = [], int $status = 200, array $headers = []): static
    {
        $headers['Content-Type'] = 'application/json';
        return new static($data, $status, $headers);
    }

    /**
     * Create a new HTML response.
     *
     * @param string $content
     * @param int $status
     * @param array $headers
     * @return static
     */
    public static function html(string $content = '', int $status = 200, array $headers = []): static
    {
        $headers['Content-Type'] = 'text/html; charset=UTF-8';
        return new static($content, $status, $headers);
    }

     /**
      * Create a new redirect response.
      *
      * @param string $url The URL to redirect to.
      * @param int $status The status code (301, 302, etc.).
      * @param array $headers
      * @return static
      */
     public static function redirect(string $url, int $status = 302, array $headers = []): static
     {
         $response = new static('', $status, $headers);
         $response->header('Location', $url);
         return $response;
     }

     // Add more factories as needed (e.g., file download, stream)
}