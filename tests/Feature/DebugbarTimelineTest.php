<?php

/*
|--------------------------------------------------------------------------
| Debugbar skeleton timeline tests
|--------------------------------------------------------------------------
|
| The Debugbar integration (v3.1.0) wires the six built-in panels but
| leaves the TimeDataCollector empty unless the framework itself emits
| startMeasure/stopMeasure around the request lifecycle. These tests
| pin the three skeleton hooks:
|
|   - 'routing'           around Router::dispatch()
|   - 'middleware.X'      around Middleware::handle()
|   - 'view.X'            around the view() helper
*/

namespace Tests\Feature;

use DebugBar\DebugBar;
use ReflectionProperty;
use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Http\Middleware\Middleware;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Routing\Router;

beforeEach(function () {
    $this->scratch = sys_get_temp_dir() . '/swallow-timeline-' . bin2hex(random_bytes(4));
    @mkdir($this->scratch, 0755, true);

    config(['app.storage_path' => $this->scratch]);
    config(['app.debug' => true]);

    // Each test gets a clean Router route table to avoid cross-test
    // pollution from previous get()/post() registrations.
    $router = new ReflectionProperty(Router::class, 'routes');
    $router->setValue(null, []);
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

    $prop = new ReflectionProperty(App::class, 'container');
    $prop->setValue(null, null);
});

/* ----------------------------------------------------------------------
 * Helpers
 * -------------------------------------------------------------------- */

/** Build a synthetic GET request to the given path. */
function timelineRequest(string $path): Request
{
    $request = Request::createFromGlobals();
    $uri = new ReflectionProperty($request, 'uri');
    $uri->setValue($request, $path);
    $method = new ReflectionProperty($request, 'method');
    $method->setValue($request, 'GET');
    return $request;
}

/** Reach into the TimeDataCollector and return the named measure if present. */
function findMeasure(array $measures, string $name): ?array
{
    foreach ($measures as $m) {
        if (($m['label'] ?? null) === $name || ($m['name'] ?? null) === $name) {
            return $m;
        }
    }
    return null;
}

/* ----------------------------------------------------------------------
 * Routing
 * -------------------------------------------------------------------- */
test('timeline: Router::dispatch records a "routing" measure', function () {
    Router::get('/probe', fn () => 'ok');

    Router::dispatch(timelineRequest('/probe'));

    $debugbar = App::container()->get(DebugBar::class);
    $measures = $debugbar->getCollector('time')->getMeasures();

    expect($measures)->not->toBeEmpty();
    expect(findMeasure($measures, 'routing'))->not->toBeNull();
});

test('timeline: routing measure is closed even when the controller throws', function () {
    Router::get('/boom', function () {
        throw new \RuntimeException('kaboom');
    });

    try {
        Router::dispatch(timelineRequest('/boom'));
    } catch (\RuntimeException $_) {
        // expected
    }

    $debugbar = App::container()->get(DebugBar::class);
    $measures = $debugbar->getCollector('time')->getMeasures();

    // The 'routing' marker must still be closed (no leaked started-but-
    // -not-finished marker). Finding the marker is enough proof; the
    // measure_range is also present.
    $m = findMeasure($measures, 'routing');
    expect($m)->not->toBeNull();
    expect($m)->toHaveKey('start');
    expect($m)->toHaveKey('end');
    expect($m['end'])->toBeGreaterThanOrEqual($m['start']);
});

/* ----------------------------------------------------------------------
 * Middleware
 * -------------------------------------------------------------------- */
test('timeline: middleware records a per-class measure', function () {
    $middlewareInstance = new class extends Middleware {
        public function handle(Request $request, \Closure $next): mixed
        {
            return $next($request);
        }
    };

    $expectedName = 'middleware.' . $middlewareInstance::class;

    Router::get('/with-mw', fn () => 'ok')
        ->middleware($middlewareInstance);

    Router::dispatch(timelineRequest('/with-mw'));

    $debugbar = App::container()->get(DebugBar::class);
    $measures = $debugbar->getCollector('time')->getMeasures();

    expect(findMeasure($measures, $expectedName))->not->toBeNull();
});

/* ----------------------------------------------------------------------
 * View
 * -------------------------------------------------------------------- */
test('timeline: view() records a per-template measure', function () {
    $viewPath = $this->scratch . '/views';
    @mkdir($viewPath, 0755, true);
    file_put_contents($viewPath . '/hello.php', '<?= $name; ?>');

    config(['app.view_path' => $viewPath]);

    $response = view('hello', ['name' => 'World']);

    expect((string) $response->getContent())->toBe('World');

    $debugbar = App::container()->get(DebugBar::class);
    $measures = $debugbar->getCollector('time')->getMeasures();

    $m = findMeasure($measures, 'view.hello');
    expect($m)->not->toBeNull();
    expect($m['label'])->toBe('view.hello');
});

test('timeline: view() closes its measure even when the template throws', function () {
    // Template that throws during render. The view() helper must still
    // close its 'view.X' measure so the timeline stays balanced.
    $viewPath = $this->scratch . '/views';
    @mkdir($viewPath, 0755, true);
    file_put_contents($viewPath . '/oops.php', '<?php throw new \RuntimeException("nope"); ?>');

    config(['app.view_path' => $viewPath]);

    try {
        view('oops');
    } catch (\RuntimeException $_) {
        // expected
    }

    $debugbar = App::container()->get(DebugBar::class);
    $measures = $debugbar->getCollector('time')->getMeasures();

    $m = findMeasure($measures, 'view.oops');
    expect($m)->not->toBeNull('view throw must still close its measure via finally');
    expect($m['end'])->toBeGreaterThanOrEqual($m['start']);
});

/* ----------------------------------------------------------------------
 * Opt-out
 * -------------------------------------------------------------------- */
test('timeline: hooks are no-ops when app.debug is false', function () {
    config(['app.debug' => false]);

    Router::get('/quiet', fn () => 'ok');
    Router::dispatch(timelineRequest('/quiet'));

    // Resolving the debugbar now throws; the helper returns null.
    expect(debugbar())->toBeNull();
});
