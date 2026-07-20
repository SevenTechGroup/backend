<?php

namespace Database\Seeders;

use App\Models\Territory;
use Illuminate\Database\Seeder;

class TerritorySeeder extends Seeder
{
    public function run(): void
    {
        $territories = [
            ['name' => 'Dakar', 'code' => 'DKR'],
            ['name' => 'Guédiawaye', 'code' => 'GWD'],
            ['name' => 'Pikine', 'code' => 'PKN'],
        ];

        foreach ($territories as $territory) {
            Territory::updateOrCreate(
                ['code' => $territory['code']],
                [...$territory, 'is_active' => true],
            );
        }
    }
}
