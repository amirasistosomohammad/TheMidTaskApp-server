<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds personnel (active, rejected, inactive) for testing the Personnel Directory UI.
 * Uses pravatar.cc - unique avatar per ID (101-122), guarantees different face per person.
 * Comment out or remove the call in DatabaseSeeder to disable.
 */
class PersonnelDirectorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $personnel = [
            [
                'name' => 'Maria Santos',
                'email' => 'maria.santos.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=101',
                'employee_id' => 'EMP-2024-101',
                'position' => 'Administrative Officer I',
                'division' => 'Curriculum Implementation Division',
                'school_name' => 'Midsalip Central School',
                'status' => 'active',
            ],
            [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan.delacruz.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=102',
                'employee_id' => 'EMP-2024-102',
                'position' => 'Administrative Officer II',
                'division' => 'School Governance and Operations',
                'school_name' => 'Dumingag National High School',
                'status' => 'active',
            ],
            [
                'name' => 'Ana Reyes',
                'email' => 'ana.reyes.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=103',
                'employee_id' => 'EMP-2024-103',
                'position' => 'Administrative Assistant',
                'division' => 'Planning and Research',
                'school_name' => 'Ramon Magsaysay Central Elementary School',
                'status' => 'active',
            ],
            [
                'name' => 'Carlos Mendoza',
                'email' => 'carlos.mendoza.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=104',
                'employee_id' => 'EMP-2024-104',
                'position' => 'Administrative Officer I',
                'division' => 'Human Resource Development',
                'school_name' => 'Labangan National High School',
                'status' => 'rejected',
            ],
            [
                'name' => 'Elena Torres',
                'email' => 'elena.torres.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=105',
                'employee_id' => 'EMP-2024-105',
                'position' => 'Administrative Officer II',
                'division' => 'Finance and Administration',
                'school_name' => 'Tukuran Central School',
                'status' => 'active',
            ],
            [
                'name' => 'Roberto Garcia',
                'email' => 'roberto.garcia.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=106',
                'employee_id' => 'EMP-2024-106',
                'position' => 'Administrative Officer I',
                'division' => 'Curriculum Implementation Division',
                'school_name' => 'Pagadian City Science High School',
                'status' => 'inactive',
            ],
            [
                'name' => 'Liza Fernandez',
                'email' => 'liza.fernandez.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=107',
                'employee_id' => 'EMP-2024-107',
                'position' => 'Administrative Officer II',
                'division' => 'School Governance and Operations',
                'school_name' => 'Zamboanga del Sur National High School',
                'status' => 'active',
            ],
            [
                'name' => 'Miguel Bautista',
                'email' => 'miguel.bautista.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=108',
                'employee_id' => 'EMP-2024-108',
                'position' => 'Administrative Assistant',
                'division' => 'Planning and Research',
                'school_name' => 'Aurora National High School',
                'status' => 'rejected',
            ],
            [
                'name' => 'Carmen Villanueva',
                'email' => 'carmen.villanueva.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=109',
                'employee_id' => 'EMP-2024-109',
                'position' => 'Administrative Officer I',
                'division' => 'Human Resource Development',
                'school_name' => 'Molave Vocational Technical School',
                'status' => 'active',
            ],
            [
                'name' => 'Fernando Ramos',
                'email' => 'fernando.ramos.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=110',
                'employee_id' => 'EMP-2024-110',
                'position' => 'Administrative Officer II',
                'division' => 'Finance and Administration',
                'school_name' => 'Dipolog City National High School',
                'status' => 'active',
            ],
            [
                'name' => 'Patricia Cruz',
                'email' => 'patricia.cruz.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=111',
                'employee_id' => 'EMP-2024-111',
                'position' => 'Administrative Officer I',
                'division' => 'Curriculum Implementation Division',
                'school_name' => 'Sindangan Central School',
                'status' => 'inactive',
            ],
            [
                'name' => 'Antonio Lim',
                'email' => 'antonio.lim.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=112',
                'employee_id' => 'EMP-2024-112',
                'position' => 'Administrative Assistant',
                'division' => 'School Governance and Operations',
                'school_name' => 'Liloy National High School',
                'status' => 'active',
            ],
            [
                'name' => 'Rosa Morales',
                'email' => 'rosa.morales.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=113',
                'employee_id' => 'EMP-2024-113',
                'position' => 'Administrative Officer II',
                'division' => 'Planning and Research',
                'school_name' => 'Osmeña Central Elementary School',
                'status' => 'rejected',
            ],
            [
                'name' => 'Eduardo Castillo',
                'email' => 'eduardo.castillo.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=114',
                'employee_id' => 'EMP-2024-114',
                'position' => 'Administrative Officer I',
                'division' => 'Human Resource Development',
                'school_name' => 'Leon B. Postigo National High School',
                'status' => 'active',
            ],
            [
                'name' => 'Teresa Ong',
                'email' => 'teresa.ong.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=115',
                'employee_id' => 'EMP-2024-115',
                'position' => 'Administrative Officer II',
                'division' => 'Finance and Administration',
                'school_name' => 'Siayan National High School',
                'status' => 'active',
            ],
            [
                'name' => 'Ricardo Dizon',
                'email' => 'ricardo.dizon.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=116',
                'employee_id' => 'EMP-2024-116',
                'position' => 'Administrative Officer I',
                'division' => 'Curriculum Implementation Division',
                'school_name' => 'Salug Valley National High School',
                'status' => 'inactive',
            ],
            [
                'name' => 'Sandra Gutierrez',
                'email' => 'sandra.gutierrez.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=117',
                'employee_id' => 'EMP-2024-117',
                'position' => 'Administrative Assistant',
                'division' => 'School Governance and Operations',
                'school_name' => 'Godod Central School',
                'status' => 'active',
            ],
            [
                'name' => 'Jose Martinez',
                'email' => 'jose.martinez.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=118',
                'employee_id' => 'EMP-2024-118',
                'position' => 'Administrative Officer II',
                'division' => 'Planning and Research',
                'school_name' => 'Gutalac National High School',
                'status' => 'rejected',
            ],
            [
                'name' => 'Monica Reyes',
                'email' => 'monica.reyes.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=119',
                'employee_id' => 'EMP-2024-119',
                'position' => 'Administrative Officer I',
                'division' => 'Human Resource Development',
                'school_name' => 'Baliguian Central School',
                'status' => 'active',
            ],
            [
                'name' => 'Alberto Tan',
                'email' => 'alberto.tan.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=120',
                'employee_id' => 'EMP-2024-120',
                'position' => 'Administrative Officer II',
                'division' => 'Finance and Administration',
                'school_name' => 'Kalawit National High School',
                'status' => 'active',
            ],
            [
                'name' => 'Gloria Silva',
                'email' => 'gloria.silva.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=121',
                'employee_id' => 'EMP-2024-121',
                'position' => 'Administrative Officer I',
                'division' => 'Curriculum Implementation Division',
                'school_name' => 'Tampilisan Central School',
                'status' => 'inactive',
            ],
            [
                'name' => 'Victor Chavez',
                'email' => 'victor.chavez.personnel@deped.gov.ph',
                'avatar_url' => 'https://i.pravatar.cc/400?u=122',
                'employee_id' => 'EMP-2024-122',
                'position' => 'Administrative Assistant',
                'division' => 'School Governance and Operations',
                'school_name' => 'Liloy Central Elementary School',
                'status' => 'active',
            ],
        ];

        foreach ($personnel as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => 'password123',
                    'role' => 'administrative_officer',
                    'status' => $data['status'],
                    'email_verified_at' => now(),
                    'employee_id' => $data['employee_id'],
                    'position' => $data['position'],
                    'division' => $data['division'],
                    'school_name' => $data['school_name'],
                    'avatar_url' => $data['avatar_url'],
                ]
            );

            if ($data['status'] === 'active') {
                $user->update(['approved_at' => now()->subDays(rand(5, 90))]);
            } elseif ($data['status'] === 'rejected') {
                $user->update([
                    'rejected_at' => now()->subDays(rand(3, 60)),
                    'rejection_remarks' => 'Incomplete documentation submitted.',
                ]);
            }
        }
    }
}
