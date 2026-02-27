<?php

namespace Oilstone\ApiCommerceLayerIntegration\Services;

use Oilstone\ApiCommerceLayerIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\CommerceLayerException;
use Oilstone\ApiCommerceLayerIntegration\Query;
use RuntimeException;

class DeleteSkuService
{
    public function __construct(
        protected CommerceLayer $client,
        protected QueryCacheHandler $cacheHandler,
        protected int $pageSize = 25,
    ) {
    }

    public function delete(array $skuIds, ?callable $onWarning = null): array
    {
        $skuIds = array_values(array_filter(
            array_map('strval', $skuIds),
            static fn (string $skuId) => $skuId !== '',
        ));

        if ($skuIds === []) {
            throw new RuntimeException('At least one SKU id is required.');
        }

        $results = [];

        foreach ($skuIds as $skuId) {
            $this->cacheHandler->forgetEntryByConditions('price_list_entries', ['sku_id' => $skuId]);

            $deletedTiers = 0;
            $deletedEntries = 0;

            foreach ($this->fetchIds('price_list_entries', static fn (Query $query) => $query->where('sku_id', $skuId)) as $entryId) {
                $this->cacheHandler->forgetEntryByConditions('price_frequency_tiers', ['price_list_entry_id' => $entryId]);

                foreach ($this->fetchIds('price_frequency_tiers', static fn (Query $query) => $query->where('price_list_entry_id', $entryId)) as $tierId) {
                    if ($this->deleteResource('price_frequency_tiers', $tierId, $onWarning)) {
                        $deletedTiers++;
                    }
                }

                if ($this->deleteResource('price_list_entries', $entryId, $onWarning)) {
                    $deletedEntries++;
                }
            }

            $this->deleteResource('skus', $skuId, $onWarning);

            $results[] = [
                'sku_id' => $skuId,
                'deleted_price_list_entries' => $deletedEntries,
                'deleted_price_frequency_tiers' => $deletedTiers,
            ];
        }

        return $results;
    }

    protected function fetchIds(string $resource, callable $constraints): array
    {
        $ids = [];
        $offset = 0;

        do {
            $query = Query::make($resource, $this->client)
                ->select(['id']);

            $constraints($query);

            try {
                $results = $query
                    ->limit($this->pageSize)
                    ->offset($offset)
                    ->get();
            } catch (CommerceLayerException $exception) {
                if ($exception->getStatusCode() === 404) {
                    break;
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

    protected function isDependentTransactionsException(CommerceLayerException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'dependent transactions');
    }
}
