# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-07-20

### Security
- **Client IP spoofing fixed**: `Request::getClientIp()` no longer trusts the
  spoofable `X-Forwarded-For` / `Client-IP` headers by default. It returns
  `REMOTE_ADDR` unless the direct peer is listed in `config('app.trusted_proxies')`
  (use `['*']` to trust all). This restores the integrity of the Auth brute-force
  lockout and the rate limiter, which key off the client IP.
  **Action required for proxied/load-balanced deployments**: set
  `app.trusted_proxies` or client IPs will resolve to the proxy address.
- **Auth user enumeration hardened**: a constant-time dummy `password_verify()`
  now runs when no user matches, so response timing no longer reveals valid emails.
- **SQL identifiers escaped**: column/table names are backtick-escaped (backticks
  doubled) and comparison operators are validated against a whitelist across
  `where`/`whereIn`/`whereBetween`/`orderBy`/`insert`/`update`.

### Added
- **`whereNull()` / `whereNotNull()`** on the query builder and `Model`, giving a
  proper way to express `IS NULL` / `IS NOT NULL` conditions.
- **`Model::__isset()` / `__unset()`**: `isset()`, `empty()` and `??` now work
  correctly on model attributes and relations (previously always read as unset).
- `app.trusted_proxies` configuration key.

### Fixed
- **Flash messages**: removed a double flash-aging call that wiped the current
  request's flash payload before controllers could read it.
- **Rate limiter**: the fixed window is anchored to its start and no longer
  extends its TTL on every request, so clients are no longer locked out forever
  under continuous traffic.
- **Query builder state corruption**: `Model::query()` now returns an independent
  builder per call (container factory) while sharing a single pooled PDO
  connection, so concurrently held builders/relations no longer reset each other.
- `Database::first()` no longer leaves the builder permanently limited to 1 row.
- `Model::create()` no longer fires the `creating` event twice.
- `minifyHtml()` HTML-comment stripping regex (was a silent no-op).

### Changed
- Require PHP >= 8.2; fixed an implicit-nullable constructor parameter deprecated
  in PHP 8.4.

### Performance
- `FileCache` encodes the cache once per write on the common path (was twice).
- Router compiles each route's regex once instead of on every dispatch.
- `Cookie` memoizes the decoded application key; `LoggedPDOStatement` only
  captures bindings when query logging is enabled.

## [1.1.0] - 2024-12-24

### Added
- **Subdirectory Support**: Routes and file URLs now respect `app.path` configuration for subdirectory installations
- **HTML Escape Helpers**: New `e()`, `raw()`, and `attr()` functions for XSS protection in views
- **ValidatePostSize Middleware**: Returns proper 413 error for oversized uploads instead of failing silently
- **PayloadTooLargeException**: New exception class for handling large request payloads

### Changed
- **BREAKING**: Models now require explicit `$table` property definition; automatic table name inference has been removed

### Fixed
- Remember me cookie lifetime now correctly uses days instead of minutes
- Queued cookies are now properly sent on redirect responses
- Large file uploads return 413 (Payload Too Large) instead of incorrect CSRF 419 error

## [1.0.1] - 2024-12-XX

### Fixed
- BASE_PATH fallback for Composer installations
- Auth config key name correction for remember token lifetime
- `cursorPaginate()` return type in Model class
- Allow colon character in cache keys
- Standard `app.log` filename in logging config

## [1.0.0] - 2024-12-XX

### Added
- Initial framework release
- Dependency Injection with League Container
- Fluent Query Builder with PDO
- Eloquent-style ORM with relationships and events
- Expressive routing with middleware support
- Built-in authentication with remember me and brute-force protection
- File-based session management with flash messages
- PSR-16 compatible caching (file, SQLite drivers)
- PSR-3 compatible file-based logging
- CSRF protection middleware
- Per-route rate limiting
