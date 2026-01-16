<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Query;

class DeleteDraftCart extends Command
{
    protected $signature = 'commerce-layer:cart:draft:delete {orderId} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a pending cart order that is still unpaid';

    protected int $pageSize = 25;

    public function handle(): int
    {
        $orderId = (string) $this->argument('orderId');

        if ($orderId === '') {
            $this->error('An order id is required.');

            return self::FAILURE;
        }

        /** @var CommerceLayer $client */
        $client = app(CommerceLayer::class);

        $order = Query::make('orders', $client)
            ->select(['id', 'status', 'payment_status', 'created_at'])
            ->find($orderId);

        if (! $order) {
            $this->error('Order not found.');

            return self::FAILURE;
        }

        $attributes = $order['attributes'] ?? [];
        $status = $attributes['status'] ?? null;
        $paymentStatus = $attributes['payment_status'] ?? null;

        if (($status !== 'pending' && $status !== 'draft') || $paymentStatus !== 'unpaid') {
            $this->warn('The order is not a pending unpaid cart.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Delete the pending unpaid cart order?')) {
            $this->info('No changes were made.');

            return self::SUCCESS;
        }

        $lineItemIds = $this->fetchIds(
            'line_items',
            $client,
            static fn (Query $query) => $query->where('order_id', $orderId),
        );

        $deletedLineItems = 0;

        foreach ($lineItemIds as $lineItemId) {
            $client->delete('line_items', $lineItemId);
            $deletedLineItems++;
        }

        $client->delete('orders', $orderId);

        $this->info(sprintf('Deleted pending unpaid cart order %s.', $orderId));
        $this->info(sprintf(
            'Deleted pending unpaid cart order %s and %d line item%s.',
            $orderId,
            $deletedLineItems,
            $deletedLineItems === 1 ? '' : 's',
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
