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
        $t0 = microtime(true);
        $ok = false;
        try {
            if (is_array($params)) {
                // also reflect provided params
                foreach ($params as $k => $v) { $this->bindings[$k] = $v; }
            }
            $ok = parent::execute($params ?? null);
        } catch (\PDOException $e) {
            if ($this->logger && $this->logQueries) {
                $ctx = [
                    'sql' => $this->queryString ?? '',
                    'exception' => $e,
                    'driver' => $this->driverName,
                ];
                if ($this->logBindings) { $ctx['bindings'] = $this->collectBindings(); }
                $this->logger->error('EXECUTE FAILED', $ctx);
            }
            throw $e;
        } finally {
            $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
            if ($this->logger && $this->logQueries) {
                $ctx = [
                    'sql' => $this->queryString ?? '',
                    'ms' => $elapsedMs,
                    'driver' => $this->driverName,
                ];
                if ($this->logBindings) { $ctx['bindings'] = $this->collectBindings(); }
                if ($elapsedMs >= $this->slowThresholdMs) {
                    $this->logger->warning('[SLOW QUERY] PREPARED EXEC', $ctx);
                } else {
                    $this->logger->info('PREPARED EXEC', $ctx);
                }
            }
        }
        return $ok;
    }
}


