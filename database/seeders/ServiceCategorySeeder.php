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

        foreach ($tree as $parent => $children) {
            $parentCategory = ServiceCategory::firstOrCreate(
                ['name' => $parent, 'parent_id' => null],
                ['is_active' => true, 'guide_price' => 100],
            );

            foreach ($children as $child) {
                ServiceCategory::firstOrCreate(
                    ['name' => $child, 'parent_id' => $parentCategory->id],
                    ['is_active' => true, 'guide_price' => 100],
                );
            }
        }
    }
}
