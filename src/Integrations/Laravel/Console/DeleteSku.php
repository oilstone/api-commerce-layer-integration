<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\CommerceLayerException;
use Oilstone\ApiCommerceLayerIntegration\Services\DeleteSkuService;
use RuntimeException;

class DeleteSku extends Command
{
    protected $signature = 'commerce-layer:sku:delete {skuIds* : One or more SKU ids} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a SKU and its related price list entries and price frequency tiers';

    public function handle(DeleteSkuService $service): int
    {
        $skuIds = (array) $this->argument('skuIds');

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

        try {
            $results = $service->delete($skuIds, fn (string $message) => $this->warn($message));
        } catch (RuntimeException|CommerceLayerException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $this->info(sprintf(
                'Deleted SKU %s, %d price list entr%s, and %d price frequency tier%s.',
                $result['sku_id'],
                $result['deleted_price_list_entries'],
                $result['deleted_price_list_entries'] === 1 ? 'y' : 'ies',
                $result['deleted_price_frequency_tiers'],
                $result['deleted_price_frequency_tiers'] === 1 ? '' : 's',
            ));
        }

        return self::SUCCESS;
    }
}
