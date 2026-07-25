<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Dormant until SMS_DRIVER=smschef. Verify field names against your SMS Chef API docs before enabling.
class SmsChefSmsSender implements SmsSender
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiSecret,
        private readonly string $deviceId,
    ) {}

    public function send(string $phone, string $message): void
    {
        $response = Http::asForm()->post($this->endpoint, [
            'secret' => $this->apiSecret,
            'mode' => 'devices',
            'device' => $this->deviceId,
            'phone' => $phone,
            'message' => $message,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "SMS Chef delivery failed for {$phone}: HTTP {$response->status()} {$response->body()}"
            );
        }
    }
}
