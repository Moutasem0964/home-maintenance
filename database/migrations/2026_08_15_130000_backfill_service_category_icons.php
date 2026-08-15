<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill service_categories.icon_url with meaningful Material Design icons
     * (served as SVG by the Iconify public CDN). Existing rows were seeded before
     * icons existed, so icon_url is null in production; this fixes them on deploy
     * without a manual seeder run. Idempotent — safe to re-run. Kept self-contained
     * (map frozen here) so it never drifts with the seeder.
     */
    private const ICONS = [
        'كهرباء' => 'flash',
        'تمديدات' => 'power-plug',
        'أعطال كهربائية' => 'flash-alert',
        'إنارة' => 'lightbulb',
        'سباكة' => 'pipe-wrench',
        'تسريب مياه' => 'pipe-leak',
        'تركيب أدوات صحية' => 'toilet',
        'تسليك' => 'pipe-disconnected',
        'تكييف وتبريد' => 'air-conditioner',
        'تركيب مكيف' => 'hvac',
        'صيانة مكيف' => 'air-filter',
        'تعبئة غاز' => 'gas-cylinder',
        'أجهزة منزلية' => 'dishwasher',
        'غسالات' => 'washing-machine',
        'برادات' => 'fridge',
        'أفران' => 'stove',
    ];

    public function up(): void
    {
        foreach (self::ICONS as $name => $icon) {
            DB::table('service_categories')
                ->where('name', $name)
                ->update(['icon_url' => "https://api.iconify.design/mdi/{$icon}.svg"]);
        }
    }

    public function down(): void
    {
        // No-op: icons are display data; reverting to null would only regress the UI.
    }
};
