<?php

namespace SwallowPHP\Framework\Database;

use PDO;
use PDOException;
use InvalidArgumentException;
use Exception; // Keep for re-throwing generic Exception
use SwallowPHP\Framework\Foundation\Config;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Foundation\App; // For logger access
use Psr\Log\LoggerInterface; // For logger type hint
use SwallowPHP\Framework\Database\Paginator; // Import the Paginator class

/**
 * Database class for handling database operations using PDO.
 */
class Database
{
    /** @var PDO|null PDO connection instance. */
    protected ?PDO $connection = null;

    /** @var bool Flag to indicate if the connection was successful. */
    protected bool $connectedSuccessfully = false;

    /** @var string The name of the table to perform operations on. */
    public string $table = '';

    /** @var string The columns to select in the query. */
    protected string $select = '*';

    /** @var array Array of where conditions. */
    protected array $where = [];

    /** @var array Array of raw where conditions. */
    protected array $whereRaw = [];

    /** @var array Array of where in conditions. */
    protected array $whereIn = [];

    /** @var array Array of where between conditions. */
    protected array $whereBetween = [];

    /** @var array Array of order by clauses. */
    protected array $orderBy = [];

    /** @var int|null The limit for the query. */
    protected ?int $limit = null;

    /** @var int|null The offset for the query. */
    protected ?int $offset = null;

    /** @var array Array of or where conditions. */
    protected array $orWhere = [];

    /** @var array Array of raw where conditions with bindings. */
    protected array $whereRawBindings = [];

    /** @var array The database connection configuration. */
    protected array $config = [];

    /** @var string|null The model class associated with this query builder instance. */
    protected ?string $modelClass = null;

    /** @var LoggerInterface|null Logger instance. */
    protected ?LoggerInterface $logger = null;


    /**
     * Database constructor. Initializes the connection.
     */
    public function __construct(?array $config = null)
    {
        if ($config) {
            $this->config = $config;
        }
        // Get logger instance early if possible
        try {
             $this->logger = App::container()->get(LoggerInterface::class);
        } catch (\Throwable $e) {
             error_log("CRITICAL: Could not resolve LoggerInterface in Database constructor: " . $e->getMessage());
        }
        $this->initialize();
    }

    /**
     * Initialize the database connection.
     * @throws Exception If the connection fails.
     */
    public function initialize(): void
    {
        if ($this->connection && $this->connectedSuccessfully) {
            return;
        }
        $config = $this->config ?: config('database.connections.' . config('database.default', 'mysql'), []);
        $driver = $config['driver'] ?? 'mysql';
        $host = $config['host'] ?? env('DB_HOST', '127.0.0.1');
        $port = $config['port'] ?? env('DB_PORT', '3306');
        $database = $config['database'] ?? env('DB_DATABASE', 'swallowphp');
        $username = $config['username'] ?? env('DB_USERNAME', 'root');
        $password = $config['password'] ?? env('DB_PASSWORD', '');
        $charset = $config['charset'] ?? env('DB_CHARSET', 'utf8mb4');
        $options = $config['options'] ?? [];

        try {
            if ($driver === 'sqlite') {
                 $storagePath = config('app.storage_path');
                 if (!$storagePath || !is_dir(dirname($storagePath))) {
                     $potentialBasePath = defined('BASE_PATH') ? constant('BASE_PATH') : dirname(__DIR__, 3);
                     $storagePath = $potentialBasePath . '/storage';
                     $warningMsg = "Warning: 'app.storage_path' not configured or invalid, using fallback for DB path: " . $storagePath;
                     if ($this->logger) $this->logger->warning($warningMsg); else error_log($warningMsg);
                     if (!is_dir($storagePath)) { @mkdir($storagePath, 0755, true); }
                 }
                 $dbPath = rtrim($storagePath, '/\\') . '/' . ltrim($database, '/\\');
                 $dbDir = dirname($dbPath);
                 if (!is_dir($dbDir)) {
                      if (!@mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
                           throw new InvalidArgumentException("Could not create directory for SQLite database: {$dbDir}");
                      }
                 }
                 $dsn = "sqlite:" . $dbPath;
            } elseif ($driver === 'pgsql') {
                $dsn = "pgsql:host=$host;port=$port;dbname=$database";
            } else {
                 $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=$charset";
            }

            $defaultOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdoOptions = array_replace($defaultOptions, $options);

            $this->connection = new PDO($dsn, $username, $password, $pdoOptions);
            $this->connectedSuccessfully = true;

            if ($driver === 'sqlite') {
                 try {
                     $this->connection->exec('PRAGMA journal_mode = WAL;');
                 } catch (PDOException $e) {
                     $warningMsg = "SQLite WAL mode could not be enabled";
                     if ($this->logger) $this->logger->warning($warningMsg, ['error' => $e->getMessage()]);
                     else error_log($warningMsg . ": " . $e->getMessage());
                 }
            }

        } catch (PDOException $e) {
             // Log connection error before throwing
             $errorMsg = 'Veritabanı bağlantısı başlatılamadı';
             if ($this->logger) $this->logger->critical($errorMsg, ['exception' => $e, 'driver' => $driver]);
             else error_log($errorMsg . ": " . $e->getMessage());
            throw new Exception($errorMsg, 0, $e); // Re-throw generic Exception
        } catch (\Throwable $e) { // Catch other potential errors (like InvalidArgumentException)
             $errorMsg = 'Veritabanı başlatılırken beklenmedik hata';
             if ($this->logger) $this->logger->critical($errorMsg, ['exception' => $e, 'driver' => $driver]);
             else error_log($errorMsg . ": " . $e->getMessage());
             throw new Exception($errorMsg, 0, $e);
        }
    }

