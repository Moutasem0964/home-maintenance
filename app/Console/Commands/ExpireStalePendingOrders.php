<?php

namespace App\Console\Commands;

use App\Services\AssignmentService;
use Illuminate\Console\Command;

class ExpireStalePendingOrders extends Command
{
    protected $signature = 'orders:expire-pending';

    protected $description = 'Expire orders that have sat pending past the allowed window, refunding the client inspection hold.';

    public function handle(AssignmentService $assignmentService): int
    {
        $count = $assignmentService->expireStalePending();

        $this->info("Expired {$count} stale pending order(s).");

        return self::SUCCESS;
    }
}
