<?php

namespace SwallowPHP\Framework\Database;

use ArrayIterator;
use Traversable; // Add Traversable import

class Relation implements \IteratorAggregate, \Countable
{
    protected Database $query;
    protected string $type; // 'one' or 'many'

    /**
     * Relation constructor.
     *
     * @param Database $query The query builder instance.
     * @param string $type The type of relation ('one' or 'many').
     */
    public function __construct(Database $query, string $type)
    {
        $this->query = $query;
        $this->type = $type;
    }

    /**
     * Get the type of the relation.
     *
     * @return string 'one' or 'many'
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Dynamically handle calls to the query builder.
     *
     * @param string $method
     * @param array $arguments
     * @return mixed|self
     */
    public function __call(string $method, array $arguments)
    {
        $result = $this->query->{$method}(...$arguments);
        if ($result instanceof Database) {
            return $this;
        }
        // If the method returns the Database instance (query builder), return $this for chaining.
        // Otherwise, return the actual result (e.g., count, exists).
        return $result;
    }

    /**
     * Execute the query and get the results.
     * For 'many' relations.
     *
     * @return array
     */
    public function get(): array
    {
        return $this->query->get();
    }

    /**
     * Execute the query and get the first result.
     * For 'one' relations.
     *
     * @return Model|object|null
     */
    public function first()
    {
        return $this->query->first();
    }

    /**
     * Get an iterator for the results.
     *
     * @return Traversable
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->get());
    }

    /**
     * Count the number of results for the relation.
     * Uses the query builder's count for efficiency.
     *
     * @return int
     */
    public function count(): int
    {
        // Use the query builder's count method directly for efficiency
        return $this->query->count();
    }
}
