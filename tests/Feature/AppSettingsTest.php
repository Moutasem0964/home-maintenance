<?php

namespace Tests\Feature;

use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
    }

    public function test_returns_public_settings_without_auth_and_typed(): void
    {
        $this->getJson('/api/app-settings')
            ->assertOk()
            ->assertJsonPath('data.probation_daily_limit', 3)   // int, not "3"
            ->assertJsonPath('data.scheduled_max_days', 10);
    }

    public function test_excludes_internal_settings(): void
    {
        $this->getJson('/api/app-settings')
            ->assertOk()
            ->assertJsonMissingPath('data.price_anomaly_multiplier')
            ->assertJsonMissingPath('data.closure_max_attempts');
    }
}
