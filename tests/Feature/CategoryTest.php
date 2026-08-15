<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use Database\Seeders\ServiceCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_is_public(): void
    {
        $this->getJson('/api/categories')->assertOk();
    }

    public function test_seeded_categories_expose_meaningful_icon_urls(): void
    {
        $this->seed(ServiceCategorySeeder::class);

        $response = $this->getJson('/api/categories')->assertOk();

        // Every parent and child carries a real Iconify SVG url, never null.
        foreach ($response->json('data') as $parent) {
            $this->assertStringStartsWith('https://api.iconify.design/mdi/', $parent['icon_url']);
            $this->assertStringEndsWith('.svg', $parent['icon_url']);

            foreach ($parent['children'] as $child) {
                $this->assertStringStartsWith('https://api.iconify.design/mdi/', $child['icon_url']);
            }
        }
    }

    public function test_lists_active_top_level_categories(): void
    {
        ServiceCategory::factory()->create(['name' => 'Electric']);
        ServiceCategory::factory()->create(['name' => 'Plumbing']);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_nests_active_children_under_parents(): void
    {
        $parent = ServiceCategory::factory()->create(['name' => 'Electric']);
        ServiceCategory::factory()->childOf($parent)->create(['name' => 'Wiring']);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.children.0.name', 'Wiring');
    }

    public function test_excludes_inactive_categories(): void
    {
        ServiceCategory::factory()->create(['name' => 'Visible']);
        ServiceCategory::factory()->inactive()->create(['name' => 'Hidden']);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Visible');
    }
}
