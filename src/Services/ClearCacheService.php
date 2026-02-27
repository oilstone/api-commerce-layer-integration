<?php

namespace Oilstone\ApiCommerceLayerIntegration\Services;

use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;

class ClearCacheService
{
    public function __construct(protected QueryCacheHandler $cacheHandler)
    {
    }

    public function clear(string $resource, ?string $id = null, string $field = 'id'): array
    {
        $this->cacheHandler->flushQueryCache();

        if ($id !== null && $id !== '') {
            $this->cacheHandler->forgetEntryByConditions($resource, [$field => $id]);

            return [
                'resource' => $resource,
                'id' => $id,
                'field' => $field,
                'entry_cache_cleared' => true,
            ];
        }

        return [
            'resource' => $resource,
            'entry_cache_cleared' => false,
        ];
    }
}
