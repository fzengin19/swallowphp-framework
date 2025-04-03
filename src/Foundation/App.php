<?php

namespace SwallowPHP\Framework\Foundation;

use League\Container\Container;
use League\Container\ReflectionContainer;
use SwallowPHP\Framework\Contracts\CacheInterface;
use SwallowPHP\Framework\Cache\CacheManager;
use SwallowPHP\Framework\Database\Database;
use SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Routing\Router;
use Psr\Log\LoggerInterface; // Import PSR-3 Logger Interface
use Psr\Log\LogLevel; // Import LogLevel constants
use SwallowPHP\Framework\Session\SessionManager; // Import SessionManager
use SwallowPHP\Framework\Foundation\Config;

class App
{
    private static $instance;
    private static Router $router;
    private static ?string $viewDirectory;
    private static ?Container $container = null;

    private function __construct()
    {
        self::container(); // Initialize container first

        // Get config after container is available
        $config = self::container()->get(Config::class);

        // Set timezone and locale using config, with fallbacks
        date_default_timezone_set($config->get('app.timezone', 'UTC'));
        setlocale(LC_TIME, ($config->get('app.locale', 'en') ?? 'en') . '.UTF-8');

        // Set view directory using config, with fallback calculation
        self::$viewDirectory = $config->get('app.view_path');
        if (!self::$viewDirectory) {
             $potentialBasePath = defined('BASE_PATH') ? constant('BASE_PATH') : dirname(__DIR__, 3);
             self::$viewDirectory = $potentialBasePath . '/resources/views';
        }

        // Get Router from container
        self::$router = self::container()->get(Router::class);
    }

