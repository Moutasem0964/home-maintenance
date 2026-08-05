<?php

namespace App\Console\Commands;

use App\Services\SchedulingService;
use Illuminate\Console\Command;

class ActivateDueAppointments extends Command
{
    protected $signature = 'appointments:activate-due';

    protected $description = 'Activate confirmed appointments whose time has arrived and drop their orders on-site.';

    public function handle(SchedulingService $schedulingService): int
    {
        $count = $schedulingService->activateDue();

        $this->info("Activated {$count} appointment(s).");

        return self::SUCCESS;
    }
}
