<?php

namespace SwallowPHP\Framework\Database\Instrumentation;

use PDOStatement;
use PDO;
use Psr\Log\LoggerInterface;

class LoggedPDOStatement extends PDOStatement
{
    protected ?LoggerInterface $logger;
    protected bool $logQueries;
    protected int $slowThresholdMs;
    protected bool $logBindings;
    protected string $driverName;

    /** @var array<int|string,mixed> */
    protected array $bindings = [];
    /** @var array<int|string,mixed> */
    protected array $paramRefs = [];

    protected function __construct(?LoggerInterface $logger = null, bool $logQueries = false, int $slowThresholdMs = 500, bool $logBindings = true, string $driverName = '')
    {
        $this->logger = $logger;
        $this->logQueries = $logQueries;
        $this->slowThresholdMs = $slowThresholdMs;
        $this->logBindings = $logBindings;
        $this->driverName = $driverName;
    }

    public function bindValue($param, $value, $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    public function bindParam($param, &$var, $type = PDO::PARAM_STR, $maxLength = null, $driverOptions = null): bool
    {
        // store reference; value will be read at execute time
        $this->paramRefs[$param] = &$var;
        return parent::bindParam($param, $var, $type, $maxLength ?? 0, $driverOptions ?? null);
    }

    protected function collectBindings(): array
    {
        $result = $this->bindings;
        foreach ($this->paramRefs as $k => $ref) {
            $result[$k] = $ref;
        }
        // Ensure positional order for numeric keys
        ksort($result);
        return $result;
    }

    public function execute($params = null): bool
    {
        $t0 = hrtime(true);
        $ok = false;
        try {
            if (is_array($params)) {
                foreach ($params as $k => $v) { $this->bindings[$k] = $v; }
            }
            $ok = parent::execute($params ?? null);
        } catch (\PDOException $e) {
            if ($this->logger && $this->logQueries) {
                $sql = $this->queryString ?? '';
                $binds = $this->logBindings ? $this->collectBindings() : null;
                $msg = $sql;
                if ($binds !== null && !empty($binds)) {
                    $msg .= ' | ' . json_encode($binds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $this->logger->error("SQL ERROR: {$msg}", ['exception' => $e]);
            }
            throw $e;
        } finally {
            $elapsedMs = (hrtime(true) - $t0) / 1e6;
            if ($this->logger && $this->logQueries) {
                $sql = $this->queryString ?? '';
                $binds = $this->logBindings ? $this->collectBindings() : null;
                $dur = number_format($elapsedMs, 3, '.', '');
                $msg = "[{$dur}ms] {$sql}";
                if ($binds !== null && !empty($binds)) {
                    $msg .= ' | ' . json_encode($binds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if ($elapsedMs >= $this->slowThresholdMs) {
                    $this->logger->warning("[SLOW] {$msg}");
                } else {
                    $this->logger->info($msg);
                }
            }
        }
        return $ok;
    }
}


