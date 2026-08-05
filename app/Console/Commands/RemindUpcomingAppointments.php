<?php

namespace App\Console\Commands;

use App\Services\SchedulingService;
use Illuminate\Console\Command;

class RemindUpcomingAppointments extends Command
{
    protected $signature = 'appointments:remind';

    protected $description = 'Send one-time reminders for confirmed appointments starting within the lead window.';

    public function handle(SchedulingService $schedulingService): int
    {
        $count = $schedulingService->remindUpcoming();

        $this->info("Reminded {$count} appointment(s).");

        return self::SUCCESS;
    }
}
