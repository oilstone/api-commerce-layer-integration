<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Query;

class DeleteStaleDraftCarts extends Command
{
    protected $signature = 'commerce-layer:cart:draft:purge {--days=30 : Delete pending unpaid carts older than the provided number of days} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all pending unpaid cart orders that are older than a cutoff date';

    protected int $pageSize = 25;

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days <= 0) {
            $this->error('The days option must be greater than zero.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days)->toAtomString();

        /** @var CommerceLayer $client */
        $client = app(CommerceLayer::class);

        $orderIds = $this->fetchIds(
            'orders',
            $client,
            static fn (Query $query) => $query
                ->where('payment_status', 'unpaid')
                ->where('created_at', '<=', $cutoff)
                ->where('status', 'IN', ['pending', 'draft']),
        );

        if ($orderIds === []) {
            $this->info('No pending unpaid carts matched the criteria.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Delete %d pending unpaid cart order(s)?', count($orderIds)))) {
            $this->info('No changes were made.');

            return self::SUCCESS;
        }

        $deletedLineItems = 0;

        foreach ($orderIds as $orderId) {
            $lineItemIds = $this->fetchIds(
                'line_items',
                $client,
                static fn (Query $query) => $query->where('order_id', $orderId),
            );

            foreach ($lineItemIds as $lineItemId) {
                $client->delete('line_items', $lineItemId);
                $deletedLineItems++;
            }

            $client->delete('orders', $orderId);
        }

        $this->info(sprintf('Deleted %d pending unpaid cart order(s).', count($orderIds)));
        $this->info(sprintf('Deleted %d line item%s.', $deletedLineItems, $deletedLineItems === 1 ? '' : 's'));

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

            $results = $query
                ->limit($this->pageSize)
                ->offset($offset)
                ->get();

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
