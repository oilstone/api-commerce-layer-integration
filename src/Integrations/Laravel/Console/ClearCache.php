<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Services\ClearCacheService;

class ClearCache extends Command
{
    protected $signature = 'commerce-layer:cache:clear {resource} {id?} {--field=id}';

    protected $description = 'Clear Commerce Layer cache entries';

    public function handle(ClearCacheService $clearCacheService): int
    {
        $resource = (string) $this->argument('resource');
        $id = $this->argument('id');
        $field = (string) $this->option('field');

        $clearCacheService->clear($resource, $id ? (string) $id : null, $field);

        if ($id) {
            $this->info(sprintf(
                'Cleared query cache and entry cache for %s where %s = %s.',
                $resource,
                $field,
                $id,
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('Cleared query cache for %s queries.', $resource));

        return self::SUCCESS;
    }
}
