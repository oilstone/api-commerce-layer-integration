<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Bridge;

use Api\Queries\Expression;
use Api\Queries\Field;
use Api\Queries\Paths\Path;
use Api\Queries\Relations as RequestRelations;
use Carbon\Carbon;
use Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Results\Collection;
use Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Results\Record;
use Oilstone\ApiCommerceLayerIntegration\Query as CommerceLayerQuery;

class Query
{
    protected const OPERATOR_MAP = [
        'IS NULL' => '=',
        'IS NOT NULL' => '!=',
    ];

    protected const VALUE_MAP = [
        'IS NULL' => null,
        'IS NOT NULL' => null,
    ];

    public function __construct(
        protected CommerceLayerQuery $baseQuery
    ) {
    }

    public function getBaseQuery(): CommerceLayerQuery
    {
        return $this->baseQuery;
    }

    public function get(): Collection
    {
        $raw = $this->baseQuery->get();

        return Collection::make(array_map(fn ($item) => Record::make($item ?? []), $raw));
    }

    public function first(): ?Record
    {
        $result = $this->baseQuery->first();

        return $result ? Record::make($result) : null;
    }

    public function include(RequestRelations $relations): self
    {
        foreach ($relations->collapse() as $relation) {
            $path = (string) $relation->getPath();

            if ($path !== '') {
                $this->baseQuery->with($path);
            }
        }

        return $this;
    }

    public function select(array $fields): self
    {
        if ($fields === []) {
            $this->baseQuery->select([]);

            return $this;
        }

        $resolved = [];

        foreach ($fields as $field) {
            if ($field instanceof Field) {
                $resolved[] = $this->resolvePropertyPath($field->getPath());
                continue;
            }

            if (is_string($field) && $field !== '') {
                $resolved[] = $field;
            }
        }

        if ($resolved === []) {
            $this->baseQuery->select([]);
        } else {
            $this->baseQuery->select($resolved);
        }

        return $this;
    }

    public function where(Expression $expression): self
    {
        return $this->applyExpression($this->baseQuery, $expression);
    }

    public function orderBy(array $orders): self
    {
        foreach ($orders as $order) {
            $property = method_exists($order, 'getProperty') && $order->getProperty()
                ? $order->getProperty()->alias ?: $order->getProperty()->getName()
                : (method_exists($order, 'getPropertyName') ? $order->getPropertyName() : null);

            if (! $property) {
                continue;
            }

            $this->baseQuery->orderBy($property, method_exists($order, 'getDirection') ? $order->getDirection() : 'asc');
        }

        return $this;
    }

    public function limit($limit): self
    {
        if ($limit) {
            $this->baseQuery->limit((int) $limit);
        }

        return $this;
    }

    public function offset($offset): self
    {
        if ($offset) {
            $this->baseQuery->offset((int) $offset);
        }

        return $this;
    }

    protected function applyExpression($query, Expression $expression): self
    {
        foreach ($expression->getItems() as $item) {
            $method = $item['operator'] === 'OR' ? 'orWhere' : 'where';
            $constraint = $item['constraint'];

            if ($constraint instanceof Expression) {
                $query->{$method}(function ($nested) use ($constraint) {
                    $this->applyExpression($nested, $constraint);
                });

                continue;
            }

            $operator = $constraint->getOperator();

            $query->{$method}(
                $this->resolvePropertyPath($constraint->getPath()),
                $this->resolveConstraintOperator($operator),
                $this->resolveConstraintValue($operator, $constraint->getValue(), $constraint->getPath())
            );
        }

        return $this;
    }

    protected function resolvePropertyPath(Path $path): string
    {
        $property = $path->getEntity();
        $prefix = trim((string) $path->prefix()->implode('.'));
        $name = $property?->alias ?: $property?->getName();

        return implode('.', array_filter([$prefix, $name], static fn ($segment) => $segment !== null && $segment !== ''));
    }

    protected function resolveConstraintOperator($operator): mixed
    {
        return self::OPERATOR_MAP[$operator] ?? $operator;
    }

    protected function resolveConstraintValue($operator, $value, ?Path $path = null): mixed
    {
        if (array_key_exists($operator, self::VALUE_MAP)) {
            return self::VALUE_MAP[$operator];
        }

        $property = $path?->getEntity();

        if (! $property) {
            return $value;
        }

        switch ($property->getType()) {
            case 'boolean':
                if (is_string($value)) {
                    $lower = strtolower($value);
                    if (in_array($lower, ['true', '1', 'yes'], true)) {
                        return true;
                    }

                    if (in_array($lower, ['false', '0', 'no'], true)) {
                        return false;
                    }
                }

                return (bool) $value;

            case 'integer':
                return $value !== null ? (int) $value : $value;

            case 'float':
            case 'decimal':
            case 'number':
                return $value !== null ? (float) $value : $value;

            case 'date':
                return $value ? Carbon::parse($value)->toDateString() : $value;

            case 'datetime':
            case 'timestamp':
                return $value ? Carbon::parse($value)->toDateTimeString() : $value;
        }

        if ($property->hasMeta('isYesNo') && $value !== null) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value) && in_array(strtoupper((string) $operator), ['IN', 'NOT IN'], true)) {
            return array_map('strval', $value);
        }

        return $value;
    }
}
