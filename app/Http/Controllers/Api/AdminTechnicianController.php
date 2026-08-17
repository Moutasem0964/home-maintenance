<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesAdmin;
use App\Http\Controllers\Controller;
use App\Http\Resources\TechnicianResource;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianModerationService;
use Illuminate\Http\Request;

class AdminTechnicianController extends Controller
{
    use AuthorizesAdmin;

    public function __construct(
        private readonly TechnicianModerationService $moderation,
    ) {}

    /** Approve a pending technician into the probation trial. */
    public function approve(Request $request, int $technician): TechnicianResource
    {
        $this->assertAdmin($request);

        $model = $this->moderation->approve(Technician::findOrFail($technician));

        return new TechnicianResource($model->load('services'));
    }

    /** Suspend a technician for reliability failures (probation + offline; open flags resolved). */
    public function suspend(Request $request, int $technician): TechnicianResource
    {
        $this->assertAdmin($request);

        /** @var User $user */
        $user = $request->user();
        $model = $this->moderation->suspend(Technician::findOrFail($technician), $user, $this->sanctionNote($request));

        return new TechnicianResource($model->load('services'));
    }

    /** Ban a technician outright and take them offline. */
    public function ban(Request $request, int $technician): TechnicianResource
    {
        $this->assertAdmin($request);

        /** @var User $user */
        $user = $request->user();
        $model = $this->moderation->ban(Technician::findOrFail($technician), $user, $this->sanctionNote($request));

        return new TechnicianResource($model->load('services'));
    }

    /** Lift a ban and restart the technician in a fresh probation trial. */
    public function reinstate(Request $request, int $technician): TechnicianResource
    {
        $this->assertAdmin($request);

        $model = $this->moderation->reinstate(Technician::findOrFail($technician));

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
