<?php

namespace App\Http\Controllers\Api;

use App\Enums\TechnicianFlagOutcome;
use App\Enums\TechnicianFlagStatus;
use App\Http\Controllers\Concerns\AuthorizesAdmin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewFlagRequest;
use App\Http\Resources\TechnicianFlagResource;
use App\Models\TechnicianFlag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminTechnicianFlagController extends Controller
{
    use AuthorizesAdmin;

    /** The queue of reliability offenses awaiting assessment (newest first). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->assertAdmin($request);

        $flags = TechnicianFlag::query()
            ->where('status', TechnicianFlagStatus::Open)
            ->with('technician.user')
            ->latest()
            ->get();

        return TechnicianFlagResource::collection($flags);
    }

    /** Admin dismisses a single flag (reviewed, no sanction) with an optional note. */
    public function review(ReviewFlagRequest $request, int $flag): TechnicianFlagResource
    {
        $this->assertAdmin($request);

        /** @var User $user */
        $user = $request->user();
        $flagModel = TechnicianFlag::findOrFail($flag);
        $note = $request->validated('note');

        $flagModel->update([
            'status' => TechnicianFlagStatus::Reviewed,
            'outcome' => TechnicianFlagOutcome::Dismissed,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'note' => $note !== null ? (string) $note : null,
        ]);

        return new TechnicianFlagResource($flagModel);
    }
}
