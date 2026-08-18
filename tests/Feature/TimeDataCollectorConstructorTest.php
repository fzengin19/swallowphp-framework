<?php

/*
|--------------------------------------------------------------------------
| TimeDataCollector constructor shape regression.
|--------------------------------------------------------------------------
|
| TimeDataCollector::__construct($requestStartTime = null) takes a FLOAT
| start time, not a collector name. Passing the string 'time' (the
| collector's getName() return) as the first arg silently cast to 0.0,
| and getRequestDuration() then returned microtime(true) - 0 ≈ current
| Unix timestamp — rendered as a 1.78e9-second "Request" duration in the
| timeline panel.
|
| These source-shape tests pin the fix:
|   - the provider must instantiate TimeDataCollector with no arguments
|     (so the constructor falls back to $_SERVER['REQUEST_TIME_FLOAT'] /
|     microtime(true));
|   - the literal pattern `new TimeDataCollector('time')` must never
|     reappear in the provider.
*/

namespace Tests\Feature;

test('DebugbarServiceProvider constructs TimeDataCollector with no positional argument', function () {
    $source = file_get_contents(
        dirname(__DIR__, 2) . '/src/Foundation/DebugbarServiceProvider.php'
    );

    // Must instantiate with no positional argument — the string 'time'
    // used to be passed here and silently broke requestStartTime.
    expect($source)
        ->toContain('new TimeDataCollector()')
        ->not->toContain("new TimeDataCollector('time')")
        ->not->toContain('new TimeDataCollector("time")');
});

test('TimeDataCollector fallback yields a sensible request duration', function () {
    // Behavioural check that doesn't depend on the framework bootstrap:
    // build a TimeDataCollector with the same default the framework now
    // uses, simulate collect() at a slightly later microtime, and assert
    // the duration is in the millisecond range — not the current Unix
    // timestamp (~1.78e9 seconds).
    $start = microtime(true);
    $_SERVER['REQUEST_TIME_FLOAT'] = $start;

    $time = new \DebugBar\DataCollector\TimeDataCollector();

    // Mimic the renderer's collect() — sets requestEndTime to microtime
    // (true) ~10ms later and auto-stops any started measures.
    usleep(10_000); // 10ms

    $data = $time->collect();
    $duration = $data['duration'];

    expect($duration)
        ->toBeFloat()
        ->toBeGreaterThan(0.005)   // >5ms
        ->toBeLessThan(2.0);        // <2s (well below ~1.78e9)

    // And the duration is meaningfully less than the Unix timestamp
    // it would have been if requestStartTime had been left at 0.
    expect($duration)->toBeLessThan($start);
});

test('TimeDataCollector with a string arg sets requestStartTime to 0 (regression shape)', function () {
    // Sanity check on the underlying API so the framework-side fix has
    // a documented invariant to lean on: passing the legacy 'time'
    // string really does collapse to 0 — i.e. it would silently break
    // duration display, exactly as it did in 3.1.0.
    $time = new \DebugBar\DataCollector\TimeDataCollector('time');
    expect($time->getRequestStartTime())->toBe(0.0);
});