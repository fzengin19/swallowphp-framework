# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.1] - 2026-08-14 (in progress)

Continuation of the correctness/security hardening series: a fresh comprehensive
bugfix/stabilization scan (explicitly not new features). This entry covers Sections 1-3b
(security-critical; Database/Model correctness; Session; Foundation) of a 4-section
sweep; Section 4 (Cache/Log stabilization) will be appended as it lands.

### Fixed
- `Database::select()`/`table()` now quote identifiers before interpolating them into
  SQL (`select()` closes a raw-column-name injection vector for plain identifiers;
  `table()` validates against a strict identifier pattern). Expression-style `select()`
  calls (e.g. `COUNT(*) as total`) are unaffected — deliberate scope: this closes the
  raw-identifier vector only.
- `Database::initialize()` validates `host`/`port`/`database`/`charset` config values
  for mysql/pgsql connections before building the DSN, rejecting DSN-breaking characters.
- `VerifyCsrfToken::isReading()` no longer trusts the `_method` body/query override —
  a `POST` with `_method=GET` in its body is now correctly CSRF-checked instead of
  silently bypassing the token comparison.
- `FileLogger` sanitizes `\r`/`\n` in log messages, closing a log-injection/log-forging
  vector reachable via `mailto()` and `webpImage()` (both funnel through the same
  logger choke point).
- `FileCache::saveCache()` uses a CSPRNG-backed temp filename (`random_bytes`) instead
  of `uniqid(mt_rand())`, closing a symlink-attack surface on its atomic rename.
- `Auth::logout()` now invalidates the user's server-side remember-me token, so a
  remember-me cookie captured before logout can no longer re-authenticate afterward.
- `ExceptionHandler` no longer includes the raw exception object in the data handed to
  the error view when `debug=false` (previously only the debug-only detail fields were
  gated; the exception object itself was not). The bundled default error views already
  guarded their own use of it, so this has no visible effect unless an app supplies a
  custom production error view that reads `$exception` directly.
- `Request::getBoundaryFromContentType()` no longer returns a degenerate `'--'`
  boundary for a malformed/empty `boundary=` value (which could corrupt multipart
  parsing), and no longer truncates a quoted boundary value at its first internal space.
- `Database::delete()` now returns `false` (not `0`) on a genuine `PDOException`,
  matching `update()`'s existing `int|false` pattern — `0` stays a legitimate "no rows
  matched" result, now distinguishable from an actual write failure.
