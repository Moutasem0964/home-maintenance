<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\CompleteWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminWithdrawalController extends Controller
{
    /** Withdrawal requests, filtered by status (default: processing = awaiting payout). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensureAdmin($request);

        $status = (string) $request->query('status', 'processing');

        return WithdrawalResource::collection(
            Withdrawal::where('status', $status)->latest()->get(),
        );
    }

    /** Admin paid the technician and uploads the receipt: the reserved funds leave the ledger. */
    public function complete(CompleteWithdrawalRequest $request, Withdrawal $withdrawal, WithdrawalService $withdrawalService): WithdrawalResource
    {
        $this->ensureAdmin($request);

        try {
            $resolved = $withdrawalService->complete($withdrawal, $request->user(), $request->file('receipt'));
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }

        return new WithdrawalResource($resolved);
    }

    /** Decline the payout and return the reserved funds to the technician's available balance. */
    public function reject(Request $request, Withdrawal $withdrawal, WithdrawalService $withdrawalService): WithdrawalResource
    {
        $this->ensureAdmin($request);

        try {
            $resolved = $withdrawalService->reject($withdrawal, $request->user());
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }

        return new WithdrawalResource($resolved);
    }

    /** Stream the payout receipt for the record. */
    public function receipt(Request $request, Withdrawal $withdrawal): StreamedResponse
    {
        $this->ensureAdmin($request);

        abort_if($withdrawal->receipt_url === null, 404, 'No receipt on this withdrawal.');
        abort_unless(Storage::disk('local')->exists($withdrawal->receipt_url), 404);

        return Storage::disk('local')->response($withdrawal->receipt_url);
    }

    private function ensureAdmin(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === UserRole::Admin, 403, 'Admins only.');
    }
}
