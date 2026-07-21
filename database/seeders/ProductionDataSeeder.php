<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductionDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TerritorySeeder::class,
            CategorySeeder::class,
        ]);
    }
}
