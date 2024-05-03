<?php

namespace SwallowPHP\Framework;

use mysqli;

/**
 * Class Database
 *
 * This class provides a fluent interface for building and executing SQL queries using MySQLi,
 * and includes pagination functionality.
 */
class Database
{
    /**
     * The MySQLi instance to use for database connections.
     *
     * @var \mysqli
     */
    protected mysqli $connection;

    /**
     * The name of the table.
     *
     * @var string
     */
    public $table;

    /**
     * The columns to select.
     *
     * @var string|array
     */
    protected $select = '*';

    /**
     * The where clauses for the query.
     *
     * @var array
     */
    protected $where = [];

    /**
     * The where clauses for the raw query.
     *
     * @var array
     */
    protected $whereRaw = [];
    /**
     * The order by clauses for the query.
     *
     * @var array
     */
    protected $orderBy = [];

    /**
     * The maximum number of rows to return.
     *
     * @var int|null
     */
    protected $limit = null;

    /**
     * The number of rows to skip.
     *
     * @var int|null
     */
    protected $offset = null;

    /**
     * Database constructor
     */
    public function __construct()
    {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $database = env('DB_DATABASE', 'swallowphp');
        $username = env('DB_USERNAME', 'root');
        $password = env('DB_PASSWORD', '');
        $charset = env('DB_CHARSET', 'utf8');

        $this->connection = new \mysqli($host, $username, $password, $database, $port);

        if ($this->connection->connect_errno) {
            die('Could not connect to the database: ' . $this->connection->connect_error);
        }
        $this->connection->set_charset($charset);
    }

    /**
     * Set the table for the query.
     *
     * @param string $table The name of the table.
     * @return Database
     */
    public function table($table): Database
    {
        $this->table = $table;
        return $this;
    }
    /**
     * Reset all query conditions and properties.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->table = null;
        $this->select = '*';
        $this->where = [];
        $this->whereRaw = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
    }

    /**
     * Set the columns to select.
     *
     * @param array $columns The columns to select.
     * @return Database
     */
    public function select(array $columns = ['*']): Database
    {
        $this->select = implode(', ', $columns);
        return $this;
    }

    /**
     * Add a where clause to the query.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare.
     * @return Database
     */
    public function where(string $column, string $operator, $value): Database
    {
        $this->where[] = [$column, $operator, $value];
        return $this;
    }

    /**
     * Add an order by clause to the query.
     *
     * @param string $column The column to order by.
     * @param string $direction The sort direction (ASC or DESC).
     * @return Database
     */
    public function orderBy(string $column, string $direction = 'ASC'): Database
    {
        $this->orderBy[] = [$column, $direction];
        return $this;
    }

    /**
     * Set the limit for the query.
     *
     * @param int $limit The maximum number of rows to return.
     * @return Database
     */
    public function limit(int $limit): Database
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the offset for the query.
     *
     * @param int $offset The number of rows to skip.
     * @return Database
     */
    public function offset(int $offset): Database
    {
        $this->offset = $offset;
        return $this;
    }


    /**
     * Execute the select query and return the result set.
     *
     * @return array The result set as an associative array.
     */
    public function get(): array
    {
        $sql = "SELECT $this->select FROM $this->table";
        $whereConditions = [];
        $bindValues = [];
        if ($this->limit ==  14) {
        }
        if (!empty($this->where)) {
            foreach ($this->where as $condition) {
                $whereConditions[] = "{$condition[0]} {$condition[1]} ?";
                $bindValues[] = $condition[2];
            }
        }
        if (!empty($this->whereRaw)) {
            $whereConditions[] = implode(' AND ', $this->whereRaw);
        }
        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(' AND ', $whereConditions);
        }
        if (!empty($this->orderBy)) {
            $orderByColumns = [];
            foreach ($this->orderBy as $order) {
                $orderByColumns[] = "{$order[0]} {$order[1]}";
            }
            $sql .= " ORDER BY " . implode(', ', $orderByColumns);
        }
        if ($this->limit !== null) {
            $sql .= " LIMIT $this->limit";
            if ($this->offset !== null) {
                $sql .= " OFFSET $this->offset";
            }
        }
        $statement = $this->connection->prepare($sql);
        if (!empty($this->where)) {
            $bindTypes = $this->getBindTypes($bindValues);
            array_unshift($bindValues, $bindTypes);
            call_user_func_array([$statement, 'bind_param'], $this->refValues($bindValues));
        }
        $statement->execute();

