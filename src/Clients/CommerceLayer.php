<?php

namespace Oilstone\ApiCommerceLayerIntegration\Clients;

use GuzzleHttp\Client;
use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\CommerceLayerException;
use Psr\Log\LoggerInterface;

class CommerceLayer
{
    protected Client $httpClient;

    protected ?LoggerInterface $logger;

    protected ?QueryCacheHandler $cacheHandler;

    protected array $defaultHeaders = [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
    ];

    public function __construct(
        Client $httpClient,
        protected string $baseUrl,
        protected string $accessToken,
        ?LoggerInterface $logger = null,
        ?QueryCacheHandler $cacheHandler = null
    ) {
        $this->httpClient = $httpClient;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = $logger;
        $this->cacheHandler = $cacheHandler;
    }

    public function setCacheHandler(?QueryCacheHandler $handler): static
    {
        $this->cacheHandler = $handler;

        return $this;
    }

    public function getCacheHandler(): ?QueryCacheHandler
    {
        return $this->cacheHandler;
    }

    public function listResources(string $resource, array $parameters = [], array $options = []): array
    {
        $uri = $this->buildUri($resource);

        $callback = function () use ($uri, $parameters, $options) {
            $response = $this->request('GET', $uri, [
                'query' => $parameters,
                'log_request' => true,
            ] + $options);

            return $response ?? [];
        };

        if (($options['use_cache'] ?? true) && $this->cacheHandler) {
            $key = $uri.'?'.http_build_query($parameters);

            return $this->cacheHandler->rememberQuery($key, $callback, $options);
        }

        return $callback();
    }

    public function retrieve(string $resource, string $id, array $parameters = [], array $options = []): array
    {
        $uri = $this->buildUri($resource, $id);

        return $this->request('GET', $uri, [
            'query' => $parameters,
            'log_request' => true,
        ] + $options);
    }

    public function create(string $resource, array $payload, array $options = []): array
    {
        $uri = $this->buildUri($resource);

        return $this->request('POST', $uri, [
            'json' => ['data' => $payload],
            'log_request' => true,
        ] + $options);
    }

    public function update(string $resource, string $id, array $payload, array $options = []): array
    {
        $uri = $this->buildUri($resource, $id);

        return $this->request('PATCH', $uri, [
            'json' => ['data' => $payload],
            'log_request' => true,
        ] + $options);
    }

    public function delete(string $resource, string $id, array $options = []): array
    {
        $uri = $this->buildUri($resource, $id);

        return $this->request('DELETE', $uri, ['log_request' => true] + $options);
    }

    protected function request(string $method, string $uri, array $options = []): array
    {
        $options['headers'] = array_merge($this->defaultHeaders, $options['headers'] ?? [], [
            'Authorization' => 'Bearer '.$this->accessToken,
        ]);
        $options['http_errors'] = false;

        $response = $this->httpClient->request($method, $uri, $options);

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if ($options['log_request'] ?? false) {
            $this->log($method, $uri, $options, $decoded ?? [], $response->getStatusCode());
        }

        if ($response->getStatusCode() >= 400) {
            throw CommerceLayerException::fromResponse($response);
        }

        return $decoded ?? [];
    }

    protected function buildUri(string $resource, ?string $id = null): string
    {
        $resource = trim($resource, '/');
        $uri = $this->baseUrl.'/'.($resource === '' ? '' : $resource);

        if ($id !== null) {
            $uri .= '/'.trim($id, '/');
        }

        return $uri;
    }

    protected function log(string $method, string $url, array $context, array $response, int $status): void
    {
        if (! $this->logger) {
            return;
        }

        $this->logger->debug('Commerce Layer request', array_merge($context, [
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'response' => $response,
        ]));
    }
}
