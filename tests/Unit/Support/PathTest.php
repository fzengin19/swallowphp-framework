<?php

use SwallowPHP\Framework\Support\Path;

test('relative path is joined onto the base', function () {
    expect(Path::joinAbsolute('/var/lib/app/storage', 'db.sqlite'))
        ->toBe('/var/lib/app/storage/db.sqlite');
});

test('base with trailing slash is normalised', function () {
    expect(Path::joinAbsolute('/var/lib/app/storage/', 'db.sqlite'))
        ->toBe('/var/lib/app/storage/db.sqlite');
});

test('relative path with leading slash is treated as absolute (POSIX)', function () {
    // POSIX semantics: any leading '/' (or '\') turns the path into an
    // absolute path. The framework must NOT silently re-prefix the base
    // onto a path whose first character is a separator.
    expect(Path::joinAbsolute('/var/lib/app/storage', '/db.sqlite'))
        ->toBe('/db.sqlite');
});

test('path with multiple leading separators is treated as absolute', function () {
    expect(Path::joinAbsolute('/var/lib/app/storage', '//db.sqlite'))
        ->toBe('//db.sqlite');
});

test('nested relative path is preserved', function () {
    expect(Path::joinAbsolute('/var/lib/app/storage', 'cache/data.json'))
        ->toBe('/var/lib/app/storage/cache/data.json');
});

test('posix absolute path is returned as-is', function () {
    expect(Path::joinAbsolute('/var/lib/app/storage', '/srv/external/db.sqlite'))
        ->toBe('/srv/external/db.sqlite');
});

test('posix absolute path with backslash prefix is returned as-is', function () {
    // On POSIX, a leading backslash is treated as absolute (some legacy code
    // paths emit these on Windows-shared mounts). The framework must not
    // prefix the base onto it.
    expect(Path::joinAbsolute('/var/lib/app/storage', '\\srv\\external\\db.sqlite'))
        ->toBe('\\srv\\external\\db.sqlite');
});

test('windows drive-letter path is returned as-is', function () {
    expect(Path::joinAbsolute('C:\\app\\storage', 'D:\\data\\app.db'))
        ->toBe('D:\\data\\app.db');
});

test('empty path returns the base with trailing separators stripped', function () {
    expect(Path::joinAbsolute('/var/lib/app/storage', ''))
        ->toBe('/var/lib/app/storage');
    expect(Path::joinAbsolute('/var/lib/app/storage/', ''))
        ->toBe('/var/lib/app/storage');
});

test('regression: storage prefix must not be prepended to absolute sqlite path', function () {
    // This is the failure mode AC-?? reported: when a user sets
    //   'database' => '/home/user/myapp/storage/database.sqlite'
    // the old code produced
    //   '/home/user/myapp/storage/home/user/myapp/storage/database.sqlite'
    // leaving a stray file inside the storage tree.
    $absolute = '/home/user/myapp/storage/database.sqlite';
    $resolved = Path::joinAbsolute('/home/user/myapp/storage', $absolute);
    expect($resolved)->toBe($absolute);
    // And explicitly: it must NOT contain the base twice.
    expect(substr_count($resolved, '/home/user/myapp/storage'))->toBe(1);
});
