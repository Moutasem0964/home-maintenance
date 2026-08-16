<?php

namespace App\Console\Commands;

use App\Services\WarrantyPayoutService;
use Illuminate\Console\Command;

class RetryWarrantyPayouts extends Command
{
    protected $signature = 'warranty:retry-payouts';

    protected $description = 'Retry warranty substitute payouts that were waiting on the platform wallet.';

    public function handle(WarrantyPayoutService $warrantyPayoutService): int
    {
        $count = $warrantyPayoutService->retryPending();

        $this->info("Settled {$count} pending warranty payout(s).");

        return self::SUCCESS;
    }
}
