<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class StatisticsMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'from_username' => $this->faker->userName(),
            'from_domain' => $this->faker->domainName(),
            'sent_at' => $this->faker->dateTimeBetween('-1 year'),
            'encrypted' => false
        ];
    }
}
