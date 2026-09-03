<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name'        => fake()->words(2, true),
            'price'       => fake()->numberBetween(100, 50000),
            'description' => fake()->sentence(),
            'stock'       => fake()->numberBetween(1, 20),
            'category'    => fake()->randomElement(Product::CATEGORIES),
        ];
    }
}
