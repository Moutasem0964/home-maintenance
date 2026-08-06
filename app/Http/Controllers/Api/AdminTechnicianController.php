<?php

namespace App\Http\Controllers\Api;

use App\Enums\TechnicianFlagOutcome;
use App\Enums\TechnicianStatus;
use App\Http\Controllers\Concerns\AuthorizesAdmin;
use App\Http\Controllers\Controller;
use App\Http\Resources\TechnicianResource;
use App\Models\AppSetting;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianFlagService;
use Illuminate\Http\Request;

class AdminTechnicianController extends Controller
{
    use AuthorizesAdmin;

    public function __construct(
        private readonly TechnicianFlagService $flagService,
    ) {}

    /** Approve a pending technician into the active pool. */
    public function approve(Request $request, int $technician): TechnicianResource
    {
        $this->assertAdmin($request);

        $model = Technician::findOrFail($technician);
        $model->update(['status' => TechnicianStatus::Active]);

        return new TechnicianResource($model->load('services'));
    }

    /**
     * Sanction a technician for reliability failures (raised as technician_flags off
     * no-show / withdraw). Suspend = probation with a capped daily order limit and
     * taken offline; the tech can work again once an admin reinstates (approve).
     */
    public function suspend(Request $request, int $technician): TechnicianResource
    {
        $this->assertAdmin($request);
        $note = $this->sanctionNote($request);

        /** @var User $user */
        $user = $request->user();
        $model = Technician::findOrFail($technician);
        $model->update([
            'status' => TechnicianStatus::Probation,
            'daily_order_limit' => (int) AppSetting::get('probation_daily_limit', 3),
            'is_available' => false,
        ]);
        $this->flagService->resolveOpenFor($model->id, $user, TechnicianFlagOutcome::Suspended, $note);

        return new TechnicianResource($model->load('services'));
    }

    /** Ban a technician outright and take them offline (removed from anywhere, per the status lifecycle). */
    public function ban(Request $request, int $technician): TechnicianResource
    {
        $this->assertAdmin($request);
        $note = $this->sanctionNote($request);

        /** @var User $user */
        $user = $request->user();
        $model = Technician::findOrFail($technician);
        $model->update([
            'status' => TechnicianStatus::Banned,
            'is_available' => false,
        ]);
        $this->flagService->resolveOpenFor($model->id, $user, TechnicianFlagOutcome::Banned, $note);

        return new TechnicianResource($model->load('services'));
    }

    /** Optional free-text reason the admin attaches to a sanction. */
    private function sanctionNote(Request $request): ?string
    {
        /** @var array{note?: string|null} $data */
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $data['note'] ?? null;
    }
}
