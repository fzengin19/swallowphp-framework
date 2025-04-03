<?php

namespace SwallowPHP\Framework\Foundation;

use SwallowPHP\Framework\Exceptions\AuthorizationException;
use SwallowPHP\Framework\Exceptions\CsrfTokenMismatchException;
use SwallowPHP\Framework\Exceptions\EnvPropertyValueException;
use SwallowPHP\Framework\Exceptions\MethodNotAllowedException;
use SwallowPHP\Framework\Exceptions\MethodNotFoundException;
use SwallowPHP\Framework\Exceptions\RateLimitExceededException;
use SwallowPHP\Framework\Exceptions\RouteNotFoundException;
use SwallowPHP\Framework\Exceptions\ViewNotFoundException;
use Throwable;
use Psr\Log\LoggerInterface; // Import LoggerInterface
use Psr\Log\LogLevel; // Import LogLevel

class ExceptionHandler
{
    /**
     * Handle exceptions, log them, and generate an appropriate response.
     *
     * @param Throwable $exception The exception to handle.
     * @return void Outputs the response directly.
     */
    public static function handle(Throwable $exception): void
    {
        // Get Logger instance from container
        $logger = null;
        try {
            $logger = App::container()->get(LoggerInterface::class);
        } catch (\Throwable $e) {
            // Fallback if logger cannot be resolved
            @error_log("!!! CRITICAL: Could not resolve LoggerInterface in ExceptionHandler: " . $e->getMessage());
            @error_log("Original Exception: " . get_class($exception) . " - " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
        }

        // --- Log the exception using the PSR-3 Logger ---
        if ($logger) {
            // Determine log level based on exception type (optional refinement)
            $logLevel = self::getLogLevel($exception);

            // Prepare context for logger
            $context = [
                'exception' => $exception, // Pass the exception object itself
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                // 'trace' => $exception->getTraceAsString(), // Logger might handle trace better
            ];

            // Log the message
            $logger->log($logLevel, $exception->getMessage(), $context);

            // Log errors during logging process itself using the same logger? Risky.
            // Stick to error_log for meta-errors.
        } else {
             // Fallback to error_log if logger wasn't available
             @error_log("Exception caught (Logger Unavailable): " . get_class($exception) . " - " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
        }
        // --- End Logging ---

        // Determine status code and message based on exception type
        $statusCode = self::getStatusCode($exception);
        $message = $exception->getMessage(); // Start with original message

        // Use standard HTTP status text if message is empty or generic, or if not in debug mode
        $debug = false;
        try {
            $debug = filter_var(config('app.debug', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        } catch (\Throwable $configError) {
             if ($logger) $logger->warning("Error accessing config('app.debug') in ExceptionHandler", ['error' => $configError]);
             else @error_log("Error accessing config('app.debug') in ExceptionHandler: " . $configError->getMessage());
        }

        // Prepare response body details
        $responseBody = [];
        if ($debug) {
            $responseBody['message'] = $message ?: (self::STATUS_TEXTS[$statusCode] ?? 'Error'); // Use original msg if available
            $responseBody['exception'] = get_class($exception);
            $responseBody['file'] = $exception->getFile();
            $responseBody['line'] = $exception->getLine();
            try {
                 $responseBody['trace'] = explode("\n", $exception->getTraceAsString());
            } catch (\Throwable $traceError) {
                 if ($logger) $logger->warning("Error getting trace string", ['error' => $traceError]);
                 $responseBody['trace'] = ['Error retrieving stack trace.'];
            }
        } else {
            // Use standard status text for client/server errors in production
             $responseBody['message'] = self::STATUS_TEXTS[$statusCode] ?? ($statusCode >= 500 ? 'Internal Server Error' : 'Error');
        }


        // Determine response format (JSON or HTML)
        $wantsJson = false;
         try {
             $acceptHeader = request()->header('Accept', '');
             $wantsJson = str_contains($acceptHeader, '/json') || str_contains($acceptHeader, '+json');
         } catch (\Throwable $requestError) {
              if ($logger) $logger->warning("Error accessing request() helper in ExceptionHandler", ['error' => $requestError]);
              else @error_log("Error accessing request() helper in ExceptionHandler: " . $requestError->getMessage());
         }

        // Ensure output buffer is clean
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        // Send Response
        if (!headers_sent()) {
             http_response_code($statusCode);
        }

        if ($wantsJson) {
             if (!headers_sent()) header('Content-Type: application/json');
             try {
                  echo json_encode($responseBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
             } catch (\JsonException $jsonError) {
                  if ($logger) $logger->critical("JSON encoding failed in ExceptionHandler", ['error' => $jsonError]);
                  else @error_log("JSON encoding failed in ExceptionHandler: " . $jsonError->getMessage());
                  if (!headers_sent()) http_response_code(500);
                  echo '{"error": "Internal server error during error reporting."}';
             }
        } else { // HTML Response
             if (!headers_sent()) header('Content-Type: text/html; charset=UTF-8');
            $statusText = htmlspecialchars(self::STATUS_TEXTS[$statusCode] ?? 'Error', ENT_QUOTES, 'UTF-8');
            echo "<!DOCTYPE html><html><head><title>Error {$statusCode} - {$statusText}</title>";
            echo "<style>body{font-family:sans-serif;padding:20px;background-color:#f8f8f8;color:#333}h1{color:#d9534f;border-bottom:1px solid #eee;padding-bottom:10px}p{font-size:1.1em}pre{background-color:#eee;border:1px solid #ccc;padding:10px;overflow-x:auto;font-size:0.9em;line-height:1.4em;white-space:pre-wrap;word-wrap:break-word}</style>";
            echo "</head><body>";
            echo "<h1>Error {$statusCode} - {$statusText}</h1>";
            echo "<p>" . htmlspecialchars($responseBody['message'] ?? 'An unexpected error occurred.', ENT_QUOTES, 'UTF-8') . "</p>";

            if ($debug) {
                echo "<hr><h2>Details</h2>";
                echo "<p><strong>Exception:</strong> " . htmlspecialchars($responseBody['exception'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</p>";
                echo "<p><strong>File:</strong> " . htmlspecialchars($responseBody['file'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</p>";
                echo "<p><strong>Line:</strong> " . htmlspecialchars($responseBody['line'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</p>";
                $traceOutput = 'No trace available.';
                if (isset($responseBody['trace']) && is_array($responseBody['trace'])) {
                    try {
                        $traceString = implode("\n", $responseBody['trace']);
                        $traceOutput = htmlspecialchars($traceString, ENT_QUOTES, 'UTF-8');
                    } catch (\Throwable $e) {
                        if ($logger) $logger->warning("Error processing trace for HTML output", ['error' => $e]);
                        $traceOutput = 'Error displaying trace.';
                    }
                }
                echo "<h3>Trace:</h3><pre>" . $traceOutput . "</pre>";
            }
            echo "</body></html>";
        }
        exit;
    }

    /**
     * Determine the log level for the given exception.
     * @param Throwable $exception
     * @return string Log level from Psr\Log\LogLevel constants.
     */
    protected static function getLogLevel(Throwable $exception): string
    {
        if ($exception instanceof RouteNotFoundException ||
            $exception instanceof MethodNotAllowedException ||
            $exception instanceof CsrfTokenMismatchException) {
            return LogLevel::WARNING; // User/Request errors often logged as warning
        }
        if ($exception instanceof AuthorizationException) {
             return LogLevel::WARNING;
        }
        if ($exception instanceof RateLimitExceededException) {
             return LogLevel::INFO; // Rate limiting might be info level
        }
        // Add more specific exception checks if needed

        // Default to ERROR for most other exceptions/errors
        return LogLevel::ERROR;
    }

    /**
     * Determine the HTTP status code for the given exception.
     * @param Throwable $exception
     * @return int HTTP status code.
     */
    protected static function getStatusCode(Throwable $exception): int
    {
        if ($exception instanceof ViewNotFoundException || $exception instanceof RouteNotFoundException) {
            return 404;
        }
        if ($exception instanceof MethodNotAllowedException) {
            return 405;
        }
        if ($exception instanceof RateLimitExceededException) {
            return 429;
        }
        if ($exception instanceof AuthorizationException) {
            return 403;
        }
        if ($exception instanceof CsrfTokenMismatchException) {
            return 419;
        }
        // Other specific exceptions can be added here

        // Default to 500 for generic Exceptions and Errors
        return 500;
    }


    /** Standard HTTP status codes and texts. */
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
        416 => 'Range Not Satisfiable', 417 => 'Expectation Failed', 419 => 'Page Expired', // Common for CSRF
        421 => 'Misdirected Request', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency',
        425 => 'Too Early', 426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage', 508 => 'Loop Detected', 510 => 'Not Extended', 511 => 'Network Authentication Required',
    ];
}