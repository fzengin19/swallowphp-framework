<?php

namespace SwallowPHP\Framework;

use PDO;
use PDOException;
use InvalidArgumentException;
use Exception;

/**
 * Database class for handling database operations using PDO.
 */
class Database
{
    /**
     * PDO connection instance.
     *
     * @var PDO|null
     */
    protected ?PDO $connection = null;

    /**
     * Flag to indicate if the connection was successful.
     *
     * @var bool
     */
    protected bool $connectedSuccessfully = false;

    /**
     * The name of the table to perform operations on.
     *
     * @var string
     */
    public string $table = '';

    /**
     * The columns to select in the query.
     *
     * @var string
     */
    protected string $select = '*';

    /**
     * Array of where conditions.
     *
     * @var array
     */
    protected array $where = [];

    /**
     * Array of raw where conditions.
     *
     * @var array
     */
    protected array $whereRaw = [];

    /**
     * Array of where in conditions.
     *
     * @var array
     */
    protected array $whereIn = [];

    /**
     * Array of where between conditions.
     *
     * @var array
     */
    protected array $whereBetween = [];

    /**
     * Array of order by clauses.
     *
     * @var array
     */
    protected array $orderBy = [];

    /**
     * The limit for the query.
     *
     * @var int|null
     */
    protected ?int $limit = null;

    /**
     * The offset for the query.
     *
     * @var int|null
     */
    protected ?int $offset = null;

    /**
     * Array of or where conditions.
     *
     * @var array
     */
    protected array $orWhere = [];

