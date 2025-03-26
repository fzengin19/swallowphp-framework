<?php

namespace SwallowPHP\Framework;

use SwallowPHP\Framework\Database;
use SwallowPHP\Framework\Env;
use SwallowPHP\Framework\ExceptionHandler;
use SwallowPHP\Framework\Request;
use SwallowPHP\Framework\Router;
use SwallowPHP\Framework\Middleware\VerifyCsrfToken;
use League\Container\Container; // Import DI Container
use SwallowPHP\Framework\Contracts\CacheInterface; // Import Cache Interface
use SwallowPHP\Framework\Cache\CacheManager; // Import Cache Manager

date_default_timezone_set('Europe/Istanbul');
setlocale(LC_TIME, 'turkish');

class App
{
    private static $instance;
    private static Router $router;
    private static ?string $viewDirectory;
    private static ?Container $container = null; // Container property correctly placed

    /**
     * Initializes a new instance of the class and creates a new Router object.
     * Private constructor to enforce singleton pattern via getInstance().
     */
    private function __construct()
    {
        // Initialize container first
        self::container();

        // TODO: View directory path should be more robust (e.g., relative to project root)
        self::$viewDirectory = $_SERVER['DOCUMENT_ROOT'].env('VIEW_DIRECTORY', '/views/');
        self::$router = new Router(); // Router could also be managed by container later

        // Assign the App instance itself to the container? Optional.
        // self::$container->addShared(App::class, $this);
    }

    /**
     * Get the globally available container instance.
     * Initializes the container on first call.
     *
     * @return Container
     */
    public static function container(): Container
    {
        if (is_null(self::$container)) {
            self::$container = new Container();

            // --- Service Definitions ---

            // Cache Service (Shared Singleton)
            // Resolves the appropriate cache driver via CacheManager
            self::$container->addShared(CacheInterface::class, function () {
                try {
                    return CacheManager::driver();
                } catch (\Exception $e) {
                    // Log the error and potentially throw a more specific framework exception
                    error_log("Cache service initialization failed: " . $e->getMessage());
                    // Depending on requirements, could return a NullCache driver or re-throw
                    throw new \RuntimeException("Failed to initialize cache service.", 0, $e);
                }
            });

            // Request Service (Shared Singleton for the current request)
            // We create it once in run() and could potentially add it to the container
            // self::$container->addShared(Request::class, function() {
            //     return Request::createFromGlobals();
            // });
            // Or register the instance created in run() later.

            // Database Service (Shared Singleton)
            // Assumes Database constructor handles connection
             self::$container->addShared(Database::class, Database::class);

            // Router Service (Shared Singleton)
            // Using the instance created in App's constructor for now
             self::$container->addShared(Router::class, function() {
                 // Ensure router is initialized if getInstance wasn't called first
                 // This might need adjustment depending on how App lifecycle is managed
                 if (!isset(self::$router)) {
                      self::$router = new Router();
                 }
                 return self::$router;
             });

            // Add other core services...

        }
        return self::$container;
    }

    /**
     * Get the application's view directory path.
     *
     * @return string|null
     */
    public static function getViewDirectory(): ?string
    {
        // Ensure instance exists if called statically before run()
        // self::getInstance(); // Might cause issues if called too early
        return self::$viewDirectory;
    }

    /**
     * Returns the singleton instance of this class.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self(); // Calls private __construct which initializes container
        }
        return self::$instance;
    }

    /**
     * Returns the router instance.
     * Consider getting this from the container in the future.
     *
     * @return Router The router instance.
     */
    public static function getRouter(): Router
    {
         // Ensure instance/router exists
         // self::getInstance(); // Might cause issues
         if (!isset(self::$router)) {
              // Potentially get from container if registered there
              return self::container()->get(Router::class);
         }
        return self::$router;
    }

    /**
     * Sets the router object to be used by the class.
     * Note: Directly setting might bypass container management if router is registered there.
     *
     * @param Router $router The router object to be used.
     * @return void
     */
    public static function setRouter(Router $router): void
    {
        self::$router = $router;
        // Optionally update container if router is managed there
        // self::container()->extend(Router::class)->setConcrete($router);
    }

    /**
     * Handles a request by dispatching it to the router.
     *
     * @param Request $request The request object to handle.
     * @return mixed The response from the router dispatch.
     */
    public static function handleRequest(Request $request): mixed
    {
        // Use the router instance (potentially from container in future)
        return self::getRouter()->dispatch($request);
    }


