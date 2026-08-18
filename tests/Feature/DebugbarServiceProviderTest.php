<?php

/*
|--------------------------------------------------------------------------
| Debugbar integration tests
|--------------------------------------------------------------------------
|
| These tests pin the opt-in / opt-out contract of the debugbar stack:
|
|   - When app.debug is true, DebugBar\DebugBar is resolvable from the
|     container, the built-in six collectors are wired, and the SQL /
|     messages panels record live activity.
|   - When app.debug is false, the container binding still exists (so
|     code can write `debugbar()` without `has()` guards) but resolution
|     throws a RuntimeException, which the helpers and Database both
|     catch and fall back from.
*/

namespace Tests\Feature;

use DebugBar\DebugBar;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Foundation\DebugbarLogAdapter;
use SwallowPHP\Framework\Support\Path;

beforeEach(function () {
    // Each test gets a fresh scratch directory for the storage path so the
    // test never collides with a real app's logs.
    $this->scratch = sys_get_temp_dir() . '/swallow-debugbar-' . bin2hex(random_bytes(4));
    @mkdir($this->scratch, 0755, true);

    config(['app.storage_path' => $this->scratch]);
    config(['app.debug' => true]);
});

afterEach(function () {
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

    // Reset the shared container so the next test boots cleanly.
    $prop = new ReflectionProperty(App::class, 'container');
    $prop->setValue(null, null);
});

/* ----------------------------------------------------------------------
 * Provider registration
 * -------------------------------------------------------------------- */
test('debugbar: container exposes DebugBar when app.debug is true', function () {
    $debugbar = App::container()->get(DebugBar::class);

    expect($debugbar)->toBeInstanceOf(DebugBar::class);
});

test('debugbar: container throws when app.debug is false', function () {
    config(['app.debug' => false]);

    App::container()->get(DebugBar::class);
})->throws(\RuntimeException::class, 'Debugbar is disabled');

test('debugbar: built-in collectors are wired', function () {
    $debugbar = App::container()->get(DebugBar::class);

    foreach (['time', 'memory', 'php', 'request', 'messages', 'pdo'] as $name) {
        expect($debugbar->hasCollector($name))->toBeTrue("collector '{$name}' should be wired");
    }
});

test('debugbar: helper returns the instance when app.debug is true', function () {
    expect(debugbar())->toBeInstanceOf(DebugBar::class);
});

test('debugbar: helper returns null when app.debug is false', function () {
    config(['app.debug' => false]);
    expect(debugbar())->toBeNull();
});

test('debugbar: helper is safe to call before app is bootstrapped', function () {
    // No container() call — the helper must still return null, not blow up.
    $prop = new ReflectionProperty(App::class, 'container');
    $prop->setValue(null, null);

    expect(debugbar())->toBeNull();
});

/* ----------------------------------------------------------------------
 * PSR-3 logger mirror
 * -------------------------------------------------------------------- */
test('debugbar: log calls are mirrored into the messages panel', function () {
    config(['logging.default' => 'stderr']);

    $logger = App::container()->get(LoggerInterface::class);
    expect($logger)->toBeInstanceOf(DebugbarLogAdapter::class);

    $logger->info('hello world');
    $logger->warning('be careful');

    $debugbar = App::container()->get(DebugBar::class);
    $messages = $debugbar->getCollector('messages')->getMessages();

    expect($messages)->toHaveCount(2);
    expect($messages[0]['message'])->toBe('hello world');
    expect($messages[0]['label'])->toBe('info');
    expect($messages[1]['message'])->toBe('be careful');
    expect($messages[1]['label'])->toBe('warning');
});

test('debugbar: log mirror falls back to plain logger when app.debug is false', function () {
    config(['app.debug' => false]);
    config(['logging.default' => 'stderr']);

    $logger = App::container()->get(LoggerInterface::class);

    // Without the debugbar wrapper, the logger must NOT be the adapter.
    expect($logger)->not->toBeInstanceOf(DebugbarLogAdapter::class);
});

/* ----------------------------------------------------------------------
 * PDOCollector integration
 * -------------------------------------------------------------------- */
test('debugbar: Database.connections end up in the SQL panel', function () {
    config(['database.default' => 'sqlite']);
    $absolutePath = $this->scratch . '/debugbar.sqlite';
    config(['database.connections.sqlite' => [
        'driver' => 'sqlite',
        'database' => $absolutePath,
        'prefix' => '',
    ]]);

    /** @var \SwallowPHP\Framework\Database\Database $db */
    $db = App::container()->get(\SwallowPHP\Framework\Database\Database::class);
    $db->table('users')->insert(['name' => 'Alice']);

    $debugbar = App::container()->get(DebugBar::class);
    $pdo = $debugbar->getCollector('pdo');

    $connections = $pdo->getConnections();
    expect($connections)->toHaveCount(1);

    // The collector's measurements should now record at least one statement.
    $data = $pdo->collect();
    expect($data['nb_statements'])->toBeGreaterThan(0);
});

test('debugbar: Database uses plain PDO when app.debug is false', function () {
    config(['app.debug' => false]);
    config(['database.default' => 'sqlite']);
    $absolutePath = $this->scratch . '/plain.sqlite';
    config(['database.connections.sqlite' => [
        'driver' => 'sqlite',
        'database' => $absolutePath,
        'prefix' => '',
    ]]);

    /** @var \SwallowPHP\Framework\Database\Database $db */
    $db = App::container()->get(\SwallowPHP\Framework\Database\Database::class);
    $db->table('users')->insert(['name' => 'Bob']);

    // No exception thrown, file should exist. The exact class is a plain
    // PDO, not a TraceablePDO.
    expect(file_exists($absolutePath))->toBeTrue();
    $prop = new ReflectionProperty($db, 'connection');
    expect($prop->getValue($db))->toBeInstanceOf(\PDO::class);
    expect($prop->getValue($db))->not->toBeInstanceOf(\DebugBar\DataCollector\PDO\TraceablePDO::class);
});
