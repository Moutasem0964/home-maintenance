<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Technician\SetAvailabilityRequest;
use App\Http\Requests\Technician\SetServicesRequest;
use App\Http\Resources\TechnicianResource;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Http\Request;

class TechnicianController extends Controller
{
    /** The caller's own technician profile — 403 if they are not a technician. */
    private function technicianFor(Request $request): Technician
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Technician|null $technician */
        $technician = $user->technician()->first();
        abort_if($technician === null, 403, 'This account is not a technician.');

        return $technician;
    }

    public function me(Request $request): TechnicianResource
    {
        return new TechnicianResource($this->technicianFor($request)->load('services'));
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

        $technician->update([
            'is_available' => $data['is_available'],
            'current_lat' => $data['current_lat'] ?? $technician->current_lat,
            'current_lng' => $data['current_lng'] ?? $technician->current_lng,
        ]);

        return new TechnicianResource($technician->load('services'));
    }
}
