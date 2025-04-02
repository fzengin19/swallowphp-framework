<?php

namespace SwallowPHP\Framework\Foundation; 

use SwallowPHP\Framework\Exceptions\AuthorizationException;
use SwallowPHP\Framework\Exceptions\EnvPropertyValueException;
use SwallowPHP\Framework\Exceptions\MethodNotAllowedException;
use SwallowPHP\Framework\Exceptions\MethodNotFoundException;
use SwallowPHP\Framework\Exceptions\RateLimitExceededException;
use SwallowPHP\Framework\Exceptions\RouteNotFoundException;
use SwallowPHP\Framework\Exceptions\ViewNotFoundException;
use Throwable;


class ExceptionHandler
{
    /**
     * Handle exceptions and generate an appropriate response.
     * Also logs the exception to a configured file.
     *
     * @param Throwable $exception The exception to handle.
     * @return void Outputs the response directly.
     */
    public static function handle(Throwable $exception): void
    {
        // --- Log the exception details to configured file ---
        try {
            $logPath = config('app.log_path'); // Get log path from config
            if ($logPath) {
                $logDir = dirname($logPath);
                // Ensure log directory exists and is writable
                if (!is_dir($logDir)) {
                    // Attempt to create directory recursively
                    if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) { // Check is_dir again after mkdir
                         throw new \RuntimeException("Log directory does not exist and could not be created: {$logDir}");
                    }
                }
                 if (!is_writable($logDir)) {
                      throw new \RuntimeException("Log directory is not writable: {$logDir}");
                 }

                $logMessage = sprintf(
                    "[%s] %s: %s in %s:%d\nStack trace:\n%s\n---\n", // Added separator
                    date('Y-m-d H:i:s'),
                    get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine(),
                    $exception->getTraceAsString()
                );
                // Use error_log with type 3 to append to the specified file
                if (!error_log($logMessage, 3, $logPath)) {
                     // Fallback to default log if writing to custom file fails
                     error_log("!!! FAILED TO WRITE TO CUSTOM LOG FILE: {$logPath} !!!\n" . $logMessage);
                }
            } else {
                 // Log to default PHP log if log_path is not configured
                 error_log("Exception caught by Handler (log_path not configured): " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
            }
        } catch (\Throwable $logError) {
            // Log logging error itself to PHP's default error log if possible
            // Avoid infinite loops if error_log itself fails
            @error_log("!!! FATAL: FAILED TO WRITE TO ANY LOG FILE: " . $logError->getMessage());
            @error_log("Original Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
        }
        // --- End Logging ---

        // Default status code
        $statusCode = 500;
        $message = 'Internal Server Error'; // Default message

        // Determine status code and message based on exception type
        if ($exception instanceof ViewNotFoundException) {
            $statusCode = 404;
            $message = 'View Not Found';
        } elseif ($exception instanceof RouteNotFoundException) {
            $statusCode = 404;
            $message = 'Route Not Found';
        } elseif ($exception instanceof RateLimitExceededException) {
            $statusCode = 429;
            $message = 'Too Many Requests';
        } elseif ($exception instanceof EnvPropertyValueException) {
            $statusCode = 500; // Config error is a server error
            $message = $exception->getMessage();
        } elseif ($exception instanceof AuthorizationException) {
            $statusCode = 401; // Or 403 Forbidden depending on context
            $message = 'Unauthorized';
        } elseif ($exception instanceof MethodNotFoundException) {
            $statusCode = 404; // Method on controller not found
            $message = $exception->getMessage();
        } elseif ($exception instanceof MethodNotAllowedException) {
            $statusCode = 405;
            $message = $exception->getMessage();
        } else {
            // Keep default 500 for other Throwables
            // Use actual message for generic errors too, unless debug is off AND status >= 500
            $message = $exception->getMessage(); 
        }

        // Determine if debug mode is enabled (use global helper)
        // Note: config() or request() might fail if the error happened early
        $debug = false; 
        try {
             $debug = config('app.debug', false) === true;
        } catch (\Throwable $configError) {
             // Logging already attempted above
        }

        $responseBody = ['message' => $message];
        if ($debug) {
            $responseBody['exception'] = get_class($exception);
            $responseBody['file'] = $exception->getFile();
            $responseBody['line'] = $exception->getLine();
            $responseBody['trace'] = explode("\n", $exception->getTraceAsString());
        } else {
            // Ensure generic message in production for server errors
            if ($statusCode >= 500) {
                 $responseBody['message'] = 'An error occurred while processing your request.';
            }
        }

        // Determine response format (simple check for JSON)
        $wantsJson = false;
         try {
             $acceptHeader = request()->header('Accept', '');
             $wantsJson = str_contains($acceptHeader, 'application/json');
         } catch (\Throwable $requestError) {
              // Logging already attempted above
         }

        // Ensure output buffer is clean before sending output
        while (ob_get_level() > 0) {
            @ob_end_clean(); // Clean buffer robustly
        }

        // Set status code if headers not already sent
        if (!headers_sent()) {
             http_response_code($statusCode);
        } else {
             // Logging already attempted above
             // error_log("ExceptionHandler::handle - Cannot set status code {$statusCode}, headers already sent.");
        }

        // Output response
        if ($wantsJson) {
             if (!headers_sent()) {
                 header('Content-Type: application/json');
             }
             echo json_encode($responseBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            // Output simple HTML response
             if (!headers_sent()) {
                 header('Content-Type: text/html; charset=UTF-8');
             }
            echo "<!DOCTYPE html><html><head><title>Error {$statusCode}</title>";
            echo "<style>body { font-family: sans-serif; padding: 20px; background-color: #f8f8f8; color: #333; }";
            echo "h1 { color: #d9534f; border-bottom: 1px solid #eee; padding-bottom: 10px; }";
            echo "p { font-size: 1.1em; }";
            echo "pre { background-color: #f0f0f0; padding: 15px; border: 1px solid #ccc; overflow-x: auto; font-size: 0.9em; line-height: 1.4em; white-space: pre-wrap; word-wrap: break-word; }";
            echo "</style>";
            echo "</head><body>";
            echo "<h1>Error {$statusCode}</h1>";
            echo "<p>" . htmlspecialchars($responseBody['message'] ?? 'An unexpected error occurred.', ENT_QUOTES, 'UTF-8') . "</p>";
            if ($debug) {
                echo "<hr><h2>Details</h2>";
                echo "<p><strong>Exception:</strong> " . htmlspecialchars($responseBody['exception'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</p>";
                echo "<p><strong>File:</strong> " . htmlspecialchars($responseBody['file'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</p>";
                echo "<p><strong>Line:</strong> " . htmlspecialchars($responseBody['line'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</p>";
                echo "<h3>Trace:</h3><pre>" . htmlspecialchars(implode("\n", $responseBody['trace'] ?? []), ENT_QUOTES, 'UTF-8') . "</pre>";
            }
            echo "</body></html>";
        }
        exit; // Stop execution after handling the error
    }
}