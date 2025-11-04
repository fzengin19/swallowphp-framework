<?php

namespace SwallowPHP\Framework\Database\Instrumentation;

use PDO;
use PDOException;
use PDOStatement;
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

    /**
     * Executes a SQL statement, returning a result set as a PDOStatement object.
     *
     * @param string $query The SQL statement to prepare and execute.
     * @param ?int $fetchMode The fetch mode to use.
     * @param mixed ...$fetchModeArgs Arguments for the fetch mode.
     * @return PDOStatement|false Returns a PDOStatement object on success, or false on failure.
     * @throws PDOException
     */
    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): PDOStatement|false
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

    /**
     * Execute an SQL statement and return the number of affected rows.
     *
     * @param string $statement The SQL statement to prepare and execute.
     * @return int|false Returns the number of rows affected by the statement, or false on failure.
     * @throws PDOException
     */
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
