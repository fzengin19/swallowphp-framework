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
  private ?string $compiledRegex = null;


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
  public function limit(int $rateLimit, ?int $ttl = null) // Explicitly mark $ttl as nullable
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
    // via setRouteParams(). We use routeParams() (not all()) so the scalar
    // coercion in resolveMethodDependencies() only widens coercion for values
    // that came from URL segments — query-string and body values keep PHP's
    // loose string semantics. routeParams() is set by Router::dispatch and
    // does NOT include query/body keys.
    $routeParameters = $request->routeParams();

    // Array-callable (e.g. [Controller::class, 'method']) MUST be checked BEFORE
    // is_callable(): PHP's is_callable([ClassNameString, 'method']) returns true
    // ONLY when 'method' is a STATIC method (false for a non-static instance
    // method). For a static-method array action, is_callable() would return
    // true and send us into the ReflectionFunction branch below, which throws
    // TypeError (that helper only accepts Closure|string, not an array). A
    // bare Closure is never an array, so this ordering is safe for the
    // closure case regardless.
    if (is_array($this->action) && count($this->action) === 2 && is_string($this->action[0]) && is_string($this->action[1])) {
      [$controllerName, $method] = $this->action;
    } elseif (is_callable($this->action)) {
      // Resolve parameters for the closure using reflection and container
      $reflector = new ReflectionFunction($this->action);
      $args = $this->resolveMethodDependencies($reflector->getParameters(), $routeParameters, $container, $request);
      return call_user_func_array($this->action, $args);

    } elseif (is_string($this->action)) {
      [$controllerName, $method] = explode('@', $this->action);
    } else {
      throw new Exception('Invalid action definition', 500);
    }

    if (!class_exists($controllerName)) {
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

    // Invoke the method with resolved dependencies. A route parameter that
    // can't be coerced to its declared scalar type (e.g. "abc" for an `int`
    // parameter — coerceScalarRouteParameter() intentionally leaves those
    // as the raw string rather than inventing new behavior for them) would
    // otherwise reach this call and raise a raw, unhandled TypeError. Catch
    // that specific case here and translate it into the same
    // MethodNotFoundException/404 contract this function already uses for
    // "this URL doesn't resolve to a valid call".
    try {
      return $reflectionMethod->invokeArgs($controller, $args);
    } catch (\TypeError $e) {
      // PHP's argument-binding TypeError always has this exact shape
      // ("Class::method(): Argument #N ($param) must be of type X, Y
      // given") and is raised before the method body ever runs. A
      // TypeError thrown FROM INSIDE the controller method's own body has
      // an arbitrary message and does not match this pattern — re-throw
      // those unchanged so a genuine application bug is never
      // mis-reported as "route not found."
      if (preg_match('/^' . preg_quote("$controllerName::$method(): Argument #", '/') . '\d+/', $e->getMessage())) {
        // MethodNotFoundException::$previous only accepts ?Exception, and
        // \TypeError extends \Error, not \Exception — cannot chain it as
        // the previous exception here.
        throw new MethodNotFoundException(
          "Route parameters for \"$method\" on \"$controllerName\" do not match the expected argument types.",
          404
        );
      }
      throw $e;
    }
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
      $paramType = $this->getParameterClassName($param);

      if (array_key_exists($paramName, $routeParameters)) {
        // Match by route parameter name. URL segments arrive as strings; if the
        // declared parameter type is a scalar (int/float/bool/string) and the
        // string is safely coercible, widen PHP's implicit numeric-string
        // coercion to cover more values (e.g. leading-zero IDs, the booleans
        // "0"/"1"). Values that are NOT safely coercible (e.g. "abc" for an
        // int) are passed through as the raw string — this AC widens the
        // common-case coercion only and does not invent a 404 or a TypeError
        // for the malformed case.
        $args[] = $this->coerceScalarRouteParameter($param, $routeParameters[$paramName]);
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
      } else {
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

  /**
   * Compile (once) and return the anchored regex used to match this route's URI.
   * {param+} captures across slashes (nested paths); {param} captures a single segment.
   */
  public function getCompiledRegex(): string
  {
    if ($this->compiledRegex === null) {
      $pattern = preg_quote($this->uri, '/');
      $pattern = preg_replace('/\\\\{([a-zA-Z0-9_]+)\\\\\\+\\\\}/', '(?P<$1>.+)', $pattern);
      $pattern = preg_replace('/\\\\{([a-zA-Z0-9_]+)\\\\}/', '(?P<$1>[^\\/]+)', $pattern);
      $this->compiledRegex = '/^' . $pattern . '$/';
    }
    return $this->compiledRegex;
  }

  /**
   * Get the class name from a reflection parameter's type.
   * Handles named types, union types (PHP 8.0+), and intersection types (PHP 8.1+).
   * Returns the first class/interface type found, or null for built-in types only.
   *
   * @param ReflectionParameter $param
   * @return string|null Class name or null
   */
  protected function getParameterClassName(ReflectionParameter $param): ?string
  {
    $type = $param->getType();

    if ($type === null) {
      return null;
    }

    // PHP 8.0+ Union types (e.g., Foo|Bar|null)
    if ($type instanceof \ReflectionUnionType) {
      foreach ($type->getTypes() as $unionType) {
        if ($unionType instanceof \ReflectionNamedType && !$unionType->isBuiltin()) {
          return $unionType->getName();
        }
      }
      return null;
    }

    // PHP 8.1+ Intersection types (e.g., Foo&Bar)
    if ($type instanceof \ReflectionIntersectionType) {
      $types = $type->getTypes();
      if (!empty($types) && $types[0] instanceof \ReflectionNamedType) {
        return $types[0]->getName();
      }
      return null;
    }

    // Named type (most common case)
    if ($type instanceof \ReflectionNamedType) {
      return $type->isBuiltin() ? null : $type->getName();
    }

    return null;
  }

  /**
   * Coerce a string route-parameter value to a declared scalar parameter type
   * when safely coercible. Values that are NOT safely coercible are returned
   * unchanged so the caller does not introduce a new crash or a new silent
   * "not found" behavior for the malformed-input case.
   *
   * @param ReflectionParameter $param
   * @param mixed $value
   * @return mixed
   */
  protected function coerceScalarRouteParameter(ReflectionParameter $param, mixed $value): mixed
  {
    if (!is_string($value)) {
      return $value;
    }

    $type = $param->getType();
    if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
      return $value;
    }

    $builtin = $type->getName();
    switch ($builtin) {
      case 'int':
        // is_numeric() rejects "abc" but accepts "42", "3.14", "1e2", "  42", etc.
        // is_numeric() also accepts floats, which (int) truncates — that's the
        // same trade-off PHP itself makes for numeric-string coercion.
        if (is_numeric($value)) {
          // EXACT integer-range validation via string comparison. Float
          // comparison cannot detect overflow at the PHP_INT_MAX / MIN
          // boundary: both "9223372036854775808" (PHP_INT_MAX + 1) and
          // PHP_INT_MAX itself round to the same IEEE-754 double
          // (9.2233720368548E+18), so $asFloat > PHP_INT_MAX is false
          // even though the value is out of range — and (int) then
          // silently produces PHP_INT_MAX, mapping a different route ID
          // to the same record. Compare the magnitude as a string
          // against PHP_INT_MAX/MIN instead.
          //
          // For pure-integer strings (no decimal point, no exponent,
          // optional leading sign), validate length + lexicographic
          // comparison. For everything else is_numeric accepts
          // ("3.14", "1e2", "1e309") fall back to the float comparison
          // — float is sufficient there because is_finite() catches
          // the INF/NaN cases that would otherwise slip through.
          $trimmed = trim($value);
          if (preg_match('/^-?\d+$/', $trimmed)) {
            $isNegative = $trimmed[0] === '-';
            $abs = $isNegative ? substr($trimmed, 1) : $trimmed;
            // Strip leading zeros before the length comparison below, or a
            // harmless, in-range value like "00000000000000000000000042"
            // gets misjudged as out-of-range purely because of its zero
            // padding (26 digits > PHP_INT_MAX's 19) and is wrongly left as
            // a string instead of becoming int 42. Keep at least one digit.
            $abs = ltrim($abs, '0');
            if ($abs === '') {
              $abs = '0';
            }
            $bound = $isNegative
              ? substr((string) PHP_INT_MIN, 1)  // "9223372036854775808"
              : (string) PHP_INT_MAX;            // "9223372036854775807"
            $absLen = strlen($abs);
            $boundLen = strlen($bound);
            if ($absLen > $boundLen || ($absLen === $boundLen && strcmp($abs, $bound) > 0)) {
              // Out of int range — preserve the raw string so the
              // controller sees the original URL segment instead of
              // a silently-mangled value.
              return $value;
            }
            return (int) $value;
          }
          $asFloat = (float) $value;
          if (!is_finite($asFloat) || $asFloat < PHP_INT_MIN || $asFloat > PHP_INT_MAX) {
            return $value;
          }
          return (int) $value;
        }
        return $value;
      case 'float':
        if (is_numeric($value)) {
          // is_numeric() accepts "1e309" / "-1e309" — (float) yields INF.
          // is_finite() catches both, and (float) "nan"/"inf" are also
          // numeric strings in PHP that produce NaN/INF; reject those too.
          $asFloat = (float) $value;
          if (!is_finite($asFloat)) {
            return $value;
          }
          return $asFloat;
        }
        return $value;
      case 'bool':
        // Only coerce the literal forms a reasonable route param would use;
        // PHP's loose truthiness rules for arbitrary strings are surprising.
        $lower = strtolower($value);
        if ($value === '1' || $lower === 'true') {
          return true;
        }
        if ($value === '0' || $lower === 'false') {
          return false;
        }
        return $value;
      case 'string':
        return $value;
    }
    return $value;
  }
}