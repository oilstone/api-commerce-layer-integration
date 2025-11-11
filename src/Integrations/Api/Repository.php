<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Api;

use Api\Pipeline\Pipes\Pipe;
use Api\Repositories\Contracts\Resource as RepositoryInterface;
use Api\Result\Contracts\Collection as ResultCollectionInterface;
use Api\Result\Contracts\Record as ResultRecordInterface;
use Api\Schema\Schema;
use Api\Transformers\Contracts\Transformer as TransformerContract;
use ArgumentCountError;
use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\ObjectNotSpecifiedException;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\RecordNotFoundException;
use Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Bridge\QueryResolver;
use Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Results\Collection as ApiResultCollection;
use Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Results\Record as ApiResultRecord;
use Oilstone\ApiCommerceLayerIntegration\Integrations\Api\Transformers\Transformer as DefaultTransformer;
use Oilstone\ApiCommerceLayerIntegration\Query;
use Oilstone\ApiCommerceLayerIntegration\Repository as BaseRepository;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TypeError;

class Repository implements RepositoryInterface
{
    protected ?Schema $schema = null;

    protected ?TransformerContract $transformer = null;

    protected array $defaultConstraints = [];

    protected array $defaultIncludes = [];

    protected array $defaultFields = [];

    protected array $defaultValues = [];

    protected ?QueryCacheHandler $cacheHandler = null;

    protected string $identifier = 'id';

    protected ?CommerceLayer $client = null;

    public function __construct(
        protected ?string $resource = null,
        ?CommerceLayer $client = null,
    ) {
        $this->client = $client;
    }

    public function getSchema(): ?Schema
    {
        return $this->schema;
    }

    public function setSchema(Schema $schema): static
    {
        $this->schema = $schema;
        $this->defaultFields = $this->extractSchemaFields($schema);
        $this->defaultValues = $this->extractSchemaDefaults($schema);

        if ($schema->getPrimary()) {
            $this->identifier = $schema->getPrimary()->alias ?: $schema->getPrimary()->getName();
        }

        return $this;
    }

    public function getTransformer(): ?TransformerContract
    {
        return $this->transformer;
    }

    public function setTransformer(TransformerContract $transformer): static
    {
        $this->transformer = $transformer;

        return $this;
    }

    public function ensureTransformer(): TransformerContract
    {
        if (! $this->transformer && $this->schema) {
            $this->transformer = new DefaultTransformer($this->schema);
        }

        return $this->transformer;
    }

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

    public function setDefaultFields(array $fields): static
    {
        $this->defaultFields = array_values(array_filter($fields));

        return $this;
    }

