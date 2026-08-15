<?php

namespace App\Jobs;

use App\Contracts\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a push off the request/transaction thread so the FCM HTTP call never
 * blocks a controller or holds a database lock. Runs inline on the sync queue in tests.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, string>  $data
     */
    public function __construct(
        public array $tokens,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(PushSender $pushSender): void
    {
        $pushSender->send($this->tokens, $this->title, $this->body, $this->data);
    }
}
