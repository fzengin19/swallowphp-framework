<?php

namespace SwallowPHP\Framework\Foundation;


use League\Container\Container;
use League\Container\ReflectionContainer; // Import ReflectionContainer
use SwallowPHP\Framework\Contracts\CacheInterface;
use SwallowPHP\Framework\Cache\CacheManager;
use SwallowPHP\Framework\Database\Database;
use SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Routing\Router;

use SwallowPHP\Framework\Foundation\Config; // Config is back in Foundation

// Set timezone from config
// Note: config() might not be available *yet* when this file is first parsed.
// Consider moving this logic inside run() or after container initialization.
// date_default_timezone_set(config('app.timezone', 'Europe/Istanbul'));
// setlocale(LC_TIME, config('app.locale', 'tr') . '.UTF-8'); // Ensure locale includes encoding

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

        // Set timezone and locale after container and config are ready
        date_default_timezone_set(config('app.timezone', 'Europe/Istanbul'));
        setlocale(LC_TIME, config('app.locale', 'tr') . '.UTF-8');

        // Use config for view path, assuming it's relative to project root
        self::$viewDirectory = config('app.view_path', dirname(__DIR__, 2) . '/resources/views');
        // Get Router from container now
        self::$router = self::container()->get(Router::class); 

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
            // Enable auto-wiring via ReflectionContainer delegate
            // The 'true' argument allows attempting to resolve even unresolvable parameters (e.g., using default values)
            self::$container->delegate(new ReflectionContainer(true)); 


            // --- Configuration Service ---
            // Load configuration early and share the instance
            self::$container->addShared(Config::class, function () {
                 // Assumes config files are in project_root/config
                 $configPath = dirname(__DIR__, 2) . '/src/Config'; // Correct path to src/Config
                 // TODO: Allow overriding with application's config path?
                 // $appConfigPath = dirname(__DIR__, 3) . '/config'; // Example app path
                 // $config = new Config($appConfigPath); // Load app config first
                 // $config->loadFromDirectory($frameworkConfigPath); // Then load framework config (or merge)
                 return new Config($configPath);
            });

            // --- Service Definitions ---

            // Cache Service (Shared Singleton)
            // Resolves the appropriate cache driver via CacheManager
            self::$container->addShared(CacheInterface::class, function () {
                try {
                    // Pass container to CacheManager if it needs config service later
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
            self::$container->addShared(Router::class, function () {
                // Keep simple instantiation for now, can be enhanced later
                return new Router(); 
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
        // self::getInstance(); // This might cause issues if called too early
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
     *
     * @return Router The router instance.
     */
    public static function getRouter(): Router
    {
        // Get router from container
        return self::container()->get(Router::class);
    }

    /**
     * Sets the router object to be used by the class.
     * Note: This is generally discouraged; router should be managed by the container.
     * Kept for potential backward compatibility or specific use cases.
     *
     * @param Router $router The router object to be used.
     * @return void
     */
    // public static function setRouter(Router $router): void
    // {
    //     self::$router = $router;
    //     // Optionally update container if router is managed there
    //     // self::container()->extend(Router::class)->setConcrete($router);
    // }

    /**
     * Handles a request by dispatching it to the router.
     *
     * @param Request $request The request object to handle.
     * @return mixed The response from the router dispatch.
     */
    public static function handleRequest(Request $request): mixed
    {
        // Use the router instance obtained from the container
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
        // Load environment variables FIRST!
        // This ensures $_ENV is populated before container/config is initialized.
        Env::load(); 

        try {
            // Ensure the App instance and container are created
            $app = self::getInstance(); // This also initializes the container via __construct -> container()
            $container = self::container(); // Get initialized container

            // Basic environment setup using config (now available)
            set_time_limit((int)config('app.max_execution_time', 30));
            if (config('app.ssl_redirect', false) === true && empty($_SERVER['HTTPS'])) {
                header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
                exit;
            }
            // Use debug config for error reporting
            if (config('app.debug', false) !== true) {
                error_reporting(0);
            } else {
                 // Optionally set higher error reporting for debug mode
                 error_reporting(E_ALL);
                 ini_set('display_errors', 1); // Ensure errors are displayed if debug is true
            }

            // Get Request instance from container
            $request = $container->get(Request::class); 

            // Setup encoding and session
            mb_internal_encoding('UTF-8');
            if (session_status() == PHP_SESSION_NONE) {
                // Ensure headers aren't already sent before starting session
                if (!headers_sent()) {
                    // TODO: Use session config from config/session.php if available
                    session_start(); 
                } else {
                    // Log error if session cannot be started
                    error_log("App::run() - Session could not be started: Headers already sent.");
                }
            }

            // Output buffering and Gzip
            if (config('app.gzip_compression', true) === true && isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
                ini_set('zlib.output_compression', '1');
                header('Content-Encoding: gzip');
            } else {
                ini_set('zlib.output_compression', '0');
            }
            ob_start(); // Always start output buffering

            // Apply global middleware (e.g., CSRF protection)
            $csrfMiddleware = $container->get(VerifyCsrfToken::class);

            $response = $csrfMiddleware->handle($request, function ($request) use ($app) {
                // Pass request through router
                // Ensure handleRequest always returns a Response object or convertible value
                $routeResponse = $app->handleRequest($request); // Calls Router::dispatch

                // Handle non-Response return types from controllers/closures
                if (!$routeResponse instanceof \SwallowPHP\Framework\Http\Response) {
                     if (is_array($routeResponse) || is_object($routeResponse)) {
                         return \SwallowPHP\Framework\Http\Response::json($routeResponse);
                     } elseif (is_scalar($routeResponse) || is_null($routeResponse) || (is_object($routeResponse) && method_exists($routeResponse, '__toString'))) {
                         return \SwallowPHP\Framework\Http\Response::html((string) $routeResponse);
                     } else {
                          // Log error for unhandled return type
                          error_log("Route action returned an unconvertible type: " . gettype($routeResponse));
                          return \SwallowPHP\Framework\Http\Response::html('Internal Server Error: Invalid response type from route.', 500);
                     }
                }
                return $routeResponse; // Return original Response object
            });

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
