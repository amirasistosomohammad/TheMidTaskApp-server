<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Models\Submission;
use App\Models\Validation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Performance report (MIDTAS Excel): generate from stored template.
 * Available to Administrative Officers (own report) and School Heads (report for supervised AO).
 */
class ReportController extends Controller
{
    /** Template filename options (in case client sends MIDTAS vs MIDTASK). */
    private const TEMPLATE_FILENAMES = [
        'Sample Performance Report MIDTASK.xlsx',
        'Sample Performance Report MIDTAS.xlsx',
    ];

    /**
     * Adjectival rating from numeric score (1–5).
     */
    private static function adjectivalRating(float $score): string
    {
        if ($score >= 4.50) {
            return 'Outstanding';
        }
        if ($score >= 3.50) {
            return 'Very Satisfactory';
        }
        if ($score >= 2.50) {
            return 'Satisfactory';
        }
        if ($score >= 1.50) {
            return 'Unsatisfactory';
        }
        return 'Unsatisfactory';
    }

    /**
     * Scale a percentage (0–100) to 1–5 score (Option A derived metrics).
     */
    private static function percentToScore(float $pct): float
    {
        if ($pct >= 100) {
            return 5.0;
        }
        if ($pct <= 0) {
            return 1.0;
        }
        $score = 1.0 + ($pct / 100.0) * 4.0;
        return round($score, 2);
    }

