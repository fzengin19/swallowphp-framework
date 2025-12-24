# Middleware

Middleware provides a convenient mechanism for filtering HTTP requests entering your application.

## Table of Contents

- [How Middleware Works](#how-middleware-works)
- [Creating Middleware](#creating-middleware)
- [Applying Middleware to Routes](#applying-middleware-to-routes)
- [Built-in Middleware](#built-in-middleware)
  - [CSRF Protection](#csrf-protection)
  - [Rate Limiting](#rate-limiting)
  - [Content Security Policy](#content-security-policy)
  - [Validate Post Size](#validate-post-size)

---

## How Middleware Works

Middleware acts as a layer between the incoming request and your application. Each middleware can:

1. **Examine** the request before passing it along
2. **Modify** the request or response
3. **Terminate** the request early (e.g., return a redirect)
4. **Pass** the request to the next middleware

Middleware forms an "onion" around your route action:

```
Request → Middleware1 → Middleware2 → Route Action → Middleware2 → Middleware1 → Response
```

---

## Creating Middleware

Extend the `Middleware` base class and implement the `handle` method:

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
        // Before action logic
        if (!Auth::isAuthenticated()) {
            return redirect('/login');
        }
        
        // Pass to next middleware/action
        $response = $next($request);
        
        // After action logic (optional)
        // You can modify $response here
        
        return $response;
    }
}
```

### Base Middleware Class

The base `Middleware` class provides a simple passthrough:

```php
abstract class Middleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}
```

### Example: Admin Middleware

```php
<?php

namespace App\Middleware;

use SwallowPHP\Framework\Http\Middleware\Middleware;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Auth\Auth;
use Closure;

class AdminMiddleware extends Middleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Auth::user();
        
        if (!$user || !$user->is_admin) {
            session()->flash('error', 'Access denied.');
            redirectToRoute('home');
        }
        
        return $next($request);
    }
}
```

### Example: Logging Middleware

```php
<?php

namespace App\Middleware;

use SwallowPHP\Framework\Http\Middleware\Middleware;
use SwallowPHP\Framework\Http\Request;
use Closure;

class LogRequestMiddleware extends Middleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $start = microtime(true);
        
        // Log incoming request
        logger()->info('Request received', [
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'ip' => $request->getClientIp(),
        ]);
        
        // Execute request
        $response = $next($request);
        
        // Log response time
        $duration = round((microtime(true) - $start) * 1000, 2);
        logger()->info("Request completed in {$duration}ms");
        
        return $response;
    }
}
```

---

## Applying Middleware to Routes

Attach middleware to routes using the `middleware()` method:

```php
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

// Single middleware
Router::get('/dashboard', [DashboardController::class, 'index'])
    ->name('dashboard')
    ->middleware(new AuthMiddleware());

// Multiple middleware (executed in order added)
Router::get('/admin', [AdminController::class, 'index'])
    ->name('admin.dashboard')
    ->middleware(new AuthMiddleware())
    ->middleware(new AdminMiddleware());
```

### Execution Order

Middleware is executed in a stack (LIFO for the request, FIFO for the response):

```php
Router::get('/admin', ...)
    ->middleware(new AuthMiddleware())    // Runs 1st
    ->middleware(new LogMiddleware())     // Runs 2nd
    ->middleware(new AdminMiddleware());  // Runs 3rd
```

Request flow: `Auth → Log → Admin → Action`  
Response flow: `Action → Admin → Log → Auth`

---

## Built-in Middleware

### CSRF Protection

**Class:** `SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken`

CSRF (Cross-Site Request Forgery) protection is applied globally in `App::run()`.

#### How It Works

1. A unique token is stored in the user's session (`$_SESSION['_token']`)
2. Every POST/PUT/PATCH/DELETE request must include this token
3. Token can be sent as:
   - Form field: `_token`
   - Header: `X-CSRF-TOKEN`
   - Header: `X-XSRF-TOKEN`

#### Including Token in Forms

```php
<form method="POST" action="/submit">
    <?php csrf_field() ?>
    <!-- form fields -->
</form>
```

#### Including Token in AJAX

```php
<meta name="csrf-token" content="<?= csrf_token() ?>">

<script>
fetch('/api/data', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify(data)
});
</script>
```

#### Skipped Requests

CSRF verification is **skipped** for:
- GET requests
- HEAD requests
- OPTIONS requests

#### Excluding URIs

To exclude specific URIs from CSRF verification, extend the middleware:

```php
<?php

namespace App\Middleware;

use SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken as BaseVerifier;

