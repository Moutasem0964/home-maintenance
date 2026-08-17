<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Enums\TechnicianFlagOutcome;
use App\Enums\TechnicianStatus;
use App\Models\AppSetting;
use App\Models\Technician;
use App\Models\User;

/**
 * Technician lifecycle moderation (approve / suspend / ban), shared by the API admin
 * controller and the Filament admin panel so both take exactly the same transitions.
 */
class TechnicianModerationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly TechnicianFlagService $flagService,
    ) {}

    /** Approve a pending technician into the probation trial (onboarding done offline). */
    public function approve(Technician $technician): Technician
    {
        $technician->update([
            'status' => TechnicianStatus::Probation,
            'daily_order_limit' => (int) AppSetting::get('probation_daily_limit', 3),
        ]);

        $this->notificationService->notify($technician->user, NotificationCategory::Admin, 'تم اعتماد حسابك', 'تمت الموافقة على حسابك كفني — يمكنك الآن استقبال الطلبات.');

        return $technician;
    }

    /** Suspend: back to probation with a capped daily limit and taken offline; open flags resolved. */
    public function suspend(Technician $technician, User $admin, ?string $note = null): Technician
    {
        $technician->update([
            'status' => TechnicianStatus::Probation,
            'daily_order_limit' => (int) AppSetting::get('probation_daily_limit', 3),
            'is_available' => false,
        ]);
        $this->flagService->resolveOpenFor($technician->id, $admin, TechnicianFlagOutcome::Suspended, $note);

        $this->notificationService->notify($technician->user, NotificationCategory::Admin, 'تم إيقاف حسابك', 'تم إيقاف حسابك مؤقتاً — تواصل مع الإدارة.');

        return $technician;
    }

    /** Ban outright and take offline (terminal per the status lifecycle); open flags resolved. */
    public function ban(Technician $technician, User $admin, ?string $note = null): Technician
    {
        $technician->update([
            'status' => TechnicianStatus::Banned,
            'is_available' => false,
        ]);
        $this->flagService->resolveOpenFor($technician->id, $admin, TechnicianFlagOutcome::Banned, $note);

        $this->notificationService->notify($technician->user, NotificationCategory::Admin, 'تم حظر حسابك', 'تم حظر حسابك على المنصة.');

        return $technician;
    }
}
