<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;

class AppSettingController extends Controller
{
    /**
     * The subset of platform settings the frontend needs (typed). Internal/sensitive
     * keys (anomaly multipliers, closure attempt caps, ...) are deliberately excluded.
     */
    private const PUBLIC_KEYS = [
        'inspection_fee_default',
        'offer_timeout_seconds',
        'no_show_wait_minutes',
        'closure_code_ttl_minutes',
        'dispute_window_hours',
        'quote_expiry_hours',
        'appointment_duration_minutes',
        'appointment_reminder_minutes',
        'cancel_fee_share',
        'probation_daily_limit',
        'promotion_min_orders',
        'promotion_min_rating',
        'min_withdrawal_amount',
        'scheduled_max_days',
        'arrival_radius_meters',
        'parts_wait_max_hours',
    ];

    public function index(): JsonResponse
    {
        $settings = [];
        foreach (self::PUBLIC_KEYS as $key) {
            $settings[$key] = AppSetting::get($key);
        }

        return response()->json(['data' => $settings]);
    }
}
