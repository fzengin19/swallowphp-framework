<?php

declare(strict_types=1);

namespace SwallowPHP\Framework\Foundation;

use DebugBar\DebugBar;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * PSR-3 LoggerInterface decorator that mirrors every log call into the
 * debugbar's "messages" panel.
 *
 * The wrapped $inner logger still runs (file logging, error_log, etc.) so
 * the application's production logging path is untouched. The adapter
 * only adds a side-channel write into the in-memory messages collector.
 *
 * If the debugbar or its messages collector is unavailable for any reason
 * the mirror becomes a no-op — the wrapper is best-effort, never a hard
 * dependency.
 */
final class DebugbarLogAdapter extends AbstractLogger
{
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly DebugBar $debugbar,
    ) {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        // Forward to the real logger first so production logging is not
        // affected by debugbar availability.
        $this->inner->log($level, $message, $context);

        // Best-effort mirror. Throwing here would mask the original log
        // call, so any failure is swallowed.
        try {
            $collector = $this->debugbar->getCollector('messages');
            if ($collector !== null) {
                $collector->addMessage(
                    (string) $message,
                    (string) $level,
                    $context === [] ? false : $context
                );
            }
        } catch (\Throwable $_) {
            // No-op.
        }
    }
}
