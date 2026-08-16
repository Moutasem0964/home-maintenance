<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\ApproveDepositRequest;
use App\Http\Resources\TopUpResource;
use App\Models\TopUp;
use App\Models\User;
use App\Services\DepositService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDepositController extends Controller
{
    /** Manual top-up requests for review, filtered by status (default: pending). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensureAdmin($request);

        $status = (string) $request->query('status', 'pending');

        return TopUpResource::collection(
            TopUp::whereNotNull('receipt_url')->where('status', $status)->latest()->get(),
        );
    }

    /** Approve and credit the wallet with the confirmed amount (defaults to the requested one). */
    public function approve(ApproveDepositRequest $request, TopUp $topUp, DepositService $depositService): TopUpResource
    {
        $this->ensureAdmin($request);

        $amount = $request->validated('amount');

        try {
            $resolved = $depositService->approve($topUp, $request->user(), $amount !== null ? (string) $amount : null);
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }

        return new TopUpResource($resolved);
    }

    /** Decline a manual top-up without crediting. */
    public function reject(Request $request, TopUp $topUp, DepositService $depositService): TopUpResource
    {
        $this->ensureAdmin($request);

        try {
            $resolved = $depositService->reject($topUp, $request->user());
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }

        return new TopUpResource($resolved);
    }

    /** Stream the private transfer receipt for review. */
    public function receipt(Request $request, TopUp $topUp): StreamedResponse
    {
        $this->ensureAdmin($request);

        abort_if($topUp->receipt_url === null, 404, 'No receipt on this top-up.');
        abort_unless(Storage::disk('local')->exists($topUp->receipt_url), 404);

        return Storage::disk('local')->response($topUp->receipt_url);
    }

    private function ensureAdmin(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === UserRole::Admin, 403, 'Admins only.');
    }
}