    /**
     * Initialize the database connection.
     *
     * @return void
     * @throws Exception If the connection fails.
     */
    public function initialize(): void
    {
        if ($this->connection && $this->connectedSuccessfully) {
            return;
        }

        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $database = env('DB_DATABASE', 'swallowphp');
        $username = env('DB_USERNAME', 'root');
        $password = env('DB_PASSWORD', '');
        $charset = env('DB_CHARSET', 'utf8mb4');

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->connection = new PDO($dsn, $username, $password, $options);
            $this->connectedSuccessfully = true;
        } catch (PDOException $e) {
            throw new Exception('Veritabanı bağlantısı başlatılamadı: ' . $e->getMessage());
        }
    }

    /**
     * Set the table for the query.
     *
     * @param string $table The name of the table.
     * @return self
     */
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Reset all query parameters.
     *
     * @return void
     */
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
    }

    /**
     * Set the columns to select.
     *
     * @param array $columns The columns to select.
     * @return self
     */
    public function select(array $columns = ['*']): self
    {
        $this->select = implode(', ', $columns);
        return $this;
    }

    /**
     * Add a where condition to the query.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return self
     */
    public function where(string $column, string $operator, $value): self
    {
        $this->where[] = [$column, $operator, $value];
        return $this;
    }

    /**
     * Add an or where condition to the query.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return self
     */
    public function orWhere(string $column, string $operator, $value): self
    {
        $this->orWhere[] = [$column, $operator, $value];
        return $this;
    }

    /**
     * Add a where in condition to the query.
     *
     * @param string $column The column name.
     * @param array $values The values to check against.
     * @return self
     */
    public function whereIn(string $column, array $values): self
    {
        $this->whereIn[] = [$column, $values];
        return $this;
    }

    /**
     * Add a where between condition to the query.
     *
     * @param string $column The column name.
     * @param mixed $start The start value.
     * @param mixed $end The end value.
     * @return self
     */
    public function whereBetween(string $column, $start, $end): self
    {
        $this->whereBetween[] = [$column, $start, $end];
        return $this;
    }

    /**
     * Add a raw where condition to the query.
     *
     * @param string $rawCondition The raw SQL condition.
     * @return self
     */
    public function whereRaw(string $rawCondition): self
    {
        $this->whereRaw[] = $rawCondition;
        return $this;
    }

    /**
     * Add an order by clause to the query.
     *
     * @param string $column The column to order by.
     * @param string $direction The direction to order (ASC or DESC).
     * @return self
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = [$column, $direction];
        return $this;
    }

    /**
     * Set the limit for the query.
     *
     * @param int $limit The limit value.
     * @return self
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the offset for the query.
     *
     * @param int $offset The offset value.
     * @return self
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Build the where clause for the query.
     *
     * @return string The complete where clause.
     */
    protected function buildWhereClause(): string
    {
        $conditions = [];

        foreach ($this->where as $index => $condition) {
            $conditions[] = $this->buildCondition($condition[0], $condition[1], $condition[2], $index === 0 ? 'WHERE' : 'AND');
        }

        foreach ($this->orWhere as $condition) {
            $conditions[] = $this->buildCondition($condition[0], $condition[1], $condition[2], 'OR');
        }

        foreach ($this->whereIn as $condition) {
            $placeholders = implode(', ', array_fill(0, count($condition[1]), '?'));
            $conditions[] = (empty($conditions) ? 'WHERE ' : 'AND ') . "{$condition[0]} IN ($placeholders)";
        }

        foreach ($this->whereBetween as $condition) {
            $conditions[] = (empty($conditions) ? 'WHERE ' : 'AND ') . "{$condition[0]} BETWEEN ? AND ?";
        }

        if (!empty($this->whereRaw)) {
            foreach ($this->whereRaw as $index => $rawCondition) {
                $conditions[] = (empty($conditions) && $index === 0 ? 'WHERE ' : 'AND ') . $rawCondition;
            }
        }

        return implode(' ', $conditions);
    }

    /**
     * Build a single condition for the where clause.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param string $conjunction The conjunction (AND or OR).
     * @return string The built condition.
     */
    protected function buildCondition(string $column, string $operator, $value, string $conjunction): string
    {
        return "$conjunction $column $operator ?";
    }

    /**
     * Execute the select query and get the results.
     *
     * @return array The query results.
     */
    public function get(): array
    {
        $this->initialize();
        $sql = "SELECT {$this->select} FROM {$this->table} ";
        $sql .= $this->buildWhereClause();

        if (!empty($this->orderBy)) {
            $orderByColumns = array_map(fn($order) => "{$order[0]} {$order[1]}", $this->orderBy);
            $sql .= ' ORDER BY ' . implode(', ', $orderByColumns);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT ?";
            if ($this->offset !== null) {
                $sql .= " OFFSET ?";
            }
        }

        $statement = $this->connection->prepare($sql);

        $bindValues = $this->getBindValues();
        foreach ($bindValues as $key => $value) {
            $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
        }

        $statement->execute();

        $rows = $statement->fetchAll();
        $this->reset();
        return $rows;
    }

    /**
     * Get the first result from the query.
     *
     * @return mixed|null The first result or null if no results.
     */
    public function first()
    {
        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? null;
    }

    /**
     * Insert a new record into the database.
     *
     * @param array $data The data to insert.
     * @return int The ID of the inserted record.
     */
    public function insert(array $data): int
    {
        $this->initialize();
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";
        $statement = $this->connection->prepare($sql);

        $values = array_values($data);
        foreach ($values as $key => $value) {
            $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
        }

        $statement->execute();

        $insertId = $this->connection->lastInsertId();
        $this->reset();

        return $insertId;
    }

    /**
     * Update records in the database.
     *
     * @param array $data The data to update.
     * @return int The number of affected rows.
     */
    public function update(array $data): int
    {
        $this->initialize();
        $sets = array_map(fn($column) => "$column = ?", array_keys($data));
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
        $sql .= ' ' . $this->buildWhereClause();

        $statement = $this->connection->prepare($sql);

        $bindValues = array_merge(array_values($data), $this->getBindValues());
        foreach ($bindValues as $key => $value) {
            $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
        }

        $statement->execute();

        $affectedRows = $statement->rowCount();
        $this->reset();

        return $affectedRows;
    }

    /**
     * Delete records from the database.
     *
     * @return int The number of affected rows.
     */
    public function delete(): int
    {
        $this->initialize();
        $sql = "DELETE FROM {$this->table} " . $this->buildWhereClause();

        $statement = $this->connection->prepare($sql);

        $bindValues = $this->getBindValues();
        foreach ($bindValues as $key => $value) {
            $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
        }

        $statement->execute();
        $affectedRows = $statement->rowCount();
        $this->reset();

        return $affectedRows;
    }

    /**
     * Count the number of records matching the query conditions.
     *
     * @return int The count of matching records.
     */
    public function count(): int
    {
        $this->initialize();
        $sql = "SELECT COUNT(*) AS count FROM {$this->table} " . $this->buildWhereClause();

        $statement = $this->connection->prepare($sql);

        $bindValues = $this->getBindValues();
        foreach ($bindValues as $key => $value) {
            $statement->bindValue($key + 1, $value, $this->getPDOParamType($value));
        }

        $statement->execute();
        $result = $statement->fetch(PDO::FETCH_ASSOC);
        $count = $result['count'];

        return $count;
    }

    /**
     * Paginate the query results.
     *
     * @param int $perPage The number of items per page.
     * @param int $page The current page number.
     * @return array The paginated results.
     */
    public function paginate(int $perPage, int $page = 1): array
    {
        $this->initialize();
        $total = $this->count();
        $totalPages = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;

        $this->limit($perPage)->offset($offset);
        $data = $this->get();

        $baseUrl = request()->fullUrl();
        $baseUrl = strtok($baseUrl, '?');
        $existingParams = request()->query();
        unset($existingParams['page']);
        $baseUrlWithParams = $baseUrl . (empty($existingParams) ? '' : '?' . http_build_query($existingParams));

        $pagination = $this->generatePaginationLinks($page, $totalPages, $baseUrlWithParams);

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $totalPages,
            'prev_page_url' => $page > 1 ? $baseUrlWithParams . '&page=' . ($page - 1) : null,
            'next_page_url' => $page < $totalPages ? $baseUrlWithParams . '&page=' . ($page + 1) : null,
            'pagination_links' => $pagination,
        ];
    }

    /**
     * Paginate the query results using a cursor.
     *
     * @param int $perPage The number of items per page.
     * @return array The cursor paginated results.
     */
    public function cursorPaginate(int $perPage): array
    {
        $this->initialize();
        $cursorColumn = 'id';

        $currentCursor = $_GET['cursor'] ?? null;

        if ($currentCursor) {
            $this->where($cursorColumn, '>', $currentCursor);
        }

        $this->limit($perPage + 1);

        $result = $this->get();

        $hasNextPage = count($result) > $perPage;
        if ($hasNextPage) {
            array_pop($result);
        }

        $nextCursor = null;
        if ($hasNextPage) {
            $lastItem = end($result);
            $nextCursor = $lastItem[$cursorColumn];
        }

        $urlParts = parse_url($_SERVER['REQUEST_URI']);
        $queryParams = [];
        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $queryParams);
        }

        unset($queryParams['cursor']);

        $queryString = http_build_query($queryParams);

        $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $urlParts['path'];
        $nextPageUrl = $url . ($queryString ? '?' : '') . $queryString;

        if ($nextCursor) {
            $nextPageUrl .= ($queryString ? '&' : '?') . "cursor=$nextCursor";
        } else {
            $nextPageUrl = null;
        }

        $prevPageUrl = null;
        $hasPrevPage = false;
        if ($currentCursor && count($result) > 0) {
            $hasPrevPage = true;
            $firstItem = reset($result);
            $prevCursor = $firstItem[$cursorColumn] - ($perPage + 1);

            $prevPageUrl = $url . ($queryString ? '?' : '?') . http_build_query(array_merge($queryParams, ['cursor' => $prevCursor]));
        }

        return [
            'data' => $result,
            'next_page_url' => $nextPageUrl,
            'prev_page_url' => $prevPageUrl,
            'has_next_page' => $hasNextPage,
            'has_prev_page' => $hasPrevPage,
        ];
    }

    /**
     * Get the bind values for the query.
     *
     * @return array The bind values.
     */
    protected function getBindValues(): array
    {
        $bindValues = [];
        foreach ($this->where as $condition) {
            $bindValues[] = $condition[2];
        }
        foreach ($this->orWhere as $condition) {
            $bindValues[] = $condition[2];
        }
        foreach ($this->whereIn as $condition) {
            $bindValues = array_merge($bindValues, $condition[1]);
        }
        foreach ($this->whereBetween as $condition) {
            $bindValues[] = $condition[1];
            $bindValues[] = $condition[2];
        }
        if ($this->limit !== null) {
            $bindValues[] = $this->limit;
            if ($this->offset !== null) {
                $bindValues[] = $this->offset;
            }
        }
        return $bindValues;
    }

    /**
     * Get the PDO parameter type for a given value.
     *
     * @param mixed $value The value to check.
     * @return int The PDO parameter type.
     */
    protected function getPDOParamType($value): int
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        } elseif (is_bool($value)) {
            return PDO::PARAM_BOOL;
        } elseif (is_null($value)) {
            return PDO::PARAM_NULL;
        } else {
            return PDO::PARAM_STR;
        }
    }

    /**
     * Close the database connection.
     *
     * @return void
     */
    public function close(): void
    {
        $this->connection = null;
        $this->connectedSuccessfully = false;
    }

    protected function generatePaginationLinks(int $currentPage, int $totalPages, string $baseUrl): array
    {
        $links = [];
        $range = 2;

        $separator = parse_url($baseUrl, PHP_URL_QUERY) ? '&' : '?';

        // İlk sayfa her zaman gösterilir
        $links[] = ['url' => $baseUrl . $separator . 'page=1', 'label' => '1', 'active' => $currentPage === 1];

        // Eğer gerekirse, ilk sayfadan sonra "..." ekleyin
        if ($currentPage - $range > 2) {
            $links[] = ['url' => null, 'label' => '...', 'active' => false];
        }

        // Orta sayfaları ekleyin
        for ($i = max(2, $currentPage - $range); $i <= min($totalPages - 1, $currentPage + $range); $i++) {
            $links[] = ['url' => $baseUrl . $separator . 'page=' . $i, 'label' => (string)$i, 'active' => $currentPage === $i];
        }

        // Eğer gerekirse, son sayfadan önce "..." ekleyin
        if ($currentPage + $range < $totalPages - 1) {
            $links[] = ['url' => null, 'label' => '...', 'active' => false];
        }

        // Son sayfa her zaman gösterilir (eğer ilk sayfadan farklıysa)
        if ($totalPages > 1) {
            $links[] = ['url' => $baseUrl . $separator . 'page=' . $totalPages, 'label' => (string)$totalPages, 'active' => $currentPage === $totalPages];
        }

        return $links;
    }
}
