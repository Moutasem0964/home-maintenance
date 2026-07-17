<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use Illuminate\Database\Seeder;

/**
 * Tunable platform settings (SRS note 9: commission is configurable and
 * snapshotted into each order at creation time). Adjust values with the team.
 */
class AppSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'commission_rate',          'value' => '0.10', 'data_type' => 'decimal', 'description' => 'Platform commission, snapshotted into orders at creation'],
            ['key' => 'inspection_fee_default',   'value' => '50',   'data_type' => 'decimal', 'description' => 'Default inspection fee when the category does not override it'],
            ['key' => 'dispute_window_hours',     'value' => '48',   'data_type' => 'int',     'description' => 'Hours after closure during which the client may dispute'],
            ['key' => 'quote_expiry_hours',       'value' => '24',   'data_type' => 'int',     'description' => 'Hours before an unanswered quote expires'],
            ['key' => 'offer_timeout_seconds',    'value' => '90',   'data_type' => 'int',     'description' => 'Seconds a technician has to answer a dispatch offer'],
            ['key' => 'no_show_wait_minutes',     'value' => '15',   'data_type' => 'int',     'description' => 'Minutes the technician waits before no-show applies'],
            ['key' => 'closure_code_ttl_minutes', 'value' => '10',   'data_type' => 'int',     'description' => 'Closure code validity window'],
            ['key' => 'closure_max_attempts',     'value' => '5',    'data_type' => 'int',     'description' => 'Max failed closure-code attempts before lock'],
            ['key' => 'min_withdrawal_amount',    'value' => '100',  'data_type' => 'decimal', 'description' => 'Minimum technician withdrawal'],
            ['key' => 'price_anomaly_multiplier', 'value' => '2.0',  'data_type' => 'decimal', 'description' => 'FR-A2: alert when quote exceeds guide price by this factor'],
            ['key' => 'cancel_fee_share',         'value' => '0.30', 'data_type' => 'decimal', 'description' => 'Share of inspection fee kept for technician on late cancel'],
            ['key' => 'probation_daily_limit',    'value' => '3',    'data_type' => 'int',     'description' => 'Default daily order cap during technician probation'],
            ['key' => 'appointment_reminder_minutes', 'value' => '60', 'data_type' => 'int',   'description' => 'UC-26: reminder sent this long before the appointment'],
        ];

        foreach ($settings as $setting) {
            AppSetting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