    public static function container(): Container
    {
        if (is_null(self::$container)) {
            self::$container = new Container();
            self::$container->delegate(new ReflectionContainer(true));

            // --- Configuration Service ---
            self::$container->addShared(Config::class, function () {
                 $frameworkConfigPath = dirname(__DIR__, 2) . '/src/Config';
                 $appConfigPath = null;
                 if (defined('BASE_PATH')) {
                      $appConfigPath = constant('BASE_PATH') . '/config';
                 } else {
                      $potentialBasePath = dirname(__DIR__, 3);
                      $appConfigPath = $potentialBasePath . '/config';
                 }
                 return new Config($frameworkConfigPath, $appConfigPath);
            });

            // --- Service Definitions ---

            // Logger Service (PSR-3) - Must be defined AFTER Config
            self::$container->addShared(LoggerInterface::class, function () {
                $config = self::container()->get(Config::class);
                $defaultChannel = $config->get('logging.default', 'file');
                $channelConfig = $config->get('logging.channels.' . $defaultChannel);
                if (!$channelConfig) { throw new \RuntimeException("Default log channel '{$defaultChannel}' configuration not found."); }
                $driver = $channelConfig['driver'] ?? 'single';
                $level = $channelConfig['level'] ?? LogLevel::DEBUG;
                if ($driver === 'single') {
                    $path = $channelConfig['path'] ?? null;
                    if (!$path) {
                         $storagePath = $config->get('app.storage_path');
                         if ($storagePath && is_dir(dirname($storagePath))) {
                              $path = $storagePath . '/logs/swallow.log';
                         } else {
                              $potentialBasePath = defined('BASE_PATH') ? constant('BASE_PATH') : dirname(__DIR__, 3);
                              $path = $potentialBasePath . '/storage/logs/swallow.log';
                              error_log("Warning: Log path not configured, using fallback: " . $path);
                              $logDir = dirname($path);
                              if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
                         }
                    }
                    if (empty($path)) { throw new \RuntimeException("Log path could not be determined for channel '{$defaultChannel}'."); }
                    try {
                         if (!class_exists(\SwallowPHP\Framework\Log\FileLogger::class)) { throw new \RuntimeException("FileLogger class not found."); }
                         return new \SwallowPHP\Framework\Log\FileLogger($path, $level);
                    } catch (\Exception $e) { throw new \RuntimeException("Failed to initialize FileLogger: " . $e->getMessage(), 0, $e); }
                } elseif ($driver === 'errorlog') {
                     return new class($level) implements LoggerInterface {
                         use \Psr\Log\LoggerTrait;
                         private int $minLevelValue;
                         private array $logLevels = [ LogLevel::DEBUG => 100, LogLevel::INFO => 200, LogLevel::NOTICE => 250, LogLevel::WARNING => 300, LogLevel::ERROR => 400, LogLevel::CRITICAL => 500, LogLevel::ALERT => 550, LogLevel::EMERGENCY => 600 ];
                         public function __construct(string $minLevel) { $this->minLevelValue = $this->logLevels[$minLevel] ?? 100; }
                         public function log($level, string|\Stringable $message, array $context = []): void {
                              if (($this->logLevels[$level] ?? 0) >= $this->minLevelValue) {
                                   $replace = [];
                                   foreach ($context as $key => $val) { $replace['{' . $key . '}'] = is_scalar($val) || (is_object($val) && method_exists($val,'__toString')) ? (string)$val : '['.gettype($val).']'; }
                                   $interpolatedMessage = strtr((string) $message, $replace);
                                   error_log(strtoupper($level) . ': ' . $interpolatedMessage);
                              }
                         }
                     };
                } else { throw new \RuntimeException("Unsupported log driver [{$driver}] configured for channel '{$defaultChannel}'."); }
            });

            // Session Manager Service - Defined AFTER Config and Logger
             self::$container->addShared(SessionManager::class, function () {
                 $manager = new SessionManager();
                 // Aging logic moved to App::run after session is started.
                 return $manager;
             });


            // Cache Service (Shared Singleton) - Defined AFTER Config and Logger
            self::$container->addShared(CacheInterface::class, function () {
                try {
                    $config = self::container()->get(Config::class);
                    $driverName = $config->get('cache.default', 'file');
                    return CacheManager::driver($driverName);
                } catch (\Exception $e) {
                    try { self::container()->get(LoggerInterface::class)->error("Cache service initialization failed: " . $e->getMessage()); }
                    catch (\Throwable $logException) { error_log("Cache service initialization failed AND logging failed: " . $e->getMessage()); }
                    throw new \RuntimeException("Failed to initialize cache service.", 0, $e);
                }
            });

            // Request Service (Shared Singleton)
            self::$container->addShared(Request::class, function () {
                return Request::createFromGlobals();
            });

            // Database Service (Shared Singleton) - Defined AFTER Config and Logger
            self::$container->addShared(Database::class, function() {
                 try {
                     $config = self::container()->get(Config::class);
                     $connectionName = $config->get('database.default', 'mysql');
                     $connectionConfig = $config->get("database.connections.{$connectionName}");
                     if (!$connectionConfig) { throw new \RuntimeException("Database configuration for connection '{$connectionName}' not found."); }
                     return new Database($connectionConfig);
                 } catch (\Exception $e) {
                     try { self::container()->get(LoggerInterface::class)->error("Database service initialization failed: " . $e->getMessage()); }
                     catch (\Throwable $logException) { error_log("Database service initialization failed AND logging failed: " . $e->getMessage()); }
                     throw new \RuntimeException("Failed to initialize database service.", 0, $e);
                 }
            });

            // Router Service (Shared Singleton)
            self::$container->addShared(Router::class, function () {
                return new Router();
            });

            // CSRF Token Middleware (Shared Singleton)
            self::$container->addShared(VerifyCsrfToken::class, function () {
                return new VerifyCsrfToken();
            });

        }
        return self::$container;
    }

    public static function getViewDirectory(): ?string
    {
        return self::$viewDirectory;
    }

    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function getRouter(): Router
    {
        return self::container()->get(Router::class);
    }

    public static function handleRequest(Request $request): mixed
    {
        return self::getRouter()->dispatch($request);
    }

