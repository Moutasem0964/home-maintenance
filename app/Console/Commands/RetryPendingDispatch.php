<?php

namespace App\Console\Commands;

use App\Services\AssignmentService;
use Illuminate\Console\Command;

class RetryPendingDispatch extends Command
{
    protected $signature = 'dispatch:retry-pending';

    protected $description = 'Re-offer any order still pending with no live dispatch offer to the next qualified technician.';

    public function handle(AssignmentService $assignmentService): int
    {
        $count = $assignmentService->retryPending();

        $this->info("Re-offered {$count} stuck pending order(s).");

        return self::SUCCESS;
    }
}
