<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;

class ClearCache extends Command
{
    protected $signature = 'commerce-layer:cache:clear {resource} {id?} {--field=id}';

    protected $description = 'Clear Commerce Layer cache entries';

    public function handle(): int
    {
        /** @var QueryCacheHandler $handler */
        $handler = app(QueryCacheHandler::class);

        $resource = (string) $this->argument('resource');
        $id = $this->argument('id');
        $field = (string) $this->option('field');

        $handler->flushQueryCache();

        if ($id) {
            $handler->forgetEntryByConditions($resource, [$field => $id]);

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
