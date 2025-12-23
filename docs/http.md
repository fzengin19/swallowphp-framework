# HTTP Layer

SwallowPHP provides robust classes for handling HTTP requests, responses, and secure cookies.

## Table of Contents

- [Request](#request)
  - [Creating Requests](#creating-requests)
  - [Accessing Input Data](#accessing-input-data)
  - [Request Information](#request-information)
  - [Headers](#headers)
  - [File Uploads](#file-uploads)
- [Response](#response)
  - [Creating Responses](#creating-responses)
  - [Response Methods](#response-methods)
  - [Factory Methods](#factory-methods)
- [Cookies](#cookies)
  - [Setting Cookies](#setting-cookies)
  - [Getting Cookies](#getting-cookies)
  - [Deleting Cookies](#deleting-cookies)
  - [Cookie Security](#cookie-security)

---

## Request

The `Request` class encapsulates all data from an incoming HTTP request.

### Creating Requests

Requests are automatically created and available via dependency injection or the helper:

```php
// Using helper function
$request = request();

// Via dependency injection in controllers
public function store(Request $request)
{
    $name = $request->get('name');
}
```

### Accessing Input Data

#### Get a Single Value

```php
// Check body first, then query parameters
$email = $request->get('email');

// With default value
$page = $request->get('page', 1);

// Query parameter only
$sort = $request->getQuery('sort', 'desc');

// Request body only
$title = $request->getRequestValue('title');
```

#### Get All Input

```php
// All combined (body + query)
$all = $request->all();

// Query parameters only
$query = $request->query();

// Request body only
$body = $request->request();
```

#### Set Input Values

```php
// Set a single value
$request->set('processed', true);

// Override all body data
$request->setAll($newData);
```

### Request Information

```php
// HTTP method (with _method override support)
$method = $request->getMethod();  // GET, POST, PUT, PATCH, DELETE

// Request URI (with query string)
$uri = $request->getUri();  // /users?page=2

// Request path (without query string)
$path = $request->getPath();  // /users

// Scheme (http or https)
$scheme = $request->getScheme();

// Host name
$host = $request->getHost();  // example.com

// Full URL
$fullUrl = $request->fullUrl();  // https://example.com/users?page=2

// Client IP address
$ip = $request->getClientIp();  // 192.168.1.1

// Raw request body
$raw = $request->rawInput();
```

### Headers

```php
// Get a single header (case-insensitive)
$contentType = $request->header('Content-Type');
$accept = $request->header('accept', 'text/html');

// Get all headers
$headers = $request->headers();

// Get bearer token from Authorization header
$token = $request->bearerToken();

// Get server variable
$serverName = $request->server('SERVER_NAME');
```

### File Uploads

```php
// Get all uploaded files
$files = $request->files();

// Access specific file
if (isset($files['avatar'])) {
    $file = $files['avatar'];
    $tmpName = $file['tmp_name'];
    $originalName = $file['name'];
    $size = $file['size'];
    $error = $file['error'];
    $mimeType = $file['type'];
    
    if ($error === UPLOAD_ERR_OK) {
        move_uploaded_file($tmpName, '/path/to/uploads/' . $originalName);
    }
}
```

### Method Spoofing

For forms that need PUT, PATCH, or DELETE:

```php
<form method="POST" action="/users/1">
    <input type="hidden" name="_method" value="DELETE">
    <!-- form fields -->
</form>
```

The framework automatically reads `_method` for POST requests:

```php
// Returns 'DELETE' if _method=DELETE in POST body
$method = $request->getMethod();
```

---

## Response

The `Response` class constructs HTTP responses to send to the client.

### Creating Responses

```php
use SwallowPHP\Framework\Http\Response;

// Basic response
$response = new Response('Hello World', 200);

// With headers
$response = new Response('Content', 200, [
    'X-Custom-Header' => 'value',
]);
```

### Response Methods

#### Set Content

```php
$response->setContent('New content');
$content = $response->getContent();
```

#### Set Status Code

```php
$response->setStatusCode(404);
$code = $response->getStatusCode();

// Available status codes (see Response::STATUS_TEXTS)
// 200 OK, 201 Created, 204 No Content
// 301 Moved Permanently, 302 Found, 304 Not Modified
// 400 Bad Request, 401 Unauthorized, 403 Forbidden, 404 Not Found
// 500 Internal Server Error, 503 Service Unavailable
// ... and many more
```

#### Set Headers

```php
// Set/replace a header
$response->header('Content-Type', 'application/xml');

// Append to existing header
$response->header('Set-Cookie', 'another=value', false);

// Get a header
$type = $response->getHeader('Content-Type');

// Get all headers
$headers = $response->getHeaders();
```

#### Send Response

```php
// Send headers and content
$response->send();

// Send only headers
$response->sendHeaders();

// Send only content
$response->sendContent();
```

### Factory Methods

#### JSON Response

```php
return Response::json([
    'success' => true,
    'data' => $userData,
]);

// With status code
return Response::json(['error' => 'Not found'], 404);

// With custom headers
return Response::json($data, 200, [
    'X-Total-Count' => 100,
]);
```

#### HTML Response

```php
return Response::html('<h1>Hello World</h1>');

return Response::html($htmlContent, 200, [
    'X-Custom' => 'value',
]);
```

#### Redirect Response

```php
return Response::redirect('/dashboard');

// Permanent redirect
return Response::redirect('/new-url', 301);

// With headers
return Response::redirect('/login', 302, [
    'X-Reason' => 'session_expired',
]);
```

### Default Security Headers

Responses automatically include these security headers:

| Header | Value | Purpose |
|--------|-------|---------|
| `X-Content-Type-Options` | `nosniff` | Prevent MIME sniffing |
| `X-Frame-Options` | `SAMEORIGIN` | Clickjacking protection |
| `X-XSS-Protection` | `1; mode=block` | XSS filter |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Referrer control |

---

## Cookies

The `Cookie` class provides secure, encrypted cookie management.

### Setting Cookies

```php
use SwallowPHP\Framework\Http\Cookie;

// Basic cookie (session cookie, expires when browser closes)
Cookie::set('preferences', ['theme' => 'dark']);

// Cookie with expiration (days)
Cookie::set('remember_token', $token, 30);  // 30 days

// With all options
Cookie::set(
    'user_prefs',           // name
    ['lang' => 'tr'],       // value (auto JSON encoded)
    365,                    // days
    '/app',                 // path
    'example.com',          // domain
    true,                   // secure (HTTPS only)
    true,                   // httpOnly
    'Strict'                // sameSite
);
```

### Cookie Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `name` | string | - | Cookie name |
| `value` | mixed | - | Value (JSON encoded automatically) |
| `days` | int | `0` | Days until expiration (0 = session) |
| `path` | string | `'/'` | Cookie path |
| `domain` | string | `null` | Cookie domain |
| `secure` | bool | auto | HTTPS only |
| `httpOnly` | bool | `true` | JavaScript inaccessible |
| `sameSite` | string | `'Lax'` | SameSite policy |

### Getting Cookies

```php
// Get cookie value (auto decrypted and JSON decoded)
$preferences = Cookie::get('preferences');

// With default value
$theme = Cookie::get('theme', 'light');

// Check if cookie exists
if (Cookie::has('remember_token')) {
    $token = Cookie::get('remember_token');
}
```

### Deleting Cookies

```php
// Delete by name
Cookie::delete('remember_token');

// With path and domain (must match original)
Cookie::delete('user_prefs', '/app', 'example.com');
```

### Cookie Security

#### Encryption

All cookie values are encrypted using AES-256-CBC:

1. Value is JSON encoded
2. Random 16-byte IV generated
3. Encrypted with `APP_KEY`
4. HMAC-SHA256 signature appended
5. Base64 encoded

#### APP_KEY Requirement

You must set a 32-byte `APP_KEY` in your `.env`:

```env
# Generate with: php -r "echo 'base64:' . base64_encode(random_bytes(32));"
APP_KEY=base64:your-32-byte-key-here
```

#### Secure Prefix

When `secure=true`, cookies are prefixed with `__Secure-`:

```
__Secure-remember_token=encrypted_value
```

This provides additional browser protection.

#### SameSite Policy

| Value | Behavior |
|-------|----------|
| `Lax` | Sent with same-site requests and top-level navigations |
| `Strict` | Only sent with same-site requests |
| `None` | Sent with all requests (requires `secure=true`) |

---

## Complete Examples

### API Controller

```php
<?php

namespace App\Controllers;

use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Http\Response;

class ApiController
{
    public function index(Request $request)
    {
        $page = $request->getQuery('page', 1);
        $limit = $request->getQuery('limit', 10);
        
        $users = User::query()
            ->paginate($limit, ['*'], 'page', $page);
        
        return Response::json([
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'total' => $users->total(),
            ],
        ]);
    }
    
    public function store(Request $request)
    {
        // Validate bearer token
        $token = $request->bearerToken();
        if (!$this->validateToken($token)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }
        
        // Get JSON body
        $data = $request->all();
        
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);
        
        return Response::json([
            'success' => true,
            'data' => $user,
        ], 201);
    }
}
```

### Remember Me Implementation

```php
use SwallowPHP\Framework\Http\Cookie;

// On login with "remember me"
$token = bin2hex(random_bytes(32));
Cookie::set('remember_me', [
    'user_id' => $user->id,
    'token' => hash('sha256', $token),
], 30);  // 30 days

// On subsequent requests
$remember = Cookie::get('remember_me');
if ($remember) {
    $user = User::find($remember['user_id']);
    if ($user && hash_equals($user->remember_token, $remember['token'])) {
        // Log user in
    }
}

// On logout
Cookie::delete('remember_me');
```

---

## API Reference

### Request Methods

| Method | Return | Description |
|--------|--------|-------------|
| `get($key, $default)` | mixed | Get input value (body then query) |
| `getQuery($key, $default)` | mixed | Get query parameter |
| `getRequestValue($key, $default)` | mixed | Get body parameter |
| `all()` | array | Get all input (body + query) |
| `query()` | array | Get all query parameters |
| `request()` | array | Get all body parameters |
| `files()` | array | Get uploaded files |
| `header($key, $default)` | string\|null | Get header value |
| `headers()` | array | Get all headers |
| `bearerToken()` | string\|null | Get bearer token |
| `getUri()` | string | Get request URI |
| `getPath()` | string | Get request path |
| `getMethod()` | string | Get HTTP method |
| `getScheme()` | string | Get scheme (http/https) |
| `getHost()` | string | Get host name |
| `fullUrl()` | string | Get full URL |
| `getClientIp()` | string\|null | Get client IP |
| `rawInput()` | string | Get raw body |
| `server($key, $default)` | mixed | Get server variable |

### Response Methods

| Method | Return | Description |
|--------|--------|-------------|
| `setContent($content)` | self | Set response content |
| `getContent()` | mixed | Get response content |
| `setStatusCode($code)` | self | Set HTTP status code |
| `getStatusCode()` | int | Get HTTP status code |
| `header($key, $value, $replace)` | self | Set response header |
| `getHeader($key, $default)` | string\|null | Get response header |
| `getHeaders()` | array | Get all headers |
| `send()` | self | Send headers and content |
| `sendHeaders()` | self | Send headers only |
| `sendContent()` | self | Send content only |
| `json($data, $status, $headers)` | static | Create JSON response |
| `html($content, $status, $headers)` | static | Create HTML response |
| `redirect($url, $status, $headers)` | static | Create redirect response |

### Cookie Methods

| Method | Return | Description |
|--------|--------|-------------|
| `set($name, $value, ...)` | bool | Set encrypted cookie |
| `get($name, $default)` | mixed | Get decrypted cookie |
| `has($name)` | bool | Check if cookie exists |
| `delete($name, $path, $domain)` | bool | Delete cookie |
