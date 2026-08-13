<?php

namespace App\Console\Commands;

use App\Services\PartsWaitService;
use Illuminate\Console\Command;

class FlagOverduePartsWaits extends Command
{
    protected $signature = 'parts:flag-overdue';

    protected $description = 'Raise an admin flag for orders whose waiting-for-parts window has elapsed without resuming.';

    public function handle(PartsWaitService $partsWaitService): int
    {
        $count = $partsWaitService->flagOverdue();

        $this->info("Flagged {$count} overdue parts wait(s).");

        return self::SUCCESS;
    }
}
