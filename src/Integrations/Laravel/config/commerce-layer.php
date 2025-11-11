<?php

return [
    'api_url' => env('COMMERCE_LAYER_API_URL'),
    'auth_url' => env('COMMERCE_LAYER_AUTH_URL', 'https://auth.commercelayer.io'),
    'client_id' => env('COMMERCE_LAYER_CLIENT_ID'),
    'client_secret' => env('COMMERCE_LAYER_CLIENT_SECRET'),
    'audience' => env('COMMERCE_LAYER_AUDIENCE'),
    'scope' => env('COMMERCE_LAYER_SCOPE'),
    'scopes' => array_filter(array_map('trim', explode(',', (string) env('COMMERCE_LAYER_SCOPES', '')))),
    'debug' => env('COMMERCE_LAYER_DEBUG', false),
    'log_channel' => env('COMMERCE_LAYER_LOG_CHANNEL'),
    'query_cache_default_ttl' => env('COMMERCE_LAYER_QUERY_CACHE_DEFAULT_TTL', 3600),
    'token_cache_key' => env('COMMERCE_LAYER_TOKEN_CACHE_KEY', 'commerce-layer.access_token'),
    'token_cache_ttl' => env('COMMERCE_LAYER_TOKEN_CACHE_TTL', 3300),
];
