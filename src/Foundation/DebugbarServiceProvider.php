<?php

declare(strict_types=1);

namespace SwallowPHP\Framework\Foundation;

use DebugBar\DebugBar;
use DebugBar\DataCollector\ConfigCollector;
use DebugBar\DataCollector\MemoryCollector;
use DebugBar\DataCollector\MessagesCollector;
use DebugBar\DataCollector\PDO\PDOCollector;
use DebugBar\DataCollector\PhpInfoCollector;
use DebugBar\DataCollector\RequestDataCollector;
use DebugBar\DataCollector\TimeDataCollector;
use League\Container\ServiceProvider\AbstractServiceProvider;

/**
 * Bootstraps the php-debugbar stack when app.debug is true.
 *
 * When app.debug is false, the provider still binds the DebugBar::class
 * key but the factory throws when resolved, so consumers can catch that
 * with a single try/catch and fall back to plain-PDO / plain-logger
 * behaviour. This is the cheap "enabled, but don't crash" contract that
 * the framework's existing services (Database, LoggerInterface) rely on.
 */
class DebugbarServiceProvider extends AbstractServiceProvider
{
    /**
     * League\Container 4.x ServiceProviderInterface signature.
     *
     * @param string $id The service identifier being asked about.
     */
    public function provides(string $id): bool
    {
        return $id === DebugBar::class;
    }

    public function register(): void
    {
        // Shared singleton: every consumer (Database, LoggerInterface
        // mirror, the debugbar() helper) must see the same instance and
        // the same collectors. Resolving a fresh debugbar on every call
        // would lose every PDO addConnection / log mirror between calls.
        $this->getContainer()->addShared(DebugBar::class, function () {
            $config = $this->getContainer()->get(Config::class);

            if (!(bool) $config->get('app.debug', false)) {
                throw new \RuntimeException('Debugbar is disabled (app.debug = false).');
            }

            $debugbar = new DebugBar();

            // Built-in collectors that need no framework-specific wiring.
            $debugbar->addCollector(new TimeDataCollector('time'));
            $debugbar->addCollector(new MemoryCollector('memory'));
            $debugbar->addCollector(new PhpInfoCollector('php'));
            $debugbar->addCollector(new RequestDataCollector('request'));
            $debugbar->addCollector(new MessagesCollector('messages'));

            // Snapshot Config at boot time. Mutating app config afterwards
            // does not retroactively update the panel — the snapshot is the
            // contract.
            try {
                $debugbar->addCollector(new ConfigCollector($config->all()));
            } catch (\Throwable $_) {
                // ConfigCollector is JSON-only; skip non-serialisable values
                // rather than failing the whole boot.
            }

            // SQL panel: the actual PDO connections are added later by
            // Database::initialize() via PDOCollector::addConnection().
            $debugbar->addCollector(new PDOCollector());

            return $debugbar;
        });
    }
}
