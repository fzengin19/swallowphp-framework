# Exceptions

The framework throws a small, deliberate catalog of exceptions from `SwallowPHP\Framework\Exceptions`. Each one extends PHP's built-in `\Exception` and carries a default HTTP status code. The framework's central [`ExceptionHandler`](foundation.md#exceptionhandler) catches them all and converts them into HTTP responses with the right status code, log level, and content type.

This document is the catalog. For the response-rendering pipeline (debug vs. production body, JSON vs. HTML view, fallback chain, log levels), see [`docs/foundation.md`](foundation.md#exceptionhandler).

## Table of Contents

- [Exception-to-Status Mapping](#exception-to-status-mapping)
- [AuthenticationLockoutException](#authenticationlockoutexception)
- [AuthorizationException](#authorizationexception)
- [CsrfTokenMismatchException](#csrftokenmismatchexception)
- [EnvPropertyValueException](#envpropertyvalueexception)
- [MethodNotAllowedException](#methodnotallowedexception)
- [MethodNotFoundException](#methodnotfoundexception)
- [PayloadTooLargeException](#payloadtoolargeexception)
- [RateLimitExceededException](#ratelimitexceededexception)
- [RouteNotFoundException](#routenotfoundexception)
- [ViewNotFoundException](#viewnotfoundexception)
- [Throwing and Catching Exceptions](#throwing-and-catching-exceptions)

---

## Exception-to-Status Mapping

The HTTP status each exception resolves to. The framework's `ExceptionHandler::getStatusCode()` is the source of truth — this table is derived from it and from each exception's `$code` default.

| Exception | Default HTTP Status | Source |
|-----------|---------------------|--------|
| `AuthenticationLockoutException` | 429 | exception default (`$code = 429`) |
| `AuthorizationException` | 401 (varies by context — see below) | exception default; `ExceptionHandler` returns `$exception->getCode()` or 403 |
| `CsrfTokenMismatchException` | 419 | both `ExceptionHandler` and exception default |
| `EnvPropertyValueException` | 500 (status code varies by context) | exception default; `ExceptionHandler` falls back to 500 |
| `MethodNotAllowedException` | 405 | both `ExceptionHandler` and exception default |
| `MethodNotFoundException` | 404 | exception default; `ExceptionHandler` falls back to 500 unless explicitly mapped |
| `PayloadTooLargeException` | 413 | both `ExceptionHandler` and exception default |
| `RateLimitExceededException` | 429 | both `ExceptionHandler` and exception default |
| `RouteNotFoundException` | 404 | both `ExceptionHandler` and exception default |
| `ViewNotFoundException` | 404 | both `ExceptionHandler` and exception default |

**Notes on "varies by context":**

- `AuthorizationException` defaults to 401 (the standard "unauthenticated" code), but the constructor accepts any `$code`, and the rest of the framework commonly passes 403 ("authenticated but forbidden"). This is the only framework exception whose HTTP status is read from the exception instance rather than hard-coded in the handler.
- `EnvPropertyValueException` is a bootstrap-level exception (it signals that a required `.env` entry is missing or invalid). The framework doesn't catch it specially — it propagates as a generic 500 with a "Invalid or missing environment variable configuration." message. The exact HTTP body is whatever `ExceptionHandler`'s fallback chain produces.
- `MethodNotFoundException` is thrown by the container's reflection autowiring when a route action's constructor or a service dependency requests a class that doesn't exist. The exception itself defaults to 404, but `ExceptionHandler::getStatusCode()` does **not** include a clause for it, so it falls through to the 500 default. Downstream applications that want a 404 here should override `getStatusCode()`.

---

## AuthenticationLockoutException

**Namespace:** `SwallowPHP\Framework\Exceptions\AuthenticationLockoutException`
**Default code:** 429

Thrown by the authentication layer when a user has exceeded the configured maximum number of consecutive failed login attempts within the lockout window. The default message is `"Too many login attempts. Account locked."`.

```php
throw new AuthenticationLockoutException();
```

| Property | Value |
|----------|-------|
| `$message` | `'Too many login attempts. Account locked.'` |
| `$code` | `429` |

The class inherits the standard `\Exception` constructor — to customize the message while keeping the status code, construct with a custom message:

```php
throw new AuthenticationLockoutException('Account locked for 15 minutes. Try again later.');
```

---

## AuthorizationException

**Namespace:** `SwallowPHP\Framework\Exceptions\AuthorizationException`
**Default code:** 401

Thrown when an authenticated user is not permitted to perform a specific action, or when an unauthenticated request hits a protected route. The status code is intentionally *not* hard-coded in `ExceptionHandler::getStatusCode()` — the handler returns `$exception->getCode()` (so 401 here, 403 in many real-world uses):

```php
protected static function getStatusCode(Throwable $exception): int
{
    if ($exception instanceof AuthorizationException) {
        return $exception->getCode() ?: 403; // 401 by default, 403 if caller passes 0
    }
    // ...
}
```

### Constructor

```php
public function __construct(
    $message = 'Access Denied: You are not authorized to perform this action.',
    $code = 401,
    ?Exception $previous = null
)
```

### Methods

| Method | Return | Description |
|--------|--------|-------------|
| `__construct(string $message = 'Access Denied...', int $code = 401, ?Exception $previous = null)` | void | Standard exception constructor. |

### Usage

```php
// 401 — not authenticated
throw new AuthorizationException();

// 403 — authenticated, but role check failed
throw new AuthorizationException('You do not have permission to edit this post.', 403);

// Chain a previous exception
throw new AuthorizationException('Forbidden', 403, $previousException);
```

`AuthorizationException` is the canonical exception to catch in middleware that gates routes by role or policy:

```php
try {
    $this->authorize($user, 'edit', $post);
} catch (AuthorizationException $e) {
    return redirectToRoute('login')->with('error', $e->getMessage());
}
```

---

## CsrfTokenMismatchException

**Namespace:** `SwallowPHP\Framework\Exceptions\CsrfTokenMismatchException`
**Default code:** 419

Thrown by the global `VerifyCsrfToken` middleware when the incoming request's CSRF token does not match the one stored in the session. 419 is the de-facto Laravel/PHP convention for this case (the formal HTTP codes don't include it).

### Constructor

```php
public function __construct(
    $message = 'CSRF token mismatch',
    $code = 419,
    ?Exception $previous = null
)
```

### Methods

| Method | Return | Description |
|--------|--------|-------------|
| `__construct(string $message = 'CSRF token mismatch', int $code = 419, ?Exception $previous = null)` | void | Standard exception constructor. |

### Usage

You typically don't throw this manually — the middleware handles it. To exempt a route from CSRF verification, extend `VerifyCsrfToken` and add the path to its `$except` array (see [`docs/middleware.md`](middleware.md#csrf-protection)).

```php
try {
    // Manually verify a token
    if (!hash_equals($_SESSION['_token'] ?? '', $request->input('_token'))) {
        throw new CsrfTokenMismatchException();
    }
} catch (CsrfTokenMismatchException $e) {
    return Response::json(['error' => 'Invalid CSRF token.'], 419);
}
```

---

## EnvPropertyValueException

**Namespace:** `SwallowPHP\Framework\Exceptions\EnvPropertyValueException`
**Default code:** 500

Thrown when a required `.env` entry is missing, malformed, or has an invalid value. Typically raised early in the bootstrap when a service checks `env('APP_KEY')` and finds it unset, or when an environment validator runs at startup.

This is a bootstrap-level exception — it indicates the application is in an unrecoverable state and should not be rendered through the normal view fallback chain. `ExceptionHandler::getStatusCode()` does not include a clause for it, so it falls through to the 500 default.

### Constructor

```php
public function __construct(
    $message = 'Invalid or missing environment variable configuration.',
    $code = 500,
    ?Exception $previous = null
)
```

### Methods

| Method | Return | Description |
|--------|--------|-------------|
| `__construct(string $message = 'Invalid or missing environment variable configuration.', int $code = 500, ?Exception $previous = null)` | void | Standard exception constructor. |

### Usage

```php
if (empty(env('APP_KEY'))) {
    throw new EnvPropertyValueException(
        'APP_KEY is required. Set it in .env before booting the application.'
    );
}
```

The HTTP status code for this exception is `500` by default but "varies by context" — the runtime doesn't enforce a specific status, and `ExceptionHandler` falls through to the generic 500 path. If you want a different code, pass it explicitly and override `ExceptionHandler::getStatusCode()` for the class.

---

## MethodNotAllowedException

**Namespace:** `SwallowPHP\Framework\Exceptions\MethodNotAllowedException`
**Default code:** 405

Thrown by `Router::dispatch()` when the request URL matches a registered route but the HTTP method does not (e.g. the route is registered for `GET` only and the request is `POST`). The HTTP 405 response should also include an `Allow` header listing the methods the route accepts, which the router sets up before re-throwing.

### Constructor

```php
public function __construct(
    $message = 'Method Not Allowed',
    $code = 405,
    ?Exception $previous = null
)
```

### Methods

| Method | Return | Description |
|--------|--------|-------------|
| `__construct(string $message = 'Method Not Allowed', int $code = 405, ?Exception $previous = null)` | void | Standard exception constructor. |

### Usage

```php
// Manually
Router::get('/users', [UserController::class, 'index']);

// POST /users → throws MethodNotAllowedException (HTTP 405)
```

---

## MethodNotFoundException

**Namespace:** `SwallowPHP\Framework\Exceptions\MethodNotFoundException`
**Default code:** 404

Thrown by the container's reflection autowiring when a controller class or a service dependency references a method that does not exist on the target class. Default `$code` is 404, but `ExceptionHandler::getStatusCode()` doesn't include a mapping for this class, so it falls through to the generic 500 path. Status code "varies by context" if you want to treat it as a structural error rather than a not-found.

### Constructor

```php
public function __construct(
    $message = 'Method Not Found',
    $code = 404,
    ?Exception $previous = null
)
```

### Methods

| Method | Return | Description |
|--------|--------|-------------|
| `__construct(string $message = 'Method Not Found', int $code = 404, ?Exception $previous = null)` | void | Standard exception constructor. |

### Usage

```php
// Container reflection throws this when autowiring fails
// because a constructor parameter has no matching type-hint
try {
    $controller = App::container()->get(BrokenController::class);
} catch (MethodNotFoundException $e) {
    error_log('Wiring error: ' . $e->getMessage());
}
```

---

## PayloadTooLargeException

**Namespace:** `SwallowPHP\Framework\Exceptions\PayloadTooLargeException`
**Default code:** 413

Thrown by the global `ValidatePostSize` middleware when the incoming request body exceeds PHP's `post_max_size` ini setting, or when an uploaded file exceeds `upload_max_filesize` / `MAX_FILE_SIZE`. The framework's `ExceptionHandler` has a production-only friendly fallback message ("Uploaded data is too large. Please reduce the file size and try again.") that overrides the raw exception message — see [`docs/middleware.md`](middleware.md#validate-post-size).

### Constructor

```php
public function __construct(
    string $message = 'Payload Too Large.',
    int $code = 413,
    ?Exception $previous = null
)
```

### Methods

| Method | Return | Description |
|--------|--------|-------------|
| `__construct(string $message = 'Payload Too Large.', int $code = 413, ?Exception $previous = null)` | void | Standard exception constructor. |

### Usage

The middleware throws this automatically; you rarely need to throw it yourself. If you do (e.g. a custom upload handler that enforces a per-route limit):

```php
if ($contentLength > $maxAllowed) {
    throw new PayloadTooLargeException(
        "Upload exceeds the {$maxAllowedMiB}MiB per-file limit for this endpoint."
    );
}
```

---

## RateLimitExceededException

**Namespace:** `SwallowPHP\Framework\Exceptions\RateLimitExceededException`
**Default code:** 429

Thrown by the `RateLimiter` middleware when a request exceeds the configured `$rateLimit` requests within the `$ttl` window. The middleware also attaches `Retry-After`, `X-RateLimit-Limit`, and `X-RateLimit-Remaining` headers — but these are not part of the exception object itself; they are written to the response by the middleware before the exception is thrown. The handler's default message is `"Too Many Requests"`.

### Constructor

```php
public function __construct(
    $message = 'Too Many Requests',
    $code = 429,
    ?Exception $previous = null
)
```

### Methods

| Method | Return | Description |
|--------|--------|-------------|
| `__construct(string $message = 'Too Many Requests', int $code = 429, ?Exception $previous = null)` | void | Standard exception constructor. |

### Usage

```php
// Limiting a route to 10 requests / 60 seconds
Router::post('/login', [AuthController::class, 'login'])
    ->limit(10, 60);

// Custom limit on a service
if ($attempts > $max) {
    throw new RateLimitExceededException(
        'You have exceeded the maximum number of requests per minute.'
    );
}
```

See [`docs/middleware.md`](middleware.md#rate-limiting) for full usage of the `->limit()` route helper.

---

## RouteNotFoundException

**Namespace:** `SwallowPHP\Framework\Exceptions\RouteNotFoundException`
**Default code:** 404

Thrown by `Router::dispatch()` when no registered route matches the incoming request. This is the canonical 404 for the application. The default message is `"Route Not Found"`.

### Constructor

```php
public function __construct(
    $message = 'Route Not Found',
    $code = 404,
    ?Exception $previous = null
)
```

### Methods

| Method | Return | Description |
|--------|--------|-------------|
| `__construct(string $message = 'Route Not Found', int $code = 404, ?Exception $previous = null)` | void | Standard exception constructor. |

### Usage

```php
try {
    $response = Router::dispatch($request);
} catch (RouteNotFoundException $e) {
    // Custom 404 body for in-app links
    return Response::html(view('errors.404', ['path' => $request->getPath()]), 404);
}
```

---

## ViewNotFoundException

**Namespace:** `SwallowPHP\Framework\Exceptions\ViewNotFoundException`
**Default code:** 404

Thrown by the view layer when a requested view template does not exist on disk. The `ExceptionHandler` uses this as a signal that the specific `errors.{statusCode}` view doesn't exist, in which case it falls back to `errors.default` and then to a plain HTML fallback. The default message is `"View not found"`.

### Constructor

```php
public function __construct(
    $message = 'View not found',
    $code = 404,
    ?Exception $previous = null
)
```

### Methods

| Method | Return | Description |
|--------|--------|-------------|
| `__construct(string $message = 'View not found', int $code = 404, ?Exception $previous = null)` | void | Standard exception constructor. |

### Usage

```php
try {
    return view('emails.welcome', ['user' => $user], 'layouts.email');
} catch (ViewNotFoundException $e) {
    logger()->error('Missing email template', ['view' => 'emails.welcome']);
    return Response::html('<p>Welcome, ' . htmlspecialchars($user->name) . '!</p>');
}
```

---

## Throwing and Catching Exceptions

All framework exceptions are plain `\Exception` subclasses, so anything that works with `\Throwable` works with them. The two patterns you'll use most often:

### Catch in a controller when you want to customize the response

```php
use SwallowPHP\Framework\Exceptions\AuthorizationException;
use SwallowPHP\Framework\Exceptions\ValidationException;

public function update(Request $request, int $id)
{
    try {
        $post = Post::findOrFail($id);
        $this->authorize($request->user(), 'update', $post);
        $post->update($request->all());
    } catch (AuthorizationException $e) {
        return Response::json(['error' => $e->getMessage()], $e->getCode() ?: 403);
    } catch (ValidationException $e) {
        return Response::json(['errors' => $e->errors()], 422);
    }
}
```

### Let the framework handle everything

If you don't catch, the exception propagates to `App::run()`'s `try/catch`, which delegates to `ExceptionHandler::handle()`. The handler:

- Logs the exception at the level chosen by `getLogLevel()`.
- Maps the class to an HTTP status via `getStatusCode()`.
- Renders JSON (when the client's `Accept` asks for it) or HTML (via the `errors.{status}` view fallback chain).
- Falls back to a plain HTML page if no view exists.

```php
public function destroy(int $id)
{
    $post = Post::findOrFail($id);
    $post->delete(); // throws on missing model — passes through to ExceptionHandler
}
```

For the full response-rendering pipeline, including the debug-vs-production body and the failure-while-failing fallback, see [`docs/foundation.md`](foundation.md#exceptionhandler).
