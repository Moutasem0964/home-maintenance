<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WithdrawalController extends Controller
{
    /** Technician requests a cash-out; the amount is reserved from their available balance. */
    public function store(StoreWithdrawalRequest $request, WithdrawalService $withdrawalService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->technician()->exists(), 403, 'Only technicians can request a withdrawal.');

        try {
            $withdrawal = $withdrawalService->request(
                $user,
                (string) $request->validated('amount'),
            );
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }

        return (new WithdrawalResource($withdrawal))->response()->setStatusCode(201);
    }

    /** The technician's own withdrawal requests, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $technicianId = $user->technician()->value('id');

        return WithdrawalResource::collection(
            Withdrawal::where('technician_id', $technicianId)->latest()->get(),
        );
    }

    /** Stream the payout receipt (uploaded by the admin) to the owning technician. */
    public function receipt(Request $request, Withdrawal $withdrawal): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($withdrawal->technician_id === $user->technician()->value('id'), 403, 'This is not your withdrawal.');

        abort_if($withdrawal->receipt_url === null, 404, 'No receipt yet.');
        abort_unless(Storage::disk('local')->exists($withdrawal->receipt_url), 404);

        return Storage::disk('local')->response($withdrawal->receipt_url);
    }
}
