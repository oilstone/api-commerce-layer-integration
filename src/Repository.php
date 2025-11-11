<?php

namespace Oilstone\ApiCommerceLayerIntegration;

use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\ObjectNotSpecifiedException;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\RecordNotFoundException;

class Repository
{
    public function __construct(
        protected string $resource,
        protected array $defaultConstraints = [],
        protected array $defaultIncludes = [],
        protected array $defaultValues = [],
        protected string $defaultIdentifier = 'id',
        protected ?QueryCacheHandler $cacheHandler = null,
        protected ?CommerceLayer $client = null,
    ) {}

    public function setDefaultConstraints(array $constraints): static
    {
        $this->defaultConstraints = $constraints;

        return $this;
    }

    public function setDefaultIncludes(array $includes): static
    {
        $this->defaultIncludes = $includes;

        return $this;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->defaultIdentifier = $identifier;

        return $this;
    }

    public function setDefaultValues(array $values): static
    {
        $this->defaultValues = $values;

        return $this;
    }

    public function getDefaultValues(): array
    {
        return $this->defaultValues;
    }

    public function getIdentifier(): string
    {
        return $this->defaultIdentifier;
    }

    public function setCacheHandler(QueryCacheHandler $handler): static
    {
        $this->cacheHandler = $handler;

        return $this;
    }

    public function setClient(CommerceLayer $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function newQuery(?string $resource = null): Query
    {
        $query = new Query($resource ?? $this->resource, $this->getClient(), $this->defaultIdentifier);

        if ($this->cacheHandler) {
            $query->setCacheHandler($this->cacheHandler);
        }

        foreach ($this->defaultConstraints as $constraint) {
            if (is_array($constraint)) {
                $query->where(...$constraint);
                continue;
            }

            if (is_callable($constraint)) {
                $constraint($query);
                continue;
            }

            $query->where($constraint);
        }

        foreach ($this->defaultIncludes as $include) {
            $query->with($include);
        }

        return $query;
    }

    protected function applyOptions(Query $query, array $options): Query
    {
        foreach ($options['conditions'] ?? [] as $condition) {
            if (is_callable($condition)) {
                $condition($query);
                continue;
            }

            if (is_array($condition)) {
                $query->where(...$condition);
            }
        }

        if (isset($options['select'])) {
            $query->select($options['select']);
        }

        foreach ($options['includes'] ?? ($options['with'] ?? []) as $include) {
            $query->with($include);
        }

        foreach ($options['order'] ?? ($options['sort'] ?? []) as $order) {
            if (is_array($order)) {
                $query->orderBy($order[0], $order[1] ?? 'asc');
            } else {
                $query->orderBy($order);
            }
        }

        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        if (isset($options['offset'])) {
            $query->offset($options['offset']);
        }

        if (isset($options['cache'])) {
            $query->setCacheOptions($options['cache']);
        }

        return $query;
    }

    protected function isOptionsArray(array $value): bool
    {
        $optionKeys = [
            'conditions', 'select', 'includes', 'with', 'order', 'sort', 'limit', 'offset', 'cache',
        ];

        return (bool) array_intersect($optionKeys, array_keys($value));
    }

    public function find(string|array $idConditionsOrOptions, array $options = []): ?array
    {
        $query = $this->newQuery();

        $id = null;
        $conditions = [];

        if (is_array($idConditionsOrOptions)) {
            if ($options === [] && $this->isOptionsArray($idConditionsOrOptions)) {
                $options = $idConditionsOrOptions;
            } else {
                $conditions = $idConditionsOrOptions;
            }
        } else {
            $id = $idConditionsOrOptions;
        }

        foreach ($conditions as $condition) {
            if (is_array($condition)) {
                $query->where(...$condition);
                continue;
            }

            if (is_callable($condition)) {
                $condition($query);
            }
        }

        $query = $this->applyOptions($query, $options);

        if ($id !== null) {
            return $query->find($id, $options['cache'] ?? []);
        }

        return $query->first();
    }

    public function findOrFail(string|array $idConditionsOrOptions, array $options = []): array
    {
        $result = $this->find($idConditionsOrOptions, $options);

        if (! $result) {
            throw new RecordNotFoundException('The requested record could not be found.');
        }

        return $result;
    }

    public function get(array $conditionsOrOptions = [], array $options = []): array
    {
        $query = $this->newQuery();

        if ($conditionsOrOptions) {
            if ($options === [] && $this->isOptionsArray($conditionsOrOptions)) {
                $options = $conditionsOrOptions;
            } else {
                $query->where($conditionsOrOptions);
            }
        }

        $query = $this->applyOptions($query, $options);

        return $query->get();
    }

    public function getById(string $id, array $options = []): ?array
    {
        return $this->find($id, $options);
    }

    public function create(array $attributes, array $options = []): array
    {
        $payload = array_replace_recursive($this->getDefaultValues(), $attributes);

        $response = $this->getClient()->create($this->resource, $payload, $options['request'] ?? []);

        return $response['data'] ?? $response;
    }

    public function update(string $id, array $attributes, array $options = []): array
    {
        $payload = array_replace_recursive($this->getDefaultValues(), $attributes);

        $response = $this->getClient()->update($this->resource, $id, $payload, $options['request'] ?? []);

        return $response['data'] ?? $response;
    }

    public function delete(string $id, array $options = []): void
    {
        $this->getClient()->delete($this->resource, $id, $options['request'] ?? []);
    }

    protected function getClient(): CommerceLayer
    {
        if (! $this->client) {
            throw new ObjectNotSpecifiedException('A Commerce Layer client instance has not been provided.');
        }

        if ($this->cacheHandler) {
            $this->client->setCacheHandler($this->cacheHandler);
        }

        return $this->client;
    }
}
