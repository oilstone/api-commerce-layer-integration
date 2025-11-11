<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Results;

use Api\Result\Contracts\Collection as Contract;
use Api\Result\Contracts\Record as ResultRecordInterface;

class Collection implements Contract
{
    /**
     * @var array<int, ResultRecordInterface>
     */
    protected array $items = [];

    protected iterable $meta = [];

    public static function make(array $items, iterable $meta = []): static
    {
        $instance = new static();

        return $instance->setMetaData($meta)->fill($items);
    }

    /**
     * @param array<int, array|ResultRecordInterface> $items
     */
    public function fill(array $items): static
    {
        $this->items = array_map(function ($item) {
            if ($item instanceof ResultRecordInterface) {
                return $item;
            }

            return Record::make(is_array($item) ? $item : []);
        }, $items);

        return $this;
    }

    public function getItems(): iterable
    {
        return $this->items;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function getMetaData(): iterable
    {
        return $this->meta;
    }

    public function setMetaData(iterable $meta): static
    {
        $this->meta = is_array($meta) ? $meta : iterator_to_array($meta);

        return $this;
    }
}
