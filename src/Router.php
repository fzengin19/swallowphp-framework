<?php

namespace SwallowPHP\Framework;

use SwallowPHP\Framework\Route;
use SwallowPHP\Framework\Exceptions\MethodNotAllowedException;
use SwallowPHP\Framework\Exceptions\RouteNotFoundException;

class Router
{

    /**
     * Route collection for storing registered routes.
     *
     * This collection holds all registered routes in the application.
     * It is used to store and retrieve route information for routing purposes.
     *
     * @var Route[]
     */
    protected static $routes = [];


    /**
     * Creates and returns a new Route object for a GET request with the given URI and action.
     * 
     * @param string $uri The URI pattern for the route.
     * @param string $action The action to be taken when the route is matched.
     * @return Route The newly created Route object.
     */
    public static function get($uri, $action)
    {
        $route = new Route("GET", $uri, $action);
        array_push(self::$routes, $route);
        return $route;
    }

    /**
     * Creates a new POST route with the given URI and action function, and adds it to the list of routes.
     *
     * @param string $uri The URI pattern for the route.
     * @param callable|string $action The action function to call when the route is matched.
     * @return Route The newly created route.
     */
    public static function post($uri, $action)
    {
        $route = new Route("POST", $uri, $action);
        array_push(self::$routes, $route);
        return $route;
    }






    /**
     * Returns the routes stored in the class variable $routes.
     *
     * @return array An array of routes.
     */
    public static function getRoutes()
    {
        return self::$routes;
    }
    /**
     * Checks if a route with the given name exists.
     *
     * @param string $name The name of the route to check.
     * @return bool Returns true if a route with the given name exists, false otherwise.
     */
    public static function hasRoute($name)
    {
        $request = new Request(); // Assuming you have a way to create the Request object.

        $requestUri = parse_url($request->getUri(), PHP_URL_PATH);
        $requestUri = str_replace(env('APP_PATH'), '', $requestUri);

        foreach (self::getRoutes() as $route) {
            $routeUri = preg_quote($route->getUri(), '/');

            $pattern = '/^' . str_replace(['\{', '\}'], ['(?P<', '>[^\/]+)'], $routeUri) . '$/';

            if (preg_match($pattern, $requestUri, $matches)) {
                if ($route->getMethod() === $request->getMethod()) {
                    return $route->getName() == $name;
                }
            }
        }

        return false;
    }
    /**
     * Creates and returns a new Route object for a DELETE request with the given URI and action.
     * 
     * @param string $uri The URI pattern for the route.
     * @param string $action The action to be taken when the route is matched.
     * @return Route The newly created Route object.
     */
    public static function delete($uri, $action)
    {
        $route = new Route("DELETE", $uri, $action);
        array_push(self::$routes, $route);
        return $route;
    }

    /**
     * Creates and returns a new Route object for a PUT request with the given URI and action.
     * 
     * @param string $uri The URI pattern for the route.
     * @param string $action The action to be taken when the route is matched.
     * @return Route The newly created Route object.
     */
    public static function put($uri, $action)
    {
        $route = new Route("PUT", $uri, $action);
        array_push(self::$routes, $route);
        return $route;
    }
    /**
     * Creates and returns a new Route object for a PATCH request with the given URI and action.
     * 
     * @param string $uri The URI pattern for the route.
     * @param string $action The action to be taken when the route is matched.
     * @return Route The newly created Route object.
     */
    public static function patch($uri, $action)
    {
        $route = new Route("PATCH", $uri, $action);
        array_push(self::$routes, $route);
        return $route;
    }
    /**
     * Retrieves the route URL by its name and replaces path parameters with actual values.
     *
     * @param string $name The name of the route.
     * @param array $params An array of path parameters and their corresponding values.
     * @throws RouteNotFoundException If the route with the given name is not found.
     * @return string The URL of the route with path parameters replaced.
     */
    public static function getRouteByName($name, $params = [])
    {
        foreach (self::getRoutes() as $route) {
            if ($route->getName() === $name) {
                $uriPattern = $route->getUri();

                // Replace path parameters with actual values
                foreach ($params as $param => $value) {
                    $uriPattern = str_replace('{' . $param . '}', $value, $uriPattern);
                }

                return env('APP_URL') . $uriPattern;
            }
        }
        throw new RouteNotFoundException($name . ' route not found', 404);
    }


    /**
     * Dispatches the given request to the appropriate route.
     *
     * @param Request $request the request object to be dispatched
     * @throws MethodNotAllowedException if the request method is not allowed for the given route
     * @throws RouteNotFoundException if no matching route is found for the request
     * @return mixed the result of executing the matched route
     */
    public static function dispatch(Request $request)
    {
        $requestUri = parse_url($request->getUri(), PHP_URL_PATH);
        $requestUri = str_replace(env('APP_PATH'), '', $requestUri);
        if ($requestUri != '/') {
            $requestUri = rtrim($requestUri, '/');
        }
        $supportedMethods = [];
        foreach (self::$routes as $route) {
            $routeUri = preg_quote($route->getUri(), '/');

            $pattern = '/^' . str_replace(['\{', '\}'], ['(?P<', '>[^\/]+)'], $routeUri) . '$/';
     
            if (preg_match($pattern, $requestUri, $matches)) {
                if ($route->getMethod() === $request->getMethod() || $route->getMethod() === $request->get('_method')) {

                    RateLimiter::execute($route);
                    $params = array_filter($matches, '\is_string', ARRAY_FILTER_USE_KEY);
                    $request->setAll(array_merge($params, $request->all()));
                    return $route->execute($request);
                }
                $supportedMethods[] = $route->getMethod();
            }
        }
        if (count($supportedMethods) > 0) {
            throw new MethodNotAllowedException($request->getMethod() . ' Method Not Allowed for ' . $requestUri . ' Supported Methods: ' . implode(', ', $supportedMethods));
        }

        throw new RouteNotFoundException();
    }
}
