<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Sucursal '.fake('es_MX')->unique()->city(),
            'city' => fake('es_MX')->city(),
        ];
    }
}
