<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\CommerceLayerException;
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

        $wireTransferIds = $this->fetchIds(
            'wire_transfers',
            $client,
            static fn (Query $query) => $query->where('order_id', $orderId),
        );

        $deletedWireTransfers = 0;

        foreach ($wireTransferIds as $wireTransferId) {
            if ($this->deleteResource($client, 'wire_transfers', $wireTransferId)) {
                $deletedWireTransfers++;
            }
        }

        $lineItemIds = $this->fetchIds(
            'line_items',
            $client,
            static fn (Query $query) => $query->where('order_id', $orderId),
        );

        $deletedLineItems = 0;

        foreach ($lineItemIds as $lineItemId) {
            if ($this->deleteResource($client, 'line_items', $lineItemId)) {
                $deletedLineItems++;
            }
        }

        $this->deleteResource($client, 'orders', $orderId);

        $this->info(sprintf('Deleted pending unpaid cart order %s.', $orderId));
        $this->info(sprintf(
            'Deleted pending unpaid cart order %s, %d wire transfer%s, and %d line item%s.',
            $orderId,
            $deletedWireTransfers,
            $deletedWireTransfers === 1 ? '' : 's',
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

    protected function deleteResource(CommerceLayer $client, string $resource, string $id): bool
    {
        try {
            $client->delete($resource, $id);

            return true;
        } catch (CommerceLayerException $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }

            $this->warn(sprintf('Skipping %s %s because it no longer exists.', $resource, $id));

            return false;
        }
    }
}