    /**
     * Runs the application.
     * This is the main entry point.
     *
     * @throws \Throwable If an error occurs during execution.
     */
    public static function run(): void
    {
        try {
            // Ensure the App instance and container are created
            $app = self::getInstance();
            $container = self::container(); // Get initialized container

            // Load environment variables early
            Env::load();

            // Basic environment setup
            set_time_limit((int)env('MAX_EXECUTION_TIME', 20));
            if (env('SSL_REDIRECT') === 'TRUE' && empty($_SERVER['HTTPS'])) {
                header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
                exit;
            }
            if (env('ERROR_REPORTING') !== 'true') {
                error_reporting(0);
            }

            // Create Request instance
            // TODO: Consider making Request creation managed by the container
            $request = Request::createFromGlobals();
            // $container->share(Request::class, $request); // Add instance to container?

            // Setup encoding and session
            mb_internal_encoding('UTF-8');
            if (session_status() == PHP_SESSION_NONE) {
                // Ensure headers aren't already sent before starting session
                if (!headers_sent()) {
                    session_start();
                } else {
                    // Log error if session cannot be started
                    error_log("App::run() - Session could not be started: Headers already sent.");
                }
            }

            // Output buffering and Gzip
            if (Env::get('GZIP_COMPRESSION') === 'true' && isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
                ini_set('zlib.output_compression', '1');
                // ob_start('ob_gzhandler'); // ob_gzhandler can conflict with zlib.output_compression
                header('Content-Encoding: gzip');
            } else {
                ini_set('zlib.output_compression', '0');
            }
             ob_start(); // Always start output buffering

            // Apply global middleware (e.g., CSRF protection)
            // TODO: Manage middleware pipeline via container or dedicated class
            $csrfMiddleware = new VerifyCsrfToken(); // Direct instantiation for now
            // $csrfMiddleware = $container->get(VerifyCsrfToken::class); // Future goal

            $response = $csrfMiddleware->handle($request, function ($request) use ($app) {
                // Pass request through router
                return $app->handleRequest($request); // Use instance method? Or keep static?
            });

            // Determine output format based on Accept header
            $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
            $format = self::getPreferredFormat($acceptHeader);

            // Send the response
            self::outputResponse($response, $format);

            ob_end_flush(); // Send output buffer content

        } catch (\Throwable $th) {
            // Ensure output buffer is cleaned on error
            if (ob_get_level() > 0) {
                 ob_end_clean();
            }
            ExceptionHandler::handle($th);
        }
    }

    /**
     * Determine the preferred response format based on Accept header.
     *
     * @param string $acceptHeader
     * @return string 'json' or 'html'
     */
    private static function getPreferredFormat(string $acceptHeader): string
    {
        if (strpos($acceptHeader, 'application/json') !== false) {
            return 'json';
        }
        // Prioritize HTML slightly over wildcard or empty Accept header
        elseif (strpos($acceptHeader, 'text/html') !== false || strpos($acceptHeader, '*/*') !== false || empty($acceptHeader)) {
            return 'html';
        } else {
            // Default to HTML for other specific types for now
            return 'html';
        }
    }

    /**
     * Output the response in the determined format.
     *
     * @param mixed $response The content to output.
     * @param string $format The format ('json' or 'html').
     */
    private static function outputResponse(mixed $response, string $format): void
    {
        if (!headers_sent()) { // Check if headers can still be sent
            if ($format === 'json') {
                header('Content-Type: application/json');
                $json = json_encode($response);
                if ($json === false) {
                    http_response_code(500);
                    error_log('JSON encoding error: ' . json_last_error_msg());
                    echo '{"error": "Internal Server Error"}';
                } else {
                    echo $json;
                }
            } elseif ($format === 'html') {
                header('Content-Type: text/html; charset=UTF-8');
                if (is_scalar($response) || is_null($response)) {
                    echo $response;
                } elseif (is_object($response) && method_exists($response, '__toString')) {
                     echo (string) $response; // Allow objects with __toString
                } else {
                    http_response_code(500);
                    error_log('Invalid response type for HTML format. Expected scalar, null, or object with __toString, got ' . gettype($response));
                    echo 'Internal Server Error: Invalid response type.';
                }
            } else {
                 // Fallback for unknown format? Or throw error?
                 header('Content-Type: text/plain');
                 echo 'Error: Unsupported response format requested.';
            }
        } else {
             // Headers already sent, likely an error occurred or direct output was used
             error_log("App::outputResponse - Cannot send headers, already sent. Outputting raw response.");
             // Attempt to output something anyway, might be mixed with previous output
             if ($format === 'json') {
                 $json = json_encode($response);
                 echo $json ?: '{"error": "Internal Server Error during output"}';
             } elseif (is_scalar($response) || is_null($response)) {
                 echo $response;
             } elseif (is_object($response) && method_exists($response, '__toString')) {
                  echo (string) $response;
             } else {
                  echo 'Internal Server Error: Invalid response type during output.';
             }
        }
    }
}
