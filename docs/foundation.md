# Foundation

The `SwallowPHP\Framework\Foundation` namespace provides the runtime core of the framework: the application bootstrap (`App`), the `.env` loader (`Env`), the configuration repository (`Config`) and the global exception handler (`ExceptionHandler`). These four classes are wired together by `App::container()` and `App::run()` and are the first framework code that executes on every request.

This document focuses on the Foundation layer specifically. The end-user-facing `config()` / `env()` helpers and the configuration file reference are documented in [`docs/configuration.md`](configuration.md); the per-class exception catalog lives in [`docs/exceptions.md`](exceptions.md).

## Table of Contents

- [App](#app)
  - [App::run() Lifecycle](#apprun-lifecycle)
  - [App::container() Service Wiring](#appcontainer-service-wiring)
  - [App::handleRequest()](#apphandlerequest)
  - [App::registerServiceProvider()](#appregisterserviceprovider)
  - [App::getRouter()](#appgetrouter)
  - [App::getViewDirectory()](#appgetviewdirectory)
  - [App::getInstance()](#appgetinstance)
- [Env](#env)
  - [Env::load()](#envload)
  - [Env::get()](#envget)
  - [Env::setBasePath()](#envsetbasepath)
  - [Env::getBasePath()](#envgetbasepath)
  - [Env::getAsJson()](#envgetasjson)
- [Config](#config)
  - [Config Loading](#config-loading)
  - [Config::get()](#configget)
  - [Config::set()](#configset)
  - [Config::has()](#confighas)
  - [Config::all()](#configall)
- [ExceptionHandler](#exceptionhandler)
  - [ExceptionHandler::handle()](#exceptionhandlerhandle)
  - [ExceptionHandler::getStatusCode()](#exceptionhandlergetstatuscode)
  - [ExceptionHandler::getLogLevel()](#exceptionhandlergetloglevel)
  - [ExceptionHandler::buildResponseData()](#exceptionhandlerbuildresponsedata)
  - [ExceptionHandler::renderFallbackHtml()](#exceptionhandlerrenderfallbackhtml)
- [Application Configuration File (`src/Config/app.php`)](#application-configuration-file-srcconfigappphp)

---

## App

**Class:** `SwallowPHP\Framework\Foundation\App`

`App` is a static facade that owns the application's dependency-injection container, the shared router instance, and the view directory. It is the entry point used by `public/index.php` (via `App::run()`) and by any code that needs to resolve framework services outside of constructor injection.

`App` is intentionally a singleton facade: there is at most one container and one router per process, and the constructor is private. Holding state in static properties is safe because CLI, FPM and long-running workers all run one request at a time inside a single PHP process.

### App::run() Lifecycle

`App::run()` is the application entry point. It is intentionally written as a single, top-down method so the order of operations is easy to audit. The lifecycle is:

1. **PHP error/exception handlers.** `error_reporting(E_ALL)` is set and `display_errors` is forced to `0` so the framework's `ExceptionHandler` (not PHP's default renderer) is the only thing that ever prints errors. A custom `set_error_handler` converts PHP errors into `\ErrorException`s so they flow through the same `try/catch` as user exceptions. A *temporary* `set_exception_handler` is installed only to catch errors that occur **before** the main container is built (a 500 with a plain "Fatal Error during application initialization" page is shipped).
2. **Container initialization.** `self::getInstance()` is called, which constructs `App`. The constructor calls `self::container()` first, and that lazy-initializes the DI container. Inside `container()`:
   - `Env::load()` is run with the framework's base path set from `BASE_PATH`. This populates `$_ENV` and `putenv()` (see [Env::load()](#envload) for the v3.0.1 behavior — `$_SERVER` is intentionally **not** written to).
   - A shared `Config` service is registered against the framework's `src/Config` directory and the application's `<BASE_PATH>/config` directory.
   - `LoggerInterface`, `SessionManager`, `CacheInterface`, `Request`, `Database`, `Router`, and `VerifyCsrfToken` are all registered as container services.
   - Any service providers queued via `App::registerServiceProvider()` before the container existed are replayed.
3. **Restore the exception handler.** The temporary startup-only handler from step 1 is replaced with `restore_exception_handler()` so any subsequent uncaught exception is left to the `try/catch` block.
4. **Apply configuration.** `app.timezone`, `app.locale`, `max_execution_time`, `app.error_reporting_level`, and `app.debug` are read from `Config` and applied to PHP. `app.ssl_redirect` short-circuits the request with a 301 to HTTPS if enabled.
5. **Start the session.** `SessionManager::start()` is called. (If headers have already been sent, the logger records a warning and the request continues without a session.)
6. **Output buffering and gzip.** `ob_start()` is called. If `app.gzip_compression` is true and the client advertises gzip in `Accept-Encoding`, `zlib.output_compression` is enabled. The buffer is held until the response is built.
7. **Global middleware pipeline.** The pipeline is `ValidatePostSize → VerifyCsrfToken → route action → AddContentSecurityPolicyHeader`. The two outer middleware are wired by hand; the route action and any per-route middleware are orchestrated by `Router::dispatch()`; the CSP middleware is applied directly to the final response. See [`docs/middleware.md`](middleware.md#global-middleware-pipeline-order) for the full pipeline diagram.
8. **Response shaping.** The route's return value is coerced into a `Response` object: arrays/objects become JSON, scalars and `Stringable` become HTML, anything else is logged and turned into a 500.
9. **CSP.** `AddContentSecurityPolicyHeader` is run with the route's response as the "next" closure so it can attach the `Content-Security-Policy` header to the final response.
10. **Send.** `$response->send()` is called, then `ob_end_flush()`.
11. **Failure path.** Any `\Throwable` thrown by steps 2–10 is caught, the output buffer is drained, and `ExceptionHandler::handle($th)` is invoked. If the handler itself fails, a last-resort plain-text 500 is printed and the script exits.

The `finally` block restores the previous error handler so the framework cleanup is symmetric with the setup.

```php
// public/index.php
require_once __DIR__ . '/../vendor/autoload.php';

define('BASE_PATH', dirname(__DIR__));

SwallowPHP\Framework\Foundation\App::run();
```

### App::container() Service Wiring

| Method | Return | Description |
|--------|--------|-------------|
| `container()` | `League\Container\Container` | Returns the shared container. Lazy-builds it on first call, with `Env::load()` and the full service map wired in. Safe to call before `App::run()`; in fact `App::run()` calls it indirectly via `getInstance()`. |

The container is a `League\Container\Container` with `ReflectionContainer` delegated for autowiring. The services registered are:

| Service ID | Concrete | Shared? | Notes |
|------------|----------|---------|-------|
| `Config` | `SwallowPHP\Framework\Foundation\Config` | yes | Points at `src/Config` and `<BASE_PATH>/config`. |
| `LoggerInterface` | `FileLogger` or `errorlog` anonymous class | yes | Driver chosen by `logging.default`; see [`docs/logging.md`](logging.md). |
| `SessionManager` | `SessionManager` | yes | |
| `CacheInterface` | `CacheManager::driver($config->get('cache.default'))` | yes | |
| `Request` | `Request::createFromGlobals()` | yes | |
| `Database` | `new Database($connectionConfig)` | no | A fresh `Database` builder per resolve so concurrent builders don't share state; the underlying PDO is shared via `Database::$connections`. |
| `Router` | `Router` | yes | |
| `VerifyCsrfToken` | `VerifyCsrfToken` | yes | |

### App::handleRequest()

```php
public static function handleRequest(Request $request): mixed
```

Delegates to `Router::dispatch($request)`. Called by the middleware pipeline once CSRF + post-size validation have passed. The pipeline itself wraps the raw return value in a `Response` object — see step 8 of [`App::run()` Lifecycle](#apprun-lifecycle).

### App::registerServiceProvider()

```php
public static function registerServiceProvider($provider): void
```

Queue a `ServiceProviderInterface` to be registered with the container. If the container has not been built yet, the provider is appended to `$pendingServiceProviders` and replayed during the next `container()` call. This is the hook that lets `public/index.php` add app-specific bindings before `App::run()` is invoked.

```php
App::registerServiceProvider(new App\Providers\AppServiceProvider());
App::run();
```

### App::getRouter()

```php
public static function getRouter(): Router
```

Returns the shared `Router`. Shorthand for `App::container()->get(Router::class)`. Route definitions placed in `app/Http/routes.php` (or wherever you bootstrap them) can use this to register routes.

### App::getViewDirectory()

```php
public static function getViewDirectory(): ?string
```

Returns the resolved view directory. Read from `app.view_path` first; if that is `null`, falls back to `<BASE_PATH>/resources/views`. Stored on the static and used by the view layer.

### App::getInstance()

```php
public static function getInstance(): self
```

Returns the singleton `App` instance. The constructor triggers `container()` initialization, so by the time you get an instance the container is hot. Used by `App::run()` and by anything that wants the static facade.

---

## Env

**Class:** `SwallowPHP\Framework\Foundation\Env`

`Env` is the framework's `.env` loader. It is intentionally minimal — a hand-rolled parser, no VLaravel Dotenv dependency — and is invoked exactly once, by `App::container()`, before `Config` is instantiated.

### Env::load()

```php
public static function load(): void
```

Reads `<BASE_PATH>/.env` line by line and applies each `NAME=value` pair. The format understood:

- Lines beginning with `#` are comments and skipped.
- Empty lines (and `FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES` whitespace) are skipped.
- Optional matching single or double quotes are stripped from the value.
- `export NAME=value` is handled — the `export ` prefix is stripped from the name.
- Names with internal whitespace are skipped with an `error_log()` warning (defensive: `putenv()` would otherwise accept them silently and the resulting key would be unusable).
- Empty names (`=value`) are skipped with an `error_log()` warning. Without this guard, `putenv("=value")` would throw `\ValueError` on PHP 8+ and abort the entire load loop.

**v3.0.1 behavior.** `Env::load()` writes the parsed values to two places only:

- `putenv("NAME=value")` — the OS environment.
- `$_ENV['NAME'] = $value` — the PHP superglobal.

It does **not** write to `$_SERVER`. This is a deliberate change from earlier versions: several PHP subsystems (notably `$_SERVER['HTTPS']`) read from `$_SERVER` first, and writing all `.env` keys there caused `safe_mode`-style collisions when keys happened to share names with CGI variables. `Env::get()` looks up `$_ENV` first, then `$_SERVER` (so existing `$_SERVER` values are still honored), then falls back to `getenv()`.

After the loop, `BASE_PATH` is mirrored into `putenv()` and `$_ENV['BASE_PATH']` so its value is available via `env('BASE_PATH')` consistently with the rest of the file.

```env
# .env
APP_NAME=MyApp
APP_DEBUG=true
APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=
DB_HOST="127.0.0.1"   # matching quotes are stripped → 127.0.0.1
export TZ=UTC           # supported (prefix stripped)
```

If the file is missing or unreadable, `Env::load()` returns without throwing — it writes an `error_log()` warning. The bootstrap continues with whatever is already in `$_ENV` / `$_SERVER` / `getenv()`.

### Env::get()

```php
public static function get($key, $default = null)
```

Lookup order: `$_ENV[$key]` → `$_SERVER[$key]` → `getenv($key)` → `$default`. Returns the first match. Mirrors the standard Laravel helper but skips the `vlucas/phpdotenv` RepositoryBuilder dance.

```php
$value = Env::get('APP_DEBUG', false);
$name  = Env::get('APP_NAME', 'SwallowPHP');
```

### Env::setBasePath()

```php
public static function setBasePath(string $path): void
```

Override the base path used by `Env::load()` and `getAsJson()`. `App::container()` calls this with `BASE_PATH` early in the bootstrap. Without this call, `Env::getBasePath()` falls back to autodetection via `dirname(__DIR__, 5)`.

### Env::getBasePath()

```php
public static function getBasePath(): string
```

Returns the resolved base path. Static cache: returns the explicitly set path if one was set, otherwise autodetects.

### Env::getAsJson()

```php
public static function getAsJson(): string
```

Returns the `.env` file as a JSON string of name/value pairs. Unlike `load()`, nothing is written to `$_ENV` or `putenv()` — this is a read-only dump. Useful for diagnostics or for embedding `.env` content into a baked artifact.

---

## Config

**Class:** `SwallowPHP\Framework\Foundation\Config`

`Config` is the key/value repository the rest of the framework reads through. It is built by `App::container()` and shared as a singleton. The user-facing `config()` helper is a thin wrapper around `App::container()->get(Config::class)->get(...)`.

### Config Loading

On construction, `Config` loads every `*.php` file in two paths:

1. **Framework path** — `vendor/swallowphp/framework/src/Config` (or the framework's own `src/Config` in a non-installed checkout). These files supply safe defaults.
2. **App path** — `<BASE_PATH>/config` if it exists. App files override framework defaults via `array_replace_recursive()`.

Each file is expected to `return [...]` an associative array; the basename (without `.php`) is the top-level key (`app`, `database`, `cache`, `session`, `logging`, `auth`, `security`). Files that don't return an array are skipped with an `error_log()` warning — the loader keeps going.

See [`docs/configuration.md`](configuration.md) for the full configuration reference.

### Config::get()

```php
public function get(string $key, mixed $default = null): mixed
```

Read a value using dot notation. `config('app.timezone')` walks `items['app']['timezone']`. Returns `$default` if any segment of the path is missing.

```php
$tz = config('app.timezone', 'UTC');
$driver = config('cache.default', 'file');
```

### Config::set()

```php
public function set(string $key, mixed $value): void
```

Write a value using dot notation. Auto-creates intermediate arrays. Throws `\RuntimeException` if you try to descend into a segment that already holds a non-array value (so you can't accidentally clobber a string with a nested array).

```php
config()->set('app.debug', true);
config()->set('services.stripe.secret', 'sk_test_...');
```

### Config::has()

```php
public function has(string $key): bool
```

Returns `true` if the key exists (including the case where the value is `null`). Uses a sentinel default internally so a stored `null` is not confused with a missing key.

### Config::all()

```php
public function all(): array
```

Returns the full merged configuration array. Useful for debug dumps — be careful not to log this in production because it includes the `app.key` and any database credentials.

---

## ExceptionHandler

**Class:** `SwallowPHP\Framework\Foundation\ExceptionHandler`

`ExceptionHandler` is the single hand-rolled error-to-response converter. `App::run()` wraps the entire request in a `try { ... } catch (\Throwable $th) { ExceptionHandler::handle($th); }` block, and this is the only code path that turns a thrown exception into an HTTP response. The class is `static` — there is no instance to inject.

### ExceptionHandler::handle()

```php
public static function handle(Throwable $exception): \SwallowPHP\Framework\Http\Response
```

The end-to-end pipeline:

1. **Resolve the logger.** Pulls `LoggerInterface` from the container; if that fails the fallback is `error_log()`. The log level is chosen by [`getLogLevel()`](#exceptionhandlergetloglevel); the context array always includes `'exception' => $exception`, `'file'`, and `'line'`.
2. **Determine the status code.** [`getStatusCode()`](#exceptionhandlergetstatuscode) maps the exception class to an HTTP status.
3. **Resolve the message.** `app.debug` is read from `Config` (also wrapped in a try/catch so a broken config doesn't take the handler down with it). In debug mode the original exception message is used; otherwise the standard status text from `STATUS_TEXTS` is preferred, with one special case: `PayloadTooLargeException` always gets a user-friendly "Uploaded data is too large..." copy, even in production.
4. **Pick the response format.** The `Accept` header is consulted — `application/json` or `+json` types select JSON; everything else gets an HTML view.
5. **JSON path.** Returns `Response::json([...], $statusCode)`. The JSON body always has `message`; in debug mode it additionally contains `exception`, `file`, `line`, and `trace`.
6. **HTML path.** Tries `view("errors.{$statusCode}", $data, 'layouts.error', $statusCode)`. If that view is missing (`ViewNotFoundException`), it falls back to `view("errors.default", ...)`. If both are missing or any other rendering error occurs, [`renderFallbackHtml()`](#exceptionhandlerrenderfallbackhtml) is invoked as a last resort.
7. **Failure-while-failing.** If the response object itself can't be built (e.g., JSON encoding failed), `http_response_code(500)` is set, a plain-text message is echoed, and the script exits. This is the absolute last line of defense.

### ExceptionHandler::getStatusCode()

```php
protected static function getStatusCode(Throwable $exception): int
```

Maps an exception class to an HTTP status code. The current mapping (also enforced by the v2.0.1 PHPStan/audit pass):

| Exception | HTTP Status |
|-----------|-------------|
| `ViewNotFoundException` | 404 |
| `RouteNotFoundException` | 404 |
| `MethodNotAllowedException` | 405 |
| `RateLimitExceededException` | 429 |
| `AuthorizationException` | `$exception->getCode()` (default 401, often 403) |
| `CsrfTokenMismatchException` | 419 |
| `PayloadTooLargeException` | 413 |

Anything else returns 500. The mapping is deliberately a method rather than a property map so subclasses (or a downstream application) can override it.

### ExceptionHandler::getLogLevel()

```php
protected static function getLogLevel(Throwable $exception): string
```

Maps an exception to a PSR-3 log level:

| Exception | Log Level |
|-----------|-----------|
| `RouteNotFoundException` | `WARNING` |
| `MethodNotAllowedException` | `WARNING` |
| `CsrfTokenMismatchException` | `WARNING` |
| `AuthorizationException` | `WARNING` |
| `RateLimitExceededException` | `INFO` |
| (anything else) | `ERROR` |

The split between `WARNING` and `ERROR` is intentional: 404/405/419/401/403/429 are user-facing, expected outcomes (broken link, malicious CSRF, rate-limited scraper) and should not page on-call. Genuine 500s are still errors.

### ExceptionHandler::buildResponseData()

```php
protected static function buildResponseData(Throwable $exception, bool $debug, array $responseBody, int $statusCode): array
```

Builds the `$data` array literal that `handle()` passes to `view()` / `Response::json()`. Extracted as a separate method so the exact shape — and crucially, the contract that the `exception` key is **entirely absent** (not "present but null") when `$debug` is `false` — is directly testable via reflection.

The returned array always has `statusCode`, `statusText`, `message`, and `debug`. When `debug` is true, `exception`, `exceptionClass`, `file`, `line`, and `trace` are also present.

### ExceptionHandler::renderFallbackHtml()

```php
private static function renderFallbackHtml(int $statusCode, string $statusText, string $message, bool $debug, array $debugData = []): \SwallowPHP\Framework\Http\Response
```

The last-resort HTML renderer. Used when neither `errors.{status}` nor `errors.default` exist. Produces a self-contained HTML page with `error_log`-style styling. In debug mode the page additionally includes the exception class, file, line, and the formatted trace.

### ExceptionHandler::STATUS_TEXTS

```php
public const STATUS_TEXTS = [ ... ];
```

A public constant array mapping every standard HTTP status code from 100 through 511 to its canonical reason phrase. Used by `handle()`, `buildResponseData()`, and `renderFallbackHtml()` to fill in the user-facing copy when the original exception message is empty or `app.debug` is false.

---

## Application Configuration File (`src/Config/app.php`)

The framework-default `app.php` ships with sensible middle-of-the-road values. Each entry is documented inline in the source. The most important fields your application must override:

| Key | Default | Notes |
|-----|---------|-------|
| `name` | `'SwallowPHP'` | Override in your app's `config/app.php`. |
| `env` | `'production'` | Typically `local`, `staging`, `production`. |
| `debug` | `false` | **Must** be `false` in production; controls exception detail leak. |
| `url` | `'http://localhost'` | Used by URL helpers. |
| `timezone` | `'UTC'` | Applied to `date_default_timezone_set()` in `App::run()`. |
| `locale` | `'en'` | Applied to `setlocale(LC_TIME, ...)`. |
| `key` | `null` | **Required** for encrypted cookies. Set in `.env` as `APP_KEY`. |
| `cipher` | `'AES-256-CBC'` | Cookie encryption cipher. |
| `storage_path` | `null` | Set to an absolute path (e.g. `<BASE_PATH>/storage`). |
| `view_path` | `null` | Fallback is `<BASE_PATH>/resources/views`. |
| `pagination_view` | `null` | Set to a view dotted-path to override the default Bootstrap pager. |
| `controller_namespace` | `null` | Default controller namespace (e.g. `\\App\\Controllers`). |
| `max_execution_time` | `30` | Applied to `set_time_limit()`. |
| `ssl_redirect` | `false` | Force HTTPS via 301. |
| `trusted_proxies` | `[]` | IPs allowed to set `X-Forwarded-*` headers. |
| `gzip_compression` | `true` | Whether to enable `zlib.output_compression`. |
| `error_reporting_level` | `E_ALL` | Override based on `env('APP_DEBUG')`. |
| `log_path` | `null` | Default `null` disables the framework's optional file logger; set via `env('LOG_PATH')`. |
| `minify_html` | `false` | Enable via `APP_MINIFY_HTML=true` to strip whitespace from view output. |

The commented lines in the file show the `env('...')` form you should use in your own `config/app.php`:

```php
<?php
// config/app.php in your application

return [
    'name'     => env('APP_NAME', 'MyApp'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'locale'   => env('APP_LOCALE', 'en'),
    'key'      => env('APP_KEY'),
    'cipher'   => 'AES-256-CBC',

    'storage_path' => env('STORAGE_PATH', dirname(__DIR__) . '/storage'),
    'view_path'    => env('VIEW_PATH', dirname(__DIR__) . '/resources/views'),

    'ssl_redirect' => (bool) env('SSL_REDIRECT', false),
    'error_reporting_level' => env('APP_DEBUG', false)
        ? E_ALL
        : (E_ALL & ~E_DEPRECATED & ~E_NOTICE),
];
```

See [`docs/configuration.md`](configuration.md) for the full reference of every config file (`database.php`, `session.php`, `cache.php`, `logging.php`, `auth.php`, `security.php`).
