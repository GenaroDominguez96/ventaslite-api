<?php

namespace Database\Factories;

use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SaleItem> */
class SaleItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake('es_MX')->numberBetween(1, 6);

        return [
            'sale_id' => 1,
            'product_id' => 1,
            'quantity' => $quantity,
            'unit_price' => '100.00',
            'line_total' => (string) ($quantity * 100).'.00',
        ];
    }
}
