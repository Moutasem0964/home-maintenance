<?php

namespace App\Services;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Address;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly EscrowService $escrowService,
        private readonly AssignmentService $assignmentService,
    ) {}

    /**
     * Create an order and atomically hold its inspection fee in escrow.
     *
     * Idempotent on the client-supplied operation id: a retried or duplicated
     * request (network retry, double tap, at-least-once job) returns the SAME
     * order instead of creating a second one and holding the fee twice. The
     * pre-check covers the common sequential retry; the unique constraint plus
     * the catch below covers two identical requests racing at the same instant.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $client, array $data): Order
    {
        $operationId = (string) $data['operation_id'];

        /** @var Order|null $existing */
        $existing = $client->orders()->where('idempotency_key', $operationId)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($client, $data, $operationId): Order {
                /** @var Address $address */
                $address = $client->addresses()->findOrFail($data['address_id']);

                /** @var Order $order */
                $order = $client->orders()->create([
                    'idempotency_key' => $operationId,
                    'service_category_id' => $data['service_category_id'],
                    'address_id' => $address->id,
                    'lat' => $address->lat,
                    'lng' => $address->lng,
                    'description' => $data['description'] ?? null,
                    'kind' => OrderKind::Normal,
                    'type' => $data['type'],
                    'scheduled_at' => $data['scheduled_at'] ?? null,
                    'status' => OrderStatus::Pending,
                    'commission_rate' => (string) AppSetting::get('commission_rate'),
                    'commission_amount' => '0',
                    'inspection_fee' => (string) AppSetting::get('inspection_fee_default'),
                ]);

                // Escrow keys are derived from the same operation id, so they are
                // stable and reproducible for this operation (no random tokens).
                $this->escrowService->holdFunds(
                    $order,
                    (string) $order->inspection_fee,
                    PaymentType::Inspection,
                    "inspection:{$operationId}",
                    "inspection:{$operationId}",
                );

                // Push the fresh order to the nearest qualified technician (best-effort:
                // no one available yet just leaves it pending for a later re-offer).
                $this->assignmentService->offerToNext($order);

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent identical request won the insert race — return its order.
            /** @var Order $winner */
            $winner = $client->orders()->where('idempotency_key', $operationId)->firstOrFail();

            return $winner;
        }
    }
}
