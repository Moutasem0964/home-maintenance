<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use Illuminate\Support\Facades\Log;

/** Local/testing driver: writes the push to the log instead of hitting Firebase. */
class LogPushSender implements PushSender
{
    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        Log::info('[PUSH → '.count($tokens).' device(s)] '.$title.' — '.$body, $data);
    }
}
