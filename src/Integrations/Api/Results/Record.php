<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Results;

use Api\Result\Contracts\Record as Contract;

class Record implements Contract
{
    protected array $attributes = [];

    protected array $relations = [];

    protected array $meta = [];

    protected array $raw = [];

    public static function make(array $payload, ?array $transformed = null): static
    {
        $instance = new static();

        return $instance->fill($payload, $transformed);
    }

    public function fill(array $payload, ?array $transformed = null): static
    {
        $this->raw = $payload;
        $this->attributes = $transformed ?? $this->extractAttributes($payload);
        $this->relations = $this->extractRelations($payload);
        $this->meta = $this->extractMeta($payload);

        return $this;
    }

    public function setTransformedAttributes(array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    public function setMetaData(iterable $meta): static
    {
        $this->meta = is_array($meta) ? $meta : iterator_to_array($meta);

        return $this;
    }

    public function getRelations(): iterable
    {
        return $this->relations;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function getMetaData(): iterable
    {
        return $this->meta;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    protected function extractAttributes(array $payload): array
    {
        $attributes = $payload['attributes'] ?? [];

        if (isset($payload['id'])) {
            $attributes['id'] = $payload['id'];
        }

        if (isset($payload['type'])) {
            $attributes['type'] = $payload['type'];
        }

        return $attributes;
    }

    protected function extractRelations(array $payload): array
    {
        $relationships = $payload['relationships'] ?? [];

        $normalised = [];

        foreach ($relationships as $name => $relation) {
            if (! is_array($relation)) {
                $normalised[$name] = $relation;
                continue;
            }

            if (array_key_exists('data', $relation)) {
                $data = $relation['data'];

                if (is_array($data) && array_is_list($data)) {
                    $normalised[$name] = array_map(function ($item) {
                        return is_array($item) ? $item : [];
                    }, $data);
                } else {
                    $normalised[$name] = is_array($data) ? $data : [];
                }

                continue;
            }

            $normalised[$name] = $relation;
        }

        return $normalised;
    }

    protected function extractMeta(array $payload): array
    {
        $meta = $payload['meta'] ?? [];

        if (! is_array($meta)) {
            return [];
        }

        return $meta;
    }
}
