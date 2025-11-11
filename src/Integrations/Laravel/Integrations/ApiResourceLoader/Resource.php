<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Integrations\ApiResourceLoader;

use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiCommerceLayerIntegration\Integrations\ApiResourceLoader\Resource as BaseResource;
use Throwable;

class Resource extends BaseResource
{
    public function __construct()
    {
        try {
            $this->cacheHandler = clone app(QueryCacheHandler::class);
        } catch (Throwable) {
            // Fallback to container resolution within the parent constructor.
        }

        parent::__construct();
    }
}
