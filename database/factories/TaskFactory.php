<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->realText(30),
            'description' => fake()->realText(120),
            'status' => TaskStatus::Todo->value,
            'due_date' => fake()->dateTimeBetween('-1 week', '+3 weeks')->format('Y-m-d'),
            'completed_at' => null,
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Done->value,
            'completed_at' => now(),
        ]);
    }
}
