<?php

namespace SwallowPHP\Framework\Database\Instrumentation;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use SwallowPHP\Framework\Foundation\App;

class LoggedPDO extends PDO
{
    protected ?LoggerInterface $logger = null;
    protected bool $logQueries = false;
    protected int $slowThresholdMs = 500;
    protected bool $logBindings = true;
    protected string $driverName = '';

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        parent::__construct($dsn, $username ?? '', $password ?? '', $options);

        $this->driverName = (string) $this->getAttribute(PDO::ATTR_DRIVER_NAME);

        try { $this->logger = App::container()->get(LoggerInterface::class); } catch (\Throwable $e) { $this->logger = null; }

        // Read config
        try {
            $this->logQueries = (bool) config('database.log_queries', false);
            $this->slowThresholdMs = (int) config('database.slow_threshold_ms', 500);
            $this->logBindings = (bool) config('database.log_bindings', true);
        } catch (\Throwable $_) {}

        // Ensure our statement class is used with constructor args
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
            LoggedPDOStatement::class,
            [$this->logger, $this->logQueries, $this->slowThresholdMs, $this->logBindings, $this->driverName]
        ]);
    }

    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs)
    {
        $t0 = microtime(true);
        try {
            $stmt = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $e) {
            if ($this->logger && $this->logQueries) {
                $this->logger->error('QUERY FAILED', ['sql' => $query, 'exception' => $e, 'driver' => $this->driverName]);
            }
            throw $e;
        }
        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
        if ($this->logger && $this->logQueries) {
            $ctx = ['sql' => $query, 'ms' => $elapsedMs, 'driver' => $this->driverName];
            if ($elapsedMs >= $this->slowThresholdMs) $this->logger->warning('[SLOW QUERY] QUERY', $ctx); else $this->logger->info('QUERY', $ctx);
        }
        return $stmt;
    }

    public function exec(string $statement): int|false
    {
        $t0 = microtime(true);
        try {
            $result = parent::exec($statement);
        } catch (PDOException $e) {
            if ($this->logger && $this->logQueries) {
                $this->logger->error('EXEC FAILED', ['sql' => $statement, 'exception' => $e, 'driver' => $this->driverName]);
            }
            throw $e;
        }
        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
        if ($this->logger && $this->logQueries) {
            $ctx = ['sql' => $statement, 'ms' => $elapsedMs, 'driver' => $this->driverName, 'affected' => $result];
            if ($elapsedMs >= $this->slowThresholdMs) $this->logger->warning('[SLOW QUERY] EXEC', $ctx); else $this->logger->info('EXEC', $ctx);
        }
        return $result;
    }
}


