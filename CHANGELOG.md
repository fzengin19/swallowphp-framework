# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
