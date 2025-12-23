# Caching

SwallowPHP provides a PSR-16 compliant caching system with multiple driver support.

## Table of Contents

- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Cache Drivers](#cache-drivers)
  - [File Driver](#file-driver)
  - [SQLite Driver](#sqlite-driver)
- [PSR-16 Methods](#psr-16-methods)
- [Additional Methods](#additional-methods)
- [Cache Keys](#cache-keys)

---

## Configuration

Configure caching in `config/cache.php`:

```php
<?php

return [
    // Default cache driver
    'default' => env('CACHE_DRIVER', 'file'),
    
    // Default TTL in seconds
    'ttl' => 3600,
    
    // Driver configurations
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => 'cache/data.json',     // Relative to storage_path
            'max_size' => 52428800,           // 50MB max file size
        ],
        
        'sqlite' => [
            'driver' => 'sqlite',
            'path' => 'cache/database.sqlite', // Relative to storage_path
            'table' => 'cache',
        ],
    ],
];
```

### Configuration Reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default` | string | `'file'` | Default driver name |
| `ttl` | int | `3600` | Default TTL in seconds |
| `stores.file.path` | string | - | Path to cache JSON file |
| `stores.file.max_size` | int | `52428800` | Maximum file size (50MB) |
| `stores.sqlite.path` | string | - | Path to SQLite database |
| `stores.sqlite.table` | string | `'cache'` | Table name for cache |

---

## Basic Usage

### Using the Helper Function

```php
// Get default cache instance
$cache = cache();

// Get specific driver
$cache = cache('file');
$cache = cache('sqlite');
```

### Common Operations

```php
// Store a value (with default TTL from config)
cache()->set('user:123', $userData);

// Store with custom TTL (seconds)
cache()->set('api:response', $data, 600);  // 10 minutes

// Get a value
$user = cache()->get('user:123');

// Get with default
$settings = cache()->get('settings', []);

// Check existence
if (cache()->has('user:123')) {
    // Key exists and not expired
}

// Delete a value
cache()->delete('user:123');

// Clear all cache
cache()->clear();
```

### Using CacheManager Facade

```php
use SwallowPHP\Framework\Cache\CacheManager;

// Static access (uses default driver)
CacheManager::set('key', 'value');
$value = CacheManager::get('key');
```

---

## Cache Drivers

### File Driver

Stores cache data in a single JSON file with file locking for concurrency.

**Configuration:**
```php
'file' => [
    'driver' => 'file',
    'path' => 'cache/data.json',
    'max_size' => 52428800,  // 50MB
],
```

**Features:**
- Atomic writes (temp file + rename)
- File locking (LOCK_EX/LOCK_SH)
- Automatic pruning when max size reached
- JSON storage format

**Best for:**
- Small to medium applications
- Single server deployments
- Quick setup without dependencies

### SQLite Driver

Stores cache data in an SQLite database.

**Configuration:**
```php
'sqlite' => [
    'driver' => 'sqlite',
    'path' => 'cache/database.sqlite',
    'table' => 'cache',
],
```

**Features:**
- ACID transactions
- Better concurrency than file driver
- Automatic table creation
- Garbage collection for expired items

**Best for:**
- Higher concurrency needs
- Larger cache datasets
- When SQLite extension is available

---

## PSR-16 Methods

SwallowPHP's cache implements the PSR-16 (Simple Cache) interface.

### `get(string $key, mixed $default = null): mixed`

Get a value from the cache.

```php
$user = cache()->get('user:123');
$data = cache()->get('missing-key', null);
$settings = cache()->get('settings', ['theme' => 'dark']);
```

### `set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool`

Store a value in the cache.

```php
// With default TTL (from config)
cache()->set('key', 'value');

// With TTL in seconds
cache()->set('key', 'value', 3600);  // 1 hour

// With DateInterval
cache()->set('key', 'value', new DateInterval('PT1H'));

// Complex values
cache()->set('user:123', [
    'id' => 123,
    'name' => 'John',
    'roles' => ['admin', 'user'],
]);
```

**Returns:** `true` on success, `false` on failure.

### `delete(string $key): bool`

Remove an item from the cache.

```php
cache()->delete('user:123');
```

**Returns:** `true` on success (or if key didn't exist).

### `clear(): bool`

Remove all items from the cache.

```php
cache()->clear();
```

**Returns:** `true` on success.

### `has(string $key): bool`

Check if an item exists and is not expired.

```php
if (cache()->has('user:123')) {
    $user = cache()->get('user:123');
}
```

### `getMultiple(iterable $keys, mixed $default = null): iterable`

Get multiple items at once.

```php
$data = cache()->getMultiple(['user:1', 'user:2', 'user:3']);

foreach ($data as $key => $value) {
    echo "{$key}: " . ($value ? $value['name'] : 'not found');
}

// With default
$data = cache()->getMultiple(['key1', 'key2'], 'default');
```

### `setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool`

Store multiple items at once.

```php
cache()->setMultiple([
    'user:1' => ['id' => 1, 'name' => 'John'],
    'user:2' => ['id' => 2, 'name' => 'Jane'],
    'user:3' => ['id' => 3, 'name' => 'Bob'],
], 3600);
```

**Returns:** `true` if ALL items were stored successfully.

### `deleteMultiple(iterable $keys): bool`

Remove multiple items at once.

```php
cache()->deleteMultiple(['user:1', 'user:2', 'user:3']);
```

---

## Additional Methods

Beyond PSR-16, SwallowPHP provides these extra methods:

### `increment(string $key, int $step = 1): int|false`

Increment a numeric value atomically.

```php
// Increment by 1
$newCount = cache()->increment('page:views');

// Increment by custom amount
$newCount = cache()->increment('user:123:points', 10);

// If key doesn't exist, starts from 0
cache()->increment('new:counter');  // Returns 1
```

**Returns:** New value on success, `false` on failure.

### `decrement(string $key, int $step = 1): int|false`

Decrement a numeric value atomically.

```php
$newCount = cache()->decrement('inventory:item:123');
$newCount = cache()->decrement('credits', 5);
```

**Returns:** New value on success, `false` on failure.

---

## Cache Keys

### Key Format Requirements

Cache keys must be strings and cannot contain these reserved characters:

- `{` `}` (curly braces)
- `(` `)` (parentheses)
- `/` `\` (slashes)
- `@` (at sign)
- `:` is allowed and commonly used as a separator

### Recommended Key Patterns

```php
// Entity-based keys
'user:123'
'post:456:comments'
'product:789:inventory'

// Feature-based keys
'api:weather:istanbul'
'config:site_settings'
'stats:daily:2024-01-15'

// Session/user-scoped keys
'cart:session:abc123'
'favorites:user:456'
```

### Cache Tags Pattern

While SwallowPHP doesn't have native tag support, use key prefixes:

```php
// Store with prefix
cache()->set('users:user:1', $user1);
cache()->set('users:user:2', $user2);
cache()->set('users:list', $allUsers);

// Clear by pattern (manual)
function clearCacheByPrefix(string $prefix): void
{
    // For file cache, you'd need to iterate all keys
    // Consider using a dedicated tagging library for complex needs
}
```

---

## Common Patterns

### Cache-Aside Pattern

```php
function getUser(int $id): ?array
{
    $cacheKey = "user:{$id}";
    
    // Try cache first
    $user = cache()->get($cacheKey);
    
    if ($user === null) {
        // Cache miss - get from database
        $user = User::find($id)?->toArray();
        
        if ($user) {
            cache()->set($cacheKey, $user, 3600);
        }
    }
    
    return $user;
}
```

### Cache Warming

```php
function warmUserCache(): void
{
    $users = User::limit(100)->get();
    
    $cacheData = [];
    foreach ($users as $user) {
        $cacheData["user:{$user->id}"] = $user->toArray();
    }
    
    cache()->setMultiple($cacheData, 3600);
}
```

### Cache Invalidation

```php
class UserController
{
    public function update(Request $request, int $id)
    {
        $user = User::find($id);
        $user->name = $request->get('name');
        $user->save();
        
        // Invalidate cache
        cache()->delete("user:{$id}");
        cache()->delete('users:list');
        
        return redirectToRoute('users.show', ['id' => $id]);
    }
}
```

### Rate Limiting with Cache

```php
function checkRateLimit(string $action, string $ip, int $maxAttempts = 10): bool
{
    $key = "ratelimit:{$action}:{$ip}";
    
    $attempts = cache()->get($key, 0);
    
    if ($attempts >= $maxAttempts) {
        return false;  // Rate limit exceeded
    }
    
    cache()->increment($key);
    
    // Set expiry on first attempt
    if ($attempts === 0) {
        cache()->set($key, 1, 60);  // 60 second window
    }
    
    return true;
}
```

---

## API Reference

### CacheInterface Methods

| Method | Return | Description |
|--------|--------|-------------|
| `get($key, $default)` | mixed | Get cached value |
| `set($key, $value, $ttl)` | bool | Store value |
| `delete($key)` | bool | Remove value |
| `clear()` | bool | Remove all values |
| `has($key)` | bool | Check existence |
| `getMultiple($keys, $default)` | iterable | Get multiple values |
| `setMultiple($values, $ttl)` | bool | Store multiple values |
| `deleteMultiple($keys)` | bool | Remove multiple values |
| `increment($key, $step)` | int\|false | Increment counter |
| `decrement($key, $step)` | int\|false | Decrement counter |
