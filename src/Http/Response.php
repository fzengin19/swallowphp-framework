<?php

namespace SwallowPHP\Framework\Http;

use InvalidArgumentException;
use SwallowPHP\Framework\Foundation\App; // For logger access
use Psr\Log\LoggerInterface; // For logger type hint
use Stringable; // For type hint

class Response
{
    /** @var mixed Response content. */
    protected mixed $content = '';

    /** @var int HTTP status code. */
    protected int $statusCode = 200;

    /** @var array<string, string|array> Response headers. */
    protected array $headers = [];

    /** @var LoggerInterface|null Logger instance. */
    protected ?LoggerInterface $logger = null; // Added logger property

    /** @var array<int, string> Standard HTTP status codes and texts. */
    public const STATUS_TEXTS = [
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information',
        204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content',
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        304 => 'Not Modified', 305 => 'Use Proxy', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required',
        408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required',
        412 => 'Precondition Failed', 413 => 'Payload Too Large', 414 => 'URI Too Long', 415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable', 417 => 'Expectation Failed', 419 => 'Page Expired',
        421 => 'Misdirected Request', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency',
        425 => 'Too Early', 426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage', 508 => 'Loop Detected', 510 => 'Not Extended', 511 => 'Network Authentication Required',
    ];

    /** Constructor. */
    public function __construct(mixed $content = '', int $status = 200, array $headers = [])
    {
        try { $this->logger = App::container()->get(LoggerInterface::class); }
        catch (\Throwable $e) { /* Ignore */ }

        $this->setContent($content);
        $this->setStatusCode($status);

        $defaultHeaders = [
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
            'x-xss-protection' => '1; mode=block',
            'referrer-policy' => 'strict-origin-when-cross-origin',
        ];

        $normalizedHeaders = [];
        foreach ($headers as $key => $value) {
             $normalizedHeaders[strtolower((string)$key)] = $value;
        }
        $this->headers = array_merge($defaultHeaders, $normalizedHeaders);

        if (!isset($this->headers['content-type'])) {
             if (is_string($content) || is_numeric($content) || is_null($content) || ($content instanceof Stringable)) {
                 $this->header('Content-Type', 'text/html; charset=UTF-8');
             } elseif (is_array($content) || is_object($content)) {
                 $this->header('Content-Type', 'application/json');
             }
        }
    }

    /** Set response content. */
    public function setContent(mixed $content): self { $this->content = $content; return $this; }
    /** Get response content. */
    public function getContent(): mixed { return $this->content; }

    /** Set HTTP status code. */
    public function setStatusCode(int $code, ?string $text = null): self
    {
        if (!isset(self::STATUS_TEXTS[$code])) {
            throw new InvalidArgumentException("Invalid HTTP status code: {$code}");
        }
        $this->statusCode = $code;
        return $this;
    }

    /** Get HTTP status code. */
    public function getStatusCode(): int { return $this->statusCode; }

    /** Set a response header. */
    public function header(string $key, string|array $value, bool $replace = true): self
    {
        $key = strtolower($key);
        $value = (array) $value;
        if ($replace || !isset($this->headers[$key])) {
            $this->headers[$key] = $value;
        } else {
            $this->headers[$key] = array_merge((array) $this->headers[$key], $value);
        }
        return $this;
    }

    /** Get a response header. */
    public function getHeader(string $key, mixed $default = null): ?string
    {
        $key = strtolower($key);
        if (!isset($this->headers[$key])) { return $default; }
        $value = $this->headers[$key];
        return is_array($value) ? reset($value) : $value;
    }

    /** Get all response headers. */
    public function getHeaders(): array { return $this->headers; }

    /** Sends HTTP headers and content. */
    public function send(): self
    {
        $this->sendHeaders();
        $this->sendContent();
        return $this;
    }

    /** Sends HTTP headers, including any queued cookies. */
    public function sendHeaders(): self
    {
        if (headers_sent($file, $line)) {
             $logMsg = "Response::sendHeaders - Cannot send headers, already sent";
             if ($this->logger) $this->logger->warning($logMsg, ['output_started_at' => "{$file}:{$line}"]);
             else error_log($logMsg . " in {$file}:{$line}"); // Fallback log
            return $this;
        }

        // Send queued cookies BEFORE sending other headers
        // Check if Cookie class and method exist to avoid errors if class structure changes
        if (class_exists(Cookie::class) && method_exists(Cookie::class, 'sendQueuedCookies')) {
            Cookie::sendQueuedCookies();
        }

        // Send status code
        http_response_code($this->statusCode);

        // Send response headers
        foreach ($this->headers as $name => $values) {
            $name = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
            foreach ((array) $values as $value) {
                 header($name . ': ' . $value, false, $this->statusCode);
            }
        }
        return $this;
    }

    /** Sends content for the current web response. */
    public function sendContent(): self
    {
        $output = '';
        $contentType = $this->getHeader('content-type', '');

        try {
            if (str_contains($contentType, 'application/json')) {
                if (is_array($this->content) || is_object($this->content)) {
                     $output = json_encode($this->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                } else {
                     $logMsg = "Content type is JSON but content is not array/object.";
                     $context = ['content_type' => gettype($this->content)];
                     if ($this->logger) $this->logger->error($logMsg, $context);
                     else error_log("Response::sendContent - " . $logMsg . " Type: " . $context['content_type']);
                     $output = json_encode(['error' => 'Invalid JSON response content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                     if (!headers_sent()) http_response_code(500);
                }
            } elseif ($this->content instanceof Stringable || is_scalar($this->content) || is_null($this->content)) {
                $output = (string) $this->content;
            } else {
                 $logMsg = "Unsupported content type for direct output.";
                 $context = ['content_type' => gettype($this->content)];
                 if ($this->logger) $this->logger->error($logMsg, $context);
                 else error_log('Response::sendContent - ' . $logMsg . ' Type: ' . $context['content_type']);
                 $output = 'Internal Server Error: Cannot output response content.';
                 if (!headers_sent()) {
                      http_response_code(500);
                      header('Content-Type: text/plain; charset=UTF-8');
                 }
            }
        } catch (\JsonException $e) {
             $logMsg = "JSON encoding error during content preparation.";
             if ($this->logger) $this->logger->critical($logMsg, ['error' => $e->getMessage()]);
             else error_log('Response::sendContent - ' . $logMsg . ' Error: ' . $e->getMessage());
             $output = '{"error": "Internal Server Error during JSON encoding"}';
             if (!headers_sent()) {
                  http_response_code(500);
                  header('Content-Type: application/json');
             }
        } catch (\Throwable $e) {
             $logMsg = "Unexpected error during content preparation.";
             if ($this->logger) $this->logger->critical($logMsg, ['exception' => $e]);
             else error_log('Response::sendContent - ' . $logMsg . ' Error: ' . $e->getMessage());
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

    /** Create a new JSON response. */
    public static function json(mixed $data = [], int $status = 200, array $headers = []): static
    {
        $headers['Content-Type'] = 'application/json';
        return new static($data, $status, $headers);
    }

    /** Create a new HTML response. */
    public static function html(string $content = '', int $status = 200, array $headers = []): static
    {
        $headers['Content-Type'] = 'text/html; charset=UTF-8';
        return new static($content, $status, $headers);
    }

     /** Create a new redirect response. */
     public static function redirect(string $url, int $status = 302, array $headers = []): static
     {
         if ($status < 300 || $status > 308) { $status = 302; }
         $response = new static('', $status, $headers);
         $response->header('Location', $url);
         return $response;
     }
}
