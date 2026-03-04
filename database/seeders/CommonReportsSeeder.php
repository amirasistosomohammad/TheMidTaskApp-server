<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Seeder;

/**
 * Seeds the 10 common reports from SYSTEM_CONCEPT.md.
 * Marked as is_common with common_report_no 1–10.
 * Idempotent: uses updateOrCreate so it can be run multiple times.
 */
class CommonReportsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $reports = [
            [
                'common_report_no' => 1,
                'name' => 'DepEd Partnerships Database System (DPDS)',
                'submission_date_rule' => '6th day of the month',
                'frequency' => Task::FREQUENCY_MONTHLY,
                'mov_description' => 'Scanned copy or screenshot',
                'action' => Task::ACTION_UPLOAD,
            ],
            [
                'common_report_no' => 2,
                'name' => 'Supplementary Payroll (Division Paid)',
                'submission_date_rule' => '5th day of the month',
                'frequency' => Task::FREQUENCY_MONTHLY,
                'mov_description' => 'Not applicable',
                'action' => Task::ACTION_UPLOAD,
            ],
            [
                'common_report_no' => 3,
                'name' => 'Regular Payroll',
                'submission_date_rule' => '10th day of the month',
                'frequency' => Task::FREQUENCY_MONTHLY,
                'mov_description' => 'Scanned copy of Form 7',
                'action' => Task::ACTION_INPUT,
            ],
            [
                'common_report_no' => 4,
                'name' => 'School Form 4 (SF4)',
                'submission_date_rule' => null,
                'frequency' => Task::FREQUENCY_MONTHLY,
                'mov_description' => 'Scanned copy of duly signed document and/or screenshot',
                'action' => Task::ACTION_UPLOAD,
            ],
            [
                'common_report_no' => 5,
                'name' => 'Electronic School Form 7 (eSF7)',
                'submission_date_rule' => null,
                'frequency' => 'once_or_twice_a_year',
                'mov_description' => 'Scanned copy (PDF) of duly signed document',
                'action' => Task::ACTION_UPLOAD,
            ],
            [
                'common_report_no' => 6,
                'name' => 'Wash In Schools (WinS) Report',
                'submission_date_rule' => null,
                'frequency' => Task::FREQUENCY_YEARLY,
                'mov_description' => 'Duly signed document – scanned copy and/or screenshot',
                'action' => Task::ACTION_UPLOAD,
            ],
            [
                'common_report_no' => 7,
                'name' => 'GESP, GJHSP, GSHSP (School Profiles)',
                'submission_date_rule' => 'End of school year (SY)',
                'frequency' => Task::FREQUENCY_END_OF_SY,
                'mov_description' => 'Scanned copy of duly signed validation sheet',
                'action' => Task::ACTION_UPLOAD,
            ],
            [
                'common_report_no' => 8,
                'name' => 'Inventory Count Form (ICF)',
                'submission_date_rule' => 'June & December',
                'frequency' => Task::FREQUENCY_TWICE_A_YEAR,
                'mov_description' => 'Signed validation sheet – scanned copy',
                'action' => Task::ACTION_UPLOAD,
            ],
            [
                'common_report_no' => 9,
                'name' => 'National School Building Inventory (NSBI)',
                'submission_date_rule' => null,
                'frequency' => Task::FREQUENCY_YEARLY,
                'mov_description' => 'Scanned copy of duly signed document',
                'action' => Task::ACTION_UPLOAD,
            ],
            [
                'common_report_no' => 10,
                'name' => 'Liquidation Reports',
                'submission_date_rule' => null,
                'frequency' => Task::FREQUENCY_QUARTERLY,
                'mov_description' => 'Image or scanned copy of cash disbursement report (liquidated status)',
                'action' => Task::ACTION_UPLOAD,
            ],
        ];

        foreach ($reports as $report) {
            Task::updateOrCreate(
                ['common_report_no' => $report['common_report_no']],
                [
                    'name' => $report['name'],
                    'submission_date_rule' => $report['submission_date_rule'],
                    'frequency' => $report['frequency'],
                    'mov_description' => $report['mov_description'],
                    'action' => $report['action'],
                    'is_common' => true,
                ]
            );
        }
    }
}
