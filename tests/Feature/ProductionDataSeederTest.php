<?php

namespace Tests\Feature;

use Database\Seeders\ProductionDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_installs_idempotent_production_reference_data(): void
    {
        $this->seed(ProductionDataSeeder::class);
        $this->seed(ProductionDataSeeder::class);

        $this->assertDatabaseCount('territories', 3);
        $this->assertDatabaseCount('categories', 5);
        $this->assertDatabaseCount('users', 0);
    }
}
