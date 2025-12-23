# Session Management

SwallowPHP provides a robust session management system with flash messages and custom handler support.

## Table of Contents

- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Flash Messages](#flash-messages)
- [Session Security](#session-security)
- [Session Lifecycle](#session-lifecycle)

---

## Configuration

Configure sessions in `config/session.php`:

```php
<?php

return [
    // Session driver: 'file' (more drivers coming)
    'driver' => env('SESSION_DRIVER', 'file'),
    
    // Session lifetime in minutes
    'lifetime' => env('SESSION_LIFETIME', 120),
    
    // Destroy session when browser closes
    'expire_on_close' => false,
    
    // File storage path (for 'file' driver)
    'files' => 'framework/sessions',  // Relative to storage_path
    
    // File permissions (for 'file' driver)
    'file_permission' => 0600,
    
    // Cookie settings
    'cookie' => env('SESSION_COOKIE', 'swallow_session'),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN', null),
    'secure' => env('SESSION_SECURE', null),  // Auto-detect if null
    'http_only' => true,
    'same_site' => 'Lax',  // 'Strict', 'Lax', or 'None'
    
    // Garbage collection lottery (1 in X chance)
    'lottery' => [2, 100],
];
```

### Configuration Reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `driver` | string | `'file'` | Session storage driver |
| `lifetime` | int | `120` | Session lifetime in minutes |
| `expire_on_close` | bool | `false` | Expire when browser closes |
| `files` | string | - | Path for file session storage |
| `file_permission` | octal | `0600` | File permissions (file driver) |
| `cookie` | string | `'swallow_session'` | Cookie name |
| `path` | string | `'/'` | Cookie path |
| `domain` | string\|null | `null` | Cookie domain |
| `secure` | bool\|null | `null` | HTTPS only (auto-detect) |
| `http_only` | bool | `true` | JavaScript inaccessible |
| `same_site` | string | `'Lax'` | SameSite cookie policy |

### Secure Cookie Handling

If `secure` is `null`, the framework:
1. Defaults to `true` in production environment
2. Automatically sets to `false` if current request is not HTTPS

This prevents cookie loss when testing locally over HTTP.

---

## Basic Usage

### Using the Helper Function

```php
// Get SessionManager instance
$session = session();

// Get a value
$userId = session('user_id');

// Get with default
$locale = session('locale', 'en');

// Set values
session(['user_id' => 123]);
session(['cart' => $items, 'locale' => 'tr']);
```

### Using SessionManager Methods

```php
$session = session();

// Put a value
$session->put('key', 'value');
$session->put('user', ['id' => 1, 'name' => 'John']);

// Get a value
$value = $session->get('key');
$user = $session->get('user', []);

// Check existence
if ($session->has('user')) {
    // User data exists
}

// Remove a value
$session->remove('key');

// Get all session data
$all = $session->all();
```

---

## Flash Messages

Flash data is available only for the next request. Perfect for success/error messages.

### Flashing Data

```php
// Flash a message
session()->flash('success', 'Profile updated!');
session()->flash('error', 'Invalid credentials.');

// Or use the helper
flash('warning', 'Your subscription expires soon.');

// Flash complex data
session()->flash('errors', [
    'email' => 'Invalid email format',
    'password' => 'Password too short',
]);
```

### Reading Flash Data

```php
// Check if flash exists
if (session()->hasFlash('success')) {
    echo session()->getFlash('success');
}

// With default value
$message = session()->getFlash('error', 'Unknown error');

// Also accessible via session() helper
if (session('success')) {
    echo session('success');
}
```

### In Views

```php
<?php if (session('success')): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars(session('success')) ?>
    </div>
<?php endif ?>

<?php if (session('error')): ?>
    <div class="alert alert-danger">
        <?= htmlspecialchars(session('error')) ?>
    </div>
<?php endif ?>

<?php if (session('errors')): ?>
    <ul class="errors">
        <?php foreach (session('errors') as $field => $message): ?>
            <li><?= htmlspecialchars($message) ?></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
```

### Keeping Flash Data

By default, flash data is removed after being read. To persist for another request:

```php
// Keep all flash data
session()->reflash();

// Keep specific keys only
session()->keep('success');
session()->keep(['success', 'warning']);
```

### Flash Data Lifecycle

1. **Request 1:** `flash('success', 'Saved!')` → Stored in `_flash.new`
2. **Request 2:** `session('success')` → Read from `_flash.old`, returns `'Saved!'`
3. **Request 3:** `session('success')` → Returns `null` (data expired)

---

## Session Security

### Regenerating Session ID

Regenerate the session ID to prevent session fixation attacks:

```php
// Regenerate and delete old session
session()->regenerate(true);

// Regenerate but keep old session
session()->regenerate(false);
```

**When to regenerate:**
- After successful login
- After privilege level changes
- Periodically for long sessions

### Session Destruction

Completely destroy the session:

```php
session()->destroy();
```

**What happens:**
1. `$_SESSION` array is cleared
2. Session cookie is deleted
3. Session file is destroyed
4. Session ID is invalidated

### Token Storage

Store sensitive tokens in the session:

```php
// CSRF token (handled automatically)
$_SESSION['_token'] = bin2hex(random_bytes(32));

// Custom tokens
session()->put('2fa_verified', true);
session()->put('2fa_expires', time() + 300);
```

---

## Session Lifecycle

### Starting the Session

Sessions are started automatically by `App::run()`. Manual start:

```php
$started = session()->start();

if (!$started) {
    // Headers already sent or handler error
}
```

### Session States

Check session status:

```php
// PHP native
$status = session_status();
// PHP_SESSION_DISABLED, PHP_SESSION_NONE, PHP_SESSION_ACTIVE

// Check if active
if (session_status() === PHP_SESSION_ACTIVE) {
    // Session is running
}
```

### Session ID

Access the current session ID:

```php
$sessionId = session_id();
```

---

## Complete Example

### Login Flow with Session

```php
class AuthController
{
    public function login(Request $request)
    {
        $email = $request->get('email');
        $password = $request->get('password');
        
        $user = User::where('email', $email)->first();
        
        if (!$user || !password_verify($password, $user->password)) {
            session()->flash('error', 'Invalid credentials.');
            redirectToRoute('login.form');
        }
        
        // Regenerate session ID on login
        session()->regenerate(true);
        
        // Store user in session
        session()->put('user_id', $user->id);
        session()->put('user_name', $user->name);
        
        // Flash success message
        session()->flash('success', 'Welcome back, ' . $user->name . '!');
        
        redirectToRoute('dashboard');
    }
    
    public function logout()
    {
        session()->destroy();
        session()->flash('success', 'You have been logged out.');
        redirectToRoute('home');
    }
}
```

### Shopping Cart

```php
// Add to cart
$cart = session('cart', []);
$cart[$productId] = [
    'quantity' => ($cart[$productId]['quantity'] ?? 0) + 1,
    'price' => $product->price,
];
session(['cart' => $cart]);

// Get cart
$cart = session('cart', []);
$total = array_sum(array_map(fn($item) => $item['quantity'] * $item['price'], $cart));

// Clear cart
session()->remove('cart');
```

---

## API Reference

### SessionManager Methods

| Method | Return | Description |
|--------|--------|-------------|
| `start()` | bool | Start the session |
| `get($key, $default)` | mixed | Get a session value |
| `put($key, $value)` | void | Set a session value |
| `has($key)` | bool | Check if key exists |
| `remove($key)` | void | Remove a session value |
| `all()` | array | Get all session data |
| `flash($key, $value)` | void | Flash data for next request |
| `getFlash($key, $default)` | mixed | Get flashed data |
| `hasFlash($key)` | bool | Check if flash key exists |
| `reflash()` | void | Keep all flash data |
| `keep($keys)` | void | Keep specific flash keys |
| `regenerate($delete)` | bool | Regenerate session ID |
| `destroy()` | bool | Destroy the session |