    /** Set the table for the query. */
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /** Reset all query parameters. */
    public function reset(): void
    {
        $this->table = ''; $this->select = '*'; $this->where = []; $this->whereRaw = [];
        $this->whereIn = []; $this->whereBetween = []; $this->orderBy = [];
        $this->limit = null; $this->offset = null; $this->orWhere = [];
        $this->whereRawBindings = []; $this->modelClass = null;
    }

    /** Set the columns to select. */
    public function select(array $columns = ['*']): self { $this->select = implode(', ', $columns); return $this; }
    /**
     * Add a where condition.
     * Can be called with two arguments (column, value) which defaults operator to '='.
     * Or with three arguments (column, operator, value).
     */
    public function where(string $column, $operatorOrValue, $value = null): self
    {
        if ($value === null) {
            // Two arguments provided: where($column, $value)
            $this->where[] = [$column, '=', $operatorOrValue];
        } else {
            // Three arguments provided: where($column, $operator, $value)
            $this->where[] = [$column, $operatorOrValue, $value];
        }
        return $this;
    }
    /** Add an or where condition. */
    public function orWhere(string $column, string $operator, $value): self { $this->orWhere[] = [$column, $operator, $value]; return $this; }
    /** Add a where in condition. */
    public function whereIn(string $column, array $values): self { if (empty($values)) { $this->whereRaw("1 = 0"); return $this; } $this->whereIn[] = [$column, $values]; return $this; }
    /** Add a where between condition. */
    public function whereBetween(string $column, $start, $end): self { $this->whereBetween[] = [$column, $start, $end]; return $this; }
    /** Add a raw where condition. */
    public function whereRaw(string $rawCondition, array $bindings = []): self { $this->whereRaw[] = $rawCondition; $this->whereRawBindings = array_merge($this->whereRawBindings, $bindings); return $this; }
    /** Add an order by clause. */
    public function orderBy(string $column, string $direction = 'ASC'): self { $direction = strtoupper($direction); if (!in_array($direction, ['ASC', 'DESC'])) { $direction = 'ASC'; } $this->orderBy[] = [$column, $direction]; return $this; }
    /** Set the limit. */
    public function limit(int $limit): self { $this->limit = $limit > 0 ? $limit : null; return $this; }
    /** Set the offset. */
    public function offset(int $offset): self { $this->offset = $offset >= 0 ? $offset : null; return $this; }

