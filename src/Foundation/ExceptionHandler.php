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
     * @return \SwallowPHP\Framework\Http\Response The response object to be sent.
     */
    public static function handle(Throwable $exception): \SwallowPHP\Framework\Http\Response
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

        // Prepare data for view or JSON response
        $data = [
            'exception' => $exception,
            'statusCode' => $statusCode,
            'statusText' => self::STATUS_TEXTS[$statusCode] ?? 'Error',
            'message' => $responseBody['message'] ?? 'An unexpected error occurred.',
            'debug' => $debug,
        ];
        if ($debug) {
             $data['exceptionClass'] = $responseBody['exception'] ?? null;
             $data['file'] = $responseBody['file'] ?? null;
             $data['line'] = $responseBody['line'] ?? null;
             $data['trace'] = $responseBody['trace'] ?? [];
        }


        // Return Response object
        try {
            if ($wantsJson) {
                $responseData = ['message' => $data['message']];
                if ($debug) {
                    $responseData['exception'] = $data['exceptionClass'];
                    $responseData['file'] = $data['file'];
                    $responseData['line'] = $data['line'];
                    $responseData['trace'] = $data['trace'];
                }
                return \SwallowPHP\Framework\Http\Response::json($responseData, $statusCode);
            } else {
                // Attempt to render view
                try {
                    // Check if view() helper exists before calling
                    if (function_exists('view')) {
                         // Try specific status code view first
                         try {
                              // Pass status code to view() helper
                              return view("errors.{$statusCode}", $data, 'layouts.error', $statusCode);
                         } catch (ViewNotFoundException $e) {
                              // Fallback to default error view
                              // Pass status code to view() helper
                              return view("errors.default", $data, 'layouts.error', $statusCode);
                         }
                    } else {
                         throw new \RuntimeException('view() helper function not available.');
                    }
                } catch (ViewNotFoundException $e) {
                     if ($logger) $logger->warning("Error views not found (errors.{$statusCode} or errors.default). Falling back to basic HTML.", ['exception' => $e]);
                     // Fallback to basic HTML if no views found
                     return self::renderFallbackHtml($statusCode, $data['statusText'], $data['message'], $debug, $data);
                } 
                catch (\Throwable $e) {
                     if ($logger) $logger->critical("Error rendering error view.", ['exception' => $e]);
                     // Fallback to basic HTML if view rendering fails
                     return self::renderFallbackHtml($statusCode, $data['statusText'], 'An error occurred while rendering the error page.', $debug, $data);
                }
            }
        } catch (\Throwable $responseError) {
             // Catch potential errors during Response creation itself (e.g., JSON encoding)
             if ($logger) $logger->critical("Critical error creating response in ExceptionHandler.", ['exception' => $responseError]);
             // Last resort: plain text error
             http_response_code(500);
             header('Content-Type: text/plain');
             echo "Internal Server Error. Could not generate error response.";
             exit; // Exit here as we can't even create a response object
        }
    }

    /** Renders a basic HTML fallback error page */
    private static function renderFallbackHtml(int $statusCode, string $statusText, string $message, bool $debug, array $debugData = []): \SwallowPHP\Framework\Http\Response
    {
        $content = "<!DOCTYPE html><html><head><title>Error {$statusCode} - {$statusText}</title>";
        $content .= "<style>body{font-family:sans-serif;padding:20px;background-color:#f8f8f8;color:#333}h1{color:#d9534f;border-bottom:1px solid #eee;padding-bottom:10px}p{font-size:1.1em}pre{background-color:#eee;border:1px solid #ccc;padding:10px;overflow-x:auto;font-size:0.9em;line-height:1.4em;white-space:pre-wrap;word-wrap:break-word}</style>";
        $content .= "</head><body>";
        $content .= "<h1>Error {$statusCode} - {$statusText}</h1>";
        $content .= "<p>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>";

        if ($debug) {
            $content .= "<hr><h2>Details</h2>";
            $content .= "<p><strong>Exception:</strong> " . htmlspecialchars($debugData['exceptionClass'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</p>";
            $content .= "<p><strong>File:</strong> " . htmlspecialchars($debugData['file'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</p>";
            $content .= "<p><strong>Line:</strong> " . htmlspecialchars($debugData['line'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</p>";
            $traceOutput = 'No trace available.';
            if (isset($debugData['trace']) && is_array($debugData['trace'])) {
                try {
                    $traceString = implode("\n", $debugData['trace']);
                    $traceOutput = htmlspecialchars($traceString, ENT_QUOTES, 'UTF-8');
                } catch (\Throwable $e) { $traceOutput = 'Error displaying trace.'; }
            }
            $content .= "<h3>Trace:</h3><pre>" . $traceOutput . "</pre>";
        }
        $content .= "</body></html>";

        return \SwallowPHP\Framework\Http\Response::html($content, $statusCode);
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
             // Return the code set in the exception itself (defaults to 401, can be 403)
             return $exception->getCode() ?: 403; // Fallback to 403 if code is 0 for some reason
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