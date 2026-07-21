<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\ProductionDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class ProductionDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_idempotent_operational_demo_data_for_all_roles(): void
    {
        $passwords = $this->configurePasswords();

        $this->seed(ProductionDemoSeeder::class);
        $this->seed(ProductionDemoSeeder::class);

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('reports', 6);
        $this->assertDatabaseCount('assignments', 4);
        $this->assertDatabaseCount('notifications', 7);

        $this->assertDatabaseHas('users', [
            'email' => 'manager.demo@sahelsignal.test',
            'role' => UserRole::Manager->value,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'intervenant.demo@sahelsignal.test',
            'role' => UserRole::Agent->value,
        ]);
        $this->assertSame(3, User::query()->where('role', UserRole::Citizen->value)->count());

        foreach ($passwords as $email => $password) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->assertTrue(Hash::check($password, $user->password));
        }

        $citizens = User::query()->where('role', UserRole::Citizen->value)->get();

        foreach ($citizens as $citizen) {
            $this->assertSame(2, Report::query()->whereBelongsTo($citizen)->count());
        }
    }

    public function test_it_refuses_to_seed_when_a_password_is_missing(): void
    {
        $this->configurePasswords();
        config()->set('production-demo.accounts.manager.password');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('manager');

        $this->seed(ProductionDemoSeeder::class);
    }

    /**
     * @return array<string, string>
     */
    private function configurePasswords(): array
    {
        $passwords = [
            'manager.demo@sahelsignal.test' => 'Manager-Test-Password-01!',
            'intervenant.demo@sahelsignal.test' => 'Intervenant-Test-Password-02!',
            'citoyen1.demo@sahelsignal.test' => 'Citizen-One-Test-Password-03!',
            'citoyen2.demo@sahelsignal.test' => 'Citizen-Two-Test-Password-04!',
            'citoyen3.demo@sahelsignal.test' => 'Citizen-Three-Test-Password-05!',
        ];

        config()->set('production-demo.accounts.manager.password', $passwords['manager.demo@sahelsignal.test']);
        config()->set('production-demo.accounts.intervenant.password', $passwords['intervenant.demo@sahelsignal.test']);
        config()->set('production-demo.accounts.citizen_1.password', $passwords['citoyen1.demo@sahelsignal.test']);
        config()->set('production-demo.accounts.citizen_2.password', $passwords['citoyen2.demo@sahelsignal.test']);
        config()->set('production-demo.accounts.citizen_3.password', $passwords['citoyen3.demo@sahelsignal.test']);

        return $passwords;
    }
}
