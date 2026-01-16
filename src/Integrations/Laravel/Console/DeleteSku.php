<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\CommerceLayerException;
use Oilstone\ApiCommerceLayerIntegration\Query;

class DeleteSku extends Command
{
    protected $signature = 'commerce-layer:sku:delete {skuId} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a SKU and its related price list entries and price frequency tiers';

    protected int $pageSize = 25;

    public function handle(): int
    {
        $skuId = (string) $this->argument('skuId');

        if ($skuId === '') {
            $this->error('A SKU id is required.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Delete the SKU and related price data?')) {
            $this->info('No changes were made.');

            return self::SUCCESS;
        }

        /** @var CommerceLayer $client */
        $client = app(CommerceLayer::class);

        $priceListEntryIds = $this->fetchIds(
            'price_list_entries',
            $client,
            static fn (Query $query) => $query->where('sku_id', $skuId),
        );

        $deletedTiers = 0;
        $deletedEntries = 0;

        foreach ($priceListEntryIds as $entryId) {
            $tierIds = $this->fetchIds(
                'price_frequency_tiers',
                $client,
                static fn (Query $query) => $query->where('price_list_entry_id', $entryId),
            );

            foreach ($tierIds as $tierId) {
                $client->delete('price_frequency_tiers', $tierId);
                $deletedTiers++;
            }

            $client->delete('price_list_entries', $entryId);
            $deletedEntries++;
        }

        $client->delete('skus', $skuId);

        $this->info(sprintf(
            'Deleted SKU %s, %d price list entr%s, and %d price frequency tier%s.',
            $skuId,
            $deletedEntries,
            $deletedEntries === 1 ? 'y' : 'ies',
            $deletedTiers,
            $deletedTiers === 1 ? '' : 's',
        ));

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
                    return [];
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
}
