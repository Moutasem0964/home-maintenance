<?php

namespace App\Services;

use App\Enums\TechnicianFlagOutcome;
use App\Enums\TechnicianFlagReason;
use App\Enums\TechnicianFlagStatus;
use App\Models\TechnicianFlag;
use App\Models\User;

class TechnicianFlagService
{
    /** Queue a reliability offense for admin assessment (the system flags; the admin decides). */
    public function raise(int $technicianId, TechnicianFlagReason $reason, ?int $orderId): TechnicianFlag
    {
        return TechnicianFlag::create([
            'technician_id' => $technicianId,
            'order_id' => $orderId,
            'reason' => $reason,
            'status' => TechnicianFlagStatus::Open,
        ]);
    }

    /** How many offenses are still awaiting assessment for a technician (the accumulation signal). */
    public function openCountFor(int $technicianId): int
    {
        return TechnicianFlag::where('technician_id', $technicianId)
            ->where('status', TechnicianFlagStatus::Open)
            ->count();
    }

    /** Close out a technician's open flags with an explicit outcome — used when an admin suspends/bans them. */
    /** Resolve a single flag with the admin's decision. */
    public function resolve(TechnicianFlag $flag, User $admin, TechnicianFlagOutcome $outcome, ?string $note = null): void
    {
        $flag->update([
            'status' => TechnicianFlagStatus::Reviewed,
            'outcome' => $outcome,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'note' => $note,
        ]);
    }

    public function resolveOpenFor(int $technicianId, User $admin, TechnicianFlagOutcome $outcome, ?string $note = null): int
    {
        return TechnicianFlag::where('technician_id', $technicianId)
            ->where('status', TechnicianFlagStatus::Open)
            ->update([
                'status' => TechnicianFlagStatus::Reviewed,
                'outcome' => $outcome,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'note' => $note,
            ]);
    }
}
