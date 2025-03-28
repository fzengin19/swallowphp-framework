<?php

namespace SwallowPHP\Framework\Routing;

use Exception;
use SwallowPHP\Framework\Exceptions\MethodNotFoundException;
use SwallowPHP\Framework\Http\Middleware\Middleware;
use SwallowPHP\Framework\Foundation\App; // Need access to the container
use ReflectionMethod;
use ReflectionFunction;
use ReflectionParameter;
use League\Container\Container; // For type hinting
use SwallowPHP\Framework\Http\Request; // Ensure correct Request is imported

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

  protected function executeAction($request)
  {
    $container = App::container(); // Get the DI container
    // Route parameters are already added to the request object in Router::dispatch
    // We'll pass the whole request object and let container/reflection handle it.
    $routeParameters = $request->all(); // Get all request data, including route params

    if (is_callable($this->action)) {
        // Resolve parameters for the closure using reflection and container
        $reflector = new ReflectionFunction($this->action);
        $args = $this->resolveMethodDependencies($reflector->getParameters(), $routeParameters, $container, $request);
        return call_user_func_array($this->action, $args);

    } elseif (is_string($this->action)) {
      [$controllerName, $method] = explode('@', $this->action);
    } elseif (is_array($this->action) && count($this->action) === 2 && is_string($this->action[0]) && is_string($this->action[1])) {
      [$controllerName, $method] = $this->action;
    } else {
      throw new Exception('Invalid action definition', 500);
    }

    if (!class_exists($controllerName)) {
      // TODO: Make controller namespace configurable?
      $configuredNamespace = config('app.controller_namespace', '\\App\\Controllers');
      $controllerName = rtrim($configuredNamespace, '\\') . '\\' . $controllerName;
      if (!class_exists($controllerName))
        throw new Exception("Controller '$controllerName' not found", 404);
    }

    // Resolve controller instance from the container
    try {
        $controller = $container->get($controllerName);
    } catch (\Exception $e) {
         throw new Exception("Could not resolve controller '{$controllerName}' from container: " . $e->getMessage(), 500, $e);
    }

    if (!method_exists($controller, $method)) {
      throw new MethodNotFoundException("Method \"$method\" Not Found on \"$controllerName\"", 404);
    }

    // Resolve dependencies for the controller method
    $reflectionMethod = new ReflectionMethod($controllerName, $method);
    $reflectionParams = $reflectionMethod->getParameters();
    $args = $this->resolveMethodDependencies($reflectionParams, $routeParameters, $container, $request);

    // Invoke the method with resolved dependencies
    return $reflectionMethod->invokeArgs($controller, $args);
  }

  /**
   * Resolve dependencies for a given set of reflection parameters.
   * Tries to match parameters with route parameters, the request object, or services from the container.
   *
   * @param ReflectionParameter[] $parameters
   * @param array $routeParameters Parameters extracted from the route URI.
   * @param Container $container The DI container.
   * @param Request $request The current request object.
   * @return array The resolved arguments for the method/function call.
   * @throws Exception If a required parameter cannot be resolved.
   */
  protected function resolveMethodDependencies(array $parameters, array $routeParameters, Container $container, Request $request): array
  {
      $args = [];
      foreach ($parameters as $param) {
          $paramName = $param->getName();
          $paramType = $param->getType() instanceof \ReflectionNamedType ? $param->getType()->getName() : null;

          if (array_key_exists($paramName, $routeParameters)) {
              // Match by route parameter name
              $args[] = $routeParameters[$paramName];
          } elseif ($paramType === Request::class || is_subclass_of($paramType, Request::class)) {
              // Match by Request type hint
              $args[] = $request;
          } elseif ($paramType && $container->has($paramType)) {
              // Match by type hint in the container
              try {
                   $args[] = $container->get($paramType);
              } catch (\Exception $e) {
                   // Handle cases where container fails to resolve (e.g., interface not bound)
                   if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                   } elseif ($param->allowsNull()) {
                        $args[] = null;
                   } else {
                        throw new Exception("Could not resolve parameter '{$paramName}' of type '{$paramType}': " . $e->getMessage(), 500, $e);
                   }
              }
          } elseif ($param->isDefaultValueAvailable()) {
              // Use default value if available
              $args[] = $param->getDefaultValue();
          } elseif ($param->allowsNull()) {
               // Use null if allowed
               $args[] = null;
          }
           else {
              // Cannot resolve the parameter
              $methodName = $param->getDeclaringFunction()->getName();
              $className = $param->getDeclaringClass() ? $param->getDeclaringClass()->getName() . '::' : '';
              throw new Exception("Unresolvable dependency resolving [{$param->getName()}] in {$className}{$methodName}");
          }
      }
      return $args;
  }

  // Removed duplicate: public function execute(Request $request)
  public function execute(\SwallowPHP\Framework\Http\Request $request)
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