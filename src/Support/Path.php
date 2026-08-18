<?php

declare(strict_types=1);

namespace SwallowPHP\Framework\Support;

/**
 * Filesystem path helpers.
 *
 * The framework historically joined user-configured relative paths (e.g. SQLite
 * database files, log files, cache files) onto a base directory by doing
 * `rtrim($base, '/\\') . '/' . ltrim($relative, '/\\')`. That mute/ltrim pair
 * only strips a single leading separator, so when a user supplied an absolute
 * path (e.g. `/var/lib/myapp/db.sqlite` or `C:\data\app.db`) the base directory
 * was still prepended, producing nonsensical paths like
 * `/var/lib/myapp/storage/var/lib/myapp/db.sqlite`.
 *
 * Path::joinAbsolute() preserves absolute paths verbatim and only joins
 * relative paths onto the base.
 */
final class Path
{
    /** Prevent instantiation. */
    private function __construct()
    {
    }

    /**
     * Join a base directory with a configured path.
     *
     * If $path is absolute (POSIX absolute, Windows drive-letter, or UNC path),
     * it is returned as-is. Otherwise it is appended to $base with a single
     * separator and any leading separator on $path is stripped.
     *
     * @param string $base Base directory. Trailing separators are tolerated.
     * @param string $path Relative or absolute path to join onto $base.
     *
     * @return string Joined absolute path (or $path itself if it was absolute).
     */
    public static function joinAbsolute(string $base, string $path): string
    {
        if ($path === '') {
            return rtrim($base, '/\\');
        }

        // POSIX absolute path: starts with '/' or '\'
        if ($path[0] === '/' || $path[0] === '\\') {
            return $path;
        }

        // Windows drive-letter path: 'C:', 'D:', etc. followed by a separator
        // or end-of-string. Must be checked before the UNC test below because
        // 'C:\foo' starts with 'C', not '\\'.
        if (strlen($path) >= 2 && $path[1] === ':') {
            return $path;
        }

        // Relative path: join onto the base directory.
        return rtrim($base, '/\\') . '/' . ltrim($path, '/\\');
    }
}
