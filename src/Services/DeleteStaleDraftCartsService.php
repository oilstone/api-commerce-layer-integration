<?php

namespace Oilstone\ApiCommerceLayerIntegration\Services;

use Carbon\Carbon;
use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\CommerceLayerException;
use Oilstone\ApiCommerceLayerIntegration\Query;
use RuntimeException;

class DeleteStaleDraftCartsService
{
    public function __construct(
        protected CommerceLayer $client,
        protected QueryCacheHandler $cacheHandler,
        protected int $pageSize = 25,
    ) {
    }

    public function findOrderIds(int $days): array
    {
        if ($days <= 0) {
            throw new RuntimeException('The days option must be greater than zero.');
        }

        $cutoff = Carbon::now()->subDays($days)->toAtomString();

        return $this->fetchIds(
            'orders',
            static fn (Query $query) => $query
                ->where('payment_status', 'unpaid')
                ->where('created_at', '<=', $cutoff)
                ->where('status', 'IN', ['pending', 'draft']),
        );
    }

    public function purge(int $days, ?callable $onWarning = null): array
    {
        return $this->purgeOrders($this->findOrderIds($days), $onWarning);
    }

    public function purgeOrders(array $orderIds, ?callable $onWarning = null): array
    {
        if ($orderIds === []) {
            return [
                'order_ids' => [],
                'deleted_orders' => 0,
                'deleted_line_items' => 0,
                'deleted_transactions' => 0,
                'failures' => [],
            ];
        }

        $deletedLineItems = 0;
        $deletedTransactions = 0;
        $failures = [];

        foreach ($orderIds as $orderId) {
            try {
                $this->cacheHandler->forgetEntryByConditions('transactions', ['order_id' => $orderId]);
                $this->cacheHandler->forgetEntryByConditions('wire_transfers', ['order_id' => $orderId]);
                $this->cacheHandler->forgetEntryByConditions('line_items', ['order_id' => $orderId]);

                $deletedTransactions += $this->deleteTransactionsForOrder($orderId, $onWarning);

                foreach ($this->fetchIds('wire_transfers', static fn (Query $query) => $query->where('order_id', $orderId)) as $wireTransferId) {
                    try {
                        $this->deleteResource('wire_transfers', $wireTransferId, $onWarning);
                    } catch (CommerceLayerException $exception) {
                        if (! $this->isDependentTransactionsException($exception)) {
                            throw $exception;
                        }

                        $deletedTransactions += $this->deleteTransactionsForOrder($orderId, $onWarning);
                        $this->deleteResource('wire_transfers', $wireTransferId, $onWarning);
                    }
                }

                foreach ($this->fetchIds('line_items', static fn (Query $query) => $query->where('order_id', $orderId)) as $lineItemId) {
                    try {
                        if ($this->deleteResource('line_items', $lineItemId, $onWarning)) {
                            $deletedLineItems++;
                        }
                    } catch (CommerceLayerException $exception) {
                        if (! $this->isDependentTransactionsException($exception)) {
                            throw $exception;
                        }

                        $deletedTransactions += $this->deleteTransactionsForOrder($orderId, $onWarning);

                        if ($this->deleteResource('line_items', $lineItemId, $onWarning)) {
                            $deletedLineItems++;
                        }
                    }
                }

                try {
                    $this->deleteResource('orders', $orderId, $onWarning);
                } catch (CommerceLayerException $exception) {
                    if (! $this->isDependentTransactionsException($exception)) {
                        throw $exception;
                    }

                    $deletedTransactions += $this->deleteTransactionsForOrder($orderId, $onWarning);
                    $this->deleteResource('orders', $orderId, $onWarning);
                }
            } catch (CommerceLayerException $exception) {
                $failures[$orderId] = $exception->getMessage();
            }
        }

        return [
            'order_ids' => $orderIds,
            'deleted_orders' => count($orderIds) - count($failures),
            'deleted_line_items' => $deletedLineItems,
            'deleted_transactions' => $deletedTransactions,
            'failures' => $failures,
        ];
    }

    protected function fetchIds(string $resource, callable $constraints): array
    {
        $ids = [];
        $offset = 0;

        do {
            $query = Query::make($resource, $this->client)->select(['id']);
            $constraints($query);

            $results = $query->limit($this->pageSize)->offset($offset)->get();

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

    protected function deleteResource(string $resource, string $id, ?callable $onWarning = null): bool
    {
        try {
            $this->client->delete($resource, $id);

            return true;
        } catch (CommerceLayerException $exception) {
            if ($exception->getStatusCode() === 404) {
                if ($onWarning) {
                    $onWarning(sprintf('Skipping %s %s because it no longer exists.', $resource, $id));
                }

                return false;
            }

            throw $exception;
        }
    }

    protected function deleteTransactionsForOrder(string $orderId, ?callable $onWarning = null): int
    {
        $deletedTransactions = 0;

        foreach ($this->fetchIds('transactions', static fn (Query $query) => $query->where('order_id', $orderId)) as $transactionId) {
            if ($this->deleteResource('transactions', $transactionId, $onWarning)) {
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
