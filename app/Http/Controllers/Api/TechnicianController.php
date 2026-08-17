<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTechnician;
use App\Http\Controllers\Controller;
use App\Http\Requests\Technician\SetAvailabilityRequest;
use App\Http\Requests\Technician\SetServicesRequest;
use App\Http\Requests\Technician\UpdateLocationRequest;
use App\Http\Requests\Technician\UpdateShamCashAccountRequest;
use App\Http\Resources\TechnicianResource;
use App\Services\ProbationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TechnicianController extends Controller
{
    use ResolvesTechnician;

    public function me(Request $request): TechnicianResource
    {
        return new TechnicianResource($this->technicianFor($request)->load('services'));
    }

    /** Progress toward promotion from probation to active (jobs done, rating vs thresholds). */
    public function probationProgress(Request $request, ProbationService $probationService): JsonResponse
    {
        return response()->json([
            'data' => $probationService->progress($this->technicianFor($request)),
        ]);
    }

    public function setServices(SetServicesRequest $request): TechnicianResource
    {
        $technician = $this->technicianFor($request);
        $technician->services()->sync($request->validated('service_category_ids'));

        return new TechnicianResource($technician->load('services'));
    }

    public function setAvailability(SetAvailabilityRequest $request): TechnicianResource
    {
        $technician = $this->technicianFor($request);
        $data = $request->validated();

        $hasFix = ($data['current_lat'] ?? null) !== null;

        $technician->update([
            'is_available' => $data['is_available'],
            'current_lat' => $data['current_lat'] ?? $technician->current_lat,
            'current_lng' => $data['current_lng'] ?? $technician->current_lng,
            'location_updated_at' => $hasFix ? now() : $technician->location_updated_at,
        ]);

        return new TechnicianResource($technician->load('services'));
    }

    /**
     * Save (or replace) the technician's Sham Cash payout account. Withdrawals are sent here,
     * so the number is required before a payout can be requested; it's encrypted at rest.
     */
    public function setShamCashAccount(UpdateShamCashAccountRequest $request): TechnicianResource
    {
        $technician = $this->technicianFor($request);

        $technician->update([
            'sham_cash_number' => $request->validated('account_number'),
            'sham_cash_name' => $request->validated('account_holder_name'),
        ]);

        return new TechnicianResource($technician->load('services'));
    }

    /**
     * Frequent, lightweight location heartbeat (the app sends it every ~3 min or ~300 m of
     * movement while available). Refreshes the dispatch snapshot and its freshness stamp so a
     * technician who has driven away from where they went online is routed from their real spot.
     */
    public function updateLocation(UpdateLocationRequest $request): JsonResponse
    {
        $technician = $this->technicianFor($request);

        $technician->update([
            'current_lat' => $request->validated('current_lat'),
            'current_lng' => $request->validated('current_lng'),
            'location_updated_at' => now(),
        ]);

        return response()->json(['location_updated_at' => $technician->location_updated_at]);
    }
}
