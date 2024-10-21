<?php

namespace SwallowPHP\Framework;

use Exception;
use SwallowPHP\Framework\Exceptions\MethodNotFoundException;
use SwallowPHP\Framework\Exceptions\RouteNotFoundException;
use SwallowPHP\Framework\Middleware;
use ReflectionMethod;

class Route
{
  private $method;
  private $name;
  private $uri;
  private $middlewares = [];
  private $action;
  private $rateLimit = null;
  private $ttl = null;


  /**
   * Constructs a new instance of the class.
   *
   * @param string $method The HTTP method to be used.
   * @param string $uri The URI to be used for the request.
   * @param mixed $action The action to be executed.
   * @param array $middleware An array of middleware functions to be executed.
   */
  public function __construct($method, $uri, $action, $middlewares = [])
  {
    $this->uri = $uri;
    $this->method = $method;
    $this->action = $action;
    $this->middlewares = $middlewares;
  }

  /**
   * Sets the name of the object.
   *
   * @param string $name The name to set.
   * @return $this The current object.
   */
  public function name($name)
  {
    $this->name = $name;
    return $this;
  }

  /**
   * Get the rate limit.
   *
   * @return int The rate limit.
   */
  public function getRateLimit()
  {
    return $this->rateLimit ?? null;
  }

  /**
   * Get the rate limit.
   *
   * @return int The rate limit.
   */
  public function getTimeToLive()
  {
    return $this->ttl ?? null;
  }

  /**
   * Set the rate limit for the route.
   *
   * @param int $rateLimit The rate limit to set.
   * @param int|null $ttl The time-to-live (TTL) value for the rate limit cache. Defaults to the value of the 'RATE_LIMIT_CACHE_TTL' environment variable, or 60 if not set.
   * @return $this The current object instance.
   */
  public function limit(int $rateLimit, int $ttl = null)
  {
    $this->rateLimit = $rateLimit;
    $this->ttl = $ttl;
    return $this;
  }

  /**
   * Get the name of the route.
   *
   * @return string The name of the route.
   */
  public function getName()
  {
    return $this->name;
  }
  /**
   * Adds a middleware to the collection of middlewares.
   *
   * @param mixed $middleware The middleware to add.
   * @return $this
   */
  public function middleware(Middleware $middleware)
  {
    $this->middlewares[] = $middleware;
    return $this;
  }

  /**
   * Match the given request method and URI with this route.
   *
   * @param string $requestMethod The HTTP request method.
   * @param string $requestUri The HTTP request URI.
   * @return array|boolean Returns an array of route parameters if the route matches, 
   *              or false if there is no match.
   */
  public function match($requestMethod, $requestUri)
  {
    if ($this->method !== $requestMethod) {
      return false;
    }

    $routeUriParts = explode('/', $this->uri);
    $requestUriParts = explode('/', $requestUri);

    if (count($routeUriParts) !== count($requestUriParts)) {
      return false;
    }

    $params = [];

    for ($i = 0; $i < count($routeUriParts); $i++) {
      if (strpos($routeUriParts[$i], '{') !== false) {
        $paramName = trim($routeUriParts[$i], '{}');
        $paramValue = $requestUriParts[$i];
        $params[$paramName] = $paramValue;
      } elseif ($routeUriParts[$i] !== $requestUriParts[$i]) {
        return false;
      }
    }

    return $params;
  }


  protected function executeAction($request)
  {
    $params = ['request' => $request];

    if (is_callable($this->action)) {
      return call_user_func($this->action, $request);
    } elseif (is_string($this->action)) {
      [$controllerName, $method] = explode('@', $this->action);
    } elseif (is_array($this->action) && count($this->action) === 2 && is_string($this->action[0]) && is_string($this->action[1])) {
      [$controllerName, $method] = $this->action;
    } else {
      throw new Exception('Invalid action definition', 500);
    }

    if (!class_exists($controllerName)) {
      $controllerName = '\\App\\Controllers\\' . $controllerName;
      if (!class_exists($controllerName))
        throw new Exception("Controller '$controllerName' not found", 404);
    }

    $controller = new $controllerName;

    if (!method_exists($controller, $method)) {
      throw new MethodNotFoundException("Method \"$method\" Not Found on \"$controllerName\"", 404);
    }

    $reflectionMethod = new ReflectionMethod($controllerName, $method);
    $reflectionParams = $reflectionMethod->getParameters();
    $finalParams = [];

    foreach ($reflectionParams as $reflectionParam) {
      $paramName = $reflectionParam->getName();
      if (!isset($params[$paramName])) {
        throw new Exception("Missing parameter '$paramName' for method '$method' in controller '$controllerName'", 500);
      }
      $finalParams[] = $params[$paramName];
    }

    return $reflectionMethod->invokeArgs($controller, $finalParams);
  }


  public function execute(Request $request)
  {
    // Middleware'leri ters sırayla al
    $pipeline = array_reverse($this->middlewares);
    // İstek işleyici
    $handler = function ($request) {
      return $this->executeAction($request);
    };
    // Middleware'leri uygula
    foreach ($pipeline as $middleware) {
      // Middleware'i işleyiciye bağla
      $handler = function ($request) use ($middleware, $handler) {
        // Middleware'in handle metodunu çağır ve sonucu işleyiciye aktar
        return $middleware->handle($request, $handler);
      };
    }
    // İşleyiciyi çağır ve isteği işle
    return $handler($request);
  }

  /**
   * Returns the current value of the method property.
   *
   * @return mixed The current value of the method property.
   */
  public function getMethod()
  {
    return $this->method;
  }

  /**
   * Returns the current value of the method property.
   *
   * @return mixed The current value of the method property.
   */
  public function getUri()
  {
    return $this->uri;
  }
}
