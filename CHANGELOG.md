# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.1] - 2026-08-13

### Fixed
- Documentation now matches the actual v2.0.0 API across 11 files
  (README.md, docs/authentication.md, docs/configuration.md,
  docs/database.md, docs/helpers.md, docs/http.md, docs/logging.md,
  docs/middleware.md, docs/routing.md, docs/session.md, docs/views.md).
  Highlights: removed references to a nonexistent `auth()` helper and
  `Model::all()`; corrected `Model::on()` event-callback examples (the
  callback receives the model instance, not a data array) and documented
  the return-`false` abort signal; documented `Database::deleteAll()` and
  the v2.0.0 no-WHERE `delete()` exception; documented `update()`'s
  `int|false` return; documented `whereNull()`/`whereNotNull()` and
  `where(col, null)` → `IS NULL` translation; fixed the `paginate()`
  example's nonexistent 4-argument form; fixed `request()->query($key)`
  examples to use `getQuery($key)`; fixed flash-message examples that
  read via plain `session($key)` (which misses the flash bucket) to use
  `getFlash()`/`hasFlash()`; corrected `getClientIp()` to the documented
  `getIp()` helper; corrected the remember-me cookie example to the real
  string format; corrected `ViewNotFoundException`'s HTTP status (404,
  not 500); corrected the `raw()` helper's return type documentation;
  documented `app.trusted_proxies`; and several smaller signature/import
  corrections. No source code changed in this release.
- Added `tests/Feature/DocsConsistencyTest.php` — a Pest regression suite
  (47 tests) that pins the corrected documentation claims above against
  the live source, including source-aware checks for the middleware
  pipeline order, the CSRF `/api/*` exemption, and the upload-size 413
  response body, so these docs can't silently drift again.

## [2.0.0] - 2026-08-13

### Action required
- `Database::delete()` now throws `\RuntimeException` if called with no
  `WHERE` condition, instead of silently deleting every row. Any code that
  relies on a bare `->delete()` to clear a table must switch to the new
  `->deleteAll()`.
- `Database::update()`'s return type widened from `int` to `int|false`; a
  failed UPDATE now returns `false` instead of `0`. Code that type-hints the
  return value as `int` (or otherwise assumes it's never `false`) must be
  updated to handle the new failure signal.

These are the reasons this release is a major version bump: both are
deliberate, behavior-changing fixes to prevent silent data loss / silent
failure reporting (see "Fixed" below for the bugs they close).

### Changed
- `Database::delete()` now throws `\RuntimeException` when called with no
  `WHERE` condition (previously deleted every row in the table silently);
  use the new `Database::deleteAll()` for the old unguarded behavior. The
  guard also rejects a `where(...)` closure that renders no predicate (e.g.
  an empty nested closure), which previously bypassed it the same way.

### Fixed
- `where($column, null)` and `where($column, '=', null)` now produce
  `IS NULL` instead of binding a literal `NULL` to `=` (which never matches).
  `where($column, '!=', null)` / `where($column, '<>', null)` produce
  `IS NOT NULL` for symmetry.
- `Model::fireEvent()` now honors the documented abort signal: a listener
  that returns `false` stops propagation to later listeners on the same event.
- `Database::update()` returns `false` on a PDOException (previously
  returned `0`, which `Model::save()` could not distinguish from a
  successful no-op update). A genuinely-failed UPDATE therefore no longer
  fires the `updated`/`saved` events and `save()` reports failure.
- `AuthenticatableTrait::setRememberToken()` now writes to the attributes
  array directly, bypassing the model's mass-assignment guard so that
  "remember me" logins persist on User models whose `$fillable` does not
  list `remember_token`.
- `Model::where()`'s static wrapper now forwards only the arguments the
  caller actually supplied, instead of always passing 4 args through to
  `Database::where()`. The always-4-args form broke ordinary calls like
  `Model::where('status', 'active')` (compiled to `status IS NULL`) and
  `Model::where('age', '>=', 18)` (compiled to `age = '>='`).
- `Database::where()` now recognizes its own explicit 4-argument
  `(column, operator, value, boolean)` shape; previously a 4-arg call fell
  through to a fallback branch that bound the operator string as the value.

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
