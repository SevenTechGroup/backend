<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Déchets',
                'slug' => 'dechets',
                'severity' => 'medium',
                'description' => 'Dépôts sauvages, collecte et propreté des espaces publics.',
            ],
            [
                'name' => 'Voirie',
                'slug' => 'voirie',
                'severity' => 'medium',
                'description' => 'Chaussées, trottoirs, nids-de-poule et circulation locale.',
            ],
            [
                'name' => 'Éclairage public',
                'slug' => 'eclairage-public',
                'severity' => 'medium',
                'description' => 'Lampadaires en panne et zones publiques non éclairées.',
            ],
            [
                'name' => 'Eau et assainissement',
                'slug' => 'eau-assainissement',
                'severity' => 'high',
                'description' => 'Fuites, eau stagnante, drainage et assainissement.',
            ],
            [
                'name' => 'Santé animale',
                'slug' => 'sante-animale',
                'severity' => 'high',
                'description' => 'Animal malade ou mort dans un espace public.',
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['slug' => $category['slug']],
                [...$category, 'is_active' => true],
            );
        }
    }
}
