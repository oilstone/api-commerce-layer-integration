<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\SystemStatus;

use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\SystemStatus\Concerns\MakesHttpRequests;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;
use Throwable;

/**
 * Checks Commerce Layer by listing a single record of a resource through the client, which
 * authenticates with its cached access token but bypasses the query cache.
 *
 * Options:
 * - resource: the resource to list (default "markets"); the client's token must be able to read it
 * - timeout: seconds for each request (default 5)
 * - connect_timeout: seconds to establish each connection (default 3)
 *
 * Both are passed to the client's token resolver too, so fetching or refreshing the access token
 * is bounded by them. A check that refreshes its token makes up to three requests.
 */
class CommerceLayerMonitor extends Monitor
{
    use MakesHttpRequests;

    protected string $name = 'Commerce Layer';

    protected array $thresholds = [1000 => 'optimal', 3000 => 'acceptable'];

    public function __construct(array $config, protected CommerceLayer $client)
    {
        parent::__construct($config);
    }

    public function check(Probe $probe): void
    {
        $resource = $this->config['resource'] ?? 'markets';

        try {
            $response = $this->client->listResources($resource, ['page[size]' => 1], [
                'use_cache' => false,
                'timeout' => (float) ($this->config['timeout'] ?? 5),
                'connect_timeout' => (float) ($this->config['connect_timeout'] ?? 3),
            ]);
        } catch (Throwable $e) {
            $this->fail(sprintf('Listing %s failed: %s', $resource, $this->redactUrls($e->getMessage())), $e);
        }

        if (! is_array($response['data'] ?? null)) {
            $this->fail(sprintf('Listing %s returned no data', $resource));
        }

        $probe->meta($resource, $response['meta']['record_count'] ?? count($response['data']));
    }
}
