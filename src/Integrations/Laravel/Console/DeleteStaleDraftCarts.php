<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Query;

class DeleteStaleDraftCarts extends Command
{
    protected $signature = 'commerce-layer:cart:draft:purge {--days=30 : Delete draft carts older than the provided number of days} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all draft cart orders that have not been completed or paid and are older than a cutoff date';

    protected int $pageSize = 100;

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
            $client,
            static fn (Query $query) => $query
                ->where('status', 'draft')
                ->where('payment_status', '!=', 'paid')
                ->where('created_at', '<=', $cutoff),
        );

        if ($orderIds === []) {
            $this->info('No draft carts matched the criteria.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Delete %d draft cart order(s)?', count($orderIds)))) {
            $this->info('No changes were made.');

            return self::SUCCESS;
        }

        foreach ($orderIds as $orderId) {
            $client->delete('orders', $orderId);
        }

        $this->info(sprintf('Deleted %d draft cart order(s).', count($orderIds)));

        return self::SUCCESS;
    }

    protected function fetchIds(CommerceLayer $client, callable $constraints): array
    {
        $ids = [];
        $offset = 0;

        do {
            $query = Query::make('orders', $client)
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
