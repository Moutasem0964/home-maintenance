<?php

namespace App\Console\Commands;

use App\Services\ClosureService;
use Illuminate\Console\Command;

class AutoCompleteClosures extends Command
{
    protected $signature = 'closure:auto-complete';

    protected $description = 'Auto-complete in-progress orders whose closure review window elapsed with no client action.';

    public function handle(ClosureService $closureService): int
    {
        $count = $closureService->autoCompleteStaleClosures();

        $this->info("Auto-completed {$count} order(s).");

        return self::SUCCESS;
    }
}
