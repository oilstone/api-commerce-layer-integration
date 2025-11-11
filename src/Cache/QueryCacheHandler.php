<?php

namespace Oilstone\ApiCommerceLayerIntegration\Cache;

use Oilstone\ApiCommerceLayerIntegration\Query;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

class QueryCacheHandler
{
    protected string $queryPrefix = 'commercelayer.query.';

    protected string $queryNamespaceKey = 'commercelayer.query.namespace';

    protected bool $skipRetrievalByDefault = false;

    public function __construct(
        protected CacheInterface $cache,
        protected ?int $queryTtl = null,
        protected ?LoggerInterface $logger = null
    ) {}

    public function setLogger(?LoggerInterface $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    public function setTtl(?int $ttl): static
    {
        $this->queryTtl = $ttl;

        return $this;
    }

    public function skipRetrievalByDefault(bool $skip = true): static
    {
        $this->skipRetrievalByDefault = $skip;

        return $this;
    }

    public function remember(string $key, callable $callback, array $tags = [], array $options = []): mixed
    {
        return $this->rememberQuery($key, $callback, $options);
    }

    public function rememberQuery(string $key, callable $callback, array $options = []): mixed
    {
        $cacheKey = $this->buildQueryCacheKey($key);

        $skipCache = array_key_exists('skip_cache', $options)
            ? (bool) $options['skip_cache']
            : $this->skipRetrievalByDefault;

        if (! $skipCache && $this->cache->has($cacheKey)) {
            $value = $this->cache->get($cacheKey);
            $this->log($key, $value, $options);

            return $value;
        }

        $value = $callback();

        $this->cache->set($cacheKey, $value, $this->queryTtl);

        $this->log($key, $value, $options);

        return $value;
    }

    public function rememberEntry(Query $query, string $key, callable $callback, array $options = []): mixed
    {
        return $this->rememberQuery($key, $callback, $options);
    }

    public function forgetEntryByConditions(string $object, array $conditions): void
    {
        // Entry cache indexing is not yet implemented for the Commerce Layer integration basis.
    }

    public function flushQueryCache(): void
    {
        $this->cache->set($this->queryNamespaceKey, $this->generateNamespace());
    }

    protected function log(string $key, mixed $value, array $options): void
    {
        if (! $this->logger || ! ($options['log_request'] ?? false)) {
            return;
        }

        $this->logger->debug('Commerce Layer cache hit', [
            'key' => $key,
            'value' => $value,
        ]);
    }

    protected function buildQueryCacheKey(string $key): string
    {
        return $this->queryPrefix.$this->getQueryNamespace().md5($key);
    }

    protected function getQueryNamespace(): string
    {
        $namespace = $this->cache->get($this->queryNamespaceKey);

        if (! is_string($namespace) || $namespace === '') {
            $namespace = $this->generateNamespace();
            $this->cache->set($this->queryNamespaceKey, $namespace);
        }

        return $namespace;
    }

    protected function generateNamespace(): string
    {
        return (string) microtime(true);
    }
}
