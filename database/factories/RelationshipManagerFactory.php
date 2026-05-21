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
        ];
    }
}
