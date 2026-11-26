<?php

namespace Database\Factories;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sale> */
class SaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'folio' => strtoupper(fake('es_MX')->unique()->bothify('VLT-########')),
            'user_id' => 1,
            'branch_id' => 1,
            'customer_name' => fake('es_MX')->name(),
            'subtotal' => '0.00',
            'tax' => '0.00',
            'total' => '0.00',
            'payment_method' => fake('es_MX')->randomElement(['efectivo', 'tarjeta', 'transferencia']),
            'status' => 'pagada',
            'sold_at' => fake('es_MX')->dateTimeBetween('-18 months', 'now'),
        ];
    }
}
