<?php

namespace Oilstone\ApiCommerceLayerIntegration;

use Countable;
use IteratorAggregate;
use Traversable;

class RecordCollection implements Countable, IteratorAggregate
{
    /**
     * @param array<int, array> $items
     */
    public function __construct(protected array $items = [], protected array $meta = [])
    {
    }

    public static function make(array $payload): static
    {
        return new static($payload['data'] ?? [], $payload['meta'] ?? []);
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }
}
