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
        $t0 = hrtime(true);
        try {
            $stmt = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $e) {
            if ($this->logger && $this->logQueries) {
                $this->logger->error("SQL ERROR: {$query}", ['exception' => $e]);
            }
            throw $e;
        }
        $elapsedMs = (hrtime(true) - $t0) / 1e6;
        if ($this->logger && $this->logQueries) {
            $dur = number_format($elapsedMs, 3, '.', '');
            $msg = "[{$dur}ms] {$query}";
            if ($elapsedMs >= $this->slowThresholdMs) {
                $this->logger->warning("[SLOW] {$msg}");
            } else {
                $this->logger->info($msg);
            }
        }
        return $stmt;
    }

    public function exec(string $statement): int|false
    {
        $t0 = hrtime(true);
        try {
            $result = parent::exec($statement);
        } catch (PDOException $e) {
            if ($this->logger && $this->logQueries) {
                $this->logger->error("SQL ERROR: {$statement}", ['exception' => $e]);
            }
            throw $e;
        }
        $elapsedMs = (hrtime(true) - $t0) / 1e6;
        if ($this->logger && $this->logQueries) {
            $dur = number_format($elapsedMs, 3, '.', '');
            $msg = "[{$dur}ms] {$statement}";
            if ($elapsedMs >= $this->slowThresholdMs) {
                $this->logger->warning("[SLOW] {$msg}");
            } else {
                $this->logger->info($msg);
            }
        }
        return $result;
    }
}


