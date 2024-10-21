<?php

namespace SwallowPHP\Framework;

use PDO;
use PDOException;
use InvalidArgumentException;
use Exception;

class Database
{
    protected ?PDO $connection = null;
    protected bool $connectedSuccessfully = false;
    public string $table = '';
    protected string $select = '*';
    protected array $where = [];
    protected array $whereRaw = [];
    protected array $whereIn = [];
    protected array $whereBetween = [];
    protected array $orderBy = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $orWhere = [];

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

    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

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

    public function select(array $columns = ['*']): self
    {
        $this->select = implode(', ', $columns);
        return $this;
    }

    public function where(string $column, string $operator, $value): self
    {
        $this->where[] = [$column, $operator, $value];
        return $this;
    }

    public function orWhere(string $column, string $operator, $value): self
    {
        $this->orWhere[] = [$column, $operator, $value];
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $this->whereIn[] = [$column, $values];
        return $this;
    }

    public function whereBetween(string $column, $start, $end): self
    {
        $this->whereBetween[] = [$column, $start, $end];
        return $this;
    }

    public function whereRaw(string $rawCondition): self
    {
        $this->whereRaw[] = $rawCondition;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = [$column, $direction];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    protected function buildWhereClause(): string
    {
        $conditions = [];

        foreach ($this->where as $condition) {
            $conditions[] = $this->buildCondition($condition[0], $condition[1], $condition[2], 'AND');
        }

        foreach ($this->orWhere as $condition) {
            $conditions[] = $this->buildCondition($condition[0], $condition[1], $condition[2], 'OR');
        }

        foreach ($this->whereIn as $condition) {
            $placeholders = implode(', ', array_fill(0, count($condition[1]), '?'));
            $conditions[] = "{$condition[0]} IN ($placeholders)";
        }

        foreach ($this->whereBetween as $condition) {
            $conditions[] = "{$condition[0]} BETWEEN ? AND ?";
        }

        if (!empty($this->whereRaw)) {
            $conditions = array_merge($conditions, $this->whereRaw);
        }

        return !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    }

    protected function buildCondition(string $column, string $operator, $value, string $conjunction): string
    {
        return "$conjunction $column $operator ?";
    }

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

    public function first()
    {
        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? null;
    }

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

    protected function generatePaginationLinks(int $currentPage, int $totalPages, string $baseUrl): array
    {
        $links = [];
        $range = 2;

        for ($i = max(1, $currentPage - $range); $i <= min($totalPages, $currentPage + $range); $i++) {
            $links[] = $baseUrl . '&page=' . $i;
        }

        if ($currentPage - $range > 1) {
            array_unshift($links, '...');
            array_unshift($links, $baseUrl . '&page=1');
        }

        if ($currentPage + $range < $totalPages) {
            $links[] = '...';
            $links[] = $baseUrl . '&page=' . $totalPages;
        }

        return $links;
    }

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

    public function close(): void
    {
        $this->connection = null;
        $this->connectedSuccessfully = false;
    }
}
