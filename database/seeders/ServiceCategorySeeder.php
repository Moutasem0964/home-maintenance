<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            'كهرباء' => ['تمديدات', 'أعطال كهربائية', 'إنارة'],
            'سباكة' => ['تسريب مياه', 'تركيب أدوات صحية', 'تسليك'],
            'تكييف وتبريد' => ['تركيب مكيف', 'صيانة مكيف', 'تعبئة غاز'],
            'أجهزة منزلية' => ['غسالات', 'برادات', 'أفران'],
        ];

        $seed = 0;

        foreach ($tree as $parent => $children) {
            $parentCategory = ServiceCategory::firstOrCreate(
                ['name' => $parent, 'parent_id' => null],
                ['is_active' => true, 'guide_price' => 100, 'icon_url' => $this->icon(++$seed)],
            );
            $this->ensureIcon($parentCategory, $seed);

            foreach ($children as $child) {
                $childCategory = ServiceCategory::firstOrCreate(
                    ['name' => $child, 'parent_id' => $parentCategory->id],
                    ['is_active' => true, 'guide_price' => 100, 'icon_url' => $this->icon(++$seed)],
                );
                $this->ensureIcon($childCategory, $seed);
            }
        }
    }

    private function icon(int $seed): string
    {
        return "https://picsum.photos/seed/service-{$seed}/300/200";
    }

    /** Backfill the icon on rows that already existed before icons were seeded. */
    private function ensureIcon(ServiceCategory $category, int $seed): void
    {
        if ($category->icon_url === null) {
            $category->update(['icon_url' => $this->icon($seed)]);
        }
    }
}
