<?php

namespace App\Contracts;

interface PushSender
{
    /**
     * Deliver a push notification to the given device tokens.
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, string>  $data  extra key/values for deep-linking (all strings, per FCM)
     */
    public function send(array $tokens, string $title, string $body, array $data = []): void;
}