    public function getDefaultFields(): array
    {
        $fields = $this->defaultFields;

        if ($fields === []) {
            return [];
        }

        if (! in_array($this->identifier, $fields, true)) {
            $fields[] = $this->identifier;
        }

        return array_values(array_unique($fields));
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

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setCacheHandler(QueryCacheHandler $handler): static
    {
        $this->cacheHandler = $handler;

        return $this;
    }

    public function getCacheHandler(): ?QueryCacheHandler
    {
        return $this->cacheHandler;
    }

    public function setClient(CommerceLayer $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getClient(): ?CommerceLayer
    {
        if ($this->client) {
            return $this->client;
        }

        $resolved = $this->resolveClientFromContainer();

        if ($resolved) {
            $this->client = $resolved;
        }

        return $this->client;
    }

    public function setResource(?string $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    public function getResource(): ?string
    {
        return $this->resource;
    }

    public function getRecords(array $conditions = [], array $options = [], ?string $resource = null): ResultCollectionInterface
    {
        $repository = $this->repository($resource);

        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $conditions = $this->reverseConditions($conditions);
        $options = $this->prepareRecordOptions($options);

        $records = $repository->get($conditions, $options);

        $transformed = array_map(fn (array $record) => $this->transformRecord($record), $records);

        return ApiResultCollection::make($transformed);
    }

    public function findRecord(string $id, array $options = [], ?string $resource = null): ?ResultRecordInterface
    {
        $repository = $this->repository($resource);

        $options['select'] = $options['select'] ?? $this->getDefaultFields();
        $options = $this->prepareRecordOptions($options);

        $record = $repository->find($id, $options);

        if (! $record) {
            return null;
        }

        return $this->transformRecord($record);
    }

    public function findRecordOrFail(string $id, array $options = [], ?string $resource = null): ResultRecordInterface
    {
        $record = $this->findRecord($id, $options, $resource);

        if (! $record) {
            throw new RecordNotFoundException('The requested record could not be found.');
        }

        return $record;
    }

    public function firstRecord(array $conditions = [], array $options = [], ?string $resource = null): ?ResultRecordInterface
    {
        $repository = $this->repository($resource);

        $options['select'] = $options['select'] ?? $this->getDefaultFields();
        $options['limit'] = $options['limit'] ?? 1;

        $conditions = $this->reverseConditions($conditions);
        $options = $this->prepareRecordOptions($options);

        $records = $repository->get($conditions, $options);
        $record = $records[0] ?? null;

        if (! $record) {
            return null;
        }

        return $this->transformRecord($record);
    }

    public function firstRecordOrFail(array $conditions = [], array $options = [], ?string $resource = null): ResultRecordInterface
    {
        $record = $this->firstRecord($conditions, $options, $resource);

        if (! $record) {
            throw new RecordNotFoundException('The requested record could not be found.');
        }

        return $record;
    }

    public function countRecords(array $conditions = [], array $options = [], ?string $resource = null): int
    {
        $repository = $this->repository($resource);

        $conditions = $this->reverseConditions($conditions);
        $options = $this->prepareRecordOptions($options);

        $options['select'] = $options['select'] ?? [$this->identifier];

        $records = $repository->get($conditions, $options);

        return count($records);
    }

    public function getByKey(Pipe $pipe): ?ResultRecordInterface
    {
        return (new QueryResolver($this->newQuery(), $pipe, $this->getDefaultFields()))->byKey();
    }

    public function getCollection(Pipe $pipe, ServerRequestInterface $request): ResultCollectionInterface
    {
        $query = $this->newQuery($this->queryResourceFromRequest($request));

        return (new QueryResolver($query, $pipe, $this->getDefaultFields()))->collection($request);
    }

    public function getRecord(Pipe $pipe, ServerRequestInterface $request): ?ResultRecordInterface
    {
        $query = $this->newQuery($this->queryResourceFromRequest($request));

        return (new QueryResolver($query, $pipe, $this->getDefaultFields()))->record($request);
    }

    public function create(Pipe $pipe, ServerRequestInterface $request): ResultRecordInterface
    {
        $resource = $this->queryResourceFromRequest($request);
        $repository = $this->repository($resource);

        $attributes = $this->reverseAttributes($this->parseRequestBody($request), true);
        $result = $repository->create($attributes);

        $id = $result['id'] ?? ($result[$this->identifier] ?? null);

        if ($id) {
            $record = $repository->findOrFail($id, ['select' => $this->getDefaultFields()]);
        } else {
            $record = $result;
        }

        return $this->transformRecord($record);
    }

    public function update(Pipe $pipe, ServerRequestInterface $request): ResultRecordInterface
    {
        $resource = $this->queryResourceFromRequest($request);
        $repository = $this->repository($resource);

        $attributes = $this->reverseAttributes($this->parseRequestBody($request), true);

        $repository->update($pipe->getKey(), $attributes);

        $record = $repository->findOrFail($pipe->getKey(), ['select' => $this->getDefaultFields()]);

        return $this->transformRecord($record);
    }

    public function delete(Pipe $pipe): ResultRecordInterface
    {
        $repository = $this->repository();

        $record = $repository->findOrFail($pipe->getKey(), ['select' => $this->getDefaultFields()]);

        $repository->delete($pipe->getKey());

        return $this->transformRecord($record);
    }

    public function repository(?string $resource = null): BaseRepository
    {
        $resource ??= $this->resource;

        if (! $resource) {
            throw new ObjectNotSpecifiedException('A resource must be specified before executing a query.');
        }

        return new BaseRepository(
            $resource,
            $this->defaultConstraints,
            $this->defaultIncludes,
            $this->defaultValues,
            $this->identifier,
            $this->cacheHandler,
            $this->getClient(),
        );
    }

    protected function newQuery(?string $resource = null): Query
    {
        $repository = $this->repository($resource);

        return $repository->newQuery($resource ?? $this->resource);
    }

    protected function transformRecord(array $record): ResultRecordInterface
    {
        $resultRecord = ApiResultRecord::make($record);

        if ($this->transformer) {
            $transformed = $this->transformer->transform(ApiResultRecord::make($record));
            $resultRecord->setTransformedAttributes($transformed);

            if (method_exists($this->transformer, 'transformMetaData')) {
                $meta = $this->transformer->transformMetaData(ApiResultRecord::make($record));
                $resultRecord->setMetaData($meta);
            }
        }

        return $resultRecord;
    }

    protected function reverseConditions(array $conditions): array
    {
        if (! $conditions) {
            return [];
        }

        $reversed = $this->reverseAttributes($conditions, true, true);

        if ($this->schema) {
            $reversed = $this->stripDefaultValues($reversed, $this->getDefaultValues(), $conditions);
        }

        return $reversed;
    }

    protected function prepareRecordOptions(array $options): array
    {
        if (! isset($options['conditions'])) {
            return $options;
        }

        $options['conditions'] = array_map(function ($condition) {
            if (! is_array($condition) || $condition === []) {
                return $condition;
            }

            if (! isset($condition[0]) || ! is_string($condition[0])) {
                return $condition;
            }

            $condition = array_values($condition);

            $valueIndex = array_key_exists(2, $condition) ? 2 : 1;
            [$field, $value] = $this->reverseConditionComponents($condition[0], $condition[$valueIndex] ?? null);

            $condition[0] = $field;
            $condition[$valueIndex] = $value;

            return $condition;
        }, $options['conditions']);

        return $options;
    }

    protected function reverseConditionComponents(string $field, mixed $value): array
    {
        $reversed = $this->reverseConditions([$field => $value]);

        if ($reversed === []) {
            return [$field, $value];
        }

        $reversedField = array_key_first($reversed);

        if ($reversedField === null) {
            return [$field, $value];
        }

        return [$reversedField, $reversed[$reversedField] ?? $value];
    }

    protected function stripDefaultValues(array $reversed, array $defaults, array $provided): array
    {
        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $reversed)) {
                continue;
            }

            if (is_array($value)) {
                $childProvided = is_array($provided[$key] ?? null) ? $provided[$key] : [];
                $childReversed = is_array($reversed[$key]) ? $reversed[$key] : [];

                $reversed[$key] = $this->stripDefaultValues($childReversed, $value, $childProvided);

                if ($reversed[$key] === []) {
                    unset($reversed[$key]);
                }

                continue;
            }

            if (! array_key_exists($key, $provided)) {
                unset($reversed[$key]);
            }
        }

        return $reversed;
    }

