<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Bridge;

use Api\Pipeline\Pipes\Pipe;
use Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Results\Collection;
use Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Results\Record;
use Oilstone\ApiCommerceLayerIntegration\Query as QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;

class QueryResolver
{
    public function __construct(
        protected QueryBuilder $queryBuilder,
        protected Pipe $pipe,
        protected array $defaultFields = [],
    ) {
    }

    public function byKey(): ?Record
    {
        $query = $this->keyedQuery();

        return (new Query($query))
            ->select($this->defaultFields)
            ->first();
    }

    public function record(ServerRequestInterface $request): ?Record
    {
        return $this->resolve($this->keyedQuery($request->getQueryParams()['key'] ?? null), $request)->first();
    }

    public function collection(ServerRequestInterface $request): Collection
    {
        return $this->resolve($this->baseQuery(), $request)->get();
    }

    public function resolve(QueryBuilder $queryBuilder, ServerRequestInterface $request): Query
    {
        $parsedQuery = $request->getAttribute('parsedQuery');

        $resolver = new Query($queryBuilder);

        if ($parsedQuery) {
            $resolver->include($parsedQuery->getRelations());
        }

        $fields = $parsedQuery ? $parsedQuery->getFields() : null;

        if (! $fields) {
            $fields = $this->defaultFields;
        }

        $resolver->select($fields ?? []);

        if ($parsedQuery) {
            $resolver
                ->where($parsedQuery->getFilters())
                ->orderBy($parsedQuery->getSort())
                ->limit($parsedQuery->getLimit())
                ->offset($parsedQuery->getOffset());
        }

        return $resolver;
    }

    public function keyedQuery(?string $primaryKey = null): QueryBuilder
    {
        if ($primaryKey) {
            $primaryKey = $this->pipe->getResource()->getSchema()->getProperty($primaryKey);
        } else {
            $primaryKey = $this->pipe->getResource()->getSchema()->getPrimary();
        }

        if (! $primaryKey) {
            return $this->baseQuery();
        }

        $field = $primaryKey->alias ?: $primaryKey->getName();

        return $this->baseQuery()->where($field, $this->pipe->getKey());
    }

    public function baseQuery(): QueryBuilder
    {
        if ($this->pipe->isScoped()) {
            $scope = $this->pipe->getScope();

            return $this->queryBuilder->where($scope->getKey(), $scope->getValue());
        }

        return $this->queryBuilder;
    }
}
