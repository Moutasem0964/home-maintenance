<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderMessageController extends Controller
{
    /** The order's chat thread, newest first (paginated). Readable by both parties + an admin. */
    public function index(Request $request, Order $order): AnonymousResourceCollection
    {
        $this->ensureAccess($order, $request, allowAdmin: true);

        $conversationId = $order->conversation()->value('id');

        return MessageResource::collection(
            Message::where('conversation_id', $conversationId)->latest('id')->paginate(30),
        );
    }

    /** Send a message (text and/or image) to the other party. */
    public function store(StoreMessageRequest $request, Order $order, ChatService $chatService): JsonResponse
    {
        $this->ensureAccess($order, $request, allowAdmin: false);

        /** @var User $user */
        $user = $request->user();
        $text = $request->validated('message_text');

        try {
            $message = $chatService->send(
                $order,
                $user,
                $text !== null ? (string) $text : null,
                $request->file('image'),
            );
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    /** Mark the other party's messages as read. */
    public function markRead(Request $request, Order $order, ChatService $chatService): JsonResponse
    {
        $this->ensureAccess($order, $request, allowAdmin: false);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['read' => $chatService->markRead($order, $user)]);
    }

    /** Stream a chat image to a participant (or an admin reviewing a dispute). */
    public function image(Request $request, Message $message): StreamedResponse
    {
        /** @var Order $order */
        $order = $message->conversation->order;
        $this->ensureAccess($order, $request, allowAdmin: true);

        abort_if($message->image_url === null, 404, 'This message has no image.');
        abort_unless(Storage::disk('local')->exists($message->image_url), 404);

        return Storage::disk('local')->response($message->image_url);
    }

    private function ensureAccess(Order $order, Request $request, bool $allowAdmin): void
    {
        /** @var User $user */
        $user = $request->user();

        $isClient = $order->client_id === $user->id;
        $isTech = $order->technician_id !== null && $order->technician_id === $user->technician()->value('id');
        $isAdmin = $allowAdmin && $user->role === UserRole::Admin;

        abort_unless($isClient || $isTech || $isAdmin, 403, 'You are not part of this conversation.');
    }
}
