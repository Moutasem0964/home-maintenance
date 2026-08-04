<?php

namespace App\Console\Commands;

use App\Services\QuoteService;
use Illuminate\Console\Command;

class ExpireQuotes extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Expire unanswered quotes past their deadline and close those orders as inspection-only.';

    public function handle(QuoteService $quoteService): int
    {
        $count = $quoteService->expireStaleQuotes();

        $this->info("Expired {$count} quote(s).");

        return self::SUCCESS;
    }
}
