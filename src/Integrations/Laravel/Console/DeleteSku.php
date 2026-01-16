<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Query;

class DeleteSku extends Command
{
    protected $signature = 'commerce-layer:sku:delete {skuId} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a SKU and its related price list entries and price frequency tiers';

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

        $priceListEntries = Query::make('price_list_entries', $client)
            ->where('sku_id', $skuId)
            ->get();

        $deletedTiers = 0;
        $deletedEntries = 0;

        foreach ($priceListEntries as $entry) {
            $entryId = $entry['id'] ?? null;

            if (! $entryId) {
                continue;
            }

            $tiers = Query::make('price_frequency_tiers', $client)
                ->where('price_list_entry_id', $entryId)
                ->get();

            foreach ($tiers as $tier) {
                $tierId = $tier['id'] ?? null;

                if (! $tierId) {
                    continue;
                }

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
}
