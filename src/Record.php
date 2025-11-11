<?php

namespace Oilstone\ApiCommerceLayerIntegration;

class Record
{
    public function __construct(
        protected array $attributes,
        protected array $relationships = [],
        protected array $meta = [],
    ) {}

    public static function make(array $payload): static
    {
        return new static(
            $payload['attributes'] ?? [],
            $payload['relationships'] ?? [],
            $payload['meta'] ?? []
        );
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function getRelationships(): array
    {
        return $this->relationships;
    }

    public function getRelationship(string $key): mixed
    {
        return $this->relationships[$key] ?? null;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }
}
