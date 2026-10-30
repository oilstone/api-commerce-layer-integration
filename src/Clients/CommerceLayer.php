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

    protected ?string $accessToken = null;

    /**
     * A token, or a closure called with whether to force a refresh and the request's timeout
     * options (timeout, connect_timeout), so it can bound its own token request.
     *
     * @var string|\Closure(bool, array): string
     */
    protected $accessTokenResolver;

    protected array $defaultHeaders = [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
    ];

    public function __construct(
        Client $httpClient,
        protected string $baseUrl,
        string|\Closure $accessTokenResolver,
        ?LoggerInterface $logger = null,
        ?QueryCacheHandler $cacheHandler = null
    ) {
        $this->httpClient = $httpClient;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->accessTokenResolver = $accessTokenResolver;
        $this->logger = $logger;
        $this->cacheHandler = $cacheHandler;
    }

    protected function getAccessToken(array $timeouts = []): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        if (is_callable($this->accessTokenResolver)) {
            $this->accessToken = ($this->accessTokenResolver)(false, $timeouts);
        } else {
            $this->accessToken = $this->accessTokenResolver;
        }

        return $this->accessToken;
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
        $timeouts = array_intersect_key($options, array_flip(['timeout', 'connect_timeout']));

        $options['headers'] = array_merge($this->defaultHeaders, $options['headers'] ?? [], [
            'Authorization' => 'Bearer '.$this->getAccessToken($timeouts),
        ]);
        $options['http_errors'] = false;

        $response = $this->httpClient->request($method, $uri, $options);

        if ($response->getStatusCode() === 401 && is_callable($this->accessTokenResolver)) {
            $this->accessToken = ($this->accessTokenResolver)(true, $timeouts);
            $options['headers']['Authorization'] = 'Bearer '.$this->accessToken;
            $response = $this->httpClient->request($method, $uri, $options);
        }

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
