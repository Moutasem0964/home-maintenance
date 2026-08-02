<?php

namespace App\Console\Commands;

use App\Services\AssignmentService;
use Illuminate\Console\Command;

class ExpireDispatchOffers extends Command
{
    protected $signature = 'dispatch:expire-offers';

    protected $description = 'Expire timed-out dispatch offers and re-offer each order to the next technician.';

    public function handle(AssignmentService $assignmentService): int
    {
        $count = $assignmentService->expireStaleOffers();

        $this->info("Expired {$count} stale offer(s).");

        return self::SUCCESS;
    }
}
