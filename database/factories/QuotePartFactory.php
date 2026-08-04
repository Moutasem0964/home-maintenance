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
            'image_url' => fake()->imageUrl(),
        ];
    }
}