    /** Build the where clause. */
    protected function buildWhereClause(): string
    {
        $conditions = []; $firstCondition = true;
        foreach ($this->where as $condition) { $conjunction = $firstCondition ? 'WHERE' : 'AND'; $conditions[] = "$conjunction `{$condition[0]}` {$condition[1]} ?"; $firstCondition = false; }
        foreach ($this->orWhere as $condition) { $conjunction = $firstCondition ? 'WHERE' : 'OR'; $conditions[] = "$conjunction `{$condition[0]}` {$condition[1]} ?"; $firstCondition = false; }
        foreach ($this->whereIn as $condition) { if (empty($condition[1])) continue; $placeholders = implode(', ', array_fill(0, count($condition[1]), '?')); $conjunction = $firstCondition ? 'WHERE' : 'AND'; $conditions[] = "$conjunction `{$condition[0]}` IN ($placeholders)"; $firstCondition = false; }
        foreach ($this->whereBetween as $condition) { $conjunction = $firstCondition ? 'WHERE' : 'AND'; $conditions[] = "$conjunction `{$condition[0]}` BETWEEN ? AND ?"; $firstCondition = false; }
        if (!empty($this->whereRaw)) { foreach ($this->whereRaw as $rawCondition) { $conjunction = $firstCondition ? 'WHERE' : 'AND'; $conditions[] = "$conjunction ($rawCondition)"; $firstCondition = false; } }
        return implode(' ', $conditions);
    }

    /** Execute the select query and get the results. */
    public function get(): array
    {
        $this->initialize();
        $sql = "SELECT {$this->select} FROM `{$this->table}` ";
        $sql .= $this->buildWhereClause();
        if (!empty($this->orderBy)) { $orderByColumns = array_map(fn($order) => "`{$order[0]}` {$order[1]}", $this->orderBy); $sql .= ' ORDER BY ' . implode(', ', $orderByColumns); }
        if ($this->limit !== null) { $sql .= " LIMIT ?"; if ($this->offset !== null) { $sql .= " OFFSET ?"; } }

        try {
            $statement = $this->connection->prepare($sql);
            $paramIndex = 1;
            $whereBindings = $this->getBindValuesForWhere();
            foreach ($whereBindings as $value) { $statement->bindValue($paramIndex++, $value, $this->getPDOParamType($value)); }
            if ($this->limit !== null) { $statement->bindValue($paramIndex++, $this->limit, PDO::PARAM_INT); if ($this->offset !== null) { $statement->bindValue($paramIndex++, $this->offset, PDO::PARAM_INT); } }
            $statement->execute();
            $rows = $statement->fetchAll();
        } catch (PDOException $e) {
             $errorMsg = "Database Error in get()";
             if ($this->logger) $this->logger->error($errorMsg, ['exception' => $e, 'sql' => $sql]);
             else error_log($errorMsg . ": " . $e->getMessage() . " | SQL: " . $sql);
             throw $e;
        }
        if ($this->modelClass && method_exists($this->modelClass, 'hydrateModels')) {
            return call_user_func([$this->modelClass, 'hydrateModels'], $rows);
        }
        return $rows;
    }

