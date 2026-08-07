<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use Database\Seeders\ServiceCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryPhotoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_populates_every_category_icon(): void
    {
        $this->seed(ServiceCategorySeeder::class);

        $this->assertTrue(
            ServiceCategory::whereNull('icon_url')->doesntExist(),
            'every seeded category should have an icon_url',
        );
    }
}