class VerifyCsrfToken extends BaseVerifier
{
    protected array $except = [
        'api/*',
        'webhooks/*',
        'stripe/webhook',
    ];
}
```

Pattern matching:
- Exact match: `'/webhook'`
- Wildcard: `'/api/*'` (matches `/api`, `/api/users`, `/api/users/1`, etc.)

#### Token Mismatch

If token validation fails, `CsrfTokenMismatchException` is thrown (HTTP 419).

---

### Rate Limiting

**Class:** `SwallowPHP\Framework\Http\Middleware\RateLimiter`

Rate limiting is applied per-route using the `limit()` method.

#### Basic Usage

```php
// 100 requests per 60 seconds
Router::post('/api/search', [ApiController::class, 'search'])
    ->limit(100, 60);

// 10 requests per default TTL (from cache.ttl config)
Router::post('/api/login', [AuthController::class, 'login'])
    ->limit(10);
```

#### Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `$rateLimit` | int | Maximum requests allowed in the window |
| `$ttl` | int\|null | Window duration in seconds (default: `cache.ttl` config) |

#### How It Works

1. Request count tracked per IP + route combination
2. Data stored in cache with key: `rate_limit:{route}:{ip}`
3. When limit exceeded, `RateLimitExceededException` thrown (HTTP 429)

#### Response Headers

| Header | Description |
|--------|-------------|
| `X-RateLimit-Limit` | Maximum requests allowed |
| `X-RateLimit-Remaining` | Requests remaining in window |
| `Retry-After` | Seconds until reset (when exceeded) |

#### Handling Rate Limit Exceeded

The framework's exception handler automatically returns a 429 response. You can customize this in your `ExceptionHandler`.

---

### Content Security Policy

**Class:** `SwallowPHP\Framework\Http\Middleware\AddContentSecurityPolicyHeader`

CSP is applied globally in `App::run()` after the response is generated.

#### Configuration

Configure CSP in `config/security.php`:

```php
<?php

return [
    'enabled' => env('CSP_ENABLED', true),
    
    'directives' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
        'img-src' => ["'self'", 'data:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'object-src' => ["'none'"],
        'media-src' => ["'self'"],
        'frame-src' => ["'self'"],
        'frame-ancestors' => ["'self'"],
        'form-action' => ["'self'"],
        'base-uri' => ["'self'"],
    ],
];
```

#### Directive Types

**Array-based (sources):**
```php
'script-src' => ["'self'", 'https://cdn.example.com'],
// Output: script-src 'self' https://cdn.example.com
```

**Boolean (flags):**
```php
'upgrade-insecure-requests' => true,
// Output: upgrade-insecure-requests
```

**String (single value):**
```php
'report-uri' => '/csp-report',
// Output: report-uri /csp-report
```

#### Common Patterns

**Allow inline scripts (use with caution):**
```php
'script-src' => ["'self'", "'unsafe-inline'"],
```

**Allow Google Fonts:**
```php
'style-src' => ["'self'", 'https://fonts.googleapis.com'],
'font-src' => ["'self'", 'https://fonts.gstatic.com'],
```

**Allow images from anywhere:**
```php
'img-src' => ["'self'", 'data:', 'https:'],
```

**Allow external APIs:**
```php
'connect-src' => ["'self'", 'https://api.stripe.com'],
```

#### Output Header

The middleware generates a `Content-Security-Policy` header:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'
```

---

## Middleware Best Practices

1. **Keep middleware focused** - Each middleware should do one thing
2. **Order matters** - Place auth middleware before role-checking middleware
3. **Avoid database calls** - Keep middleware fast; defer heavy logic to controllers
4. **Use early returns** - Terminate early if conditions aren't met
5. **Log appropriately** - Use debug/info levels, not warning/error for normal flow

---

### Validate Post Size

**Class:** `SwallowPHP\Framework\Http\Middleware\ValidatePostSize`

This middleware validates that incoming POST requests don't exceed PHP's configured upload limits. It's applied globally in `App::run()`.

#### How It Works

1. Checks `CONTENT_LENGTH` header against `post_max_size` PHP setting
2. For multipart uploads, also validates against `upload_max_filesize`
3. If exceeded, throws `PayloadTooLargeException` (HTTP 413)

#### Why This Matters

Without this middleware, oversized uploads would fail silently or trigger misleading errors (like CSRF token mismatch). The middleware provides clear 413 responses instead.

#### Configuration

The limits are determined by your `php.ini` settings:

```ini
; Maximum size of POST data
post_max_size = 8M

; Maximum upload file size
upload_max_filesize = 2M
```

#### Response

When a request exceeds the limit:

```
HTTP/1.1 413 Payload Too Large
Content-Type: application/json

{
    "error": "Payload Too Large",
    "message": "The uploaded content exceeds the server limit of 8M."
}
```
