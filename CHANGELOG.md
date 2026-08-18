# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.4] - 2026-08-18

Bug fix: absolute paths passed as `database.sqlite`, `cache.stores.file.path`,
`cache.stores.sqlite.path`, or any logger `path` config value were being
silently prefixed by `app.storage_path`, producing a doubled-up path like
`/var/lib/app/storage/var/lib/app/db.sqlite` and a stray file inside the
storage tree. Patch release — public API unchanged, only the path-join
internal is fixed.

### Fixed
- **Database (sqlite)** — `src/Database/Database.php` no longer prefixes
  `app.storage_path` when `database.connections.sqlite.database` is already
  an absolute path. POSIX absolute (`/foo`), Windows drive-letter
  (`D:\data\app.db`), and UNC (`\\server\share`) paths are now used verbatim.
- **Cache (file)** — `src/Cache/CacheManager.php` `createFileDriver()` now
  honours absolute `cache.stores.file.path` values.
- **Cache (sqlite)** — `src/Cache/CacheManager.php` `createSqliteDriver()`
  now honours absolute `cache.stores.sqlite.path` values.
- **Logging (file)** — `src/Foundation/App.php` logger `single` driver now
  honours an absolute `path` config value.

### Added
- **src/Support/Path.php** (new) — `Path::joinAbsolute(string $base, string
  $path)` centralises the "join onto base unless absolute" logic. All four
  call sites above route through it. Strips a single trailing separator from
  the base, returns the path verbatim when it's absolute (POSIX / POSIX
  backslash / Windows drive-letter). Empty path returns the base.
- **tests/Unit/Support/PathTest.php** (new) — 10 unit tests covering
  relative join, POSIX absolute, Windows drive-letter, empty path, trailing
  base slash, and the exact regression case.
- **tests/Feature/PathJoinRegressionTest.php** (new) — 5 integration tests
  proving the four call sites no longer prefix the storage path when given
  an absolute path, and that the relative-path behaviour is preserved.

## [4.0.0] - 2026-08-17 (yanked)

This release was tagged and reverted in the same day (see commit 501e1c1, which softened the
AC-71 mass-assignment fix back to a non-breaking deprecation warning). The 4.0.0 bump was
withdrawn because the change it introduced was not actually breaking — the deprecation
warning fires on the same code path that previously worked silently, so consumer
applications did not need a major-version signal. The reverted change lives on as the
deprecation note in [3.0.2] below; no separate 4.x release was published.

## [3.0.3] - 2026-08-17

Documentation accuracy sweep + dead-config cleanup. Patch release — no API changes, no
runtime behavior changes. Source-identical to 3.0.2.

### Changed
- **README.md** — version badge 2.0.0 → 3.0.3 (Total Downloads shield retained — package
  is published on Packagist),
  Quick Start now uses `$request->routeParams()['id']` for URL-segment parameters
  (v3 API split), Sessions → Session (singular), added `composer test` one-liner,
  Directory Structure now lists `config/logging.php` and `config/security.php`,
  requirement list trimmed to PHP 8.2+ and PDO.
- **CHANGELOG.md** — added `[4.0.0] - yanked` record (was missing), fixed intro paragraph,
  corrected v1.1.0 date (2024 → 2025).
- **docs/http.md** — `APP_KEY` setup section now warns that the env var must also be
  mapped into `src/Config/app.php` (`'key' => env('APP_KEY')`) or every cookie operation
  silently fails; documented `Cookie::set()` queue semantics (cookies are queued, sent
  by `Response::sendHeaders()`); added `Request::routeParams()` / `setRouteParams()`
  subsection; documented `setStatusCode()` throws for codes outside `STATUS_TEXTS`;
  `Response::redirect()` CR/LF + protocol-relative guards; `getScheme()` ignores
  `X-Forwarded-Proto`; corrected status-text 419 to `Page Expired` (matches
  `STATUS_TEXTS`); encryption now describes the HMAC-derived enc/mac subkeys correctly.
- **docs/routing.md** — Resolution Order step 1 corrected: route-parameter DI binding
  reads **only** from `$request->routeParams()` (not from the legacy merged data);
  contradiction note rewritten; documented `{param+}` greedy capture, `_method` POST
  spoofing, `Router::getRequest()`, array-form controller action, `controller_namespace`
  fallback to `\App\Controllers`.