- `Model::belongsTo()` short-circuits a `null` foreign key with an always-false
  predicate (mirroring `hasMany()`'s existing pattern) instead of round-tripping an
  `IS NULL` query against the related table.
- `Database::cursorPaginate()`'s `prev_page_url` no longer returns a dangling
  `?cursor=` link with an empty value; it points back at the base URL instead (no real
  previous-cursor value is tracked by this implementation).
- `Env::load()` no longer aborts the entire `.env` parse on one malformed line (empty or
  whitespace-containing variable name) — skips just that line with a warning instead.
- `Env::load()` no longer mirrors `.env` values into `$_SERVER` (only `$_ENV`/
  `putenv()`), closing a path where a `.env` entry could silently override
  request-supplied `$_SERVER` values like `HTTP_HOST`.
- `Config::set()` now throws instead of silently destroying an existing scalar value
  when a dotted sub-key traversal collides with it.

### Added
- `tests/Feature/Phase4Section1Test.php` — regression suite for the Section 1 fixes.
- `tests/Feature/Phase4Section2Test.php` — regression suite for the Section 2 fixes.
- `tests/Feature/Phase4Section3bTest.php` — regression suite for the Section 3b fixes.

In addition to the standard Keep-a-Changelog subsections (Added / Changed /
Deprecated / Removed / Fixed / Security), this project uses an additional
`### Action required` subsection as a documented convention: any release entry
that contains breaking changes needing explicit migration steps gets one
`Action required` block listing the specific call signatures or behaviors that
changed and the one-line migration a consumer needs to apply.

## [3.0.0] - 2026-08-13

Continuation of the correctness/security hardening series (v2.0.0, v2.0.1):
a second, deeper documentation-consistency pass (15 more findings — config
field accuracy, driver-support claims, formatting) plus six previously
deferred, now-verified code-level fixes. Three of the code fixes are
deliberate behavior changes — hence the major version bump.

### Action required
- `Cookie`'s encryption and MAC now use two independently-derived keys
  instead of reusing one key for both (closes a key-reuse anti-pattern).
  Any session/remember-me cookie issued before this deploy fails its MAC
  check under the new derivation and is treated as invalid — the same
  code path as any other tampered cookie (rejected cleanly, not a crash).
  Practical effect: every user is signed out on first request after
  upgrading; no other action needed.
- `Router::dispatch()` no longer merges query-string values into
  `Request::request()` (the body-only accessor) — it now only adds the
  route's own URL parameters. Code that read `$request->request()`
  expecting query-string values to be present there needs to switch to
  `$request->all()` (which still merges query + body, unchanged) or
  `$request->query()`.
- `RateLimiter` now skips rate limiting entirely (instead of applying it
  against one shared bucket) when the client's IP can't be resolved. If
  you depend on rate limiting for anonymous/unresolvable-IP traffic
  specifically, note that such requests are now unlimited rather than
  incorrectly sharing one limit across all such clients.

### Fixed
- `Cookie::encrypt()`/`decrypt()` derive separate encryption and MAC keys
  via HMAC-based key derivation instead of reusing one key for both
  primitives (see Action required above).
- `SqliteCache` validates its table name against `/^[A-Za-z0-9_]+$/` in
  the constructor before interpolating it into SQL, closing a
  defense-in-depth gap (not reachable via attacker input in the default
  config, but hardened regardless).
- `Route::executeAction()` now checks for an array-form controller action
  (`[Controller::class, 'method']`) before the `is_callable()` closure
  check. `is_callable()` returns `true` for an array naming a **static**
  method, which previously sent it into the closure-reflection branch and
  crashed with `TypeError` (array-form actions naming non-static methods
  were never affected by this).
- A route parameter that can't be coerced to a controller method's
  declared scalar type (e.g. `/items/abc` against `show(int $id)`)
  previously crashed with a raw, unhandled `TypeError`. It now throws the
  same `MethodNotFoundException` (404) this function already uses for
  other "route doesn't resolve to a valid call" cases. A genuine
  `TypeError` thrown from inside a controller method's own body is not
  affected — only the specific argument-binding error is translated.
  Purely-numeric route segments continue to coerce correctly, including
  zero-padded ones (e.g. `/items/00042` → `42`) and out-of-range values
  (which are preserved as the raw string rather than silently truncated
  to `PHP_INT_MAX`).
- `Router::dispatch()` no longer smuggles query-string values into the
  request body accessor (see Action required above); route parameters
  remain available via a new dedicated `Request::routeParams()` accessor
  as well as the existing `request()`/`all()`.
- `RateLimiter::execute()` no longer collapses every client with an
  unresolvable IP into one shared cache bucket (which let one such client
  exhaust the limit and lock out every other one); it now skips limiting
  for that request and logs a warning (see Action required above).
- `webpImage()` rejects a `$destinationDir` (or `$source`) containing
  `..` path-traversal segments, an absolute path, or a null byte, falling
  back to the function's existing default output directory instead of
  writing outside the intended subtree.
- 15 more documentation findings from the same audit that produced v2.0.1
  (config fields documented as active when they're not consumed by any
  driver; PostgreSQL listed as supported despite MySQL/SQLite-only
  identifier quoting; cache TTL semantics; cursor-pagination's broken
  `hasMorePages()`/nonexistent `nextCursor()` corrected to the working
  `nextPageUrl()`; session/cache config accuracy; CHANGELOG date
  placeholders; a documented middleware pipeline order; and more).

### Added
- `tests/Feature/Phase3HardeningTest.php` — a Pest regression suite
  covering all of the above code fixes, including source-aware dispatch
  tests (not just unit tests of the underlying helpers) and named
  mutations for each fix.
- Extended `tests/Feature/DocsConsistencyTest.php` with the 15 new
  documentation findings above.

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

## [1.0.1] - 2025-12-23

### Fixed
- BASE_PATH fallback for Composer installations
- Auth config key name correction for remember token lifetime
- `cursorPaginate()` return type in Model class
- Allow colon character in cache keys
- Standard `app.log` filename in logging config

## [1.0.0] - 2025-12-22

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
