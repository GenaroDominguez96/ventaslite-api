<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@ventaslite.com'],
            [
                'name' => 'Administrador',
                'password' => 'contraseña',
                'role' => 'admin',
            ]
        );

        $this->call([
            CatalogSeeder::class,
            SalesSeeder::class,
        ]);
    }
}
