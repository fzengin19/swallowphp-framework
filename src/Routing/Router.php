<?php

namespace SwallowPHP\Framework\Routing;

use SwallowPHP\Framework\Exceptions\RateLimitExceededException;

use SwallowPHP\Framework\Exceptions\MethodNotAllowedException;
use SwallowPHP\Framework\Exceptions\RouteNotFoundException;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Http\Middleware\RateLimiter;
use SwallowPHP\Framework\Foundation\Env;
use SwallowPHP\Framework\Foundation\App; // For config access

class Router
{
    protected static Request $request;

    /** @var Route[] Route collection for storing registered routes. */
    protected static $routes = [];

    /** @var Route|null The currently matched route after dispatch. */
    protected static ?Route $matchedRoute = null;

    /** Creates a GET route. */
    public static function get($uri, $action): Route
    {
        $route = new Route('GET', $uri, $action);
        self::$routes[] = $route; // Use [] for appending
        return $route;
    }

    /** Creates a POST route. */
    public static function post($uri, $action): Route
    {
        $route = new Route('POST', $uri, $action);
        self::$routes[] = $route;
        return $route;
    }

    /** Returns all registered routes. */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    /** Checks if a route with the given name exists. */
    public static function hasRoute($name): bool
    {
        foreach (self::getRoutes() as $route) {
            if ($route->getName() === $name) {
                return true;
            }
        }
        return false;
    }

    /** Creates a DELETE route. */
    public static function delete($uri, $action): Route
    {
        $route = new Route('DELETE', $uri, $action);
        self::$routes[] = $route;
        return $route;
    }

    /** Gets the current request object (set during dispatch). */
    public static function getRequest(): Request
    {
        // Consider making this non-static or ensuring $request is always set before access
        if (!isset(self::$request)) {
            // This might happen if called outside the dispatch cycle.
            // Maybe get from container instead?
            return App::container()->get(Request::class);
        }
        return self::$request;
    }

    /** Creates a PUT route. */
    public static function put($uri, $action): Route
    {
        $route = new Route('PUT', $uri, $action);
        self::$routes[] = $route;
        return $route;
    }

    /** Creates a PATCH route. */
    public static function patch($uri, $action): Route
    {
        $route = new Route('PATCH', $uri, $action);
        self::$routes[] = $route;
        return $route;
    }

    /** Retrieves the route URL by name. */
    public static function getRouteByName($name, $params = []): string
    {
        foreach (self::getRoutes() as $route) {
            if ($route->getName() === $name) {
                $uriPattern = $route->getUri();

                // Replace path parameters
                foreach ($params as $param => $value) {
                    $placeholder = '{' . $param . '}';
                    if (str_contains($uriPattern, $placeholder)) {
                        $uriPattern = str_replace($placeholder, rawurlencode((string) $value), $uriPattern); // URL encode values
                        unset($params[$param]);
                    }
                    // Handle optional parameters? e.g., {param?} - more complex regex needed
                }

                // Append remaining params as query string
                $queryString = !empty($params) ? http_build_query($params) : '';

                // Get base URL and app path from config
                $baseUrl = rtrim(config('app.url', 'http://localhost'), '/');
                $appPath = config('app.path', '');
                $routePath = '/' . ltrim($uriPattern, '/');
                $url = $baseUrl . $appPath . $routePath;

                return $queryString ? $url . '?' . $queryString : $url;
            }
        }
        throw new RouteNotFoundException("Route [{$name}] not defined.", 404);
    }

    /** Gets the currently matched route object, if any. */
    public static function getCurrentRoute(): ?Route
    {
        return self::$matchedRoute;
    }

    /** Dispatches the request to the appropriate route. */
    public static function dispatch(Request $request): mixed
    {
        self::$request = $request; // Store current request statically
        $requestUriPath = $request->getPath(); // Use getPath() from Request object

        // Remove potential base path defined in config
        $appPath = config('app.path', '');
        if (!empty($appPath) && str_starts_with($requestUriPath, $appPath)) {
            $requestUriPath = substr($requestUriPath, strlen($appPath));
            // Ensure it starts with / after removing base path
            if (!str_starts_with($requestUriPath, '/')) {
                $requestUriPath = '/' . $requestUriPath;
            }
        }

        // Ensure path starts with / and remove trailing slash (unless it's just '/')
        $processedUri = '/' . ltrim($requestUriPath, '/');
        if ($processedUri !== '/') {
            $processedUri = rtrim($processedUri, '/');
        }

        $supportedMethods = []; // Track methods for matched URI but wrong method

        foreach (self::$routes as $route) {
            // Prepare regex pattern from route URI
            // Escape regex characters, then replace {param} with named capture groups
            $pattern = preg_quote($route->getUri(), '/');
            $pattern = preg_replace('/\\\{([a-zA-Z0-9_]+)\\\}/', '(?P<$1>[^\/]+)', $pattern);
            $regex = '/^' . $pattern . '$/';

            if (preg_match($regex, $processedUri, $matches)) {
                // URI matches, now check method
                $requestMethod = $request->getMethod(); // Handles _method override
                if ($route->getMethod() === $requestMethod) {
                    // Method matches! Execute rate limiter and route action.
                    try {
                        RateLimiter::execute($route); // Apply rate limiting
                    } catch (RateLimitExceededException $e) {
                        // Let ExceptionHandler handle this specific exception
                        throw $e;
                    }

                    // Extract named parameters from matches
                    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                    // URL-decode all parameters
                    $params = array_map('urldecode', $params);
                    // Add route parameters to the request object (overwriting query/body params with same name)
                    $request->setAll(array_merge($request->all(), $params));

                    // Store the matched route before executing
                    self::$matchedRoute = $route;

                    // Execute the route's action (controller or closure)
                    // Route::execute handles middleware pipeline and action execution
                    return $route->execute($request);
                } else {
                    // URI matched, but method didn't. Store the supported method.
                    $supportedMethods[] = $route->getMethod();
                }
            }
        }

        // After checking all routes:
        if (!empty($supportedMethods)) {
            // URI was matched, but no route for the requested method
            $allowed = implode(', ', array_unique($supportedMethods));
            throw new MethodNotAllowedException("The {$request->getMethod()} method is not supported for route {$processedUri}. Supported methods: {$allowed}.", 405);
        } else {
            // No route matched the URI at all
            throw new RouteNotFoundException("Route not found for URI [{$processedUri}]", 404);
        }
    }
}