    /**
     * GET /api/reports/performance-report?date_from=Y-m-d&date_to=Y-m-d
     *     &ao_id= (school head only: which AO)
     * Returns Excel file download.
     */
    public function performanceReport(Request $request): StreamedResponse|Response|JsonResponse
    {
        // Report generation can be slow (template load, queries, Excel write). Avoid 504 in deployment.
        set_time_limit(120);
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '256M');
        }

        $user = $request->user();
        $role = $user->role;

        if (! in_array($role, ['administrative_officer', 'school_head'], true)) {
            abort(403, 'Access denied. Reports are available to Personnel and School Heads only.');
        }

        $valid = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'ao_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $dateFrom = $valid['date_from'];
        $dateTo = $valid['date_to'];

        if ($role === 'administrative_officer') {
            $subject = $user;
        } else {
            $aoId = $valid['ao_id'] ?? null;
            if (! $aoId) {
                return response()->json(['message' => 'Please select a personnel (Administrative Officer) to generate the report for.'], 422);
            }
            $subject = User::find($aoId);
            if (! $subject || $subject->role !== 'administrative_officer') {
                return response()->json(['message' => 'Selected user is not an Administrative Officer.'], 422);
            }
            $supervisedIds = $user->supervisedAdministrativeOfficers()->pluck('users.id')->all();
            if (! in_array((int) $aoId, $supervisedIds, true)) {
                abort(403, 'You can only generate reports for personnel assigned to you.');
            }
        }

        // Look in storage/app/reports/ (not the default disk root storage/app/private)
        $reportsDir = storage_path('app/reports');
        $templateFullPath = null;
        foreach (self::TEMPLATE_FILENAMES as $filename) {
            $full = $reportsDir . DIRECTORY_SEPARATOR . $filename;
            if (file_exists($full)) {
                $templateFullPath = $full;
                break;
            }
        }

        if (! $templateFullPath) {
            return response()->json([
                'message' => 'Report template not found. Please place the Excel template (e.g. "Sample Performance Report MIDTASK.xlsx") in storage/app/reports/.',
                'path_checked' => $reportsDir,
            ], 503);
        }

        try {
            $spreadsheet = IOFactory::load($templateFullPath);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Report template could not be loaded. Please check the file format.',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 503);
        }

        $sheet = $spreadsheet->getActiveSheet();

        $periodLabel = sprintf('%s - %s', date('F j, Y', strtotime($dateFrom)), date('F j, Y', strtotime($dateTo)));

        $completedStatuses = [UserTask::STATUS_COMPLETED, UserTask::STATUS_SUBMITTED];

        $baseQuery = UserTask::query()
            ->where('user_id', $subject->id)
            ->whereDate('due_date', '>=', $dateFrom)
            ->whereDate('due_date', '<=', $dateTo);

        $totalTasks = (clone $baseQuery)->count();
        $completedTasks = (clone $baseQuery)->whereIn('status', $completedStatuses)->count();

        $completionRatePct = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 2) : 0;

        $timelinessPct = $this->timelinessPercent($subject->id, $dateFrom, $dateTo);
        $qualityPct = $this->qualityPercent($subject->id, $dateFrom, $dateTo);
        $compliancePct = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 2) : 0;

        $timelinessScore = self::percentToScore($timelinessPct);
        $qualityScore = self::percentToScore($qualityPct);
        $complianceScore = self::percentToScore($compliancePct);

        $weightedAvg = round(($timelinessScore + $qualityScore + $complianceScore) / 3, 2);
        $weightedRemark = self::adjectivalRating($weightedAvg);

        $schoolHead = $subject->supervisingSchoolHeads()->first();
        $schoolHeadName = $schoolHead?->name ?? '—';
        $schoolHeadPosition = $schoolHead?->position ?? '—';

        $sheet->setCellValue('C9', $subject->name);
        $sheet->setCellValue('C10', $subject->school_name ?? '—');
        $sheet->setCellValue('C11', $periodLabel);

        $sheet->setCellValue('B16', $timelinessScore);
        $sheet->setCellValue('C16', self::adjectivalRating($timelinessScore));
        $sheet->setCellValue('B17', $qualityScore);
        $sheet->setCellValue('C17', self::adjectivalRating($qualityScore));
        $sheet->setCellValue('B18', $complianceScore);
        $sheet->setCellValue('C18', self::adjectivalRating($complianceScore));
        $sheet->setCellValue('B19', $weightedAvg);
        $sheet->setCellValue('C19', $weightedRemark);

        $sheet->setCellValue('C21', $completedTasks);
        $sheet->setCellValue('C22', $totalTasks);
        $sheet->setCellValue('C23', $totalTasks > 0 ? $completionRatePct . '%' : '0%');

        $sheet->setCellValue('B42', $schoolHeadName);
        $sheet->setCellValue('B43', $schoolHeadPosition);
        $sheet->setCellValue('D45', now()->format('F j, Y g:i A'));

        $breakdownRows = $this->breakdownRows($subject->id, $dateFrom, $dateTo);
        $breakdownStartRow = 52;
        foreach ($breakdownRows as $i => $row) {
            $r = $breakdownStartRow + $i;
            $sheet->setCellValue('B' . $r, $row['name']);
            $sheet->setCellValue('C' . $r, $row['completed']);
            $sheet->setCellValue('D' . $r, $row['total']);
            $sheet->setCellValue('E' . $r, $row['frequency']);
            $sheet->setCellValue('F' . $r, $row['percentage']);
        }
        for ($r = $breakdownStartRow + count($breakdownRows); $r <= $breakdownStartRow + 10; $r++) {
            $sheet->setCellValue('B' . $r, '');
            $sheet->setCellValue('C' . $r, '');
            $sheet->setCellValue('D' . $r, '');
            $sheet->setCellValue('E' . $r, '');
            $sheet->setCellValue('F' . $r, '');
        }

        $filename = sprintf(
            'Performance_Report_%s_%s.xlsx',
            preg_replace('/[^a-z0-9]+/i', '_', $subject->name),
            date('Y-m-d')
        );

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Timeliness: % of completed/submitted tasks in period that were completed on or before due date.
     * Uses a single aggregate query to avoid loading all rows (prevents timeout on large ranges).
     */
    private function timelinessPercent(int $userId, string $dateFrom, string $dateTo): float
    {
        $completedStatuses = [UserTask::STATUS_COMPLETED, UserTask::STATUS_SUBMITTED];

        $totalCompleted = UserTask::query()
            ->where('user_id', $userId)
            ->whereDate('due_date', '>=', $dateFrom)
            ->whereDate('due_date', '<=', $dateTo)
            ->whereIn('status', $completedStatuses)
            ->count();

        if ($totalCompleted === 0) {
            return 0.0;
        }

        $onTimeCount = UserTask::query()
            ->where('user_id', $userId)
            ->whereDate('due_date', '>=', $dateFrom)
            ->whereDate('due_date', '<=', $dateTo)
            ->whereIn('status', $completedStatuses)
            ->whereNotNull('completed_at')
            ->whereRaw('DATE(completed_at) <= due_date')
            ->count();

        return round(($onTimeCount / $totalCompleted) * 100, 2);
    }

    /**
     * Quality: % of submissions (in period) that have an approved validation.
     * Uses count queries only—no loading of submission collections (prevents timeout).
     */
    private function qualityPercent(int $userId, string $dateFrom, string $dateTo): float
    {
        $submissionCount = Submission::query()
            ->whereHas('userTask', function ($q) use ($userId, $dateFrom, $dateTo) {
                $q->where('user_id', $userId)
                    ->whereDate('due_date', '>=', $dateFrom)
                    ->whereDate('due_date', '<=', $dateTo);
            })
            ->count();

        if ($submissionCount === 0) {
            return 0.0;
        }

        $approvedCount = Validation::query()
            ->where('status', 'approved')
            ->whereHas('submission.userTask', function ($q) use ($userId, $dateFrom, $dateTo) {
                $q->where('user_id', $userId)
                    ->whereDate('due_date', '>=', $dateFrom)
                    ->whereDate('due_date', '<=', $dateTo);
            })
            ->count();

        return round(($approvedCount / $submissionCount) * 100, 2);
    }

    /**
     * Task breakdown: one row per task with completed/total and frequency.
     * Uses a single grouped query instead of loading all user_tasks (prevents timeout).
     *
     * @return array<int, array{name: string, completed: int, total: int, frequency: string, percentage: string}>
     */
    private function breakdownRows(int $userId, string $dateFrom, string $dateTo): array
    {
        $frequencyLabels = [
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'yearly' => 'Yearly',
            'twice_a_year' => 'Twice a year',
            'end_of_sy' => 'End of SY',
            'every_two_months' => 'Every 2 months',
            'one_time' => 'One time',
        ];

        $statusList = "'" . UserTask::STATUS_COMPLETED . "','" . UserTask::STATUS_SUBMITTED . "'";
        $rows = UserTask::query()
            ->where('user_tasks.user_id', $userId)
            ->whereDate('user_tasks.due_date', '>=', $dateFrom)
            ->whereDate('user_tasks.due_date', '<=', $dateTo)
            ->join('tasks', 'tasks.id', '=', 'user_tasks.task_id')
            ->select([
                'tasks.id as task_id',
                'tasks.name as task_name',
                'tasks.frequency',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN user_tasks.status IN ({$statusList}) THEN 1 ELSE 0 END) as completed"),
            ])
            ->groupBy('tasks.id', 'tasks.name', 'tasks.frequency')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $total = (int) $row->total;
            $completed = (int) $row->completed;
            $pct = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
            $freq = $frequencyLabels[$row->frequency ?? ''] ?? $row->frequency ?? '—';
            $result[] = [
                'name' => $row->task_name,
                'completed' => $completed,
                'total' => $total,
                'frequency' => $freq,
                'percentage' => $pct . '%',
            ];
        }

        usort($result, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $result;
    }
}
