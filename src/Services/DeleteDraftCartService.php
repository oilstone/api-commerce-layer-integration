<?php

namespace Oilstone\ApiCommerceLayerIntegration\Services;

use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\CommerceLayerException;
use Oilstone\ApiCommerceLayerIntegration\Query;
use RuntimeException;

class DeleteDraftCartService
{
    public function __construct(
        protected CommerceLayer $client,
        protected QueryCacheHandler $cacheHandler,
        protected int $pageSize = 25,
    ) {
    }

    public function delete(string $orderId, ?callable $onWarning = null): array
    {
        if ($orderId === '') {
            throw new RuntimeException('An order id is required.');
        }

        $order = Query::make('orders', $this->client)
            ->select(['id', 'status', 'payment_status', 'created_at'])
            ->find($orderId);

        if (! $order) {
            throw new RuntimeException('Order not found.');
        }

        $attributes = $order['attributes'] ?? [];
        $status = $attributes['status'] ?? null;
        $paymentStatus = $attributes['payment_status'] ?? null;

        if (($status !== 'pending' && $status !== 'draft') || $paymentStatus !== 'unpaid') {
            throw new RuntimeException('The order is not a pending unpaid cart.');
        }

        $this->cacheHandler->forgetEntryByConditions('transactions', ['order_id' => $orderId]);
        $this->cacheHandler->forgetEntryByConditions('wire_transfers', ['order_id' => $orderId]);
        $this->cacheHandler->forgetEntryByConditions('line_items', ['order_id' => $orderId]);

        $deletedTransactions = $this->deleteTransactionsForOrder($orderId, $onWarning);

        $deletedWireTransfers = 0;
        foreach ($this->fetchIds('wire_transfers', static fn (Query $query) => $query->where('order_id', $orderId)) as $wireTransferId) {
            if ($this->deleteResource('wire_transfers', $wireTransferId, $onWarning)) {
                $deletedWireTransfers++;
            }
        }

        $deletedLineItems = 0;
        foreach ($this->fetchIds('line_items', static fn (Query $query) => $query->where('order_id', $orderId)) as $lineItemId) {
            if ($this->deleteResource('line_items', $lineItemId, $onWarning)) {
                $deletedLineItems++;
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

        return [
            'order_id' => $orderId,
            'deleted_wire_transfers' => $deletedWireTransfers,
            'deleted_line_items' => $deletedLineItems,
            'deleted_transactions' => $deletedTransactions,
        ];
    }

    protected function fetchIds(string $resource, callable $constraints): array
    {
        $ids = [];
        $offset = 0;

        do {
            $query = Query::make($resource, $this->client)
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

    protected function deleteResource(string $resource, string $id, ?callable $onWarning = null): bool
    {
        $attempts = 3;
        $delay = 250;

        for ($i = 1; $i <= $attempts; $i++) {
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

                if ($i === $attempts || ! $this->isDependentTransactionsException($exception)) {
                    throw $exception;
                }

                usleep($delay * 1000 * $i);
            }
        }

        return false;
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
