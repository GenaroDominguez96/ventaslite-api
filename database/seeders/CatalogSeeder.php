<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            ['name' => 'Sucursal Centro', 'city' => 'Toluca'],
            ['name' => 'Sucursal Norte', 'city' => 'Ciudad de México'],
            ['name' => 'Sucursal Bajío', 'city' => 'Querétaro'],
            ['name' => 'Sucursal Occidente', 'city' => 'Guadalajara'],
        ];

        foreach ($branches as $branch) {
            Branch::updateOrCreate(
                ['name' => $branch['name'], 'city' => $branch['city']],
                $branch
            );
        }

        $categoryNames = [
            'Abarrotes',
            'Bebidas',
            'Limpieza',
            'Cuidado personal',
            'Papelería',
            'Electrónica',
            'Hogar',
            'Alimentos',
        ];

        foreach ($categoryNames as $name) {
            Category::firstOrCreate(['name' => $name]);
        }

        $categoryIds = Category::query()->orderBy('id')->pluck('id')->all();

        for ($index = 1; $index <= 150; $index++) {
            $product = Product::factory()->make([
                'sku' => sprintf('VLT-%04d', $index),
                'category_id' => $categoryIds[($index - 1) % count($categoryIds)],
                'active' => $index > 15,
            ]);

            Product::updateOrCreate(
                ['sku' => $product->sku],
                $product->only([
                    'name',
                    'category_id',
                    'price',
                    'cost',
                    'stock',
                    'active',
                ])
            );
        }

        for ($index = 1; $index <= 8; $index++) {
            $email = sprintf('vendedor%02d@ventaslite.test', $index);
            $user = User::factory()->make([
                'name' => fake('es_MX')->name(),
                'email' => $email,
                'password' => 'password',
                'role' => 'vendedor',
            ]);

            User::updateOrCreate(
                ['email' => $email],
                $user->only(['name', 'password', 'role'])
            );
        }
    }
}
