<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\NotificationCategory;
use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Per-order chat between the client and the assigned technician. The conversation is created
 * on the first message and stays writable only while the order is actively being worked; once
 * the order reaches a terminal state it locks to read-only, preserved as dispute evidence.
 */
class ChatService
{
    /** Order states in which the two parties may still exchange messages. */
    private const CHATTABLE = [
        OrderStatus::Scheduled,
        OrderStatus::Accepted,
        OrderStatus::InProgress,
        OrderStatus::WaitingForParts,
    ];

    public function __construct(private readonly NotificationService $notificationService) {}

    public function send(Order $order, User $sender, ?string $text, ?UploadedFile $image): Message
    {
        if ($order->technician_id === null) {
            throw new \DomainException('No technician is assigned to this order yet.');
        }

        // Rejecting a finished order must PERSIST the read-only lock, so it happens outside the
        // send transaction (a throw inside would roll the status change back with everything else).
        if (! in_array($order->status, self::CHATTABLE, true)) {
            /** @var Conversation|null $existing */
            $existing = $order->conversation()->first();
            if ($existing !== null && $existing->status === ConversationStatus::Open) {
                $existing->update(['status' => ConversationStatus::ReadOnly]); // kept as dispute evidence
            }
            throw new \DomainException('This conversation is closed.');
        }

        return DB::transaction(function () use ($order, $sender, $text, $image): Message {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Re-check under the lock in case the order finished between the guard and here.
            if ($locked->technician_id === null || ! in_array($locked->status, self::CHATTABLE, true)) {
                throw new \DomainException('This conversation is closed.');
            }

            /** @var Conversation $conversation */
            $conversation = $locked->conversation()->firstOrCreate([], ['status' => ConversationStatus::Open]);

            /** @var Message $message */
            $message = $conversation->messages()->create([
                'sender_id' => $sender->id,
                'message_text' => $text,
                'image_url' => $image?->store('chat-images', 'local'),
            ]);

            // Ping the other party.
            $recipient = $sender->id === $locked->client_id
                ? $locked->technician->user
                : $locked->client;

            $this->notificationService->notify(
                $recipient,
                NotificationCategory::Orders,
                'رسالة جديدة',
                'لديك رسالة جديدة بخصوص طلبك.',
                $locked,
            );

            return $message;
        });
    }

    /** Mark every message from the OTHER party as read. Returns how many were updated. */
    public function markRead(Order $order, User $reader): int
    {
        /** @var Conversation|null $conversation */
        $conversation = $order->conversation()->first();
        if ($conversation === null) {
            return 0;
        }

        return $conversation->messages()
            ->where('sender_id', '!=', $reader->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
