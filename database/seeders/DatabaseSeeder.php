<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Default Central Administrative Officer account for THE MID-TASK APP.
        User::updateOrCreate(
            ['email' => 'central.admin@midtaskapp.com'],
            [
                'name' => 'Central Administrative Officer',
                'password' => '123456', // hashed via User::$casts
                'role' => 'central_admin',
                'status' => 'active',
            ]
        );

        // Test School Head account (for testing Validations, Task history, etc.)
        User::updateOrCreate(
            ['email' => 'school.head@midtaskapp.com'],
            [
                'name' => 'School Head Test',
                'password' => 'password123',
                'role' => 'school_head',
                'status' => 'active',
                'email_verified_at' => now(),
                'employee_id' => 'EMP-SH-001',
                'position' => 'School Head',
                'division' => 'Midsalip District',
                'school_name' => 'Midsalip Central School',
            ]
        );

        // Pending-approval users for testing Account approvals UI. Disable by commenting out the line below.
        $this->call(PendingApprovalsSeeder::class);

        // Personnel (active, rejected, inactive) for testing Personnel Directory UI. Disable by commenting out the line below.
        $this->call(PersonnelDirectorySeeder::class);

        // 10 common reports from SYSTEM_CONCEPT.md. Idempotent.
        $this->call(CommonReportsSeeder::class);
    }
}
