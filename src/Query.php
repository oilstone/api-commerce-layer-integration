<?php

namespace Oilstone\ApiCommerceLayerIntegration;

use Api\Exceptions\InvalidQueryArgumentsException;
use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\ObjectNotSpecifiedException;

class Query
{
    protected string $resource;

    protected CommerceLayer $client;

    protected string $identifier;

    protected array $fields = [];

    protected array $includes = [];

    protected array $conditions = [];

    protected array $orders = [];

    protected ?int $limit = null;

    protected ?int $offset = null;

    protected ?QueryCacheHandler $cacheHandler = null;

    protected array $cacheOptions = [];

    public function __construct(string $resource, CommerceLayer $client, string $identifier = 'id')
    {
        $this->resource = $resource;
        $this->client = $client;
        $this->identifier = $identifier;
        $this->fields = [$identifier];
    }

    public static function make(string $resource, CommerceLayer $client, string $identifier = 'id'): static
    {
        return new static($resource, $client, $identifier);
    }

    public function getObject(): ?string
    {
        return $this->resource;
    }

    public function setObject(string $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        if ($this->fields === []) {
            $this->fields[] = $identifier;
        }

        return $this;
    }

    public function setCacheHandler(QueryCacheHandler $handler): static
    {
        $this->cacheHandler = $handler;

        return $this;
    }

    public function setCacheOptions(array $options): static
    {
        $this->cacheOptions = $options;

        return $this;
    }

    public function with(string $relationship): static
    {
        $relationship = trim($relationship);

        if ($relationship !== '') {
            $this->includes[] = $relationship;
        }

        return $this;
    }

    public function select(array|string $fields): static
    {
        $this->fields = is_array($fields) ? $fields : array_map('trim', explode(',', (string) $fields));

        return $this;
    }

    public function where(...$arguments): static
    {
        return $this->addCondition('and', ...$arguments);
    }

    public function orWhere(...$arguments): static
    {
        return $this->addCondition('or', ...$arguments);
    }

    public function whereIn(string $field, array $values): static
    {
        return $this->addCondition('and', $field, 'in', $values);
    }

    public function orWhereIn(string $field, array $values): static
    {
        return $this->addCondition('or', $field, 'in', $values);
    }

    public function whereNotIn(string $field, array $values): static
    {
        return $this->addCondition('and', $field, 'not in', $values);
    }

    public function orWhereNotIn(string $field, array $values): static
    {
        return $this->addCondition('or', $field, 'not in', $values);
    }

