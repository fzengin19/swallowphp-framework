<?php

declare(strict_types=1);

namespace SwallowPHP\Framework\Support;

use SwallowPHP\Framework\Foundation\App;

/**
 * Thin wrapper around DebugBar\DataCollector\TimeDataCollector that lets
 * the framework's skeleton (Router, Middleware, View renderer) record
 * timeline markers without hard-coupling to the debugbar.
 *
 * The pair startMeasure() / stopMeasure() is **no-op** when:
 *   - the debugbar is not bound in the container (i.e. app.debug is false),
 *   - the TimeDataCollector is missing for any reason.
 *
 * Stop is always paired with start in the call-site's try/finally to keep
 * the timeline balanced even when the inner code throws.
 */
final class DebugbarTiming
{
    /** Prevent instantiation. */
    private function __construct()
    {
    }

    /**
     * Start a timeline marker. Pairs with stopMeasure() of the same name.
     *
     * @param string $name  Unique marker name. Call sites should pick a
     *                      stable, descriptive name (e.g. 'routing',
     *                      'middleware.X', 'view.X').
     * @param string|null $label Optional human-readable label shown in the
     *                            panel (defaults to $name).
     */
    public static function startMeasure(string $name, ?string $label = null): void
    {
        try {
            $debugbar = App::container()->get(\DebugBar\DebugBar::class);
            $time = $debugbar->getCollector('time');
            if ($time !== null) {
                $time->startMeasure($name, $label ?? $name);
            }
        } catch (\Throwable $_) {
            // Debugbar disabled or not registered — stay silent.
        }
    }

    /**
     * Stop a previously started timeline marker. Always invoked from a
     * finally block so the marker is closed even if the inner code threw.
     */
    public static function stopMeasure(string $name): void
    {
        try {
            $debugbar = App::container()->get(\DebugBar\DebugBar::class);
            $time = $debugbar->getCollector('time');
            if ($time !== null) {
                $time->stopMeasure($name);
            }
        } catch (\Throwable $_) {
            // Debugbar disabled or not registered — stay silent.
        }
    }
}
