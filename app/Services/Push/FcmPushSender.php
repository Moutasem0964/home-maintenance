<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/** Production driver: delivers the push through Firebase Cloud Messaging (multicast). */
class FcmPushSender implements PushSender
{
    public function __construct(private readonly Messaging $messaging) {}

    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        if ($tokens === []) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($data);

        $this->messaging->sendMulticast($message, $tokens);
    }
}
