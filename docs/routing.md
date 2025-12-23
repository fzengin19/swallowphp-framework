# Routing

SwallowPHP provides a simple yet powerful routing system for handling HTTP requests.

## Table of Contents

- [Basic Routing](#basic-routing)
- [Route Parameters](#route-parameters)
- [Named Routes](#named-routes)
- [Controller Routes](#controller-routes)
- [Middleware](#middleware)
- [Rate Limiting](#rate-limiting)
- [Helper Functions](#helper-functions)
- [How Routing Works](#how-routing-works)

---

## Basic Routing

Define routes in your `routes/web.php` file using the `Router` class static methods.

### Available HTTP Methods

```php
use SwallowPHP\Framework\Routing\Router;

// GET request
Router::get('/users', function () {
    return 'List users';
});

// POST request
Router::post('/users', function () {
    return 'Create user';
});

// PUT request
Router::put('/users/{id}', function () {
    return 'Update user';
});

// PATCH request
Router::patch('/users/{id}', function () {
    return 'Partial update user';
});

// DELETE request
Router::delete('/users/{id}', function () {
    return 'Delete user';
});
```

### Route Actions

Routes can use closures, controller strings, or controller arrays:

```php
// Closure
Router::get('/', function () {
    return view('welcome');
});

// Controller@method string (legacy format)
Router::get('/posts', 'PostController@index');

// Controller array (recommended)
Router::get('/posts', [PostController::class, 'index']);
```

---

## Route Parameters

Define dynamic segments in your route URIs using `{parameter}` syntax.

### Basic Parameters

```php
Router::get('/users/{id}', function (Request $request) {
    $id = $request->get('id');
    return "User ID: {$id}";
});

Router::get('/posts/{slug}', [PostController::class, 'show']);
```

### Multiple Parameters

```php
Router::get('/categories/{category}/posts/{post}', function (Request $request) {
    $category = $request->get('category');
    $post = $request->get('post');
    return "Category: {$category}, Post: {$post}";
});
```

### Accessing Route Parameters

Route parameters are merged into the request object and can be accessed via:

```php
// In controller or closure
public function show(Request $request)
{
    // Via get() method
    $id = $request->get('id');
    
    // All parameters (including route params)
    $all = $request->all();
}
```

**Note:** Route parameters automatically override any query or body parameters with the same name.

---

## Named Routes

Name your routes to easily generate URLs.

### Defining Named Routes

```php
Router::get('/', function () {
    return view('home');
})->name('home');

Router::get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show');

Router::get('/posts/{category}/{slug}', [PostController::class, 'show'])
    ->name('posts.show');
```

### Generating URLs from Named Routes

Use the `route()` helper function:

```php
// Basic route URL
$homeUrl = route('home');
// Returns: http://localhost/

// Route with parameters
$userUrl = route('users.show', ['id' => 123]);
// Returns: http://localhost/users/123

// Route with extra parameters (become query string)
$postsUrl = route('posts.show', [
    'category' => 'tech',
    'slug' => 'my-post',
    'page' => 2
]);
// Returns: http://localhost/posts/tech/my-post?page=2
```

Parameters are automatically URL-encoded when generating the URL.

---

## Controller Routes

### Controller Resolution

Controllers are resolved from the DI container, enabling constructor injection.

```php
// routes/web.php
Router::get('/posts', [PostController::class, 'index']);
```

```php
// app/Controllers/PostController.php
namespace App\Controllers;

use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Database\Database;

class PostController
{
    private Database $db;
    
    // Constructor injection
    public function __construct(Database $db)
    {
        $this->db = $db;
    }
    
    public function index(Request $request)
    {
        $posts = $this->db->table('posts')->get();
        return view('posts.index', ['posts' => $posts]);
    }
    
    public function show(Request $request)
    {
        $id = $request->get('id');
        $post = $this->db->table('posts')->where('id', $id)->first();
        return view('posts.show', ['post' => $post]);
    }
}
```

### Controller Namespace

Configure the controller namespace in `config/app.php`:

```php
'controller_namespace' => '\\App\\Controllers',
```

When using controller string notation without a fully qualified name, this namespace is prepended:

```php
// This will look for \App\Controllers\PostController
Router::get('/posts', 'PostController@index');

// This uses the full class name directly
Router::get('/posts', [\App\Controllers\PostController::class, 'index']);
```

### Method Dependency Injection

Controller methods can receive dependencies via type-hinting:

```php
use SwallowPHP\Framework\Http\Request;
use Psr\Log\LoggerInterface;

public function store(Request $request, LoggerInterface $logger)
{
    $logger->info('Creating new post');
    // Request is automatically injected
    // Logger is resolved from the container
}
```

**Resolution Order:**
1. Route parameter name match (e.g., `$id` matches `{id}`)
2. `Request` type-hint → current request object
3. Type-hint exists in container → resolved from container
4. Has default value → uses default
5. Allows null → uses null
6. Otherwise → throws exception

---

## Middleware

Middleware allows you to filter HTTP requests entering your application.

### Creating Middleware

Extend the `Middleware` base class:

```php
<?php

namespace App\Middleware;

use SwallowPHP\Framework\Http\Middleware\Middleware;
use SwallowPHP\Framework\Http\Request;
use Closure;

class AuthMiddleware extends Middleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!Auth::isAuthenticated()) {
            return redirect('/login');
        }
        
        return $next($request);
    }
}
```

### Applying Middleware to Routes

```php
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

Router::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(new AuthMiddleware());

// Multiple middleware (executed in order added)
Router::get('/admin', [AdminController::class, 'index'])
    ->middleware(new AuthMiddleware())
    ->middleware(new AdminMiddleware());
```

### Middleware Pipeline

Middleware forms an "onion" around the route action:

1. `AuthMiddleware::handle()` called
2. `AuthMiddleware` calls `$next($request)`
3. `AdminMiddleware::handle()` called
4. `AdminMiddleware` calls `$next($request)`
5. Route action executed
6. Response bubbles back through middleware

### Built-in Middleware

**CSRF Protection** (`VerifyCsrfToken`)
- Applied globally in `App::run()`
- Skips GET, HEAD, OPTIONS requests
- Validates `_token` field or `X-CSRF-TOKEN` header

**Rate Limiting** (`RateLimiter`)
- Applied per-route via `->limit()` method
- See [Rate Limiting](#rate-limiting) section

**Content Security Policy** (`AddContentSecurityPolicyHeader`)
- Applied globally in `App::run()`
- Configured via `config/security.php`

---

## Rate Limiting

Protect routes from excessive requests using rate limiting.

### Basic Rate Limiting

```php
// 100 requests per 60 seconds (per IP)
Router::get('/api/users', [ApiController::class, 'users'])
    ->limit(100, 60);

// 10 requests with default TTL (from cache.ttl config)
Router::post('/api/login', [AuthController::class, 'login'])
    ->limit(10);
```

### How It Works

1. Rate limit is checked before route action executes
2. Request count stored in cache per IP and route
3. Exceeding limit throws `RateLimitExceededException` (HTTP 429)

### Response Headers

When rate limiting is active, these headers are set:

| Header | Description |
|--------|-------------|
| `X-RateLimit-Limit` | Maximum requests allowed |
| `X-RateLimit-Remaining` | Requests remaining in window |
| `Retry-After` | Seconds until limit resets (when exceeded) |

### Cache Key Format

```
rate_limit:{route_name_or_uri}:{client_ip}
```

---

## Helper Functions

### `route($name, $params = [])`

Generate URL for a named route.

```php
$url = route('users.show', ['id' => 1]);
// http://localhost/users/1
```

### `hasRoute($name)`

Check if a named route exists.

```php
if (hasRoute('admin.dashboard')) {
    // Route exists
}
```

### `isRoute($name)`

Check if current route matches the given name(s).

```php
// Single route
if (isRoute('home')) {
    echo 'On home page';
}

// Multiple routes (array)
if (isRoute(['dashboard', 'profile', 'settings'])) {
    echo 'On user area';
}
```

### `redirect($uri, $code = 302)`

Redirect to a URI and exit.

```php
redirect('/login');
redirect('/dashboard', 301); // Permanent redirect
```

### `redirectToRoute($name, $params = [])`

Redirect to a named route and exit.

```php
redirectToRoute('home');
redirectToRoute('users.show', ['id' => 1]);
```

This function also sends any queued cookies before redirecting.

---

## How Routing Works

### Dispatch Process

1. **Request Received** - `App::run()` creates a `Request` object
2. **Path Processing** - Base path (`app.path`) removed from URI
3. **Trailing Slash Handling** - Trailing slashes normalized
4. **Route Matching** - URI matched against registered routes using regex
5. **Method Check** - HTTP method verified
6. **Rate Limiting** - `RateLimiter::execute()` called
7. **Parameter Extraction** - Route parameters extracted and decoded
8. **Middleware Pipeline** - Route middlewares executed
9. **Action Execution** - Controller/closure invoked
10. **Response Return** - Response sent to client

### Route Pattern Matching

Route URIs are converted to regex patterns:

```php
// Route: /users/{id}
// Regex: /^\/users\/(?P<id>[^\/]+)$/

// Route: /posts/{category}/{slug}
// Regex: /^\/posts\/(?P<category>[^\/]+)\/(?P<slug>[^\/]+)$/
```

Parameters match any characters except `/`.

### Error Handling

| Exception | HTTP Status | When |
|-----------|-------------|------|
| `RouteNotFoundException` | 404 | No route matches URI |
| `MethodNotAllowedException` | 405 | URI matches but wrong HTTP method |
| `RateLimitExceededException` | 429 | Rate limit exceeded |

### All Registered Routes

Access all routes programmatically:

```php
$routes = Router::getRoutes();

foreach ($routes as $route) {
    echo $route->getMethod() . ' ' . $route->getUri() . ' - ' . $route->getName();
}
```

### Current Route

Get the currently matched route:

```php
$currentRoute = Router::getCurrentRoute();

if ($currentRoute) {
    $name = $currentRoute->getName();
    $uri = $currentRoute->getUri();
    $method = $currentRoute->getMethod();
}
```
