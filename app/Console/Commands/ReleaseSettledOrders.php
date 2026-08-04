<?php

namespace App\Console\Commands;

use App\Services\EscrowService;
use Illuminate\Console\Command;

class ReleaseSettledOrders extends Command
{
    protected $signature = 'orders:release-holds';

    protected $description = 'Release escrow holds for completed orders whose dispute window has closed.';

    public function handle(EscrowService $escrowService): int
    {
        $count = $escrowService->releaseSettledOrders();

        $this->info("Released holds for {$count} order(s).");

        return self::SUCCESS;
    }
}
