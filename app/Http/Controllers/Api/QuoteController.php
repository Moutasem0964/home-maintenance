<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationCategory;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\QuoteType;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\StoreQuoteRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\QuoteResource;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Technician;
use App\Models\User;
use App\Services\NotificationService;
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

    public function store(StoreQuoteRequest $request, int $order, QuoteService $quoteService, NotificationService $notificationService): JsonResponse
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

        $notificationService->notify(
            $orderModel->client,
            NotificationCategory::Orders,
            'وصل عرض السعر',
            'أرسل الفني عرض سعر لطلبك — راجعه للموافقة أو الرفض.',
            $orderModel,
        );

        return (new QuoteResource($quote->load('parts')))->response()->setStatusCode(201);
    }

    /** Technician sends an add-on quote for an extra fault found while the repair is in progress. */
    public function storeAddon(StoreQuoteRequest $request, int $order, QuoteService $quoteService, NotificationService $notificationService): JsonResponse
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        /** @var Technician|null $technician */
        $technician = $user->technician()->first();

        abort_if($technician === null || $orderModel->technician_id !== $technician->id, 403, 'This is not your order.');
        abort_unless($orderModel->status === OrderStatus::InProgress, 409, 'Add-on quotes are only allowed while the repair is in progress.');
        abort_if($orderModel->quotes()->where('status', QuoteStatus::Pending)->exists(), 409, 'A pending quote already exists.');

        $quote = $quoteService->createAddonQuote($orderModel, $request->validated());

        $notificationService->notify(
            $orderModel->client,
            NotificationCategory::Orders,
            'وصل عرض سعر إضافي',
            'اكتشف الفني عملاً إضافياً وأرسل عرض سعر إضافي — راجعه للموافقة أو الرفض.',
            $orderModel,
        );

        return (new QuoteResource($quote->load('parts')))->response()->setStatusCode(201);
    }

    public function approve(Request $request, int $quote, QuoteService $quoteService, NotificationService $notificationService): OrderResource
    {
        $quoteModel = Quote::findOrFail($quote);
        $this->assertClient($request, $quoteModel);

        $isAddon = $quoteModel->type === QuoteType::Addon;

        try {
            $order = $quoteService->approve($quoteModel);

            $notificationService->notify(
                $order->technician->user,
                NotificationCategory::Orders,
                $isAddon ? 'تمت الموافقة على العرض الإضافي' : 'تمت الموافقة على العرض',
                $isAddon ? 'وافق العميل على العرض الإضافي — تابع العمل.' : 'وافق العميل على عرض السعر — يمكنك بدء العمل.',
                $order,
            );

            return new OrderResource($order);
        } catch (InsufficientBalanceException) {
            throw ValidationException::withMessages(['repair' => 'Insufficient wallet balance to hold the repair fee.']);
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }
    }

    public function reject(Request $request, int $quote, QuoteService $quoteService, NotificationService $notificationService): OrderResource
    {
        $quoteModel = Quote::findOrFail($quote);
        $this->assertClient($request, $quoteModel);

        $isAddon = $quoteModel->type === QuoteType::Addon;

        try {
            $order = $quoteService->reject($quoteModel);

            $notificationService->notify(
                $order->technician->user,
                NotificationCategory::Orders,
                $isAddon ? 'تم رفض العرض الإضافي' : 'تم رفض العرض',
                $isAddon ? 'رفض العميل العرض الإضافي.' : 'رفض العميل عرض السعر.',
                $order,
            );

            return new OrderResource($order);
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
