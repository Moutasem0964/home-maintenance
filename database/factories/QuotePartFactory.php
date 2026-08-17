<?php

namespace Database\Factories;

use App\Enums\PartClassification;
use App\Models\Quote;
use App\Models\QuotePart;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuotePart> */
class QuotePartFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'name' => fake()->words(2, true),
            'price' => fake()->randomFloat(2, 5, 100),
            'classification' => PartClassification::Standard,
            // Now a private storage path (the resource builds the streaming URL from the id).
            'image_url' => 'quotes/'.fake()->numberBetween(1, 999).'/parts/'.fake()->uuid().'.jpg',
        ];
    }
}
