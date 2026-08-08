<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\StoreQuoteRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\QuoteResource;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Technician;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class QuoteController extends Controller
{
    public function index(Request $request, int $order): AnonymousResourceCollection
    {
        $orderModel = Order::findOrFail($order);
        $this->assertParticipant($request, $orderModel);

        return QuoteResource::collection($orderModel->quotes()->with('parts')->latest()->get());
    }

    public function store(StoreQuoteRequest $request, int $order, QuoteService $quoteService): JsonResponse
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        /** @var Technician|null $technician */
        $technician = $user->technician()->first();

        abort_if($technician === null || $orderModel->technician_id !== $technician->id, 403, 'This is not your order.');
        abort_unless($orderModel->status === OrderStatus::Accepted, 409, 'The order is not awaiting a quote.');
        abort_if($orderModel->arrived_at === null, 409, 'You must mark arrival on-site before sending a quote.');
        abort_if($orderModel->quotes()->where('status', QuoteStatus::Pending)->exists(), 409, 'A pending quote already exists.');

        $quote = $quoteService->createInitialQuote($orderModel, $request->validated());

        return (new QuoteResource($quote->load('parts')))->response()->setStatusCode(201);
    }

    public function approve(Request $request, int $quote, QuoteService $quoteService): OrderResource
    {
        $quoteModel = Quote::findOrFail($quote);
        $this->assertClient($request, $quoteModel);

        try {
            return new OrderResource($quoteService->approve($quoteModel));
        } catch (InsufficientBalanceException) {
            throw ValidationException::withMessages(['repair' => 'Insufficient wallet balance to hold the repair fee.']);
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }
    }

    public function reject(Request $request, int $quote, QuoteService $quoteService): OrderResource
    {
        $quoteModel = Quote::findOrFail($quote);
        $this->assertClient($request, $quoteModel);

        try {
            return new OrderResource($quoteService->reject($quoteModel));
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }
    }

    private function assertParticipant(Request $request, Order $order): void
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Technician|null $technician */
        $technician = $user->technician()->first();

        $isClient = $order->client_id === $user->id;
        $isTechnician = $technician !== null && $order->technician_id === $technician->id;

        abort_unless($isClient || $isTechnician, 403, 'This is not your order.');
    }

    private function assertClient(Request $request, Quote $quote): void
    {
        /** @var Order $order */
        $order = $quote->order()->firstOrFail();

        /** @var User $user */
        $user = $request->user();
        abort_unless($order->client_id === $user->id, 403, 'This is not your order.');
    }
}