    public static function run(): void
    {
        // --- Error and Exception Handling Setup ---
        error_reporting(E_ALL);
        ini_set('display_errors', 0);

        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) { return false; }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        $earlyExceptionHandler = set_exception_handler(function ($exception) {
             http_response_code(500);
             echo "<h1>Fatal Error</h1><p>An error occurred during application initialization.</p>";
             error_log("Early Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
             exit;
        });

        // --- Core Application Logic ---
        try {
            Env::load();

            $app = self::getInstance();
            $container = self::container();

            if ($earlyExceptionHandler) {
                 restore_exception_handler();
            }

            // --- Configure PHP based on loaded config ---
            $config = $container->get(Config::class);
            $logger = $container->get(LoggerInterface::class);

            date_default_timezone_set($config->get('app.timezone', 'UTC'));
            setlocale(LC_TIME, ($config->get('app.locale', 'en') ?? 'en') . '.UTF-8');
            set_time_limit((int)$config->get('app.max_execution_time', 30));

            $isDebug = $config->get('app.debug', false);
            if (!$isDebug) {
                error_reporting(0);
                ini_set('display_errors', 0);
            } else {
                 error_reporting(E_ALL);
                 ini_set('display_errors', 1);
            }

            // SSL Redirect
            if ($config->get('app.ssl_redirect', false) === true && empty($_SERVER['HTTPS'])) {
                header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
                exit;
            }

            // --- Request Handling Pipeline ---
            $request = $container->get(Request::class);
            $sessionManager = $container->get(SessionManager::class); // Get SessionManager

            // Start session and Age flash data <<<--- YENİ KISIM
            $sessionStarted = $sessionManager->start();
            if ($sessionStarted) {
                $sessionManager->ageFlashData();
            } else {
                // Logger artık tanımlı olduğu için burada loglayabiliriz
                $logger->warning('Session could not be started in App::run(): Headers may already be sent.');
            }
            // <<<--- BİTTİ ---

            // Output buffering and Gzip
            if ($config->get('app.gzip_compression', true) === true && isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
                ini_set('zlib.output_compression', '1');
                 if (!headers_sent()) header('Content-Encoding: gzip');
            } else {
                ini_set('zlib.output_compression', '0');
            }
            ob_start();

            // Apply global middleware
            $csrfMiddleware = $container->get(VerifyCsrfToken::class);

            $response = $csrfMiddleware->handle($request, function ($request) use ($app, $logger) { // Pass logger
                $routeResponse = $app->handleRequest($request);

                if (!$routeResponse instanceof \SwallowPHP\Framework\Http\Response) {
                     if (is_array($routeResponse) || is_object($routeResponse)) {
                         return \SwallowPHP\Framework\Http\Response::json($routeResponse);
                     } elseif (is_scalar($routeResponse) || is_null($routeResponse) || (is_object($routeResponse) && method_exists($routeResponse, '__toString'))) {
                         return \SwallowPHP\Framework\Http\Response::html((string) $routeResponse);
                     } else {
                          $logger->error("Route action returned an unconvertible type: " . gettype($routeResponse));
                          return \SwallowPHP\Framework\Http\Response::html('Internal Server Error: Invalid response type.', 500);
                     }
                }
                return $routeResponse;
            });

            // Send the final response
            if (!$response instanceof \SwallowPHP\Framework\Http\Response) {
                 $logger->error("Middleware pipeline did not return a Response object. Got: " . gettype($response));
                 $response = \SwallowPHP\Framework\Http\Response::html('Internal Server Error', 500);
            }
            $response->send();

            ob_end_flush();

        } catch (\Throwable $th) {
            // --- Main Exception Handling ---
            if (ob_get_level() > 0) { ob_end_clean(); }
            if (class_exists(ExceptionHandler::class)) {
                 ExceptionHandler::handle($th);
            } else {
                 http_response_code(500);
                 echo "<h1>Fatal Error</h1><p>Application Exception Handler is unavailable.</p>";
                 error_log("Critical: ExceptionHandler class not found. Original Exception: " . $th->getMessage());
                 exit;
            }
        } finally {
             restore_error_handler();
        }
    }
}
