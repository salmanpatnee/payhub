<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BrandFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->company();
        return [
            'name'            => $name,
            'slug'            => Str::slug($name) . '-' . $this->faker->unique()->numerify('##'),
            'logo_path'       => null,
            'primary_color'   => strtoupper($this->faker->hexColor()),
            'secondary_color' => strtoupper($this->faker->hexColor()),
        ];
    }
}
