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
use Closure; // Import Closure for type hinting

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
                    $potentialBasePath = defined('BASE_PATH') ? constant('BASE_PATH') : dirname(__DIR__, 5);
                    $storagePath = $potentialBasePath . '/storage';
                    $warningMsg = "Warning: 'app.storage_path' not configured or invalid, using fallback for DB path: " . $storagePath;
                    if ($this->logger)
                        $this->logger->warning($warningMsg);
                    else
                        error_log($warningMsg);
                    if (!is_dir($storagePath)) {
                        @mkdir($storagePath, 0755, true);
                    }
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

            // Use LoggedPDO to enable global query logging at PDO level
            $this->connection = new \SwallowPHP\Framework\Database\Instrumentation\LoggedPDO($dsn, $username, $password, $pdoOptions);
            $this->connectedSuccessfully = true;

            if ($driver === 'sqlite') {
                try {
                    $this->connection->exec('PRAGMA journal_mode = WAL;');
                } catch (PDOException $e) {
                    $warningMsg = "SQLite WAL mode could not be enabled";
                    if ($this->logger)
                        $this->logger->warning($warningMsg, ['error' => $e->getMessage()]);
                    else
                        error_log($warningMsg . ": " . $e->getMessage());
                }
            }
        } catch (PDOException $e) {
            // Log connection error before throwing
            $errorMsg = 'Veritabanı bağlantısı başlatılamadı';
            if ($this->logger)
                $this->logger->critical($errorMsg, ['exception' => $e, 'driver' => $driver]);
            else
                error_log($errorMsg . ": " . $e->getMessage());
            throw new Exception($errorMsg, 0, $e); // Re-throw generic Exception
        } catch (\Throwable $e) { // Catch other potential errors (like InvalidArgumentException)
            $errorMsg = 'Veritabanı başlatılırken beklenmedik hata';
            if ($this->logger)
                $this->logger->critical($errorMsg, ['exception' => $e, 'driver' => $driver]);
            else
                error_log($errorMsg . ": " . $e->getMessage());
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
        $this->table = '';
        $this->select = '*';
        $this->where = [];
        $this->whereRaw = [];
        $this->whereIn = [];
        $this->whereBetween = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
        $this->orWhere = [];
        $this->whereRawBindings = [];
        $this->modelClass = null;
    }

    /** Set the columns to select. */
    public function select(array $columns = ['*']): self
    {
        $this->select = implode(', ', $columns);
        return $this;
    }
    /**
     * Add a where condition.
     * Can be called with:
     * - Two arguments: where(string $column, mixed $value) - operator defaults to '='.
     * - Three arguments: where(string $column, string $operator, mixed $value).
     * - One argument: where(Closure $callback) - for nested conditions with AND boolean.
     * - Two arguments: where(Closure $callback, string $boolean = 'AND') - specify boolean.
     */
    public function where($column, $operatorOrValue = null, $value = null, $boolean = 'AND'): self
    {
        // Handle nested where (Closure)
        if ($column instanceof Closure) {
            // The second argument might be the boolean ('AND'/'OR') if provided
            $actualBoolean = ($operatorOrValue === 'OR' || $operatorOrValue === 'or') ? 'OR' : 'AND';
            $this->where[] = ['type' => 'Nested', 'query' => $column, 'boolean' => $actualBoolean];
            return $this;
        }

        // Handle standard where (column, operator, value) or (column, value)
        // Adjust logic slightly to handle the optional $boolean argument potentially being passed
        // when only two args (column, value) are intended.
        if (func_num_args() === 2 || $value === null && $operatorOrValue !== null && !($operatorOrValue instanceof Closure)) {
            // where($column, $value) case
            $this->where[] = ['type' => 'Basic', 'column' => $column, 'operator' => '=', 'value' => $operatorOrValue, 'boolean' => $boolean];
        } elseif ($value !== null) {
            // where($column, $operator, $value) case
            $this->where[] = ['type' => 'Basic', 'column' => $column, 'operator' => $operatorOrValue, 'value' => $value, 'boolean' => $boolean];
        } else {
            // Fallback or error? Could indicate invalid usage.
            // For now, let's assume it might be a where($column) scenario which isn't standard.
            // Or perhaps where($column, null) which should likely be whereNull($column).
            // Let's treat where($column, null) as where($column, '=', null) for now.
            $this->where[] = ['type' => 'Basic', 'column' => $column, 'operator' => '=', 'value' => $operatorOrValue, 'boolean' => $boolean];
        }

        return $this;
    }

    /**
     * Add an or where condition.
     * Can be called with:
     * - Three arguments: orWhere(string $column, string $operator, mixed $value).
     * - One argument: orWhere(Closure $callback) - for nested conditions with OR boolean.
     */
    public function orWhere($column, $operator = null, $value = null): self
    {
        if ($column instanceof Closure) {
            // Nested orWhere
            $this->where[] = ['type' => 'Nested', 'query' => $column, 'boolean' => 'OR'];
            return $this;
        }

        // Standard orWhere (assuming 3 arguments for simplicity here, matching previous implementation)
        // We might need to add the 2-argument version (column, value) later if desired.
        if ($value !== null) {
            $this->where[] = ['type' => 'Basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => 'OR'];
        } else {
            // Handle orWhere(column, value)
            $this->where[] = ['type' => 'Basic', 'column' => $column, 'operator' => '=', 'value' => $operator, 'boolean' => 'OR'];
        }
        return $this;
    }

    /** Add a where in condition. */
    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        if (empty($values)) {
            // Add a condition that's always false
            return $this->whereRaw("1 = 0", [], $boolean);
        }
        $this->where[] = ['type' => 'In', 'column' => $column, 'values' => $values, 'boolean' => $boolean];
        return $this;
    }
    /** Add a where between condition. */
    public function whereBetween(string $column, $start, $end, string $boolean = 'AND'): self
    {
        $this->where[] = ['type' => 'Between', 'column' => $column, 'values' => [$start, $end], 'boolean' => $boolean];
        return $this;
    }
    /** Add a raw where condition. */
    public function whereRaw(string $rawCondition, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->where[] = ['type' => 'Raw', 'sql' => $rawCondition, 'bindings' => $bindings, 'boolean' => $boolean];
        // Keep whereRawBindings for now for compatibility with getBindValuesForWhere, but ideally refactor later
        $this->whereRawBindings = array_merge($this->whereRawBindings, $bindings);
        return $this;
    }
    /** Add an order by clause. */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }
        $this->orderBy[] = [$column, $direction];
        return $this;
    }
    /** Set the limit. */
    public function limit(int $limit): self
    {
        $this->limit = $limit > 0 ? $limit : null;
        return $this;
    }
    /** Set the offset. */
    public function offset(int $offset): self
    {
        $this->offset = $offset >= 0 ? $offset : null;
        return $this;
    }

    /** Build the where clause SQL string. */
    protected function buildWhereClause(): string
    {
        if (empty($this->where)) {
            return '';
        }

        $sqlParts = [];
        $first = true;

        foreach ($this->where as $condition) {
            $boolean = $first ? 'WHERE' : $condition['boolean']; // Use 'WHERE' for the very first condition
            $type = $condition['type'] ?? 'Basic'; // Default to Basic if type isn't set

            switch ($type) {
                case 'Basic':
                    $sqlParts[] = "{$boolean} `{$condition['column']}` {$condition['operator']} ?";
                    break;
                case 'In':
                    if (!empty($condition['values'])) {
                        $placeholders = implode(', ', array_fill(0, count($condition['values']), '?'));
                        $sqlParts[] = "{$boolean} `{$condition['column']}` IN ({$placeholders})";
                    } else {
                        // Handle empty IN array - this case is handled in whereIn by adding a raw '1=0'
                    }
                    break;
                case 'Between':
                    $sqlParts[] = "{$boolean} `{$condition['column']}` BETWEEN ? AND ?";
                    break;
                case 'Raw':
                    $sqlParts[] = "{$boolean} ({$condition['sql']})";
                    break;
                case 'Nested':
                    $nestedQuery = $this->newQuery(); // Create a fresh builder for the closure
                    $condition['query']($nestedQuery); // Execute the closure
                    $nestedSql = $nestedQuery->buildWhereClause(); // Build the nested SQL

                    // Remove leading 'WHERE ' from nested SQL if it exists
                    if (str_starts_with($nestedSql, 'WHERE ')) {
                        $nestedSql = substr($nestedSql, 6);
                    }

                    if (!empty($nestedSql)) {
                        $sqlParts[] = "{$boolean} ({$nestedSql})";
                    }
                    break;
                // Add cases for whereNull, whereNotNull etc. if implemented later
            }
            $first = false; // Only the very first condition gets 'WHERE'
        }

        return implode(' ', $sqlParts);
    }

    /** Creates a new instance of the query builder for nesting. */
    protected function newQuery(): self
    {
        // Create a new instance without re-initializing connection etc.
        // Pass the logger if available.
        $newInstance = new static($this->config);
        $newInstance->logger = $this->logger; // Share logger
        // Important: Do NOT copy table, select, existing wheres etc. It's a fresh sub-query.
        return $newInstance;
    }

    /** Execute the select query and get the results. */
    public function get(): array
    {
        $this->initialize();
        $sql = "SELECT {$this->select} FROM `{$this->table}` ";
        $sql .= $this->buildWhereClause();
        if (!empty($this->orderBy)) {
            $orderByColumns = array_map(fn($order) => "`{$order[0]}` {$order[1]}", $this->orderBy);
            $sql .= ' ORDER BY ' . implode(', ', $orderByColumns);
        }
        if ($this->limit !== null) {
            $sql .= " LIMIT ?";
            if ($this->offset !== null) {
                $sql .= " OFFSET ?";
            }
        }

        try {
            $statement = $this->connection->prepare($sql);
            $paramIndex = 1;
            $whereBindings = $this->getBindValuesForWhere();
            $allBindings = [];
            foreach ($whereBindings as $value) {
                $statement->bindValue($paramIndex++, $value, $this->getPDOParamType($value));
                $allBindings[] = $value;
            }
            if ($this->limit !== null) {
                $statement->bindValue($paramIndex++, $this->limit, PDO::PARAM_INT);
                $allBindings[] = $this->limit;
                if ($this->offset !== null) {
                    $statement->bindValue($paramIndex++, $this->offset, PDO::PARAM_INT);
                    $allBindings[] = $this->offset;
                }
            }
            $statement->execute();
            $rows = $statement->fetchAll();
        } catch (PDOException $e) {
            $errorMsg = "Database Error in get()";
            if ($this->logger)
                $this->logger->error($errorMsg, ['exception' => $e, 'sql' => $sql]);
            else
                error_log($errorMsg . ": " . $e->getMessage() . " | SQL: " . $sql);
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
        if (empty($data))
            return false;
        $columns = implode(', ', array_map(fn($col) => "`$col`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$this->table}` ($columns) VALUES ($placeholders)";
        try {
            $statement = $this->connection->prepare($sql);
            $values = array_values($data);
            foreach ($values as $key => $value) {
                $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
            }
            $success = $statement->execute();
            return ($success && $this->connection->lastInsertId() !== false) ? (int) $this->connection->lastInsertId() : false;
        } catch (PDOException $e) {
            $errorMsg = "Database Error in insert()";
            if ($this->logger)
                $this->logger->error($errorMsg, ['exception' => $e, 'sql' => $sql, 'data' => $data]); // Log data too
            else
                error_log($errorMsg . ": " . $e->getMessage() . " | SQL: " . $sql);
            return false;
        }
    }

    /** Update records. */
    public function update(array $data): int
    {
        $this->initialize();
        if (empty($data))
            return 0;
        $sets = array_map(fn($column) => "`$column` = ?", array_keys($data));
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets);
        $sql .= ' ' . $this->buildWhereClause();
        try {
            $statement = $this->connection->prepare($sql);
            $bindValues = array_merge(array_values($data), $this->getBindValuesForWhere());
            foreach ($bindValues as $key => $value) {
                $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
            }
            $statement->execute();
            return $statement->rowCount();
        } catch (PDOException $e) {
            $errorMsg = "Database Error in update()";
            if ($this->logger)
                $this->logger->error($errorMsg, ['exception' => $e, 'sql' => $sql, 'data' => $data]);
            else
                error_log($errorMsg . ": " . $e->getMessage() . " | SQL: " . $sql);
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
            foreach ($bindValues as $key => $value) {
                $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
            }
            $statement->execute();
            return $statement->rowCount();
        } catch (PDOException $e) {
            $errorMsg = "Database Error in delete()";
            if ($this->logger)
                $this->logger->error($errorMsg, ['exception' => $e, 'sql' => $sql]);
            else
                error_log($errorMsg . ": " . $e->getMessage() . " | SQL: " . $sql);
            return 0;
        }
    }

    /** Count matching records. */
    public function count(): int
    {
        $this->initialize();
        $originalSelect = $this->select;
        $originalOrderBy = $this->orderBy;
        $originalLimit = $this->limit;
        $originalOffset = $this->offset;
        $this->select = 'COUNT(*) AS count';
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
        $sql = "SELECT {$this->select} FROM `{$this->table}` " . $this->buildWhereClause();
        try {
            $statement = $this->connection->prepare($sql);
            $bindValues = $this->getBindValuesForWhere();
            foreach ($bindValues as $key => $value) {
                $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
            }
            $statement->execute();
            $result = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $errorMsg = "Database Error in count()";
            if ($this->logger)
                $this->logger->error($errorMsg, ['exception' => $e, 'sql' => $sql]);
            else
                error_log($errorMsg . ": " . $e->getMessage() . " | SQL: " . $sql);
            $result = ['count' => 0];
        } finally {
            $this->select = $originalSelect;
            $this->orderBy = $originalOrderBy;
            $this->limit = $originalLimit;
            $this->offset = $originalOffset;
        }
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Paginate results.
     * @param int $perPage Number of items per page.
     * @param int $page Current page number.
     * @return Paginator Paginator instance.
     */
    public function paginate(int $perPage, int $page = 1): Paginator
    {
        if ($perPage <= 0)
            $perPage = 15;
        $this->initialize();

        // Clone the current query state BEFORE calculating total and fetching data
        $queryForCount = clone $this;
        // Remove order by for count query for potential performance improvement
        // but keep where clauses. Limit/offset are handled by count() method itself.
        $queryForCount->orderBy = [];

        // Calculate total using the cloned query state
        $total = $queryForCount->count();

        $totalPages = $perPage > 0 ? ceil($total / $perPage) : 0;
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $perPage;

        // Fetch data using the original query state with limit and offset applied
        $queryForData = clone $this; // Clone again to keep original state clean
        $queryForData->limit($perPage)->offset($offset);
        $data = $queryForData->get(); // get() handles model hydration if set

        // --- URL Generation ---
        $baseUrl = '/';
        $currentQuery = [];
        $separator = '?';
        try {
            $currentRequest = request(); // Get current request instance
            $baseUrl = strtok($currentRequest->fullUrl(), '?'); // Get base URL without query string
            $currentQuery = $currentRequest->query(); // Get all query parameters
            unset($currentQuery['page']); // Remove 'page' parameter itself
            $separator = empty($currentQuery) ? '?' : '&'; // Determine separator for new page param
        } catch (\Throwable $e) {
            $errorMsg = "Pagination URL generation failed: Could not get request details";
            if ($this->logger)
                $this->logger->warning($errorMsg, ['error' => $e->getMessage()]);
            else
                error_log($errorMsg . " - " . $e->getMessage());
        }
        // Base URL for links, including existing query parameters (page will be added by generatePaginationLinks)
        $baseUrlWithExistingQuery = $baseUrl . (empty($currentQuery) ? '' : '?' . http_build_query($currentQuery));
        // Ensure separator is correct based on whether baseUrlWithExistingQuery already has '?'
        $separator = str_contains($baseUrlWithExistingQuery, '?') ? '&' : '?';

        $linkStructure = $this->generatePaginationLinks($page, (int) $totalPages, $baseUrlWithExistingQuery, $separator);

        // --- Create Paginator Instance ---
        $options = [
            'last_page' => (int) $totalPages,
            // Use $baseUrlWithExistingQuery for base URLs before adding page number
            'first_page_url' => $totalPages > 0 ? $baseUrlWithExistingQuery . $separator . 'page=1' : null,
            'last_page_url' => $totalPages > 0 ? $baseUrlWithExistingQuery . $separator . 'page=' . $totalPages : null,
            'prev_page_url' => $page > 1 ? $baseUrlWithExistingQuery . $separator . 'page=' . ($page - 1) : null,
            'next_page_url' => $page < $totalPages ? $baseUrlWithExistingQuery . $separator . 'page=' . ($page + 1) : null,
            'path' => $baseUrl, // Base path without query
            'pagination_links' => $linkStructure, // Pass the generated structure
            'query' => $currentQuery // Pass current query params (excluding 'page') for appends() base
        ];

        return new Paginator($data, $total, $perPage, $page, $options);
    }

    /**
     * Paginate results using cursor-based pagination.
     * @param int $perPage Number of items per page.
     * @param string|null $cursor Current cursor value.
     * @return Paginator Paginator instance.
     */
    public function cursorPaginate(int $perPage, ?string $cursor = null): Paginator
    {
        $this->initialize();
        $cursorColumn = 'id'; // Default cursor column
        $direction = 'ASC';   // Default direction

        if ($perPage <= 0)
            $perPage = 15;

        // Clone the current query to preserve original state
        $query = clone $this;
        $query->orderBy($cursorColumn, $direction);

        // Apply cursor condition if cursor is provided
        if ($cursor) {
            $operator = ($direction === 'ASC') ? '>' : '<';
            $query->where($cursorColumn, $operator, $cursor);
        }

        // Get one extra item to determine if there are more pages
        $query->limit($perPage + 1);
        $results = $query->get();

        // Check if we have more results than requested
        $hasMorePages = count($results) > $perPage;

        // Remove the extra item if we have more pages
        if ($hasMorePages) {
            array_pop($results);
        }

        // Get next cursor from the last item
        $nextCursor = null;
        if (!empty($results)) {
            $lastItem = end($results);
            $nextCursor = is_object($lastItem) ? ($lastItem->{$cursorColumn} ?? null) : ($lastItem[$cursorColumn] ?? null);
        }

        // Determine base URL and query parameters
        $baseUrl = '/';
        $queryParams = [];

        try {
            $currentRequest = request();
            $baseUrl = strtok($currentRequest->fullUrl(), '?');
            $queryParams = $currentRequest->query();
            unset($queryParams['cursor']); // Remove cursor parameter
        } catch (\Throwable $e) {
            $errorMsg = "Cursor Pagination URL generation failed";
            if ($this->logger)
                $this->logger->warning($errorMsg, ['error' => $e->getMessage()]);
            else
                error_log($errorMsg . ": " . $e->getMessage());
        }

        // Build options for Paginator - use count($results) for total to avoid type error
        $options = [
            'path' => $baseUrl,
            'is_cursor_pagination' => true,
            'cursor_name' => 'cursor',
            'next_cursor' => $hasMorePages ? $nextCursor : null,
            'prev_cursor' => $cursor,
            'per_page' => $perPage,
            'has_more_pages' => $hasMorePages,
            'current_cursor' => $cursor,
            'last_page' => 0, // Set a valid default for lastPage
            'next_page_url' => $hasMorePages ? ($baseUrl . '?cursor=' . urlencode($nextCursor)) : null,
            'prev_page_url' => $cursor ? ($baseUrl . '?cursor=') : null,
            'query' => $queryParams
        ];

        // Pass count($results) as the total for type compatibility
        return new Paginator($results, count($results), $perPage, 1, $options);
    }

    /** Get bind values only for the WHERE clause. */
    /** Get all bind values for the WHERE clause, including nested ones. */
    protected function getBindValuesForWhere(): array
    {
        $bindings = [];
        foreach ($this->where as $condition) {
            $type = $condition['type'] ?? 'Basic';

            switch ($type) {
                case 'Basic':
                    $bindings[] = $condition['value'];
                    break;
                case 'In':
                    if (!empty($condition['values'])) {
                        $bindings = array_merge($bindings, $condition['values']);
                    }
                    break;
                case 'Between':
                    $bindings = array_merge($bindings, $condition['values']); // values is an array [start, end]
                    break;
                case 'Raw':
                    if (!empty($condition['bindings'])) {
                        $bindings = array_merge($bindings, $condition['bindings']);
                    }
                    break;
                case 'Nested':
                    // Need to get bindings from the nested query generated within the closure
                    $nestedQuery = $this->newQuery();
                    $condition['query']($nestedQuery);
                    $nestedBindings = $nestedQuery->getBindValuesForWhere(); // Recursively get bindings
                    $bindings = array_merge($bindings, $nestedBindings);
                    break;
            }
        }
        // Include bindings from the old whereRawBindings property for backward compatibility,
        // though ideally, 'Raw' type should handle its own bindings.
        // $bindings = array_merge($bindings, $this->whereRawBindings); // Re-evaluate if needed

        return $bindings;
    }

    /** Get bind values including limit/offset (use carefully). */
    protected function getBindValues(): array
    {
        return $this->getBindValuesForWhere();
    }
    /** Get PDO param type. */
    protected function getPDOParamType($value): int
    {
        if (is_int($value))
            return PDO::PARAM_INT;
        if (is_bool($value))
            return PDO::PARAM_BOOL;
        if (is_null($value))
            return PDO::PARAM_NULL;
        return PDO::PARAM_STR;
    }
    /** Close connection. */
    public function close(): void
    {
        $this->connection = null;
        $this->connectedSuccessfully = false;
    }

    /** Generate pagination links array. */
    protected function generatePaginationLinks(int $currentPage, int $totalPages, string $baseUrl, string $separator): array
    {
        $links = [];
        $range = 2;
        $onEachSide = 1;
        if ($totalPages <= 1)
            return [];
        $links[] = ['url' => $currentPage > 1 ? ($baseUrl . $separator . 'page=' . ($currentPage - 1)) : null, 'label' => '&laquo; Previous', 'active' => false, 'disabled' => $currentPage <= 1];
        $window = $onEachSide * 2;
        if ($totalPages <= $window + 4) {
            $start = 1;
            $end = $totalPages;
        } else {
            $start = max(1, $currentPage - $onEachSide);
            $end = min($totalPages, $currentPage + $onEachSide);
            if ($start === 1 && $end < $totalPages) {
                $end = min($totalPages, $start + $window);
            } elseif ($end === $totalPages && $start > 1) {
                $start = max(1, $end - $window);
            }
        }
        if ($start > 1) {
            $links[] = ['url' => $baseUrl . $separator . 'page=1', 'label' => '1', 'active' => false];
            if ($start > 2) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false, 'disabled' => true];
            }
        }
        for ($i = $start; $i <= $end; $i++) {
            $links[] = ['url' => $baseUrl . $separator . 'page=' . $i, 'label' => (string) $i, 'active' => $currentPage === $i];
        }
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false, 'disabled' => true];
            }
            $links[] = ['url' => $baseUrl . $separator . 'page=' . $totalPages, 'label' => (string) $totalPages, 'active' => false];
        }
        $links[] = ['url' => $currentPage < $totalPages ? ($baseUrl . $separator . 'page=' . ($currentPage + 1)) : null, 'label' => 'Next &raquo;', 'active' => false, 'disabled' => $currentPage >= $totalPages];
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
