<?php

namespace Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiCommerceLayerIntegration\Exceptions\CommerceLayerException;
use Oilstone\ApiCommerceLayerIntegration\Services\DeleteDraftCartService;
use RuntimeException;

class DeleteDraftCart extends Command
{
    protected $signature = 'commerce-layer:cart:draft:delete {orderId} {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a pending cart order that is still unpaid';

    public function handle(DeleteDraftCartService $service): int
    {
        $orderId = (string) $this->argument('orderId');

        if (! $this->option('force') && ! $this->confirm('Delete the pending unpaid cart order?')) {
            $this->info('No changes were made.');

            return self::SUCCESS;
        }

        try {
            $result = $service->delete($orderId, fn (string $message) => $this->warn($message));
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'The order is not a pending unpaid cart.') {
                $this->warn($exception->getMessage());
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        } catch (CommerceLayerException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Deleted pending unpaid cart order %s.', $result['order_id']));
        $this->info(sprintf(
            'Deleted pending unpaid cart order %s, %d wire transfer%s, and %d line item%s.',
            $result['order_id'],
            $result['deleted_wire_transfers'],
            $result['deleted_wire_transfers'] === 1 ? '' : 's',
            $result['deleted_line_items'],
            $result['deleted_line_items'] === 1 ? '' : 's',
        ));
        $this->info(sprintf(
            'Deleted %d transaction%s.',
            $result['deleted_transactions'],
            $result['deleted_transactions'] === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
