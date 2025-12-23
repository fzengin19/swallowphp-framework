# Configuration

SwallowPHP uses a hierarchical configuration system where framework defaults can be overridden by application-specific settings.

## Table of Contents

- [Environment Variables](#environment-variables)
- [Configuration Files](#configuration-files)
- [Accessing Configuration](#accessing-configuration)
- [Configuration Reference](#configuration-reference)
  - [app.php](#appphp)
  - [database.php](#databasephp)
  - [session.php](#sessionphp)
  - [cache.php](#cachephp)
  - [logging.php](#loggingphp)
  - [auth.php](#authphp)
  - [security.php](#securityphp)

---

## Environment Variables

SwallowPHP loads environment variables from a `.env` file in your project root.

### .env File Format

```env
# Application
APP_NAME=MyApplication
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_KEY=your-32-character-secret-key-here

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=root
DB_PASSWORD=secret

# Session
SESSION_DRIVER=file
SESSION_LIFETIME=120

# Cache
CACHE_DRIVER=file
CACHE_TTL=3600

# Security
CSP_ENABLED=true
```

### How Environment Loading Works

The `Env` class (`SwallowPHP\Framework\Foundation\Env`) handles environment loading:

1. **BASE_PATH Detection**: The framework looks for `BASE_PATH` constant or auto-detects it
2. **File Loading**: Reads the `.env` file from base path
3. **Variable Parsing**: 
   - Lines starting with `#` are ignored (comments)
   - Lines without `=` are skipped
   - Quotes around values are automatically removed
   - `export` prefix is supported (for shell compatibility)
4. **Variable Storage**: Values are stored in:
   - `putenv()` - for `getenv()` access
   - `$_ENV` superglobal
   - `$_SERVER` superglobal

### Accessing Environment Variables

Use the `env()` helper function:

```php
// Get value with default fallback
$debug = env('APP_DEBUG', false);

// Get value (returns null if not set)
$apiKey = env('API_KEY');
```

The `env()` helper checks in this order:
1. `$_ENV[$key]`
2. `$_SERVER[$key]`
3. `getenv($key)`
4. Returns default value

---

## Configuration Files

Configuration files are PHP files that return arrays. They are located in:

1. **Framework Config**: `vendor/swallowphp/framework/src/Config/` (defaults)
2. **Application Config**: `config/` in your project root (overrides)

### Configuration Merging

The `Config` class merges configurations using `array_replace_recursive()`:
- Framework defaults are loaded first
- Application configs override framework values
- Nested arrays are merged recursively

### Creating Configuration Files

Create PHP files in your `config/` directory that return arrays:

```php
<?php
// config/app.php

return [
    'name' => env('APP_NAME', 'MyApp'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'Europe/Istanbul',
    'locale' => 'tr',
    'key' => env('APP_KEY'),
    'storage_path' => BASE_PATH . '/storage',
    'view_path' => BASE_PATH . '/resources/views',
    'controller_namespace' => '\\App\\Controllers',
];
```

---

## Accessing Configuration

### Using the `config()` Helper

```php
// Get a value (dot notation)
$appName = config('app.name');

// Get with default value
$timezone = config('app.timezone', 'UTC');

// Get entire config file as array
$databaseConfig = config('database');

// Get the Config instance
$configInstance = config();

// Set values at runtime
config(['app.debug' => true]);

// Set multiple values
config([
    'app.debug' => true,
    'app.env' => 'testing',
]);
```

### Dot Notation

Access nested values using dot notation:

```php
// config/database.php returns:
// ['connections' => ['mysql' => ['host' => '127.0.0.1']]]

$host = config('database.connections.mysql.host');
// Returns: '127.0.0.1'
```

### Config Class Methods

```php
use SwallowPHP\Framework\Foundation\Config;

$config = App::container()->get(Config::class);

// Check if key exists
$config->has('app.debug'); // true or false

// Get value
$config->get('app.name', 'Default');

// Set value
$config->set('app.custom_key', 'value');

// Get all configuration
$all = $config->all();
```

---

## Configuration Reference

### app.php

Main application configuration.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `name` | string | `'SwallowPHP'` | Application name |
| `env` | string | `'production'` | Environment (local, production, testing) |
| `debug` | bool | `false` | Enable debug mode |
| `url` | string | `'http://localhost'` | Application URL |
| `path` | string | `''` | Subdirectory path (e.g., `/myapp`) |
| `timezone` | string | `'UTC'` | PHP timezone |
| `locale` | string | `'en'` | Application locale |
| `key` | string | `null` | **Required**. 32-character encryption key |
| `cipher` | string | `'AES-256-CBC'` | Encryption cipher for cookies |
| `storage_path` | string | `null` | Path to storage directory |
| `view_path` | string | `null` | Path to views directory |
| `pagination_view` | string | `null` | Custom pagination view name |
| `controller_namespace` | string | `null` | Controller namespace (e.g., `\\App\\Controllers`) |
| `max_execution_time` | int | `30` | PHP max execution time in seconds |
| `ssl_redirect` | bool | `false` | Force HTTPS redirect |
| `gzip_compression` | bool | `true` | Enable zlib output compression |
| `error_reporting_level` | int | `E_ALL` | PHP error reporting level |
| `minify_html` | bool | `false` | Enable HTML minification for views |

**Example:**

```php
<?php
// config/app.php

return [
    'name' => env('APP_NAME', 'MyApp'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'Europe/Istanbul',
    'locale' => 'tr',
    'key' => env('APP_KEY'),
    'storage_path' => BASE_PATH . '/storage',
    'view_path' => BASE_PATH . '/resources/views',
    'controller_namespace' => '\\App\\Controllers',
    'ssl_redirect' => env('APP_ENV') === 'production',
    'minify_html' => env('APP_ENV') === 'production',
];
```

---

### database.php

Database connection configuration.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default` | string | `'mysql'` | Default connection name |
| `connections` | array | - | Connection configurations |
| `log_queries` | bool | `false` | Log all queries |
| `slow_threshold_ms` | int | `500` | Slow query threshold (ms) |
| `log_bindings` | bool | `true` | Include bindings in query logs |

#### MySQL Connection

```php
'mysql' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'myapp'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
    'engine' => null,
],
```

#### SQLite Connection

```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => 'database/database.sqlite', // Relative to storage_path
    'prefix' => '',
],
```

**Example:**

```php
<?php
// config/database.php

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'myapp'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    
    'log_queries' => env('APP_DEBUG', false),
    'slow_threshold_ms' => 500,
];
```

---

### session.php

Session configuration.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `driver` | string | `'file'` | Session driver |
| `lifetime` | int | `120` | Session lifetime in minutes |
| `expire_on_close` | bool | `false` | Expire when browser closes |
| `files` | string | `null` | File storage path |
| `connection` | string | `null` | Database connection (for db driver) |
| `table` | string | `'sessions'` | Database table name |
| `cookie` | string | `'swallow_session'` | Session cookie name |
| `path` | string | `'/'` | Cookie path |
| `domain` | string | `null` | Cookie domain |
| `secure` | bool | `null` | HTTPS only (auto-detected) |
| `http_only` | bool | `true` | HTTP only access |
| `same_site` | string | `'Lax'` | SameSite policy (Lax, Strict, None) |
| `lottery` | array | `[2, 100]` | Garbage collection probability [chance, divisor] |

**Example:**

```php
<?php
// config/session.php

return [
    'driver' => 'file',
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'files' => BASE_PATH . '/storage/sessions',
    'cookie' => 'myapp_session',
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('APP_ENV') === 'production',
    'http_only' => true,
    'same_site' => 'Lax',
];
```

---

### cache.php

Cache configuration.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default` | string | `'file'` | Default cache driver |
| `ttl` | int | `3600` | Default TTL in seconds |
| `stores` | array | - | Cache store configurations |
| `prefix` | string | `'swallowphp_cache_'` | Cache key prefix |

#### File Store

```php
'file' => [
    'driver' => 'file',
    'path' => 'cache/data.json', // Relative to storage_path
    'max_size' => 50 * 1024 * 1024, // 50MB
],
```

#### SQLite Store

```php
'sqlite' => [
    'driver' => 'sqlite',
    'path' => 'cache/database.sqlite', // Relative to storage_path
    'table' => 'cache',
],
```

**Example:**

```php
<?php
// config/cache.php

return [
    'default' => env('CACHE_DRIVER', 'file'),
    'ttl' => (int) env('CACHE_TTL', 3600),
    
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => 'cache/data.json',
            'max_size' => 50 * 1024 * 1024,
        ],
    ],
    
    'prefix' => 'myapp_cache_',
];
```

---

### logging.php

Logging configuration.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default` | string | `'file'` | Default log channel |
| `channels` | array | - | Log channel configurations |

#### File Channel

```php
'file' => [
    'driver' => 'single',
    'path' => 'logs/swallow.log', // Relative to storage_path
    'level' => \Psr\Log\LogLevel::DEBUG,
],
```

#### Stderr Channel

```php
'stderr' => [
    'driver' => 'errorlog',
    'level' => \Psr\Log\LogLevel::DEBUG,
],
```

**Example:**

```php
<?php
// config/logging.php

use Psr\Log\LogLevel;

return [
    'default' => env('LOG_CHANNEL', 'file'),
    
    'channels' => [
        'file' => [
            'driver' => 'single',
            'path' => 'logs/app.log',
            'level' => env('APP_DEBUG') ? LogLevel::DEBUG : LogLevel::WARNING,
        ],
    ],
];
```

---

### auth.php

Authentication configuration.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `model` | string | `null` | **Required**. User model class name |
| `max_attempts` | int | `5` | Max failed login attempts before lockout |
| `lockout_time` | int | `900` | Lockout duration in seconds (15 min) |
| `remember_lifetime` | int | `43200` | Remember me duration in minutes (30 days) |

**Example:**

```php
<?php
// config/auth.php

return [
    'model' => \App\Models\User::class,
    'max_attempts' => 5,
    'lockout_time' => 900,
    'remember_lifetime' => 43200,
];
```

---

### security.php

Security and CSP configuration.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | bool | `true` | Enable Content Security Policy |
| `directives` | array | - | CSP directive definitions |

#### CSP Directives

```php
'directives' => [
    'default-src' => ["'self'"],
    'script-src' => ["'self'"],
    'style-src' => ["'self'"],
    'img-src' => ["'self'", 'data:'],
    'connect-src' => ["'self'"],
    'font-src' => ["'self'"],
    'object-src' => ["'none'"],
    'media-src' => ["'self'"],
    'frame-src' => ["'self'"],
    'frame-ancestors' => ["'self'"],
    'form-action' => ["'self'"],
    'base-uri' => ["'self'"],
    'report-uri' => null,
],
```

**Example with external resources:**

```php
<?php
// config/security.php

return [
    'enabled' => env('CSP_ENABLED', true),
    
    'directives' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'unsafe-inline'", 'https://cdn.example.com'],
        'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
        'img-src' => ["'self'", 'data:', 'https:'],
        'font-src' => ["'self'", 'https://fonts.gstatic.com'],
        'connect-src' => ["'self'", 'https://api.example.com'],
    ],
];
```