- **docs/session.md** — `lottery` removed from example config (dead key); `files` default
  aligned with configuration.md; added `ageFlashData()` row to API reference; CSRF token
  example now points at `VerifyCsrfToken` auto-management; `regenerate()` lazy-start
  behavior documented; flash-lifecycle parenthetical softened for `reflash()`/`keep()`.
- **docs/configuration.md** — Env `Variable Storage` no longer lists `$_SERVER` as a
  write target (kept only as read-only BC); added `log_path` row to app.php table;
  added `file_permission` row to session.php table; `app.key` description corrected
  (32-byte base64-encoded, not 32-character); `database.log_queries` example aligned
  to literal `false` (matches table + database.md).
- **docs/cache.md** — `cache.ttl` scope note corrected (applies to `increment()` /
  `decrement()` / `RateLimit`, not just RateLimit); `cache.prefix` row removed (key
  also deleted from `src/Config/cache.php`).
- **docs/database.md** — `whereRaw()` 3rd boolean joiner documented; `Model::insert()`
  static helper added; unverifiable version stamps dropped.
- **docs/helpers.md** — `webpImage()` examples rewritten with relative paths
  (absolute paths are rejected by `isUnsafeFilesystemPath()`); signature now shows all
  defaults; `raw()` wording aligned with implementation; `csrf_token()` failure path
  documented; `route()` `RouteNotFoundException` documented; `getIp()` trusted-proxy
  precondition documented; `formatDateForHumans()` future-date + invalid-input
  behavior added; `isUnsafeFilesystemPath()` added to Quick Reference; cache helpers
  now cover `getMultiple` / `setMultiple` / `increment` / `decrement`.
- **docs/views.md** — `Always Escape Output` recommends framework `e()` / `attr()`
  helpers over raw `htmlspecialchars()`; added Pagination View subsection
  (`app.pagination_view`); documented `layouts.error` view contract used by
  `ExceptionHandler`; `view()` return-type now references the FQ `Response` class.
- **docs/logging.md** — Exception JSON example corrected to show Throwable as a JSON
  object (not a formatted string); Context Value Types table clarifies Throwable
  formatting applies only to `{key}` placeholders, JSON context keeps the raw
  Throwable; TOC now includes Best Practices anchor.
- **docs/middleware.md** — TOC and Validate Post Size placement reconciled;
  AdminMiddleware example returns the redirect.
- **docs/authentication.md** — `logout()` step list now has 5 steps (added the
  remember-token DB revocation step, so a cookie captured before logout cannot
  re-authenticate).

### Added
- **docs/foundation.md** (new) — Application boot, `Env::load()`, Config loader,
  `ExceptionHandler` (4 method tables, full TOC).
- **docs/exceptions.md** (new) — Catalog of all framework exceptions with their
  HTTP status mappings (11 method tables, full TOC).
- **docs/contracts.md** (new) — `CacheInterface` and `AuthenticatableInterface`
  extension points, with implementation examples (2 method tables, full TOC).

### Removed
- **src/Config/cache.php** — `cache.prefix` config key (defined but unused by any
  driver; no application code references it).
- **src/Config/session.php** — `session.lottery` config key (placeholder for a
  feature that was never built; no application code references it).

### Changed (config)
- **src/Config/app.php** — added default `'trusted_proxies' => []` (was previously
  documented but missing from the file).
- **composer.json** — removed hand-maintained `"version": "3.0.2"` field; version
  now lives only in git tags (per Composer convention).

### Tests
- **tests/Feature/DocsConsistencyTest.php** — AC-1 and AC-31 tests updated to assert
  the corrected v3 documentation (route-param binding from `$request->routeParams()`
  only; README mentions current 3.x version).

## [3.0.2] - 2026-08-17

Three fixes from a fresh, lightweight pentest scan across previously-unaudited areas of
the framework (Routing, Auth, Database, Views, file handling, HTTP layer).
Bugfix/stabilization scope — no breaking changes in this release.

