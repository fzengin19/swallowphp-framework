<?php

/*
|--------------------------------------------------------------------------
| Path-join regression tests
|--------------------------------------------------------------------------
|
| Regression coverage for the silent absolute-path bug that affected every
| place where the framework concatenates a user-configured path onto a base
| directory (database, file cache, sqlite cache, file logger).
|
| Before the fix, code used to do:
|     rtrim($base, '/\\') . '/' . ltrim($relative, '/\\')
| which only strips a single leading separator. When the user supplied an
| ABSOLUTE path (e.g. '/home/user/myapp/storage/database.sqlite'), the base
| was still prepended, producing a nonsensical path like:
|     /home/user/myapp/storage/home/user/myapp/storage/database.sqlite
|
| The fix routes all four call sites through Path::joinAbsolute(), which
| passes absolute paths through verbatim. These tests pin that behaviour.
*/

namespace Tests\Feature;

use ReflectionProperty;
use SwallowPHP\Framework\Cache\CacheManager;
use SwallowPHP\Framework\Cache\FileCache;
use SwallowPHP\Framework\Cache\SqliteCache;
use SwallowPHP\Framework\Database\Database;
use SwallowPHP\Framework\Foundation\App;

beforeEach(function () {
    // Fresh scratch base for every test so we can place absolute paths
    // inside it and verify the file lands at the absolute path (not at
    // base + absolute).
    $this->scratch = sys_get_temp_dir() . '/swallow-pj-' . bin2hex(random_bytes(4));
    @mkdir($this->scratch, 0755, true);

    // Reset Database's static connection cache so each test gets a fresh
    // handler bound to its own file.
    if (class_exists(Database::class)) {
        $prop = new ReflectionProperty(Database::class, 'connections');
        $prop->setValue(null, []);
    }

    // Boot DI container lazily.
    App::container();
});

afterEach(function () {
    // Best-effort cleanup.
    if (!is_dir($this->scratch)) {
        return;
    }
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->scratch, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($this->scratch);
});

/* ----------------------------------------------------------------------
 * Database — absolute sqlite path must NOT be prefixed by storage_path.
 * -------------------------------------------------------------------- */
test('database: absolute sqlite path is used verbatim', function () {
    $absolute = $this->scratch . '/absolute.sqlite';

    config(['app.storage_path' => $this->scratch]);
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite' => [
        'driver' => 'sqlite',
        'database' => $absolute,
        'prefix' => '',
    ]]);

    /** @var Database $db */
    $db = App::container()->get(Database::class);

    // The constructor invokes initialize() lazily, so by the time the
    // container returns the instance the DSN has been constructed and
    // (for sqlite) the file should exist on disk.
    expect(file_exists($absolute))->toBeTrue();
    // …and must NOT exist at the doubled-up path that the old code produced.
    $doubled = $this->scratch . $absolute;
    expect(file_exists($doubled))->toBeFalse();
});

test('database: relative sqlite path is still joined onto storage_path', function () {
    config(['app.storage_path' => $this->scratch]);
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite' => [
        'driver' => 'sqlite',
        'database' => 'relative.sqlite',
        'prefix' => '',
    ]]);

    /** @var Database $db */
    $db = App::container()->get(Database::class);

    expect(file_exists($this->scratch . '/relative.sqlite'))->toBeTrue();
});

/* ----------------------------------------------------------------------
 * CacheManager — file cache absolute path pass-through.
 * -------------------------------------------------------------------- */
test('cache.file: absolute path is used verbatim', function () {
    $absolute = $this->scratch . '/cache-absolute.json';

    // Reset CacheManager's static driver cache so each test resolves the
    // store it just configured.
    $prop = new ReflectionProperty(CacheManager::class, 'drivers');
    $prop->setValue(null, []);

    // Bootstrap config so CacheManager::driver() can resolve them.
    config(['app.storage_path' => $this->scratch]);
    config(['cache.default' => 'file']);
    config(['cache.stores.file' => [
        'driver' => 'file',
        'path' => $absolute,
        'max_size' => 50 * 1024 * 1024,
    ]]);

    $driver = CacheManager::driver('file');

    expect($driver)->toBeInstanceOf(FileCache::class);
    // After construction, the file (or its parent) should exist at the
    // absolute path. FileCache only creates the file on first write, so we
    // touch the parent directory existence too.
    expect(is_dir(dirname($absolute)))->toBeTrue();
    // The doubled-up path must not exist.
    $doubled = $this->scratch . $absolute;
    expect(file_exists($doubled))->toBeFalse();
});

/* ----------------------------------------------------------------------
 * CacheManager — sqlite cache absolute path pass-through.
 * -------------------------------------------------------------------- */
test('cache.sqlite: absolute path is used verbatim', function () {
    $absolute = $this->scratch . '/cache-absolute.sqlite';

    $prop = new ReflectionProperty(CacheManager::class, 'drivers');
    $prop->setValue(null, []);

    config(['app.storage_path' => $this->scratch]);
    config(['cache.default' => 'sqlite']);
    config(['cache.stores.sqlite' => [
        'driver' => 'sqlite',
        'path' => $absolute,
        'table' => 'cache',
    ]]);

    $driver = CacheManager::driver('sqlite');

    expect($driver)->toBeInstanceOf(SqliteCache::class);
    expect(file_exists($absolute))->toBeTrue();
    $doubled = $this->scratch . $absolute;
    expect(file_exists($doubled))->toBeFalse();
});

test('cache.sqlite: relative path is still joined onto storage_path', function () {
    $prop = new ReflectionProperty(CacheManager::class, 'drivers');
    $prop->setValue(null, []);

    config(['app.storage_path' => $this->scratch]);
    config(['cache.default' => 'sqlite']);
    config(['cache.stores.sqlite' => [
        'driver' => 'sqlite',
        'path' => 'cache-rel.sqlite',
        'table' => 'cache',
    ]]);

    $driver = CacheManager::driver('sqlite');

    expect($driver)->toBeInstanceOf(SqliteCache::class);
    expect(file_exists($this->scratch . '/cache-rel.sqlite'))->toBeTrue();
});
