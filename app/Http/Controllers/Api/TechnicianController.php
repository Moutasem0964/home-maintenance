<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTechnician;
use App\Http\Controllers\Controller;
use App\Http\Requests\Technician\SetAvailabilityRequest;
use App\Http\Requests\Technician\SetServicesRequest;
use App\Http\Resources\TechnicianResource;
use Illuminate\Http\Request;

class TechnicianController extends Controller
{
    use ResolvesTechnician;

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
