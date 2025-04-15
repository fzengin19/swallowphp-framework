<?php

namespace SwallowPHP\Framework\Database;

use ArrayIterator;

class Relation implements \IteratorAggregate, \Countable
{
    protected Database $query;

    public function __construct(Database $query)
    {
        $this->query = $query;
    }

    public function __call($method, $arguments)
    {
        $result = $this->query->{$method}(...$arguments);
        if ($result instanceof Database) {
            return $this;
        }
        return $result;
    }

    public function get(): array
    {
        return $this->query->get();
    }

    public function getIterator(): \Traversable
    {
        return new ArrayIterator($this->get());
    }

    public function count(): int
    {
        return count($this->get());
    }
}
