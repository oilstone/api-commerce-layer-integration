<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Query;

class DeleteDraftCart extends Command
{
    protected $signature = 'commerce-layer:cart:draft:delete {orderId} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a draft cart order that has not been completed or paid';

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

        if ($status !== 'draft' || $paymentStatus === 'paid') {
            $this->warn('The order is not a draft cart or has already been paid.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Delete the draft cart order?')) {
            $this->info('No changes were made.');

            return self::SUCCESS;
        }

        $client->delete('orders', $orderId);

        $this->info(sprintf('Deleted draft cart order %s.', $orderId));

        return self::SUCCESS;
    }
}
