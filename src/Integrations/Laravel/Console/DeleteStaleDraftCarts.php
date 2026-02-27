<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Services\DeleteStaleDraftCartsService;
use RuntimeException;

class DeleteStaleDraftCarts extends Command
{
    protected $signature = 'commerce-layer:cart:draft:purge {--days=30 : Delete pending unpaid carts older than the provided number of days} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all pending unpaid cart orders that are older than a cutoff date';

    public function handle(DeleteStaleDraftCartsService $service): int
    {
        $days = (int) $this->option('days');

        try {
            $orderIds = $service->findOrderIds($days);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($orderIds === []) {
            $this->info('No pending unpaid carts matched the criteria.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Delete %d pending unpaid cart order(s)?', count($orderIds)))) {
            $this->info('No changes were made.');

            return self::SUCCESS;
        }

        $result = $service->purgeOrders($orderIds, fn (string $message) => $this->warn($message));

        foreach ($result['failures'] as $orderId => $message) {
            $this->error(sprintf('Failed to delete order %s: %s', $orderId, $message));
        }

        $this->info(sprintf('Deleted %d pending unpaid cart order(s).', $result['deleted_orders']));
        $this->info(sprintf('Deleted %d line item%s.', $result['deleted_line_items'], $result['deleted_line_items'] === 1 ? '' : 's'));
        $this->info(sprintf('Deleted %d transaction%s.', $result['deleted_transactions'], $result['deleted_transactions'] === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
