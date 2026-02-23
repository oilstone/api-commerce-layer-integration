<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\CommerceLayerException;
use Oilstone\ApiCommerceLayerIntegration\Query;

class DeleteSku extends Command
{
    protected $signature = 'commerce-layer:sku:delete {skuIds* : One or more SKU ids} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a SKU and its related price list entries and price frequency tiers';

    protected int $pageSize = 25;

    public function handle(QueryCacheHandler $cacheHandler): int
    {
        $skuIds = array_values(array_filter(
            array_map('strval', (array) $this->argument('skuIds')),
            static fn (string $skuId) => $skuId !== '',
        ));

        if ($skuIds === []) {
            $this->error('At least one SKU id is required.');

            return self::FAILURE;
        }

        if (
            ! $this->option('force')
            && ! $this->confirm(sprintf(
                'Delete %d SKU%s and related price data?',
                count($skuIds),
                count($skuIds) === 1 ? '' : 's',
            ))
        ) {
            $this->info('No changes were made.');

            return self::SUCCESS;
        }

        /** @var CommerceLayer $client */
        $client = app(CommerceLayer::class);

        foreach ($skuIds as $skuId) {
            // Explicitly clear the cache for this SKU's dependencies
            $cacheHandler->forgetEntryByConditions('price_list_entries', ['sku_id' => $skuId]);

            $priceListEntryIds = $this->fetchIds(
                'price_list_entries',
                $client,
                static fn (Query $query) => $query->where('sku_id', $skuId),
            );

            $deletedTiers = 0;
            $deletedEntries = 0;

            foreach ($priceListEntryIds as $entryId) {
                // Explicitly clear the cache for this entry's dependencies
                $cacheHandler->forgetEntryByConditions('price_frequency_tiers', ['price_list_entry_id' => $entryId]);

                $tierIds = $this->fetchIds(
                    'price_frequency_tiers',
                    $client,
                    static fn (Query $query) => $query->where('price_list_entry_id', $entryId),
                );

                foreach ($tierIds as $tierId) {
                    if ($this->deleteResource($client, 'price_frequency_tiers', $tierId)) {
                        $deletedTiers++;
                    }
                }

                if ($this->deleteResource($client, 'price_list_entries', $entryId)) {
                    $deletedEntries++;
                }
            }

            if ($this->deleteResource($client, 'skus', $skuId)) {
                // Only output success message if the final SKU deletion was successful
            }

            $this->info(sprintf(
                'Deleted SKU %s, %d price list entr%s, and %d price frequency tier%s.',
                $skuId,
                $deletedEntries,
                $deletedEntries === 1 ? 'y' : 'ies',
                $deletedTiers,
                $deletedTiers === 1 ? '' : 's',
            ));
        }

        return self::SUCCESS;
    }

    protected function fetchIds(string $resource, CommerceLayer $client, callable $constraints): array
    {
        $ids = [];
        $offset = 0;

        do {
            $query = Query::make($resource, $client)
                ->select(['id']);

            $constraints($query);

            try {
                $results = $query
                    ->limit($this->pageSize)
                    ->offset($offset)
                    ->get();
            } catch (CommerceLayerException $exception) {
                if ($exception->getStatusCode() === 404) {
                    break;
                }

                throw $exception;
            }

            foreach ($results as $item) {
                if (isset($item['id'])) {
                    $ids[] = $item['id'];
                }
            }

            $count = count($results);
            $offset += $this->pageSize;
        } while ($count === $this->pageSize);

        return $ids;
    }

    protected function deleteResource(CommerceLayer $client, string $resource, string $id): bool
    {
        $attempts = 3;
        $delay = 250; // Milliseconds

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $client->delete($resource, $id);

                return true;
            } catch (CommerceLayerException $exception) {
                if ($exception->getStatusCode() === 404) {
                    $this->warn(sprintf('Skipping %s %s because it no longer exists.', $resource, $id));

                    return false;
                }

                if ($i === $attempts || ! $this->isDependentTransactionsException($exception)) {
                    throw $exception;
                }

                usleep($delay * 1000 * $i);
            }
        }

        return false;
    }

    protected function isDependentTransactionsException(CommerceLayerException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'dependent transactions');
    }
}