        $result = $statement->get_result();

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        $this->reset();
        return $rows;
    }


    public function whereRaw($query)
    {
        $this->whereRaw[] = $query;
        return $this;
    }

    /**
     * Execute the select query and return the result set using cursor-based pagination.
     *
     * @param int $perPage The number of rows per page.
     * @param string $direction The sort direction for cursor-based pagination (either 'ASC' or 'DESC').
     * @return array The result set as an associative array.
     */
    public function cursorPaginate(int $perPage): array
    {
        $cursorColumn = 'id';

        $currentCursor = isset($_GET['cursor']) ? $_GET['cursor'] : null;

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

        // Parse the current URL to preserve other query parameters
        $urlParts = parse_url($_SERVER['REQUEST_URI']);
        $queryParams = [];
        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $queryParams);
        }

        // Remove cursor and page parameters from the query string to prevent invalid input
        unset($queryParams['cursor']);
        unset($queryParams['page']);

        $queryString = http_build_query($queryParams);

        $url = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://' . $_SERVER['HTTP_HOST'] . $urlParts['path'];
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
            // Calculate the previous cursor value by using the first item's ID in the result set
            $firstItem = reset($result);
            $prevCursor = $firstItem[$cursorColumn] - ($perPage + 1);

            // Build the previous page URL
            $prevPageUrl = $url . ($queryString ? '?' : '?') . http_build_query(array_merge($queryParams, ['cursor' => $prevCursor]));
        }
        $this->reset();

        return [
            'nextPageUrl' => $nextPageUrl,
            'prevPageUrl' => $prevPageUrl,
            'hasNextPage' => $hasNextPage,
            'hasPrevPage' => $hasPrevPage,
            'data' => $result,
        ];
    }
    /**
     * Execute the select query and return the first result.
     *
     * @return array|null The first result as an associative array, or null if no results found.
     */
    public function first()
    {
        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? null;
    }

    /**
     * Execute an insert query and return the last inserted ID.
     *
     * @param array $data The data to insert as an associative array.
     * @return int The last inserted ID.
     */
    public function insert(array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $values = implode(', ', array_fill(0, count($data), '?'));

        $params = [];
        foreach ($data as $value) {
            if ($value === '') {
                $params[] = null;
            } else {
                $params[] = $value;
            }
        }

        $sql = "INSERT INTO $this->table ($columns) VALUES ($values)";
        $statement = $this->connection->prepare($sql);

        $bindTypes = $this->getBindTypes($params);
        array_unshift($params, $bindTypes);
        call_user_func_array([$statement, 'bind_param'], $this->refValues($params));
        $statement->execute();
        $this->reset();

        return $this->connection->insert_id;
    }

    /**
     * Execute an update query and return the number of affected rows.
     *
     * @param array $data The data to update as an associative array.
     * @return int The number of affected rows.
     */
    public function update(array $data): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            if ($value === '') { // Eğer değer boşsa
                $sets[] = "$column = NULL"; // Sütunun değerini NULL olarak ayarla
            } else {
                $sets[] = "$column = ?";
                $params[] = $value;
            }
        }

        $sql = "UPDATE $this->table SET " . implode(', ', $sets);

        if (!empty($this->where)) {
            $whereConditions = [];
            foreach ($this->where as $condition) {
                $whereConditions[] = "{$condition[0]} {$condition[1]} ?";
                $params[] = $condition[2];
            }
            $sql .= " WHERE " . implode(' AND ', $whereConditions);
        }
        $statement = $this->connection->prepare($sql);

        $bindTypes = $this->getBindTypes($params);
        array_unshift($params, $bindTypes);
        call_user_func_array([$statement, 'bind_param'], $this->refValues($params));
        $statement->execute();
        $this->reset();

        return $statement->affected_rows;
    }


    /**
     * Execute a delete query and return the number of affected rows.
     *
     * @return int The number of affected rows.
     */
    public function delete(): int
    {
        $sql = "DELETE FROM $this->table";

        if (!empty($this->where)) {
            $whereConditions = [];
            foreach ($this->where as $condition) {
                $whereConditions[] = "{$condition[0]} {$condition[1]} ?";
            }
            $sql .= " WHERE " . implode(' AND ', $whereConditions);
        }

        $statement = $this->connection->prepare($sql);

        if (!empty($this->where)) {
            $bindTypes = '';
            $bindValues = [];

            foreach ($this->where as $condition) {
                $bindTypes .= $this->getBindType($condition[2]);
                $bindValues[] = &$condition[2];
            }

            array_unshift($bindValues, $bindTypes);
            call_user_func_array([$statement, 'bind_param'], $bindValues);
        }

        $statement->execute();
        $this->reset();

        return $statement->affected_rows;
    }

    /**
     * Count the number of rows in the current query.
     *
     * @return int The number of rows.
     */
    public function count(): int
    {
        $sql = "SELECT COUNT(*) AS count FROM $this->table";
        $whereConditions = [];
        $bindValues = [];
        if (!empty($this->where)) {
            foreach ($this->where as $condition) {
                $whereConditions[] = "{$condition[0]} {$condition[1]} ?";
                $bindValues[] = $condition[2];
            }
        }
        if (!empty($this->whereRaw)) {
            $whereConditions[] = implode(' AND ', $this->whereRaw);
        }
        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(' AND ', $whereConditions);
        }

        $statement = $this->connection->prepare($sql);
        if (!empty($this->where)) {
            $bindTypes = $this->getBindTypes($bindValues);
            array_unshift($bindValues, $bindTypes);
            call_user_func_array([$statement, 'bind_param'], $this->refValues($bindValues));
        }
        $statement->execute();
        $result = $statement->get_result();
        $count = $result->fetch_assoc()['count'];
        $statement->close();
        return $count;
    }
    public function paginate(int $perPage, int $page = 1): array
    {
        // Get the full URL
        $url = request()->fullUrl();
    
        // Extract the base URL
        $baseUrl = strtok($url, '?');
    
        // Parse existing GET parameters
        $existingParams = request()->query();
    
        // Remove the 'page' parameter if it exists
        unset($existingParams['page']);
    
        // Append remaining GET parameters to the base URL
        $baseUrlWithParams = $baseUrl . (empty($existingParams) ? '' : '?' . http_build_query($existingParams));
    
        // Calculate the offset
        $offset = ($page - 1) * $perPage;
    
        // Get the total count
        $total = $this->count();
    
        // Fetch the data
        $data = $this->limit($perPage)->offset($offset)->get();
    
        // Calculate total pages
        $totalPages = (int)ceil($total / $perPage);
    
        // Calculate the range of pages to be shown
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
    
        // Construct pagination links
        $pagination = [];
    
        // Add the link to the first page
        if ($page > 1) {
            $pagination[] = $baseUrlWithParams . (empty($existingParams) ? '?' : '&') . 'page=1';
        }
    
        // Add "..." if necessary before the first link
        if ($page > 3) {
            $pagination[] = '...';
        }
    
        // Add the links to the pages around the current page
        for ($i = $start; $i <= $end; $i++) {
            $pagination[] = $baseUrlWithParams . (empty($existingParams) ? '?' : '&') . 'page=' . $i;
        }
    
        // Add "..." if necessary after the last link
        if ($page < $totalPages - 2) {
            $pagination[] = '...';
        }
    
        // Add the link to the last page
        if ($page < $totalPages) {
            $pagination[] = $baseUrlWithParams . (empty($existingParams) ? '?' : '&') . 'page=' . $totalPages;
        }
    
        // Generate prev_page_url and next_page_url
        $prevPageUrl = ($page > 1) ? $baseUrlWithParams . (empty($existingParams) ? '?' : '&') . 'page=' . ($page - 1) : null;
        $nextPageUrl = ($page < $totalPages) ? $baseUrlWithParams . (empty($existingParams) ? '?' : '&') . 'page=' . ($page + 1) : null;
    
        // Check if there are next and previous pages
        $hasNextPage = $page < $totalPages;
        $hasPrevPage = $page > 1;
        $pagination = removeDuplicates($pagination,['...']);
    
        // Get the current page URL
        $currentPageUrl = $baseUrlWithParams . (empty($existingParams) ? '?' : '&') . 'page=' . $page;
    
        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $totalPages,
            'prev_page_url' => $prevPageUrl,
            'next_page_url' => $nextPageUrl,
            'pagination_links' => $pagination,
            'has_next_page' => $hasNextPage,
            'has_previous_page' => $hasPrevPage,
            'current_page_url' => $currentPageUrl,
        ];
    }
    



    /**
     * Get the bind type for a given value.
     *
     * @param mixed $value The value to get the bind type for.
     * @return string The bind type.
     */
    protected function getBindType($value): string
    {
        if (is_int($value)) {
            return 'i';
        } elseif (is_float($value)) {
            return 'd';
        } elseif (is_string($value)) {
            return 's';
        } else {
            return 'b';
        }
    }

    /**
     * Get the bind types for an array of values.
     *
     * @param array $values The values to get the bind types for.
     * @return string The bind types.
     */
    protected function getBindTypes(array $values): string
    {
        $bindTypes = '';
        foreach ($values as $value) {
            $bindTypes .= $this->getBindType($value);
        }
        return $bindTypes;
    }

    /**
     * Get the reference values for a given array of values.
     *
     * @param array $array The array of values.
     * @return array The reference values.
     */
    protected function refValues(array $array): array
    {
        $refValues = [];
        foreach ($array as $key => $value) {
            $refValues[$key] = &$array[$key];
        }
        return $refValues;
    }
}
