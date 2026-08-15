<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    /**
     * Category name => Material Design Icons (mdi) icon name. Served as SVG by the
     * Iconify public CDN (open source, no auth) so the frontend can render each
     * category's icon straight from icon_url. See iconUrl().
     */
    public const ICONS = [
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

    public function run(): void
    {
        $tree = [
            'كهرباء' => ['تمديدات', 'أعطال كهربائية', 'إنارة'],
            'سباكة' => ['تسريب مياه', 'تركيب أدوات صحية', 'تسليك'],
            'تكييف وتبريد' => ['تركيب مكيف', 'صيانة مكيف', 'تعبئة غاز'],
            'أجهزة منزلية' => ['غسالات', 'برادات', 'أفران'],
        ];

        foreach ($tree as $parent => $children) {
            $parentCategory = ServiceCategory::firstOrCreate(
                ['name' => $parent, 'parent_id' => null],
                ['is_active' => true, 'guide_price' => 100],
            );
            // Always refresh the icon so a re-seed replaces older placeholders.
            $parentCategory->update(['icon_url' => self::iconUrl($parent)]);

            foreach ($children as $child) {
                $childCategory = ServiceCategory::firstOrCreate(
                    ['name' => $child, 'parent_id' => $parentCategory->id],
                    ['is_active' => true, 'guide_price' => 100],
                );
                $childCategory->update(['icon_url' => self::iconUrl($child)]);
            }
        }
    }

    /** Public Iconify SVG URL for a category name (falls back to a generic tool icon). */
    public static function iconUrl(string $name): string
    {
        $icon = self::ICONS[$name] ?? 'wrench';

        return "https://api.iconify.design/mdi/{$icon}.svg";
    }
}
