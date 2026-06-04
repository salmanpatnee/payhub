<?php

namespace Database\Factories;

use App\Models\RelationshipManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelationshipManager>
 */
class RelationshipManagerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the relationship manager is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
