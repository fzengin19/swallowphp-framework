# Logging

SwallowPHP provides a PSR-3 compliant logging system for application debugging and monitoring.

## Table of Contents

- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Log Levels](#log-levels)
- [Context and Placeholders](#context-and-placeholders)
- [Exception Logging](#exception-logging)
- [Log Format](#log-format)

---

## Configuration

Configure logging in `config/logging.php`:

```php
<?php

use Psr\Log\LogLevel;

return [
    // Default log channel
    'default' => env('LOG_CHANNEL', 'file'),
    
    // Log channels configuration
    'channels' => [
        'file' => [
            'driver' => 'single',
            'path' => 'logs/app.log',  // Relative to storage_path
            'level' => env('LOG_LEVEL', LogLevel::DEBUG),
        ],
        
        'stderr' => [
            'driver' => 'stderr',
            'level' => LogLevel::DEBUG,
        ],
    ],
];
```

### Configuration Reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default` | string | `'file'` | Default channel name |
| `channels.*.driver` | string | - | Log driver (`single`, `stderr`) |
| `channels.*.path` | string | - | Path to log file (for `single`) |
| `channels.*.level` | string | `'debug'` | Minimum log level |

### Log File Location

The default log file is located at:
```
storage/logs/app.log
```

Ensure the directory exists and is writable.

---

## Basic Usage

### Using the Helper Function

```php
// Get PSR-3 Logger instance
$logger = logger();

// Log messages at various levels
logger()->debug('Debug information');
logger()->info('User logged in', ['user_id' => 123]);
logger()->warning('Slow query detected', ['duration_ms' => 1500]);
logger()->error('Payment failed', ['order_id' => 456]);
```

### Dependency Injection

```php
use Psr\Log\LoggerInterface;

class PaymentService
{
    public function __construct(private LoggerInterface $logger)
    {
    }
    
    public function processPayment(Order $order): bool
    {
        $this->logger->info('Processing payment', [
            'order_id' => $order->id,
            'amount' => $order->total,
        ]);
        
        try {
            // Process payment...
            $this->logger->info('Payment successful', ['order_id' => $order->id]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Payment failed', [
                'order_id' => $order->id,
                'exception' => $e,
            ]);
            return false;
        }
    }
}
```

---

## Log Levels

SwallowPHP supports all eight PSR-3 log levels (from least to most severe):

| Level | Value | Usage |
|-------|-------|-------|
| `DEBUG` | 100 | Detailed debug information |
| `INFO` | 200 | Interesting events (user login, SQL queries) |
| `NOTICE` | 250 | Normal but significant events |
| `WARNING` | 300 | Exceptional occurrences that aren't errors |
| `ERROR` | 400 | Runtime errors that don't require immediate action |
| `CRITICAL` | 500 | Critical conditions (component unavailable) |
| `ALERT` | 550 | Action must be taken immediately |
| `EMERGENCY` | 600 | System is unusable |

### Setting Minimum Level

Only messages at or above the configured level are recorded:

```php
// In config/logging.php
'level' => LogLevel::WARNING,  // Only WARNING, ERROR, CRITICAL, ALERT, EMERGENCY
```

### Using Log Levels

```php
use Psr\Log\LogLevel;

logger()->log(LogLevel::INFO, 'Custom log level message');
logger()->log(LogLevel::ERROR, 'Error with custom level');

// Shorthand methods (recommended)
logger()->debug('Debug message');
logger()->info('Info message');
logger()->notice('Notice message');
logger()->warning('Warning message');
logger()->error('Error message');
logger()->critical('Critical message');
logger()->alert('Alert message');
logger()->emergency('Emergency message');
```

---

## Context and Placeholders

### Passing Context

Context data is appended to the log entry as JSON:

```php
logger()->info('User action', [
    'user_id' => 123,
    'action' => 'purchase',
    'item_id' => 456,
    'amount' => 99.99,
]);
```

**Output:**
```
[2024-01-15 10:30:45.123456] production.INFO: User action {"user_id":123,"action":"purchase","item_id":456,"amount":99.99}
```

### Message Placeholders

Use `{key}` syntax for interpolation:

```php
logger()->info('User {user_id} purchased item {item_id}', [
    'user_id' => 123,
    'item_id' => 456,
]);
```

**Output:**
```
[2024-01-15 10:30:45.123456] production.INFO: User 123 purchased item 456 {"user_id":123,"item_id":456}
```

### Context Value Types

| Type | Handling |
|------|----------|
| Scalar | Converted to string |
| String | Used as-is |
| Array | Replaced with `[array]` in message, full JSON in context |
| Object with `__toString` | Converted via `__toString()` |
| Throwable | Formatted as `[Exception Class: message in file:line]` |
| Other objects | Replaced with `[object ClassName]` |

---

## Exception Logging

### Logging Exceptions

Pass exceptions in context with the `exception` key:

```php
try {
    throw new \RuntimeException('Database connection failed');
} catch (\Throwable $e) {
    logger()->error('Operation failed', ['exception' => $e]);
}
```

**Output:**
```
[2024-01-15 10:30:45.123456] production.ERROR: Operation failed {"exception":"[Exception RuntimeException: Database connection failed in /path/to/file.php:42]"}
```

### Complete Error Logging

```php
try {
    $result = $this->riskyOperation();
} catch (\Exception $e) {
    logger()->error('Operation failed: {message}', [
        'message' => $e->getMessage(),
        'exception' => $e,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
}
```

---

## Log Format

### Default Format

```
[TIMESTAMP] ENVIRONMENT.LEVEL: MESSAGE CONTEXT
```

### Components

| Part | Description | Example |
|------|-------------|---------|
| Timestamp | ISO 8601 with microseconds | `2024-01-15 10:30:45.123456` |
| Environment | From `app.env` config | `production`, `local` |
| Level | Uppercase log level | `INFO`, `ERROR` |
| Message | Interpolated message string | `User 123 logged in` |
| Context | JSON-encoded context array | `{"user_id":123}` |

### Example Log Entries

```
[2024-01-15 10:30:45.123456] production.INFO: User logged in {"user_id":123}
[2024-01-15 10:30:46.234567] production.WARNING: Slow query detected {"duration_ms":1500,"sql":"SELECT * FROM users..."}
[2024-01-15 10:30:47.345678] production.ERROR: Payment failed {"order_id":456,"exception":"[Exception PaymentException: Invalid card in /app/Services/Payment.php:78]"}
```

---

## Best Practices

### 1. Use Appropriate Levels

```php
// Good
logger()->debug('Variable value', ['data' => $data]);
logger()->info('User registered', ['user_id' => $id]);
logger()->error('Payment failed', ['order' => $orderId]);

// Bad - Don't log everything as ERROR
logger()->error('User logged in');  // Should be INFO
```

### 2. Include Relevant Context

```php
// Good - Include identifiers and relevant data
logger()->error('Order failed', [
    'order_id' => $order->id,
    'user_id' => $order->user_id,
    'total' => $order->total,
    'error_code' => $errorCode,
]);

// Bad - Missing context
logger()->error('Order failed');
```

### 3. Use Placeholders for Readability

```php
// Good
logger()->info('User {user_id} purchased {item_count} items for ${total}', [
    'user_id' => 123,
    'item_count' => 3,
    'total' => 150.00,
]);

// Less readable
logger()->info('User purchased items', ['user_id' => 123, 'count' => 3]);
```

### 4. Avoid Sensitive Data

```php
// Bad - Never log sensitive data
logger()->info('User login', ['password' => $password]);
logger()->debug('API response', ['api_key' => $key]);

// Good - Mask or omit sensitive data
logger()->info('User login attempt', ['email' => $email]);
logger()->debug('API response', ['status' => $response->status]);
```

### 5. Log at Request Boundaries

```php
class BaseController
{
    protected function logRequest(Request $request): void
    {
        logger()->info('Request received', [
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'ip' => $request->getClientIp(),
        ]);
    }
    
    protected function logResponse(Response $response, float $duration): void
    {
        logger()->info('Response sent', [
            'status' => $response->getStatusCode(),
            'duration_ms' => round($duration * 1000, 2),
        ]);
    }
}
```

---

## API Reference

### LoggerInterface Methods

| Method | Parameters | Description |
|--------|------------|-------------|
| `log($level, $message, $context)` | string, string, array | Log with arbitrary level |
| `debug($message, $context)` | string, array | Debug message |
| `info($message, $context)` | string, array | Info message |
| `notice($message, $context)` | string, array | Notice message |
| `warning($message, $context)` | string, array | Warning message |
| `error($message, $context)` | string, array | Error message |
| `critical($message, $context)` | string, array | Critical message |
| `alert($message, $context)` | string, array | Alert message |
| `emergency($message, $context)` | string, array | Emergency message |
