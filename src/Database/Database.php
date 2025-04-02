<?php

namespace SwallowPHP\Framework\Database;

use PDO;
use PDOException;
use InvalidArgumentException;
use Exception;
use SwallowPHP\Framework\Foundation\Config; // Use Config (via helper)
use SwallowPHP\Framework\Http\Request; // Needed for request() helper in pagination

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

    /**
     * Database constructor. Initializes the connection.
     */
    public function __construct(?array $config = null)
    {
        if ($config) {
            $this->config = $config;
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
        // Get config, falling back to framework defaults ONLY if app config not passed via DI
        $config = $this->config ?: config('database.connections.' . config('database.default', 'mysql'), []);
        $driver = $config['driver'] ?? 'mysql';
        $host = $config['host'] ?? env('DB_HOST', '127.0.0.1'); // Rely on app config or .env
        $port = $config['port'] ?? env('DB_PORT', '3306');
        $database = $config['database'] ?? env('DB_DATABASE', 'swallowphp');
        $username = $config['username'] ?? env('DB_USERNAME', 'root');
        $password = $config['password'] ?? env('DB_PASSWORD', '');
        $charset = $config['charset'] ?? env('DB_CHARSET', 'utf8mb4');
        $options = $config['options'] ?? [];

        try {
            // Build DSN based on driver more robustly
            if ($driver === 'sqlite') {
                 // Get storage path from app config (should be absolute)
                 $storagePath = config('app.storage_path');
                 if (!$storagePath || !is_dir(dirname($storagePath))) { // Check if parent dir exists for storage_path itself
                     // If config() fails or returns invalid path, try a reasonable default relative to where vendor is likely installed
                     // This is a fallback, ideally app.storage_path is configured correctly.
                     $potentialBasePath = dirname(__DIR__, 3); // Assumes vendor/swallowphp/framework/src structure
                     $storagePath = $potentialBasePath . '/storage';
                     // Log a warning that fallback path is used
                     error_log("Warning: 'app.storage_path' not configured or invalid, using fallback: " . $storagePath);
                     // Ensure this fallback path exists
                     if (!is_dir($storagePath)) {
                          if (!@mkdir($storagePath, 0755, true) && !is_dir($storagePath)) {
                              throw new InvalidArgumentException("Fallback storage path could not be created: {$storagePath}");
                          }
                     }
                 }

                 // $database holds the relative path like 'database/database.sqlite' from config/database.php
                 $dbPath = $storagePath . '/' . ltrim($database, '/');

                 // Ensure the directory for the SQLite file exists
                 $dbDir = dirname($dbPath);
                 if (!is_dir($dbDir)) {
                      if (!@mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
                           throw new InvalidArgumentException("Could not create directory for SQLite database: {$dbDir}");
                      }
                 }

                 $dsn = "sqlite:" . $dbPath;
            } elseif ($driver === 'pgsql') {
                $dsn = "pgsql:host=$host;port=$port;dbname=$database";
                 // Add user/password to DSN for pgsql? Check PDO docs. Often passed to constructor.
            } else { // Default to mysql
                 $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=$charset";
            }


            $defaultOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            // Merge default and config options, config overrides defaults
            $pdoOptions = array_replace($defaultOptions, $options);

            $this->connection = new PDO($dsn, $username, $password, $pdoOptions);
            $this->connectedSuccessfully = true;

            // Enable WAL mode for SQLite after connection for better concurrency
            if ($driver === 'sqlite') {
                 try {
                     $this->connection->exec('PRAGMA journal_mode = WAL;');
                 } catch (PDOException $e) {
                     // Ignore error if WAL mode is not supported or fails
                     error_log("SQLite WAL mode could not be enabled: " . $e->getMessage());
                 }
            }

        } catch (PDOException $e) {
            throw new Exception('Veritabanı bağlantısı başlatılamadı: ' . $e->getMessage());
        }
    }

    /**
     * Set the table for the query.
     * @param string $table The name of the table.
     * @return self
     */
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
        $this->modelClass = null; // Reset model class too
    }

    /** Set the columns to select. */
    public function select(array $columns = ['*']): self
    {
        $this->select = implode(', ', $columns);
        return $this;
    }

    /** Add a where condition. */
    public function where(string $column, string $operator, $value): self
    {
        $this->where[] = [$column, $operator, $value];
        return $this;
    }

    /** Add an or where condition. */
    public function orWhere(string $column, string $operator, $value): self
    {
        $this->orWhere[] = [$column, $operator, $value];
        return $this;
    }

    /** Add a where in condition. */
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            $this->whereRaw("1 = 0"); // Ensure query returns no results
            return $this;
        }
        $this->whereIn[] = [$column, $values];
        return $this;
    }

    /** Add a where between condition. */
    public function whereBetween(string $column, $start, $end): self
    {
        $this->whereBetween[] = [$column, $start, $end];
        return $this;
    }

    /** Add a raw where condition. */
    public function whereRaw(string $rawCondition, array $bindings = []): self
    {
        $this->whereRaw[] = $rawCondition;
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

    /** Build the where clause. */
    protected function buildWhereClause(): string
    {
        $conditions = [];
        $firstCondition = true;

        foreach ($this->where as $condition) {
            $conjunction = $firstCondition ? 'WHERE' : 'AND';
            // Basic quoting for column name, might need adjustment for different DBs
            $conditions[] = "$conjunction `{$condition[0]}` {$condition[1]} ?";
            $firstCondition = false;
        }
        foreach ($this->orWhere as $condition) {
            $conjunction = $firstCondition ? 'WHERE' : 'OR';
            $conditions[] = "$conjunction `{$condition[0]}` {$condition[1]} ?";
            $firstCondition = false;
        }
        foreach ($this->whereIn as $condition) {
            if (empty($condition[1])) continue;
            $placeholders = implode(', ', array_fill(0, count($condition[1]), '?'));
            $conjunction = $firstCondition ? 'WHERE' : 'AND';
            $conditions[] = "$conjunction `{$condition[0]}` IN ($placeholders)";
            $firstCondition = false;
        }
        foreach ($this->whereBetween as $condition) {
            $conjunction = $firstCondition ? 'WHERE' : 'AND';
            $conditions[] = "$conjunction `{$condition[0]}` BETWEEN ? AND ?";
            $firstCondition = false;
        }
        if (!empty($this->whereRaw)) {
            foreach ($this->whereRaw as $rawCondition) {
                $conjunction = $firstCondition ? 'WHERE' : 'AND';
                // Wrap raw condition in parentheses for safety? Depends on usage.
                $conditions[] = "$conjunction ($rawCondition)";
                $firstCondition = false;
            }
        }
        return implode(' ', $conditions);
    }

    /**
     * Execute the select query and get the results.
     * Hydrates results if a modelClass is set.
     * @return array The query results (array of models or array of arrays).
     */
    public function get(): array
    {
        $this->initialize(); // Ensure connection is ready
        $sql = "SELECT {$this->select} FROM `{$this->table}` "; // Quote table name
        $sql .= $this->buildWhereClause();
        if (!empty($this->orderBy)) {
            // Quote order by columns
            $orderByColumns = array_map(fn($order) => "`{$order[0]}` {$order[1]}", $this->orderBy);
            $sql .= ' ORDER BY ' . implode(', ', $orderByColumns);
        }
        // Use LIMIT/OFFSET syntax
        if ($this->limit !== null) {
            $sql .= " LIMIT ?";
            if ($this->offset !== null) {
                $sql .= " OFFSET ?";
            }
        }

        try {
            $statement = $this->connection->prepare($sql);
            $bindValues = $this->getBindValues(); // Get all bindings needed

            // Bind WHERE parameters first
            $paramIndex = 1;
            $whereBindings = $this->getBindValuesForWhere();
            foreach ($whereBindings as $value) {
                $statement->bindValue($paramIndex++, $value, $this->getPDOParamType($value));
            }

            // Bind LIMIT and OFFSET if applicable (PDO needs int type)
            if ($this->limit !== null) {
                 $statement->bindValue($paramIndex++, $this->limit, PDO::PARAM_INT);
                 if ($this->offset !== null) {
                     $statement->bindValue($paramIndex++, $this->offset, PDO::PARAM_INT);
                 }
            }

            $statement->execute();
            $rows = $statement->fetchAll();

        } catch (PDOException $e) {
             error_log("Database Error in get(): " . $e->getMessage() . " | SQL: " . $sql);
             throw $e; // Re-throw exception
        }

        // If a model class is associated, hydrate the results
        if ($this->modelClass && method_exists($this->modelClass, 'hydrateModels')) {
            return call_user_func([$this->modelClass, 'hydrateModels'], $rows);
        }
        return $rows; // Return raw rows if no model class
    }

    /**
     * Get the first result from the query.
     * Hydrates result if a modelClass is set.
     * @return mixed|null The first result (model object or array) or null.
     */
    public function first()
    {
        $this->limit(1);
        $results = $this->get(); // Use the modified get() which handles hydration
        return $results[0] ?? null;
    }

    /**
     * Insert a new record.
     * @param array $data Data to insert.
     * @return int|false The ID of the inserted record or false on failure.
     */
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
            foreach ($values as $key => $value) {
                $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
            }
            $success = $statement->execute();
            // Check if insert succeeded and driver supports lastInsertId
            return ($success && $this->connection->lastInsertId() !== false) ? (int)$this->connection->lastInsertId() : false;
        } catch (PDOException $e) {
            error_log("Database Error in insert(): " . $e->getMessage() . " | SQL: " . $sql);
            return false; // Return false on error
        }
    }


    /**
     * Update records.
     * @param array $data Data to update.
     * @return int The number of affected rows.
     */
    public function update(array $data): int
    {
        $this->initialize();
        if (empty($data)) return 0;
        $sets = array_map(fn($column) => "`$column` = ?", array_keys($data));
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets);
        $sql .= ' ' . $this->buildWhereClause(); // Append WHERE clause
        try {
            $statement = $this->connection->prepare($sql);
            $bindValues = array_merge(array_values($data), $this->getBindValuesForWhere());
            foreach ($bindValues as $key => $value) {
                $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
            }
            $statement->execute();
            return $statement->rowCount();
        } catch (PDOException $e) {
            error_log("Database Error in update(): " . $e->getMessage() . " | SQL: " . $sql);
            return 0; // Return 0 affected rows on error
        }
    }

    /**
     * Delete records.
     * @return int The number of affected rows.
     */
    public function delete(): int
    {
        $this->initialize();
        $sql = "DELETE FROM `{$this->table}` " . $this->buildWhereClause(); // Append WHERE clause
        try {
            $statement = $this->connection->prepare($sql);
            $bindValues = $this->getBindValuesForWhere();
            foreach ($bindValues as $key => $value) {
                $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
            }
            $statement->execute();
            return $statement->rowCount();
        } catch (PDOException $e) {
            error_log("Database Error in delete(): " . $e->getMessage() . " | SQL: " . $sql);
            return 0; // Return 0 affected rows on error
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
             error_log("Database Error in count(): " . $e->getMessage() . " | SQL: " . $sql);
             $result = ['count' => 0]; // Default to 0 on error
        } finally {
            // Restore original query state regardless of success/failure
            $this->select = $originalSelect;
            $this->orderBy = $originalOrderBy;
            $this->limit = $originalLimit;
            $this->offset = $originalOffset;
        }

        return (int)($result['count'] ?? 0);
    }


    /** Paginate results. */
    public function paginate(int $perPage, int $page = 1): array
    {
        if ($perPage <= 0) $perPage = 15;
        $this->initialize();
        $total = $this->count();
        $totalPages = $perPage > 0 ? ceil($total / $perPage) : 0;
        $page = max(1, (int)$page); // Ensure page is a positive integer
        $offset = ($page - 1) * $perPage;

        $queryForData = clone $this;
        $queryForData->limit($perPage)->offset($offset);
        $data = $queryForData->get(); // Handles hydration

        // Build URLs
        $baseUrl = '/'; // Default path
        $existingParams = [];
        $separator = '?';
        try {
            $currentRequest = request(); // Use helper function
            $baseUrl = strtok($currentRequest->fullUrl(), '?');
            $existingParams = $currentRequest->query();
            unset($existingParams['page']);
            $separator = empty($existingParams) ? '?' : '&';
        } catch (\Throwable $e) {
             error_log("Pagination URL generation failed: Could not get request details - " . $e->getMessage());
        }
        $baseUrlWithParams = $baseUrl . (empty($existingParams) ? '' : '?' . http_build_query($existingParams));


        $pagination = $this->generatePaginationLinks($page, (int)$totalPages, $baseUrlWithParams, $separator);

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int)$totalPages,
            'first_page_url' => $totalPages > 0 ? $baseUrlWithParams . $separator . 'page=1' : null,
            'last_page_url'  => $totalPages > 0 ? $baseUrlWithParams . $separator . 'page=' . $totalPages : null,
            'prev_page_url' => $page > 1 ? $baseUrlWithParams . $separator . 'page=' . ($page - 1) : null,
            'next_page_url' => $page < $totalPages ? $baseUrlWithParams . $separator . 'page=' . ($page + 1) : null,
            'path' => $baseUrl,
            'pagination_links' => $pagination,
        ];
    }

    /** Paginate using cursor. */
    public function cursorPaginate(int $perPage, ?string $cursor = null): array
    {
        $this->initialize();
        $cursorColumn = 'id'; // TODO: Configurable
        $direction = 'ASC'; // TODO: Configurable

        if ($perPage <= 0) $perPage = 15;

        $query = clone $this;
        $query->orderBy($cursorColumn, $direction);

        if ($cursor) {
            $operator = ($direction === 'ASC') ? '>' : '<';
            $query->where($cursorColumn, $operator, $cursor);
        }
        $query->limit($perPage + 1);
        $results = $query->get(); // Handles hydration

        $hasNextPage = count($results) > $perPage;
        if ($hasNextPage) {
            array_pop($results);
        }

        $nextCursor = null;
        if (!empty($results)) {
            $lastItem = end($results);
            $nextCursor = is_object($lastItem) ? ($lastItem->{$cursorColumn} ?? null) : ($lastItem[$cursorColumn] ?? null);
        }

        // Build URLs
        $url = '/'; $queryString = ''; $queryParams = [];
        try {
            $currentRequest = request();
            $urlParts = parse_url($currentRequest->getUri());
            if (isset($urlParts['query'])) { parse_str($urlParts['query'], $queryParams); }
            unset($queryParams['cursor']);
            $queryString = http_build_query($queryParams);
            $scheme = $currentRequest->getScheme() ?? 'http';
            $host = $currentRequest->getHost();
            $path = $urlParts['path'] ?? '/';
            $url = $scheme . '://' . $host . $path;
        } catch (\Throwable $e) {
             error_log("Cursor Pagination URL generation failed: " . $e->getMessage());
        }

        $nextPageUrl = null;
        if ($nextCursor) {
             $nextQueryParams = array_merge($queryParams, ['cursor' => $nextCursor]);
             $nextPageUrl = $url . '?' . http_build_query($nextQueryParams);
        }
        $prevPageUrl = null; // Simplified

        return [
            'data' => $results,
            'next_page_url' => $nextPageUrl,
            'prev_page_url' => $prevPageUrl,
            'path' => $url,
            'next_cursor' => $hasNextPage ? $nextCursor : null,
        ];
    }


    /** Get bind values only for the WHERE clause. */
    protected function getBindValuesForWhere(): array
    {
         $bindValues = [];
         foreach ($this->where as $condition) $bindValues[] = $condition[2];
         foreach ($this->orWhere as $condition) $bindValues[] = $condition[2];
         // Ensure values are added in correct order for IN clause placeholders
         foreach ($this->whereIn as $condition) {
             if (!empty($condition[1])) {
                 $bindValues = array_merge($bindValues, $condition[1]);
             }
         }
         foreach ($this->whereBetween as $condition) { $bindValues[] = $condition[1]; $bindValues[] = $condition[2]; }
         // Raw bindings are added last
         $bindValues = array_merge($bindValues, $this->whereRawBindings);
         return $bindValues;
    }

    /** Get bind values including limit/offset (use carefully). */
    protected function getBindValues(): array
    {
        // This method primarily needed for direct binding in get(), now simplified
        return $this->getBindValuesForWhere();
    }


    /** Get PDO param type. */
    protected function getPDOParamType($value): int
    {
        if (is_int($value)) return PDO::PARAM_INT;
        if (is_bool($value)) return PDO::PARAM_BOOL;
        if (is_null($value)) return PDO::PARAM_NULL;
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
        $range = 2; // Number of links around current page
        $onEachSide = 1; // Simplified range display

        if ($totalPages <= 1) return [];

        // Previous Page Link
        $links[] = [
            'url' => $currentPage > 1 ? ($baseUrl . $separator . 'page=' . ($currentPage - 1)) : null,
            'label' => '&laquo; Previous',
            'active' => false,
            'disabled' => $currentPage <= 1
        ];


        // Determine window of links to show
        $window = $onEachSide * 2;
        if ($totalPages <= $window + 4) { // Show all if total pages is small
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


        // Add first page and ellipsis if needed
        if ($start > 1) {
            $links[] = ['url' => $baseUrl . $separator . 'page=1', 'label' => '1', 'active' => false];
            if ($start > 2) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false, 'disabled' => true];
            }
        }

        // Page Number Links
        for ($i = $start; $i <= $end; $i++) {
            $links[] = ['url' => $baseUrl . $separator . 'page=' . $i, 'label' => (string)$i, 'active' => $currentPage === $i];
        }

        // Add ellipsis and last page if needed
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false, 'disabled' => true];
            }
            $links[] = ['url' => $baseUrl . $separator . 'page=' . $totalPages, 'label' => (string)$totalPages, 'active' => false];
        }


        // Next Page Link
        $links[] = [
            'url' => $currentPage < $totalPages ? ($baseUrl . $separator . 'page=' . ($currentPage + 1)) : null,
            'label' => 'Next &raquo;',
            'active' => false,
            'disabled' => $currentPage >= $totalPages
        ];


        return $links;
    }


    /**
     * Set the model class associated with this query.
     * @param string $modelClass The fully qualified name of the model class.
     * @return self
     */
    public function setModel(string $modelClass): self
    {
        // Basic check if class exists and has the static method needed for hydration
        if (!class_exists($modelClass) || !method_exists($modelClass, 'hydrateModels')) {
            throw new InvalidArgumentException("Invalid model class provided or hydrateModels method missing: {$modelClass}");
        }
        $this->modelClass = $modelClass;
        return $this;
    }
}