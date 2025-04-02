<?php

namespace SwallowPHP\Framework\Foundation;


use League\Container\Container;
use SwallowPHP\Framework\Contracts\CacheInterface;
use SwallowPHP\Framework\Cache\CacheManager;
use SwallowPHP\Framework\Database\Database;
use SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Routing\Router;

use SwallowPHP\Framework\Foundation\Config; // Config is back in Foundation

// Set timezone from config
date_default_timezone_set(config('app.timezone', 'Europe/Istanbul'));
setlocale(LC_TIME, config('app.locale', 'tr') . '.UTF-8'); // Ensure locale includes encoding

class App
{
    private static $instance;
    private static Router $router;
    private static ?string $viewDirectory;
    private static ?Container $container = null;

    /**
     * Initializes a new instance of the class and creates a new Router object.
     * Private constructor to enforce singleton pattern via getInstance().
     */
    private function __construct()
    {
        // Initialize container first
        self::container();

        // TODO: View directory path should be more robust (e.g., relative to project root)
        // Use config for view path, assuming it's relative to project root
        self::$viewDirectory = config('app.view_path', dirname(__DIR__, 2) . '/resources/views');
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


            // --- Configuration Service ---
            // Load configuration early and share the instance
            self::$container->addShared(Config::class, function () {
                 // Assumes config files are in project_root/config
                 $configPath = dirname(__DIR__, 2) . '/src/Config'; // Correct path to src/Config
                 return new Config($configPath);
            });

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
            // Create the request instance once from globals and share it.
            self::$container->addShared(Request::class, function () {
                return Request::createFromGlobals();
            });

            // Database Service (Shared Singleton)
            // Assumes Database constructor handles connection
            self::$container->addShared(Database::class, function() {
                 // Get config service from container
                 $config = self::container()->get(Config::class);
                 // Get default connection name
                 $connectionName = $config->get('database.default', 'mysql');
                 // Get connection specific config
                 $connectionConfig = $config->get("database.connections.{$connectionName}");

                 if (!$connectionConfig) {
                      throw new \RuntimeException("Database configuration for connection '{$connectionName}' not found.");
                 }
                 // Pass the specific connection config to the Database constructor
                 return new Database($connectionConfig);
             });

            // Router Service (Shared Singleton)
            // TODO: Update Database constructor to accept config array
            // $config = self::container()->get(Config::class);
            // $connectionName = $config->get('database.default');
            // $connectionConfig = $config->get("database.connections.{$connectionName}");
            // return new Database($connectionConfig);

            // Using the instance created in App's constructor for now
            self::$container->addShared(Router::class, function () {
                // Ensure router is initialized if getInstance wasn't called first
                // This might need adjustment depending on how App lifecycle is managed
                if (!isset(self::$router)) {
 // Keep simple instantiation for now
                    self::$router = new Router();
                }
                return self::$router;
            });

            // CSRF Token Middleware (Shared Singleton)
            self::$container->addShared(VerifyCsrfToken::class, function () {
                return new VerifyCsrfToken(); // Assuming no constructor dependencies
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
            set_time_limit((int)config('app.max_execution_time', 30));
            if (config('app.ssl_redirect', false) === true && empty($_SERVER['HTTPS'])) {
                header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
                exit;
            }
            // Use debug config for error reporting
            if (config('app.debug', false) !== true) {
                error_reporting(0);
            }

            // Create Request instance
            // TODO: Consider making Request creation managed by the container
            $request = $container->get(Request::class); // Get shared request instance
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
            if (config('app.gzip_compression', true) === true && isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
                ini_set('zlib.output_compression', '1');
                // ob_start('ob_gzhandler'); // ob_gzhandler can conflict with zlib.output_compression
                header('Content-Encoding: gzip');
            } else {
                ini_set('zlib.output_compression', '0');
            }
            ob_start(); // Always start output buffering

            // Apply global middleware (e.g., CSRF protection)
            // TODO: Manage middleware pipeline via container or dedicated class
            $csrfMiddleware = $container->get(VerifyCsrfToken::class);
            // $csrfMiddleware = $container->get(VerifyCsrfToken::class); // Future goal

            $response = $csrfMiddleware->handle($request, function ($request) use ($app) {
                // Pass request through router
                return $app->handleRequest($request); // Use instance method? Or keep static?
                // Ensure handleRequest always returns a Response object
                $routeResponse = $app->handleRequest($request);
                if (!$routeResponse instanceof \SwallowPHP\Framework\Http\Response) {
                     // Attempt to create a response based on the returned content
                     if (is_array($routeResponse) || is_object($routeResponse)) {
                         return \SwallowPHP\Framework\Http\Response::json($routeResponse);
                     } else {
                         return \SwallowPHP\Framework\Http\Response::html((string) $routeResponse);
                     }
                }
                return $routeResponse;
            });

            // Determine output format based on Accept header
            // $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? ''; // No longer needed here
            // $format = self::getPreferredFormat($acceptHeader); // No longer needed here

            // Send the response
            // Ensure $response is a Response object before sending
            if (!$response instanceof \SwallowPHP\Framework\Http\Response) {
                 // This case might happen if middleware directly returns non-response
                 // Log error and create a default error response
                 error_log("Middleware pipeline did not return a Response object. Got: " . gettype($response));
                 $response = \SwallowPHP\Framework\Http\Response::html('Internal Server Error', 500);
            }
            $response->send(); // Send the response object

            ob_end_flush(); // Send output buffer content

        } catch (\Throwable $th) {
            // Ensure output buffer is cleaned on error
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            ExceptionHandler::handle($th);
        }
    }

    // Removed getPreferredFormat() - Logic moved to Response/Content Negotiation if needed
    // Removed outputResponse() - Logic moved to Response::send()
}
