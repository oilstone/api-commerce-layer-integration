<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Illuminate\Support\Carbon;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\CommerceLayerException;
use Oilstone\ApiCommerceLayerIntegration\Query;

class DeleteStaleDraftCarts extends Command
{
    protected $signature = 'commerce-layer:cart:draft:purge {--days=30 : Delete pending unpaid carts older than the provided number of days} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all pending unpaid cart orders that are older than a cutoff date';

    protected int $pageSize = 25;

    public function handle(QueryCacheHandler $cacheHandler): int
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
        $deletedTransactions = 0;

        foreach ($orderIds as $orderId) {
            // Explicitly clear the cache for this order's dependencies
            $cacheHandler->forgetEntryByConditions('transactions', ['order_id' => $orderId]);
            $cacheHandler->forgetEntryByConditions('wire_transfers', ['order_id' => $orderId]);
            $cacheHandler->forgetEntryByConditions('line_items', ['order_id' => $orderId]);

            $deletedTransactions += $this->deleteTransactionsForOrder($client, $orderId);

            $wireTransferIds = $this->fetchIds(
                'wire_transfers',
                $client,
                static fn (Query $query) => $query->where('order_id', $orderId),
            );

            foreach ($wireTransferIds as $wireTransferId) {
                try {
                    $this->deleteResource($client, 'wire_transfers', $wireTransferId);
                } catch (CommerceLayerException $exception) {
                    if (! $this->isDependentTransactionsException($exception)) {
                        throw $exception;
                    }

                    $deletedTransactions += $this->deleteTransactionsForOrder($client, $orderId);

                    $this->deleteResource($client, 'wire_transfers', $wireTransferId);
                }
            }

            $lineItemIds = $this->fetchIds(
                'line_items',
                $client,
                static fn (Query $query) => $query->where('order_id', $orderId),
            );

            foreach ($lineItemIds as $lineItemId) {
                try {
                    if ($this->deleteResource($client, 'line_items', $lineItemId)) {
                        $deletedLineItems++;
                    }
                } catch (CommerceLayerException $exception) {
                    if (! $this->isDependentTransactionsException($exception)) {
                        throw $exception;
                    }

                    $deletedTransactions += $this->deleteTransactionsForOrder($client, $orderId);

                    if ($this->deleteResource($client, 'line_items', $lineItemId)) {
                        $deletedLineItems++;
                    }
                }
            }

            try {
                $this->deleteResource($client, 'orders', $orderId);
            } catch (CommerceLayerException $exception) {
                if (! $this->isDependentTransactionsException($exception)) {
                    throw $exception;
                }

                $deletedTransactions += $this->deleteTransactionsForOrder($client, $orderId);

                $this->deleteResource($client, 'orders', $orderId);
            }
        }

        $this->info(sprintf('Deleted %d pending unpaid cart order(s).', count($orderIds)));
        $this->info(sprintf('Deleted %d line item%s.', $deletedLineItems, $deletedLineItems === 1 ? '' : 's'));
        $this->info(sprintf('Deleted %d transaction%s.', $deletedTransactions, $deletedTransactions === 1 ? '' : 's'));

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

    protected function deleteTransactionsForOrder(CommerceLayer $client, string $orderId): int
    {
        $transactionIds = $this->fetchIds(
            'transactions',
            $client,
            static fn (Query $query) => $query->where('order_id', $orderId),
        );

        $deletedTransactions = 0;

        foreach ($transactionIds as $transactionId) {
            if ($this->deleteResource($client, 'transactions', $transactionId)) {
                $deletedTransactions++;
            }
        }

        return $deletedTransactions;
    }

    protected function isDependentTransactionsException(CommerceLayerException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'dependent transactions');
    }
}