    protected function addCondition(string $boolean, ...$arguments): static
    {
        if (! $arguments) {
            throw new InvalidQueryArgumentsException('A condition requires at least one argument.');
        }

        if (is_callable($arguments[0])) {
            $arguments[0]($this);

            return $this;
        }

        if (is_array($arguments[0])) {
            foreach ($arguments[0] as $field => $value) {
                if (is_int($field)) {
                    $this->addCondition($boolean, $value);
                    continue;
                }

                $this->addCondition($boolean, $field, '=', $value);
            }

            return $this;
        }

        if (count($arguments) === 1) {
            throw new InvalidQueryArgumentsException('A condition defined by string arguments requires a value.');
        }

        if (count($arguments) === 2) {
            [$field, $value] = $arguments;
            $operator = '=';
        } else {
            [$field, $operator, $value] = $arguments;
        }

        $this->conditions[] = [
            'boolean' => $boolean,
            'field' => $field,
            'operator' => strtolower((string) $operator),
            'value' => $value,
        ];

        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): static
    {
        $this->orders[] = [
            'field' => $field,
            'direction' => strtolower($direction) === 'desc' ? 'desc' : 'asc',
        ];

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = max(1, $limit);

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function first(): ?array
    {
        $previousLimit = $this->limit;
        $previousOffset = $this->offset;

        $this->limit(1);

        $result = $this->get()[0] ?? null;

        $this->limit = $previousLimit;
        $this->offset = $previousOffset;

        return $result;
    }

    public function find(string $id, array $options = []): ?array
    {
        $this->ensureResourceIsSet();

        $parameters = [];

        if ($this->includes) {
            $parameters['include'] = implode(',', array_unique($this->includes));
        }

        if ($this->fields) {
            $parameters['fields['.$this->resource.']'] = implode(',', array_unique($this->fields));
        }

        $options = array_merge($this->cacheOptions, $options);

        $result = $this->client->retrieve($this->resource, $id, $parameters, $options);

        return $result['data'] ?? null;
    }

    public function get(): array
    {
        $this->ensureResourceIsSet();

        $parameters = $this->buildParameters();
        $options = $this->cacheOptions;

        $callback = fn () => $this->client->listResources($this->resource, $parameters, $options);

        if ($this->cacheHandler) {
            $cacheKey = $this->resource.'?'.http_build_query($parameters);

            return $this->cacheHandler->rememberQuery($cacheKey, $callback, $options);
        }

        return $callback();
    }

    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getConditionSignature(): array
    {
        return array_map(function (array $condition) {
            return [
                'boolean' => $condition['boolean'],
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'value' => $condition['value'],
            ];
        }, $this->conditions);
    }

    public function getOrders(): array
    {
        return $this->orders;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    protected function buildParameters(): array
    {
        $parameters = [];

        if ($this->fields) {
            $parameters['fields['.$this->resource.']'] = implode(',', array_unique($this->fields));
        }

        if ($this->includes) {
            $parameters['include'] = implode(',', array_unique($this->includes));
        }

        if ($filters = $this->compileFilters()) {
            foreach ($filters as $key => $value) {
                $parameters['filter['.$key.']'] = $value;
            }
        }

        if ($this->orders) {
            $sorts = array_map(function (array $order) {
                return ($order['direction'] === 'desc' ? '-' : '').$order['field'];
            }, $this->orders);

            $parameters['sort'] = implode(',', $sorts);
        }

        if ($this->limit !== null) {
            $parameters['page[size]'] = $this->limit;
        }

        if ($this->offset !== null) {
            $parameters['page[number]'] = (int) floor(($this->offset / max(1, $this->limit ?? 1)) + 1);
        }

        return $parameters;
    }

    protected function compileFilters(): array
    {
        if ($this->conditions === []) {
            return [];
        }

        $filters = [];

        foreach ($this->conditions as $condition) {
            $suffix = $this->resolveOperatorSuffix($condition['operator'] ?? '=');
            $key = trim($condition['field']);

            if ($key === '') {
                continue;
            }

            $filterKey = $suffix ? $key.'_'.$suffix : $key;
            $filters[$filterKey] = $this->normaliseFilterValue($condition['operator'], $condition['value']);
        }

        return $filters;
    }

    protected function resolveOperatorSuffix(string $operator): string
    {
        return match (strtolower($operator)) {
            '=', 'eq' => 'eq',
            '!=' => 'not_eq',
            '>' => 'gt',
            '>=' => 'gte',
            '<' => 'lt',
            '<=' => 'lte',
            'like', 'cont' => 'cont',
            'not like' => 'not_cont',
            'in' => 'in',
            'not in' => 'not_in',
            default => trim(str_replace(' ', '_', strtolower($operator))),
        };
    }

    protected function normaliseFilterValue(string $operator, mixed $value): string
    {
        $operator = strtolower($operator);

        if (in_array($operator, ['in', 'not in'], true)) {
            $value = is_array($value) ? $value : [$value];

            return implode(',', array_map('strval', $value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode(',', array_map('strval', $value));
        }

        return (string) $value;
    }

    protected function ensureResourceIsSet(): void
    {
        if (! $this->resource) {
            throw new ObjectNotSpecifiedException('A resource must be specified before executing a query.');
        }
    }
}
