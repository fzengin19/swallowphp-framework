# Helper Functions

SwallowPHP provides a comprehensive set of global helper functions for common tasks.

## Table of Contents

- [Configuration & Environment](#configuration--environment)
- [Routing](#routing)
- [Request & Response](#request--response)
- [Database](#database)
- [Session & Flash](#session--flash)
- [Cache](#cache)
- [Logging](#logging)
- [Views](#views)
- [Security](#security)
- [String & Text](#string--text)
- [Date & Time](#date--time)
- [Images](#images)
- [Email](#email)

---

## Configuration & Environment

### `config($key = null, $default = null)`

Get or set configuration values.

```php
// Get a value
$appName = config('app.name');

// Get with default
$timezone = config('app.timezone', 'UTC');

// Get nested value (dot notation)
$dbHost = config('database.connections.mysql.host');

// Get entire config file
$appConfig = config('app');

// Get Config instance
$configInstance = config();

// Set values at runtime
config(['app.debug' => true]);

// Set multiple values
config([
    'app.debug' => true,
    'app.env' => 'testing',
]);
```

### `env($key, $default = null)`

Get environment variable value.

```php
$debug = env('APP_DEBUG', false);
$dbHost = env('DB_HOST', '127.0.0.1');
$apiKey = env('API_KEY');  // Returns null if not set
```

**Lookup Order:**
1. `$_ENV[$key]`
2. `$_SERVER[$key]`
3. `getenv($key)`
4. Returns `$default`

---

## Routing

### `route($name, $params = [])`

Generate URL for a named route.

```php
// Basic route
$homeUrl = route('home');
// http://localhost/

// With path parameter
$userUrl = route('users.show', ['id' => 123]);
// http://localhost/users/123

// Extra params become query string
$searchUrl = route('search', ['q' => 'php', 'page' => 2]);
// http://localhost/search?q=php&page=2
```

### `hasRoute($name)`

Check if a named route exists.

```php
if (hasRoute('admin.dashboard')) {
    echo route('admin.dashboard');
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
    echo 'In user area';
}

// Use in navigation for active state
<li class="<?= isRoute('home') ? 'active' : '' ?>">
    <a href="<?= route('home') ?>">Home</a>
</li>
```

### `redirect($uri, $code = 302)`

Redirect to a URI and exit.

```php
redirect('/login');
redirect('/dashboard', 301);  // Permanent redirect
```

### `redirectToRoute($name, $params = [])`

Redirect to a named route and exit.

```php
redirectToRoute('home');
redirectToRoute('users.show', ['id' => 1]);
```

**Note:** This function also sends any queued cookies before redirecting.

---

## Request & Response

### `request()`

Get the current Request instance.

```php
$request = request();

// Access request data
$email = request()->get('email');
$all = request()->all();

// Query parameters
$page = request()->query('page', 1);

// Headers
$contentType = request()->header('Content-Type');

// Method and path
$method = request()->getMethod();
$path = request()->getPath();
```

### `getIp()`

Get the client's IP address.

```php
$ip = getIp();
// Returns: '192.168.1.1' or null
```

### `sendJson($data, $status = 200)`

Send a JSON response.

```php
sendJson(['success' => true, 'message' => 'Created']);
sendJson(['error' => 'Not found'], 404);
```

**Note:** This function does not exit. Consider using `Response::json()` instead.

---

## Database

### `db()`

Get the Database query builder instance.

```php
// Basic queries
$users = db()->table('users')->get();

// With conditions
$user = db()->table('users')
    ->where('email', $email)
    ->first();

// Insert
$id = db()->table('posts')->insert([
    'title' => 'Hello World',
    'body' => 'Content here',
]);

// Update
db()->table('posts')
    ->where('id', 1)
    ->update(['title' => 'Updated Title']);

// Delete
db()->table('posts')
    ->where('id', 1)
    ->delete();
```

---

## Session & Flash

### `session($key = null, $default = null)`

Get the SessionManager instance or get/set session values.

```php
// Get SessionManager instance
$session = session();

// Get a value
$userId = session('user_id');

// Get with default
$cart = session('cart', []);

// Set values
session(['user_id' => 123]);
session(['cart' => $cartData, 'locale' => 'en']);

// Using SessionManager methods
session()->put('key', 'value');
session()->get('key');
session()->has('key');
session()->remove('key');
session()->all();
session()->regenerate();
```

### `flash($key, $value)`

Flash a message to the session.

```php
flash('success', 'Profile updated!');
flash('error', 'Invalid credentials.');
flash('warning', 'Please verify your email.');

// In view
<?php if (session('success')): ?>
    <div class="alert alert-success"><?= session('success') ?></div>
<?php endif ?>
```

**Note:** Flash data is available only for the next request.

---

## Cache

### `cache($driver = null)`

Get a cache instance.

```php
// Get default cache
$cache = cache();

// Get specific driver
$fileCache = cache('file');
$sqliteCache = cache('sqlite');

// Cache operations
$value = cache()->get('key', 'default');
cache()->set('key', 'value', 3600);  // TTL in seconds
cache()->has('key');
cache()->delete('key');
cache()->clear();

// Get or set pattern
$users = cache()->get('users');
if ($users === null) {
    $users = User::all();
    cache()->set('users', $users, 600);
}
```

---

## Logging

### `logger()`

Get the PSR-3 Logger instance.

```php
logger()->debug('Debug message', ['context' => 'data']);
logger()->info('User logged in', ['user_id' => 123]);
logger()->notice('New registration');
logger()->warning('Slow query detected', ['sql' => $query]);
logger()->error('Payment failed', ['order_id' => 456]);
logger()->critical('Database connection lost');
logger()->alert('System overload');
logger()->emergency('Application crashed');
```

**Log Levels (from least to most severe):**
1. DEBUG
2. INFO
3. NOTICE
4. WARNING
5. ERROR
6. CRITICAL
7. ALERT
8. EMERGENCY

---

## Views

### `view($view, $data = [], $layout = null, $status = 200)`

Render a view file and return an HTML Response.

```php
// Basic view
return view('welcome');

// With data
return view('users.show', ['user' => $user]);

// With layout
return view('admin.dashboard', ['stats' => $stats], 'layouts.admin');

// With custom status
return view('errors.404', [], null, 404);
```

**View Resolution:**
1. Looks in `config('app.view_path')` first (app views)
2. Falls back to framework views
3. Throws `ViewNotFoundException` if not found

**Dot Notation:**
- `'users.index'` → `resources/views/users/index.php`
- `'layouts.main'` → `resources/views/layouts/main.php`

**Layout Usage:**

In layout file (`layouts/main.php`):
```php
<!DOCTYPE html>
<html>
<head>
    <title><?= $title ?? 'My App' ?></title>
</head>
<body>
    <?= $slot ?>  <!-- View content goes here -->
</body>
</html>
```

**HTML Minification:**

If `config('app.minify_html')` is `true`, the output is automatically minified.

---

## Security

### `csrf_field()`

Generate a CSRF hidden input field.

```php
<form method="POST" action="/login">
    <?php csrf_field() ?>
    <input type="email" name="email">
    <button type="submit">Login</button>
</form>
```

Output:
```html
<input type="hidden" name="_token" value="abc123...">
```

### `csrf_token()`

Get the CSRF token value.

```php
// For AJAX requests
<meta name="csrf-token" content="<?= csrf_token() ?>">

// JavaScript
fetch('/api/data', {
    headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    }
});
```

### `method($method)`

Generate a hidden input for HTTP method spoofing.

```php
<form method="POST" action="/users/1">
    <?php csrf_field() ?>
    <?php method('DELETE') ?>
    <button type="submit">Delete User</button>
</form>
```

Output:
```html
<input type="hidden" name="_method" value="DELETE">
```

---

## String & Text

### `slug($value)`

Convert a string to a URL-friendly slug.

```php
echo slug('Hello World');           // hello-world
echo slug('Türkçe Karakterler');    // turkce-karakterler
echo slug('My Post Title!');        // my-post-title
echo slug('  Multiple   Spaces ');  // multiple-spaces
```

**Turkish Character Support:**
- ç → c, ğ → g, ı → i, ö → o, ş → s, ü → u
- Uppercase variants also converted

### `shortenText($text, $length)`

Shorten text to a specified length with ellipsis.

```php
$excerpt = shortenText($post->content, 150);
// "This is a very long text that will be..." 

// HTML tags are stripped
$clean = shortenText('<p>Hello <strong>World</strong></p>', 10);
// "Hello Worl..."
```

### `minifyHtml($html)`

Minify HTML, CSS, and JavaScript content.

```php
$minified = minifyHtml($htmlContent);
```

**What it does:**
- Removes HTML comments
- Collapses whitespace between tags
- Minifies inline `<style>` blocks
- Removes comment lines from `<script>` blocks
- Preserves `<pre>` and `<textarea>` content

---

## Date & Time

### `formatDateForHumans($datetime)`

Format a date/time as human-readable relative time.

```php
echo formatDateForHumans('2024-01-15 10:30:00');
// "5 dakika önce" (5 minutes ago)
// "2 saat önce" (2 hours ago)
// "3 gün önce" (3 days ago)
// "15 January 2024" (if older than 7 days)

// Also accepts DateTime objects
echo formatDateForHumans(new DateTime('yesterday'));
// "1 gün önce"
```

**Time Ranges:**
- < 60 seconds: "X saniye önce"
- < 60 minutes: "X dakika önce"
- < 24 hours: "X saat önce"
- < 7 days: "X gün önce"
- >= 7 days: "d F Y" format

---

## Images

### `webpImage($source, $quality, $removeOld, $fileName, $destinationDir)`

Convert an image to AVIF or WebP format.

```php
// Basic usage
$newName = webpImage('/path/to/image.jpg');
// Returns: 'abc123def456.avif' or 'abc123def456.webp'

// With options
$newName = webpImage(
    '/path/to/image.jpg',  // Source file
    75,                     // Quality (0-100)
    true,                   // Delete original
    'my-image',             // Output filename (without extension)
    'uploads/'              // Destination directory
);
```

**Conversion Priority:**
1. Tries AVIF first (if `imageavif()` available)
2. Falls back to WebP (if `imagewebp()` available)
3. Returns original path on failure

**Supported Input Formats:**
- JPEG, PNG, GIF, WebP, AVIF

**Features:**
- Preserves alpha channel (transparency)
- Validates file size (max 20MB)
- Secure filename generation
- Creates destination directory if needed

### `getFile($name)`

Get the public URL for a file.

```php
$imageUrl = getFile('profile.jpg');
// http://localhost/files/profile.jpg

$docUrl = getFile('documents/report.pdf');
// http://localhost/files/documents/report.pdf
```

---

## Email

### `mailto($to, $subject, $message, $headers = [])`

Send email using PHPMailer via SMTP.

```php
// Single recipient
$sent = mailto(
    'user@example.com',
    'Welcome!',
    '<h1>Hello</h1><p>Welcome to our site!</p>'
);

// Multiple recipients
$sent = mailto(
    ['user1@example.com', 'user2@example.com'],
    'Newsletter',
    $newsletterHtml
);

// With custom headers
$sent = mailto(
    'user@example.com',
    'Report',
    $reportHtml,
    ['X-Priority' => '1', 'X-Custom' => 'value']
);
```

**Configuration Required:**

Create `config/mail.php`:
```php
<?php

return [
    'mailers' => [
        'smtp' => [
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'autotls' => true,
        ],
    ],
    
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],
    
    'timeout' => 10,
    'max_recipients_per_mail' => 50,  // For bulk sending
];
```

**Bulk Email Handling:**
- Single recipient: sent directly with `addAddress()`
- Multiple recipients: batched using BCC (respects `max_recipients_per_mail`)

**Returns:** `true` if all emails sent, `false` on any failure

---

## Quick Reference

| Function | Purpose |
|----------|---------|
| `config()` | Configuration access |
| `env()` | Environment variables |
| `route()` | Generate route URL |
| `hasRoute()` | Check route exists |
| `isRoute()` | Check current route |
| `redirect()` | Redirect to URL |
| `redirectToRoute()` | Redirect to named route |
| `request()` | Get Request instance |
| `getIp()` | Get client IP |
| `sendJson()` | Send JSON response |
| `db()` | Get Database instance |
| `session()` | Session access |
| `flash()` | Flash message |
| `cache()` | Cache access |
| `logger()` | PSR-3 logger |
| `view()` | Render view |
| `csrf_field()` | CSRF hidden input |
| `csrf_token()` | CSRF token value |
| `method()` | HTTP method input |
| `slug()` | URL-friendly string |
| `shortenText()` | Truncate text |
| `minifyHtml()` | Minify HTML |
| `formatDateForHumans()` | Relative date |
| `webpImage()` | Image conversion |
| `getFile()` | File URL |
| `mailto()` | Send email |
