<?php

namespace SwallowPHP\Framework;


use SwallowPHP\Framework\Database;
use SwallowPHP\Framework\Env;
use SwallowPHP\Framework\ExceptionHandler;
use SwallowPHP\Framework\Request;
use SwallowPHP\Framework\Router;

date_default_timezone_set('Europe/Istanbul');
setlocale(LC_TIME, 'turkish');

class App
{
    private static $instance;
    private static Router $router;
    private static ?string $viewDirectory;
    
    /**
     * Initializes a new instance of the class and creates a new Router object.
     */
    private function __construct()
    {
        self::$viewDirectory = $_SERVER['DOCUMENT_ROOT'].env('VIEW_DIRECTORY', '/views/');
        self::$router = new Router();
    }
    public static function getViewDirectory(){
        return self::$viewDirectory;
    }
    /**
     * Returns a single instance of this class.
     *
     * @return self
     */
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Returns the router instance.
     *
     * @return Router The router instance.
     */
    public static function getRouter()
    {
        return self::$router;
    }

    /**
     * Sets the router object to be used by the class.
     *
     * @param Router $router The router object to be used.
     * @return void
     */
    public static function setRouter(Router $router)
    {
        self::$router = $router;
    }

    /**
     * Handles a request by dispatching it to the router.
     *
     * @param Request $request The request object to handle.
     *
     * @return void
     */
    public static function handleRequest(Request $request)
    {
        return self::$router::dispatch($request);
    }


    /**
     * Runs the PHP function.
     *
     * @throws \Throwable If an error occurs during execution.
     * @return void
     */
    public static function run()
    {
        try {
            Env::load();
            set_time_limit(env('MAX_EXECUTION_TIME',20));
            if (env('SSL_REDIRECT') == 'TRUE' && empty($_SERVER['HTTPS'])) {
                header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
                exit;
            }
            if (env('ERROR_REPORTING') != 'true') {
                error_reporting(0);
            }

            
            $request = Request::createFromGlobals();
            mb_internal_encoding('UTF-8');
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }

            if (Env::get('GZIP_COMPRESSION') === 'true' && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
                ini_set('zlib.output_compression', 1);
                ob_start('ob_gzhandler');
                header('Content-Encoding: gzip');
            } else {
                ini_set('zlib.output_compression', 0);
                ob_start();
            }

            // Handle the request and determine the output format
            $response = self::handleRequest($request);
            $acceptHeader = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
            $format = self::getPreferredFormat($acceptHeader);

            // Output response based on selected format
            return self::outputResponse($response, $format);
        } catch (\Throwable $th) {
            ExceptionHandler::handle($th);
        }
    }
    private static function getPreferredFormat($acceptHeader)
    {
        // Determine preferred format based on Accept header
        if (strpos($acceptHeader, 'application/json') !== false) {
            return 'json';
        } elseif (strpos($acceptHeader, 'text/html') !== false) {
            return 'html';
        } else {
            // Default to HTML if no preference or unrecognized
            return 'html';
        }
    }

    private static function outputResponse($response, $format)
    {
        // Output response based on selected format
        if ($format === 'json') {
            header('Content-Type: application/json');
            print_r(json_encode($response));
        } elseif ($format === 'html') {
            header('Content-Type: text/html');
            print_r($response); // Assuming $response is already HTML
        } else {
            // Handle other formats if needed
            // Default to HTML
            header('Content-Type: text/html');
            print_r($response);
        }
    }
}
