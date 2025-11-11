<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console\ClearCache;
use RuntimeException;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/commerce-layer.php', 'commerce-layer');

        $this->app->singleton(QueryCacheHandler::class, function ($app) {
            $config = config('commerce-layer');
            $cache = $app->make('cache.store');
            $logger = ! empty($config['debug'])
                ? Log::channel($config['log_channel'] ?? config('logging.default', 'stack'))
                : null;

            $handler = new QueryCacheHandler(
                $cache,
                $config['query_cache_default_ttl'] ?? null,
                $logger,
            );

            return $handler->skipRetrievalByDefault($app->runningInConsole());
        });

        $this->app->singleton(CommerceLayer::class, function ($app) {
            $config = config('commerce-layer');

            $apiUrl = rtrim((string) ($config['api_url'] ?? ''), '/');
            $authUrl = rtrim((string) ($config['auth_url'] ?? ''), '/');

            if ($apiUrl === '' || $authUrl === '') {
                throw new RuntimeException('Commerce Layer configuration requires api_url and auth_url values.');
            }

            $httpClient = new Client();

            $tokenCacheKey = $config['token_cache_key'] ?? 'commerce-layer.access_token';
            $tokenTtl = $config['token_cache_ttl'] ?? (55 * 60);

            $token = Cache::remember($tokenCacheKey, $tokenTtl, function () use ($httpClient, $config, $authUrl) {
                $formParams = [
                    'grant_type' => 'client_credentials',
                    'client_id' => $config['client_id'] ?? null,
                    'client_secret' => $config['client_secret'] ?? null,
                ];

                $scopeConfig = $config['scope'] ?? ($config['scopes'] ?? null);

                if (is_array($scopeConfig)) {
                    $scopeConfig = implode(' ', array_filter($scopeConfig));
                } elseif (is_string($scopeConfig)) {
                    $scopeConfig = trim($scopeConfig);
                } else {
                    $scopeConfig = '';
                }

                if ($scopeConfig !== '') {
                    $formParams['scope'] = $scopeConfig;
                }

                if (! empty($config['audience'])) {
                    $formParams['audience'] = $config['audience'];
                }

                $response = $httpClient->post($authUrl.'/oauth/token', [
                    'form_params' => array_filter(
                        $formParams,
                        static fn ($value) => $value !== null && $value !== ''
                    ),
                ]);

                $data = json_decode((string) $response->getBody(), true);

                if (! isset($data['access_token'])) {
                    throw new RuntimeException('Unable to retrieve Commerce Layer access token.');
                }

                return $data['access_token'];
            });

            $logger = ! empty($config['debug'])
                ? Log::channel($config['log_channel'] ?? config('logging.default', 'stack'))
                : null;

            $handler = $app->make(QueryCacheHandler::class);

            return new CommerceLayer(
                $httpClient,
                $apiUrl,
                $token,
                $logger,
                $handler,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/commerce-layer.php' => config_path('commerce-layer.php'),
        ], 'config');

        $this->commands([
            ClearCache::class,
        ]);
    }
}
