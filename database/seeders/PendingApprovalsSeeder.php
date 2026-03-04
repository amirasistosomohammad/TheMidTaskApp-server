<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds pending-approval users for testing the Account approvals UI.
 * Comment out or remove the call in DatabaseSeeder to disable.
 */
class PendingApprovalsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pending = [
            [
                'name' => 'Maria Santos',
                'email' => 'maria.santos@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-001',
                'position' => 'Administrative Officer I',
                'division' => 'Curriculum Implementation Division',
                'school_name' => 'Midsalip Central School',
            ],
            [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan.delacruz@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-002',
                'position' => 'Administrative Officer II',
                'division' => 'School Governance and Operations',
                'school_name' => 'Dumingag National High School',
            ],
            [
                'name' => 'Ana Reyes',
                'email' => 'ana.reyes@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-003',
                'position' => 'Administrative Assistant',
                'division' => 'Planning and Research',
                'school_name' => 'Ramon Magsaysay Central Elementary School',
            ],
            [
                'name' => 'Carlos Mendoza',
                'email' => 'carlos.mendoza@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-004',
                'position' => 'Administrative Officer I',
                'division' => 'Human Resource Development',
                'school_name' => 'Labangan National High School',
            ],
            [
                'name' => 'Elena Torres',
                'email' => 'elena.torres@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-005',
                'position' => 'Administrative Officer II',
                'division' => 'Finance and Administration',
                'school_name' => 'Tukuran Central School',
            ],
            [
                'name' => 'Roberto Garcia',
                'email' => 'roberto.garcia@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-006',
                'position' => 'Administrative Officer I',
                'division' => 'Curriculum Implementation Division',
                'school_name' => 'Pagadian City Science High School',
            ],
            [
                'name' => 'Liza Fernandez',
                'email' => 'liza.fernandez@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-007',
                'position' => 'Administrative Officer II',
                'division' => 'School Governance and Operations',
                'school_name' => 'Zamboanga del Sur National High School',
            ],
            [
                'name' => 'Miguel Bautista',
                'email' => 'miguel.bautista@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-008',
                'position' => 'Administrative Assistant',
                'division' => 'Planning and Research',
                'school_name' => 'Aurora National High School',
            ],
            [
                'name' => 'Carmen Villanueva',
                'email' => 'carmen.villanueva@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-009',
                'position' => 'Administrative Officer I',
                'division' => 'Human Resource Development',
                'school_name' => 'Molave Vocational Technical School',
            ],
            [
                'name' => 'Fernando Ramos',
                'email' => 'fernando.ramos@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-010',
                'position' => 'Administrative Officer II',
                'division' => 'Finance and Administration',
                'school_name' => 'Dipolog City National High School',
            ],
            [
                'name' => 'Patricia Cruz',
                'email' => 'patricia.cruz@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-011',
                'position' => 'Administrative Officer I',
                'division' => 'Curriculum Implementation Division',
                'school_name' => 'Sindangan Central School',
            ],
            [
                'name' => 'Antonio Lim',
                'email' => 'antonio.lim@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-012',
                'position' => 'Administrative Assistant',
                'division' => 'School Governance and Operations',
                'school_name' => 'Liloy National High School',
            ],
            [
                'name' => 'Rosa Morales',
                'email' => 'rosa.morales@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-013',
                'position' => 'Administrative Officer II',
                'division' => 'Planning and Research',
                'school_name' => 'Osmeña Central Elementary School',
            ],
            [
                'name' => 'Eduardo Castillo',
                'email' => 'eduardo.castillo@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-014',
                'position' => 'Administrative Officer I',
                'division' => 'Human Resource Development',
                'school_name' => 'Leon B. Postigo National High School',
            ],
            [
                'name' => 'Teresa Ong',
                'email' => 'teresa.ong@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-015',
                'position' => 'Administrative Officer II',
                'division' => 'Finance and Administration',
                'school_name' => 'Siayan National High School',
            ],
            [
                'name' => 'Ricardo Dizon',
                'email' => 'ricardo.dizon@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-016',
                'position' => 'Administrative Officer I',
                'division' => 'Curriculum Implementation Division',
                'school_name' => 'Salug Valley National High School',
            ],
            [
                'name' => 'Sandra Gutierrez',
                'email' => 'sandra.gutierrez@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-017',
                'position' => 'Administrative Assistant',
                'division' => 'School Governance and Operations',
                'school_name' => 'Godod Central School',
            ],
            [
                'name' => 'Jose Martinez',
                'email' => 'jose.martinez@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-018',
                'position' => 'Administrative Officer II',
                'division' => 'Planning and Research',
                'school_name' => 'Gutalac National High School',
            ],
            [
                'name' => 'Monica Reyes',
                'email' => 'monica.reyes@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-019',
                'position' => 'Administrative Officer I',
                'division' => 'Human Resource Development',
                'school_name' => 'Baliguian Central School',
            ],
            [
                'name' => 'Alberto Tan',
                'email' => 'alberto.tan@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-020',
                'position' => 'Administrative Officer II',
                'division' => 'Finance and Administration',
                'school_name' => 'Kalawit National High School',
            ],
            [
                'name' => 'Gloria Silva',
                'email' => 'gloria.silva@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-021',
                'position' => 'Administrative Officer I',
                'division' => 'Curriculum Implementation Division',
                'school_name' => 'Tampilisan Central School',
            ],
            [
                'name' => 'Victor Chavez',
                'email' => 'victor.chavez@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-022',
                'position' => 'Administrative Assistant',
                'division' => 'School Governance and Operations',
                'school_name' => 'Liloy Central Elementary School',
            ],
            [
                'name' => 'Dolores Navarro',
                'email' => 'dolores.navarro@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-023',
                'position' => 'Administrative Officer II',
                'division' => 'Planning and Research',
                'school_name' => 'Sergio Osmeña Sr. National High School',
            ],
            [
                'name' => 'Felipe Romero',
                'email' => 'felipe.romero@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-024',
                'position' => 'Administrative Officer I',
                'division' => 'Human Resource Development',
                'school_name' => 'Josefina Central School',
            ],
            [
                'name' => 'Rita Acosta',
                'email' => 'rita.acosta@deped.gov.ph',
                'password' => 'password123',
                'employee_id' => 'EMP-2024-025',
                'position' => 'Administrative Officer II',
                'division' => 'Finance and Administration',
                'school_name' => 'Siocon National High School',
            ],
        ];

        foreach ($pending as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => $data['password'],
                    'role' => 'administrative_officer',
                    'status' => 'pending_approval',
                    'email_verified_at' => now(),
                    'employee_id' => $data['employee_id'],
                    'position' => $data['position'],
                    'division' => $data['division'],
                    'school_name' => $data['school_name'],
                ]
            );
        }
    }
}
