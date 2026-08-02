<?php

namespace App\Http\Controllers\Api;

use App\Enums\TechnicianStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\TechnicianResource;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Http\Request;

class AdminTechnicianController extends Controller
{
    public function approve(Request $request, int $technician): TechnicianResource
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === UserRole::Admin, 403, 'Admins only.');

        $model = Technician::findOrFail($technician);
        $model->update(['status' => TechnicianStatus::Active]);

        return new TechnicianResource($model->load('services'));
    }
}
