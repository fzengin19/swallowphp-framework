# Authentication

SwallowPHP provides a built-in authentication system with session-based login, "remember me" functionality, and brute-force protection.

## Table of Contents

- [Configuration](#configuration)
- [Setting Up User Model](#setting-up-user-model)
- [Authentication Methods](#authentication-methods)
  - [Authenticate](#authenticate)
  - [Check Authentication](#check-authentication)
  - [Get Current User](#get-current-user)
  - [Logout](#logout)
- [Remember Me](#remember-me)
- [Brute-Force Protection](#brute-force-protection)
- [Database Schema](#database-schema)
- [Example Implementation](#example-implementation)

---

## Configuration

Configure authentication in `config/auth.php`:

```php
<?php

return [
    // User model class (required)
    'model' => \App\Models\User::class,
    
    // Brute-force protection
    'max_attempts' => 5,           // Max failed attempts before lockout
    'lockout_time' => 900,         // Lockout duration in seconds (15 min)
    
    // Remember me
    'remember_lifetime' => 43200,  // Token lifetime in minutes (30 days)
];
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `model` | string | `null` | **Required**. User model class name |
| `max_attempts` | int | `5` | Maximum login attempts before lockout |
| `lockout_time` | int | `900` | Lockout duration in seconds |
| `remember_lifetime` | int | `43200` | Remember me token lifetime in minutes |

---

## Setting Up User Model

### 1. Extend AuthenticatableModel

Your User model must extend `AuthenticatableModel`:

```php
<?php

namespace App\Models;

use SwallowPHP\Framework\Auth\AuthenticatableModel;

class User extends AuthenticatableModel
{
    protected static string $table = 'users';
    
    protected array $fillable = [
        'name',
        'email',
        'password',
    ];
    
    protected array $hidden = [
        'password',
        'remember_token',
    ];
    
    protected array $casts = [
        'id' => 'integer',
        'email_verified_at' => 'datetime',
    ];
}
```

### 2. AuthenticatableInterface Methods

Your model inherits these methods from `AuthenticatableTrait`:

| Method | Returns | Description |
|--------|---------|-------------|
| `getAuthIdentifierName()` | `string` | Primary key column name (default: `'id'`) |
| `getAuthIdentifier()` | `mixed` | User's primary key value |
| `getAuthPassword()` | `string` | User's hashed password |
| `getRememberTokenName()` | `string` | Token column name (default: `'remember_token'`) |
| `getRememberToken()` | `string\|null` | Current remember token |
| `setRememberToken($value)` | `void` | Set remember token |

### 3. Overriding Defaults

Override trait methods if your schema differs:

```php
class User extends AuthenticatableModel
{
    // If your primary key isn't 'id'
    public function getAuthIdentifierName(): string
    {
        return 'user_id';
    }
    
    // If your password column isn't 'password'
    public function getAuthPassword(): string
    {
        return $this->password_hash ?? '';
    }
    
    // If your token column isn't 'remember_token'
    public function getRememberTokenName(): string
    {
        return 'auth_token';
    }
}
```

---

## Authentication Methods

### Authenticate

Attempt to log in a user with email and password:

```php
use SwallowPHP\Framework\Auth\Auth;

// Basic authentication
$success = Auth::authenticate($email, $password);

if ($success) {
    redirectToRoute('dashboard');
} else {
    // Invalid credentials
    session()->flash('error', 'Invalid email or password.');
}

// With "remember me" option
$success = Auth::authenticate($email, $password, $remember = true);
```

**What `authenticate()` does:**
1. Checks brute-force lockout status
2. Finds user by email
3. Verifies password using `password_verify()`
4. Regenerates session ID (security)
5. Stores user ID in session
6. Clears failed attempt counter
7. Sets remember me cookie (if enabled)

**Throws:** `AuthenticationLockoutException` if account is locked.

### Check Authentication

Check if a user is currently logged in:

```php
use SwallowPHP\Framework\Auth\Auth;

if (Auth::isAuthenticated()) {
    // User is logged in
}

// Or use the helper function
if (auth()->check()) {
    // User is logged in
}
```

**What `isAuthenticated()` does:**
1. Returns `true` if user already loaded in memory
2. Checks "remember me" cookie first
3. Falls back to session check
4. Loads user from database
5. Caches user instance for subsequent calls

### Get Current User

Get the authenticated user instance:

```php
use SwallowPHP\Framework\Auth\Auth;

$user = Auth::user();

if ($user) {
    echo "Welcome, {$user->name}!";
    echo "Email: {$user->email}";
}

// Or use the helper function
$user = auth()->user();
```

**Returns:** `AuthenticatableModel|null`

### Logout

Log out the current user:

```php
use SwallowPHP\Framework\Auth\Auth;

Auth::logout();
redirectToRoute('login');
```

**What `logout()` does:**
1. Removes user ID from session
2. Regenerates session ID (security)
3. Deletes "remember me" cookie
4. Clears cached user instance

---

## Remember Me

### How It Works

1. **On Login with Remember:**
   - Generate secure random token (32 bytes)
   - Hash token with SHA-256
   - Store **hashed** token in database
   - Store `user_id|raw_token` in encrypted cookie

2. **On Subsequent Requests:**
   - Read cookie value
   - Split into user ID and raw token
   - Find user by ID
   - Hash raw token and compare with database hash
   - Uses `hash_equals()` for timing-attack safety

3. **Cookie Security:**
   - Token is 64 hex characters (32 bytes)
   - Database stores SHA-256 hash of token
   - Cookie is encrypted by framework (AES-256)
   - `httpOnly` flag always enabled
   - `SameSite` policy from config

### Token Rotation

Tokens are not rotated on each request to prevent session thrashing. A new token is only generated on fresh login with "remember me" enabled.

---

## Brute-Force Protection

### How It Works

SwallowPHP tracks failed login attempts per IP + email combination:

1. **On Failed Login:**
   - Increment attempt counter in cache
   - Counter TTL = lockout_time + 60 seconds

2. **On Max Attempts Reached:**
   - Set lockout flag in cache
   - Lockout TTL = lockout_time (default 15 min)

3. **On Successful Login:**
   - Clear attempt counter
   - Clear lockout flag

### Cache Keys

```
login_attempt_{ip}_{email_hash}  # Attempt counter
login_lockout_{ip}_{email_hash}  # Lockout flag
```

### Handling Lockout

```php
use SwallowPHP\Framework\Auth\Auth;
use SwallowPHP\Framework\Exceptions\AuthenticationLockoutException;

try {
    $success = Auth::authenticate($email, $password);
} catch (AuthenticationLockoutException $e) {
    // Account is locked
    session()->flash('error', 'Too many failed attempts. Please try again later.');
    redirectToRoute('login');
}
```

### Customizing Lockout

In `config/auth.php`:

```php
'max_attempts' => 3,       // Stricter: 3 attempts
'lockout_time' => 1800,    // 30 minute lockout
```

---

## Database Schema

### Users Table

```sql
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    remember_token VARCHAR(100) NULL,
    email_verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_remember_token (remember_token)
);
```

**Required Columns:**
- `id` (or your primary key)
- `email` (for lookup)
- `password` (for verification)
- `remember_token` (for remember me, nullable)

---

## Example Implementation

### Login Controller

```php
<?php

namespace App\Controllers;

use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Auth\Auth;
use SwallowPHP\Framework\Exceptions\AuthenticationLockoutException;

class AuthController
{
    public function showLogin()
    {
        if (Auth::isAuthenticated()) {
            redirectToRoute('dashboard');
        }
        
        return view('auth.login');
    }
    
    public function login(Request $request)
    {
        $email = $request->get('email');
        $password = $request->get('password');
        $remember = (bool) $request->get('remember', false);
        
        try {
            if (Auth::authenticate($email, $password, $remember)) {
                session()->flash('success', 'Welcome back!');
                redirectToRoute('dashboard');
            } else {
                session()->flash('error', 'Invalid email or password.');
                redirectToRoute('login');
            }
        } catch (AuthenticationLockoutException $e) {
            session()->flash('error', 'Account locked. Please try again in 15 minutes.');
            redirectToRoute('login');
        }
    }
    
    public function logout()
    {
        Auth::logout();
        session()->flash('success', 'Logged out successfully.');
        redirectToRoute('login');
    }
}
```

### Login View

```php
<!-- resources/views/auth/login.php -->
<form method="POST" action="<?= route('login') ?>">
    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
    
    <div>
        <label for="email">Email</label>
        <input type="email" name="email" id="email" required>
    </div>
    
    <div>
        <label for="password">Password</label>
        <input type="password" name="password" id="password" required>
    </div>
    
    <div>
        <label>
            <input type="checkbox" name="remember" value="1">
            Remember me
        </label>
    </div>
    
    <button type="submit">Login</button>
</form>
```

### Auth Middleware

```php
<?php

namespace App\Middleware;

use SwallowPHP\Framework\Http\Middleware\Middleware;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Auth\Auth;
use Closure;

class AuthMiddleware extends Middleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!Auth::isAuthenticated()) {
            session()->flash('error', 'Please login to continue.');
            redirectToRoute('login');
        }
        
        return $next($request);
    }
}
```

### Protecting Routes

```php
// routes/web.php
use App\Middleware\AuthMiddleware;

Router::get('/dashboard', [DashboardController::class, 'index'])
    ->name('dashboard')
    ->middleware(new AuthMiddleware());

Router::get('/profile', [ProfileController::class, 'show'])
    ->name('profile')
    ->middleware(new AuthMiddleware());
```

### Password Hashing

Always hash passwords before storing:

```php
// Registration
$user = User::create([
    'name' => $request->get('name'),
    'email' => $request->get('email'),
    'password' => password_hash($request->get('password'), PASSWORD_DEFAULT),
]);
```

SwallowPHP uses PHP's native `password_verify()` which supports automatic algorithm detection and upgrade.
