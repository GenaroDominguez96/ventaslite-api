<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $priceCents = fake('es_MX')->numberBetween(5000, 250000);
        $costCents = fake('es_MX')->numberBetween(1000, $priceCents - 1);
        $toDecimal = static fn (int $cents): string => intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return [
            'sku' => strtoupper(fake('es_MX')->unique()->bothify('SKU-#####')),
            'name' => ucfirst(fake('es_MX')->words(3, true)),
            'category_id' => 1,
            'price' => $toDecimal($priceCents),
            'cost' => $toDecimal($costCents),
            'stock' => fake('es_MX')->numberBetween(10, 500),
            'active' => fake('es_MX')->boolean(90),
        ];
    }
}