    /** Get the first result from the query. */
    public function first()
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }

    /** Insert a new record. */
    public function insert(array $data): int|false
    {
        $this->initialize();
        if (empty($data)) return false;
        $columns = implode(', ', array_map(fn($col) => "`$col`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$this->table}` ($columns) VALUES ($placeholders)";
        try {
            $statement = $this->connection->prepare($sql);
            $values = array_values($data);
            foreach ($values as $key => $value) { $statement->bindValue($key + 1, $value, $this->getPDOParamType($value)); }
            $success = $statement->execute();
            return ($success && $this->connection->lastInsertId() !== false) ? (int)$this->connection->lastInsertId() : false;
        } catch (PDOException $e) {
            $errorMsg = "Database Error in insert()";
             if ($this->logger) $this->logger->error($errorMsg, ['exception' => $e, 'sql' => $sql, 'data' => $data]); // Log data too
             else error_log($errorMsg . ": " . $e->getMessage() . " | SQL: " . $sql);
            return false;
        }
    }

    /** Update records. */
    public function update(array $data): int
    {
        $this->initialize();
        if (empty($data)) return 0;
        $sets = array_map(fn($column) => "`$column` = ?", array_keys($data));
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets);
        $sql .= ' ' . $this->buildWhereClause();
        try {
            $statement = $this->connection->prepare($sql);
            $bindValues = array_merge(array_values($data), $this->getBindValuesForWhere());
            foreach ($bindValues as $key => $value) { $statement->bindValue($key + 1, $value, $this->getPDOParamType($value)); }
            $statement->execute();
            return $statement->rowCount();
        } catch (PDOException $e) {
            $errorMsg = "Database Error in update()";
             if ($this->logger) $this->logger->error($errorMsg, ['exception' => $e, 'sql' => $sql, 'data' => $data]);
             else error_log($errorMsg . ": " . $e->getMessage() . " | SQL: " . $sql);
            return 0;
        }
    }

    /** Delete records. */
    public function delete(): int
    {
        $this->initialize();
        $sql = "DELETE FROM `{$this->table}` " . $this->buildWhereClause();
        try {
            $statement = $this->connection->prepare($sql);
            $bindValues = $this->getBindValuesForWhere();
            foreach ($bindValues as $key => $value) { $statement->bindValue($key + 1, $value, $this->getPDOParamType($value)); }
            $statement->execute();
            return $statement->rowCount();
        } catch (PDOException $e) {
            $errorMsg = "Database Error in delete()";
             if ($this->logger) $this->logger->error($errorMsg, ['exception' => $e, 'sql' => $sql]);
             else error_log($errorMsg . ": " . $e->getMessage() . " | SQL: " . $sql);
            return 0;
        }
    }

    /** Count matching records. */
    public function count(): int
    {
        $this->initialize();
        $originalSelect = $this->select; $originalOrderBy = $this->orderBy;
        $originalLimit = $this->limit; $originalOffset = $this->offset;
        $this->select = 'COUNT(*) AS count'; $this->orderBy = [];
        $this->limit = null; $this->offset = null;
        $sql = "SELECT {$this->select} FROM `{$this->table}` " . $this->buildWhereClause();
        try {
            $statement = $this->connection->prepare($sql);
            $bindValues = $this->getBindValuesForWhere();
            foreach ($bindValues as $key => $value) { $statement->bindValue($key + 1, $value, $this->getPDOParamType($value)); }
            $statement->execute();
            $result = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
             $errorMsg = "Database Error in count()";
             if ($this->logger) $this->logger->error($errorMsg, ['exception' => $e, 'sql' => $sql]);
             else error_log($errorMsg . ": " . $e->getMessage() . " | SQL: " . $sql);
             $result = ['count' => 0];
        } finally {
            $this->select = $originalSelect; $this->orderBy = $originalOrderBy;
            $this->limit = $originalLimit; $this->offset = $originalOffset;
        }
        return (int)($result['count'] ?? 0);
    }

    /**
     * Paginate results.
     * @param int $perPage Number of items per page.
     * @param int $page Current page number.
     * @return Paginator Paginator instance.
     */
    public function paginate(int $perPage, int $page = 1): Paginator
    {
        if ($perPage <= 0) $perPage = 15;
        $this->initialize();

        // Clone the current query state BEFORE calculating total and fetching data
        $queryForCount = clone $this;
        // Remove order by for count query for potential performance improvement
        // but keep where clauses. Limit/offset are handled by count() method itself.
        $queryForCount->orderBy = [];

        // Calculate total using the cloned query state
        $total = $queryForCount->count();

        $totalPages = $perPage > 0 ? ceil($total / $perPage) : 0;
        $page = max(1, (int)$page);
        $offset = ($page - 1) * $perPage;

        // Fetch data using the original query state with limit and offset applied
        $queryForData = clone $this; // Clone again to keep original state clean
        $queryForData->limit($perPage)->offset($offset);
        $data = $queryForData->get(); // get() handles model hydration if set

        // --- URL Generation ---
        $total = $this->count();
        $totalPages = $perPage > 0 ? ceil($total / $perPage) : 0;
        $page = max(1, (int)$page);
        $offset = ($page - 1) * $perPage;
        $queryForData = clone $this;
        $queryForData->limit($perPage)->offset($offset);
        $data = $queryForData->get();
        $baseUrl = '/'; $existingParams = []; $separator = '?';
        try {
            $currentRequest = request();
            $baseUrl = strtok($currentRequest->fullUrl(), '?');
            $existingParams = $currentRequest->query();
            unset($existingParams['page']);
            $separator = empty($existingParams) ? '?' : '&';
        } catch (\Throwable $e) {
             $errorMsg = "Pagination URL generation failed: Could not get request details";
             if ($this->logger) $this->logger->warning($errorMsg, ['error' => $e->getMessage()]);
             else error_log($errorMsg . " - " . $e->getMessage());
        }
        $baseUrlWithParams = $baseUrl . (empty($existingParams) ? '' : '?' . http_build_query($existingParams));
        $linkStructure = $this->generatePaginationLinks($page, (int)$totalPages, $baseUrlWithParams, $separator);

        // --- Create Paginator Instance ---
        $options = [
            'last_page' => (int)$totalPages,
            'first_page_url' => $totalPages > 0 ? $baseUrlWithParams . $separator . 'page=1' : null,
            'last_page_url'  => $totalPages > 0 ? $baseUrlWithParams . $separator . 'page=' . $totalPages : null,
            'prev_page_url' => $page > 1 ? $baseUrlWithParams . $separator . 'page=' . ($page - 1) : null,
            'next_page_url' => $page < $totalPages ? $baseUrlWithParams . $separator . 'page=' . ($page + 1) : null,
            'path' => $baseUrl,
            'pagination_links' => $linkStructure, // Pass the generated structure
        ];

        return new Paginator($data, $total, $perPage, $page, $options);
    }

    /** Paginate using cursor. */
    public function cursorPaginate(int $perPage, ?string $cursor = null): array
    {
        $this->initialize();
        $cursorColumn = 'id'; $direction = 'ASC';
        if ($perPage <= 0) $perPage = 15;
        $query = clone $this; $query->orderBy($cursorColumn, $direction);
        if ($cursor) { $operator = ($direction === 'ASC') ? '>' : '<'; $query->where($cursorColumn, $operator, $cursor); }
        $query->limit($perPage + 1);
        $results = $query->get();
        $hasNextPage = count($results) > $perPage;
        if ($hasNextPage) { array_pop($results); }
        $nextCursor = null;
        if (!empty($results)) { $lastItem = end($results); $nextCursor = is_object($lastItem) ? ($lastItem->{$cursorColumn} ?? null) : ($lastItem[$cursorColumn] ?? null); }
        $hasPrevPage = !empty($cursor); // Basic check
        $url = '/'; $queryString = ''; $queryParams = [];
        try {
            $currentRequest = request(); $urlParts = parse_url($currentRequest->getUri());
            if (isset($urlParts['query'])) { parse_str($urlParts['query'], $queryParams); } unset($queryParams['cursor']);
            $queryString = http_build_query($queryParams); $scheme = $currentRequest->getScheme() ?? 'http';
            $host = $currentRequest->getHost(); $path = $urlParts['path'] ?? '/'; $url = $scheme . '://' . $host . $path;
        } catch (\Throwable $e) {
             $errorMsg = "Cursor Pagination URL generation failed";
             if ($this->logger) $this->logger->warning($errorMsg, ['error' => $e->getMessage()]);
             else error_log($errorMsg . ": " . $e->getMessage());
        }
        $nextPageUrl = null;
        if ($nextCursor) { $nextQueryParams = array_merge($queryParams, ['cursor' => $nextCursor]); $nextPageUrl = $url . '?' . http_build_query($nextQueryParams); }
        $prevPageUrl = null; // Simplified
        return [
            'data' => $results, 'next_page_url' => $nextPageUrl, 'prev_page_url' => $prevPageUrl,
            'path' => $url, 'next_cursor' => $hasNextPage ? $nextCursor : null,
        ];
    }

    /** Get bind values only for the WHERE clause. */
    protected function getBindValuesForWhere(): array
    {
         $bindValues = [];
         foreach ($this->where as $condition) $bindValues[] = $condition[2];
         foreach ($this->orWhere as $condition) $bindValues[] = $condition[2];
         foreach ($this->whereIn as $condition) { if (!empty($condition[1])) { $bindValues = array_merge($bindValues, $condition[1]); } }
         foreach ($this->whereBetween as $condition) { $bindValues[] = $condition[1]; $bindValues[] = $condition[2]; }
         $bindValues = array_merge($bindValues, $this->whereRawBindings);
         return $bindValues;
    }

    /** Get bind values including limit/offset (use carefully). */
    protected function getBindValues(): array { return $this->getBindValuesForWhere(); }
    /** Get PDO param type. */
    protected function getPDOParamType($value): int { if (is_int($value)) return PDO::PARAM_INT; if (is_bool($value)) return PDO::PARAM_BOOL; if (is_null($value)) return PDO::PARAM_NULL; return PDO::PARAM_STR; }
    /** Close connection. */
    public function close(): void { $this->connection = null; $this->connectedSuccessfully = false; }

    /** Generate pagination links array. */
    protected function generatePaginationLinks(int $currentPage, int $totalPages, string $baseUrl, string $separator): array
    {
        $links = []; $range = 2; $onEachSide = 1; if ($totalPages <= 1) return [];
        $links[] = ['url' => $currentPage > 1 ? ($baseUrl . $separator . 'page=' . ($currentPage - 1)) : null, 'label' => '&laquo; Previous', 'active' => false, 'disabled' => $currentPage <= 1 ];
        $window = $onEachSide * 2;
        if ($totalPages <= $window + 4) { $start = 1; $end = $totalPages; }
        else { $start = max(1, $currentPage - $onEachSide); $end = min($totalPages, $currentPage + $onEachSide); if ($start === 1 && $end < $totalPages) { $end = min($totalPages, $start + $window); } elseif ($end === $totalPages && $start > 1) { $start = max(1, $end - $window); } }
        if ($start > 1) { $links[] = ['url' => $baseUrl . $separator . 'page=1', 'label' => '1', 'active' => false]; if ($start > 2) { $links[] = ['url' => null, 'label' => '...', 'active' => false, 'disabled' => true]; } }
        for ($i = $start; $i <= $end; $i++) { $links[] = ['url' => $baseUrl . $separator . 'page=' . $i, 'label' => (string)$i, 'active' => $currentPage === $i]; }
        if ($end < $totalPages) { if ($end < $totalPages - 1) { $links[] = ['url' => null, 'label' => '...', 'active' => false, 'disabled' => true]; } $links[] = ['url' => $baseUrl . $separator . 'page=' . $totalPages, 'label' => (string)$totalPages, 'active' => false]; }
        $links[] = ['url' => $currentPage < $totalPages ? ($baseUrl . $separator . 'page=' . ($currentPage + 1)) : null, 'label' => 'Next &raquo;', 'active' => false, 'disabled' => $currentPage >= $totalPages ];
        return $links;
    }

    /** Set the model class associated with this query. */
    public function setModel(string $modelClass): self
    {
        if (!class_exists($modelClass) || !method_exists($modelClass, 'hydrateModels')) {
            throw new InvalidArgumentException("Invalid model class provided or hydrateModels method missing: {$modelClass}");
        }
        $this->modelClass = $modelClass;
        return $this;
    }
}