### Deprecated
- **Mass-assignment via an undeclared `$fillable`.** A `Model` subclass that doesn't
  declare `$fillable` currently accepts every attribute except `$guarded` (`['id']` by
  default) via `fill()`, `create()`, and direct property assignment (`$model->x = y`) —
  this is a mass-assignment vulnerability class if such a model ever receives unfiltered
  input. **This still works exactly as before in this release** (non-breaking) — but
  every time this fallback is what let a key through, a PSR-3 `warning`-level log entry
  is now emitted (or `error_log()` if no logger is available), naming the model class
  and the key. **Action recommended, not required:** declare `protected array $fillable
  = [...]` explicitly on any model relying on this fallback to silence the warning and
  opt into the safe behavior now. This fallback is planned for removal in a future major
  version once given a migration window — declaring `$fillable` explicitly today avoids
  any future breaking impact.

### Fixed
- `Response::redirect()` now rejects a protocol-relative (`//host/...`) target and a
  target containing an embedded CR/LF, closing an open-redirect/phishing vector, while
  still allowing legitimate external redirects (OAuth callbacks, payment gateway
  returns, etc.) via an explicit scheme.
- `view()`/layout resolution now confines the resolved file path to its configured base
  directory via `realpath()`, closing a latent (symlink-reachable) local-file-inclusion
  gap that was previously safe only by coincidence of the view-name-to-path transform,
  not by an explicit, robust guard.

### Added
- `tests/Feature/PentestFixesTest.php` — regression suite for the 3 fixes above.

## [3.0.1] - 2026-08-14

Continuation of the correctness/security hardening series: a fresh comprehensive
bugfix/stabilization scan (explicitly not new features), covering all 4 sections —
security-critical fixes, Database/Model correctness, Session, Foundation, and Cache/Log
stabilization. No breaking changes; every fix here either closes a genuine bug or
rejects previously-unvalidated invalid/malicious input (never silently, always with a
clear error), consistent with this project's existing "Fixed, not Action required"
convention for that class of change.

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
- `SessionManager::start()` now logs a clear warning when it finds an already-active
  session it never registered its custom save handler for (e.g. `session.auto_start=1`,
  or unrelated code calling `session_start()` first) — registration can't happen
  retroactively (PHP requires `session_set_save_handler()` before `session_start()`),
  but this closes the previous silent fallback to PHP's default session handler.
- `FileSessionHandler::read()`/`write()`/`destroy()` no longer let an
  `\InvalidArgumentException` escape past `SessionHandlerInterface`'s contract for a
  malformed session ID.
- `FileSessionHandler::gc()` no longer deletes a session file whose `filemtime()` call
  failed (previously coerced an unreadable mtime to "definitely expired").
- `SessionManager::regenerate()` now lazily starts the session (matching the rest of the
  class's accessors) instead of silently returning `false` when called before the
  session is active.
- `SessionManager::reflash()`/`keep()` no longer let a stale re-flashed value overwrite
  a freshly-flashed value under the same key.
- `SqliteCache::set()` now computes the expiration timestamp once instead of via two
  independent `ttlToTimestamp()` calls.
- `FileLogger`'s `minLevel` lookup is now case-insensitive, closing a silent fallback to
  logging everything at DEBUG when configured with an uppercase level name (e.g.
  `'WARNING'`).
- `FileLogger` now applies safe permissions to a pre-existing log file too, not only a
  newly-created one — while rejecting symlinks (`chmod()` follows symlinks and could
  otherwise silently change a sensitive target's mode) and preserving any stricter
  existing permission instead of loosening it.
- `formatDateForHumans()` no longer produces a nonsensical negative-prefixed string for
  future dates (e.g. `'-3600 saniye önce'`); future dates now use `'sonra'` wording.

### Added
- `tests/Feature/Phase4Section1Test.php` — regression suite for the Section 1 fixes.
- `tests/Feature/Phase4Section2Test.php` — regression suite for the Section 2 fixes.
- `tests/Feature/Phase4Section3bTest.php` — regression suite for the Section 3b fixes.
- `tests/Feature/Phase4Section3aTest.php` — regression suite for the Section 3a fixes.
- `tests/Feature/Phase4Section4Test.php` — regression suite for the Section 4 fixes.

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

## [1.1.0] - 2025-12-24

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

> **Note:** 1.2.0 was rolled directly into 1.3.0; no separate 1.2.0 release was published.

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