    protected function reverseAttributes(array $attributes, bool $allowNull = false, bool $force = false): array
    {
        if (! $this->transformer) {
            return $allowNull ? $attributes : array_filter($attributes, static fn ($value) => $value !== null);
        }

        $transformed = $force && method_exists($this->transformer, 'forceReverse')
            ? $this->transformer->forceReverse($attributes)
            : $this->transformer->reverse($attributes);

        return $allowNull ? $transformed : array_filter($transformed, static fn ($value) => $value !== null);
    }

    protected function extractSchemaFields(Schema $schema, string $prefix = ''): array
    {
        $fields = [];

        if ($schema->getPrimary()) {
            $fields[] = $prefix . ($schema->getPrimary()->alias ?: $schema->getPrimary()->getName());
        }

        foreach ($schema->getProperties() as $property) {
            if ($property->hasMeta('validationOnly') || $property->hasMeta('isRelation')) {
                continue;
            }

            if ($property->getAccepts() instanceof Schema && $property->getType() !== 'collection') {
                $fields = array_merge($fields, $this->extractSchemaFields($property->getAccepts(), $prefix));
                continue;
            }

            $name = $property->alias ?: $property->getName();
            $fields[] = $prefix . $name;
        }

        return array_values(array_unique(array_filter($fields)));
    }

    protected function extractSchemaDefaults(Schema $schema, string $prefix = ''): array
    {
        $defaults = [];

        foreach ($schema->getProperties() as $property) {
            if (
                $property->hasMeta('readonly') ||
                $property->hasMeta('calculated') ||
                $property->hasMeta('validationOnly') ||
                $property->hasMeta('isRelation')
            ) {
                continue;
            }

            $key = $property->alias ?: $property->getName();

            if ($property->getAccepts() instanceof Schema && $property->getType() !== 'collection') {
                $nested = $this->extractSchemaDefaults($property->getAccepts(), $prefix . $key . '.');
                $defaults = array_replace_recursive($defaults, $nested);
                continue;
            }

            if ($property->hasMeta('default') || $property->hasMeta('fixed')) {
                $rawValue = $property->hasMeta('fixed') ? $property->fixed : $property->default;
                $value = $this->resolvePropertyValue($rawValue, $property);

                if ($property->hasMeta('isYesNo') && $value !== null) {
                    $value = $value ? 'Yes' : 'No';
                }

                $path = explode('.', $prefix . $key);
                $current = &$defaults;

                while (count($path) > 1) {
                    $segment = array_shift($path);

                    if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                        $current[$segment] = [];
                    }

                    $current = &$current[$segment];
                }

                $current[$path[0]] = $value;
            }
        }

        return $defaults;
    }

    protected function resolvePropertyValue(mixed $value, $property, array $attributes = []): mixed
    {
        if (! is_callable($value)) {
            return $value;
        }

        $attempts = [
            fn () => $value($property, $attributes),
            fn () => $value($property),
            fn () => $value($attributes),
            fn () => $value(),
        ];

        foreach ($attempts as $attempt) {
            try {
                return $attempt();
            } catch (ArgumentCountError|TypeError $exception) {
                continue;
            }
        }

        return null;
    }

    protected function parseRequestBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        if (is_object($body) && method_exists($body, 'toArray')) {
            $body = $body->toArray();
        }

        if (is_object($body) && method_exists($body, 'all')) {
            $body = $body->all();
        }

        if (! is_array($body)) {
            $body = [];
        }

        return $body;
    }

    protected function queryResourceFromRequest(ServerRequestInterface $request): ?string
    {
        $params = $request->getQueryParams();

        return $params['resource'] ?? $params['object'] ?? null;
    }

    protected function resolveClientFromContainer(): ?CommerceLayer
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $resolved = app(CommerceLayer::class);
        } catch (Throwable) {
            return null;
        }

        return $resolved instanceof CommerceLayer ? $resolved : null;
    }
}
