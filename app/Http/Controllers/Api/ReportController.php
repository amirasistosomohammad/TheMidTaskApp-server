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
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
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
     * GET /api/reports/template-status — check if the Excel template exists in storage/app/reports/.
     * Use this to confirm the template is present in deployment (e.g. DigitalOcean App Platform).
     */
    public function templateStatus(): JsonResponse
    {
        $reportsDir = storage_path('app/reports');
        $found = null;
        foreach (self::TEMPLATE_FILENAMES as $filename) {
            $full = $reportsDir . DIRECTORY_SEPARATOR . $filename;
            if (file_exists($full)) {
                $found = $filename;
                break;
            }
        }

        return response()->json([
            'template_available' => $found !== null,
            'template_filename' => $found,
            'path_checked' => $reportsDir,
        ]);
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

        // Period: single string, full date range e.g. "March 1, 2026 - March 31, 2026"
        $periodLabel = (string) trim(sprintf(
            '%s - %s',
            date('F j, Y', strtotime($dateFrom)),
            date('F j, Y', strtotime($dateTo))
        ));

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

        // Unmerge header (C9–C11) and performance table rating/remarks cells (D–F, rows 16–19) so our fills take effect.
        $cellsToUnmerge = ['C9', 'C10', 'C11', 'D16', 'E16', 'F16', 'D17', 'E17', 'F17', 'D18', 'E18', 'F18', 'D19', 'E19', 'F19'];
        $rangesToRemerge = [];
        foreach ($sheet->getMergeCells() as $mergeRange) {
            foreach ($cellsToUnmerge as $cell) {
                if (Coordinate::coordinateIsInsideRange($mergeRange, $cell)) {
                    $rangesToRemerge[$mergeRange] = true;
                    break;
                }
            }
        }
        foreach (array_keys($rangesToRemerge) as $mergeRange) {
            $sheet->unmergeCells($mergeRange);
        }

        // NAME, SCHOOL, PERIOD COVERED — single source of truth: C9, C10, C11 only (no duplicate writes)
        $sheet->getCell('C9')->setValueExplicit((string) $subject->name, DataType::TYPE_STRING);
        $sheet->getCell('C10')->setValueExplicit((string) ($subject->school_name ?? '—'), DataType::TYPE_STRING);
        $sheet->getCell('C11')->setValueExplicit($periodLabel, DataType::TYPE_STRING);

        $valueCellStyle = [
            'font' => [
                'name' => 'Arial',
                'size' => 12,
                'bold' => false,
                'underline' => Font::UNDERLINE_SINGLE,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
        $sheet->getStyle('C9')->applyFromArray($valueCellStyle);
        $sheet->getStyle('C10')->applyFromArray($valueCellStyle);
        $sheet->getStyle('C11')->applyFromArray([
            'font' => $valueCellStyle['font'],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        // Re-merge so layout matches template
        foreach (array_keys($rangesToRemerge) as $mergeRange) {
            $sheet->mergeCells($mergeRange);
        }

        // Performance table: DO NOT MODIFY CRITERIA (column B). Fill Overall Rating and Remarks.
        // Overall Rating in D/E; Remarks may be in F or G. Fill both and set remarks as explicit string so they display.
        $sheet->setCellValue('D16', $timelinessScore);
        $sheet->setCellValue('E16', $timelinessScore);
        $sheet->getCell('F16')->setValueExplicit(self::adjectivalRating($timelinessScore), DataType::TYPE_STRING);
        $sheet->getCell('G16')->setValueExplicit(self::adjectivalRating($timelinessScore), DataType::TYPE_STRING);
        $sheet->setCellValue('D17', $qualityScore);
        $sheet->setCellValue('E17', $qualityScore);
        $sheet->getCell('F17')->setValueExplicit(self::adjectivalRating($qualityScore), DataType::TYPE_STRING);
        $sheet->getCell('G17')->setValueExplicit(self::adjectivalRating($qualityScore), DataType::TYPE_STRING);
        $sheet->setCellValue('D18', $complianceScore);
        $sheet->setCellValue('E18', $complianceScore);
        $sheet->getCell('F18')->setValueExplicit(self::adjectivalRating($complianceScore), DataType::TYPE_STRING);
        $sheet->getCell('G18')->setValueExplicit(self::adjectivalRating($complianceScore), DataType::TYPE_STRING);
        $sheet->setCellValue('D19', $weightedAvg);
        $sheet->setCellValue('E19', $weightedAvg);
        $sheet->getCell('F19')->setValueExplicit($weightedRemark, DataType::TYPE_STRING);
        $sheet->getCell('G19')->setValueExplicit($weightedRemark, DataType::TYPE_STRING);

        // Task counts: fill only value cells (labels stay in B21–B23). Write to C and D so data displays.
        $sheet->setCellValue('C21', $completedTasks);
        $sheet->setCellValue('D21', $completedTasks);
        $sheet->setCellValue('C22', $totalTasks);
        $sheet->setCellValue('D22', $totalTasks);
        $sheet->setCellValue('C23', $totalTasks > 0 ? $completionRatePct . '%' : '0%');
        $sheet->setCellValue('D23', $totalTasks > 0 ? $completionRatePct . '%' : '0%');

        $sheet->setCellValue('B42', $schoolHeadName);
        $sheet->setCellValue('B43', $schoolHeadPosition);

        try {
            // Unmerge cells above the name (B39–B41) so the signature image is not hidden by merged cells.
            $validatedByCells = ['B39', 'B40', 'B41', 'C39', 'C40', 'C41'];
            $validatedByMergesToUnmerge = [];
            foreach ($sheet->getMergeCells() as $mergeRange) {
                foreach ($validatedByCells as $cell) {
                    if (Coordinate::coordinateIsInsideRange($mergeRange, $cell)) {
                        $validatedByMergesToUnmerge[$mergeRange] = true;
                        break;
                    }
                }
            }
            foreach (array_keys($validatedByMergesToUnmerge) as $mergeRange) {
                $sheet->unmergeCells($mergeRange);
            }

            // If the designated school head has a digital signature, place it in B41 (directly above the name).
            if ($schoolHead) {
                $schoolHeadWithSignature = User::select(['id', 'name', 'position', 'signature_url'])->find($schoolHead->id);
                if ($schoolHeadWithSignature?->signature_url) {
                    $signaturePath = $this->resolveSignatureUrlToPath($schoolHeadWithSignature->signature_url);
                    if (! $signaturePath && $this->isAppUrl($schoolHeadWithSignature->signature_url)) {
                        $signaturePath = $this->fetchSignatureToTempFile($schoolHeadWithSignature->signature_url);
                    }
                    if ($signaturePath && is_readable($signaturePath)) {
                        $signaturePath = realpath($signaturePath) ?: $signaturePath;
                        $signaturePath = str_replace('\\', '/', $signaturePath);
                        $drawing = new Drawing();
                        $drawing->setName('SchoolHeadSignature');
                        $drawing->setPath($signaturePath);
                        $drawing->setCoordinates('B41');
                        $drawing->setWidth(100);
                        $drawing->setHeight(45);
                        $drawing->setWorksheet($sheet);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Do not fail report generation: skip signature/unmerge if anything throws (e.g. production path, template merge).
            report($e);
        }

        $dateTimeLabel = now(config('app.timezone'))->format('F j, Y g:i A');
        $sheet->setCellValue('C45', $dateTimeLabel);
        $sheet->setCellValue('D45', '');
        $sheet->getStyle('C45')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
        $dateTimeFont = $sheet->getStyle('C45')->getFont();
        $dateTimeFont->setUnderline(Font::UNDERLINE_SINGLE);
        $dateTimeFont->getColor()->setARGB(Color::COLOR_BLACK);
        $dateTimeFont->setUnderlineColor(['type' => 'srgbClr', 'value' => '000000']);

        // NUCLEAR FIX: Rebuild the entire BREAKDOWN ANALYSIS block from scratch. Do not depend on template merges or layout.
        $breakdownTitleRow = 50;
        $breakdownHeaderRow = 51;
        $breakdownStartRow = 52;
        $breakdownEndRow = 62;
        $breakdownCols = ['B', 'C', 'D', 'E', 'F', 'G'];

        // 1) Unmerge every cell in the block (rows 50–62, B–G)
        $cellsToUnmerge = [];
        for ($row = $breakdownTitleRow; $row <= $breakdownEndRow; $row++) {
            foreach ($breakdownCols as $col) {
                $cellsToUnmerge[] = $col . $row;
            }
        }
        $mergesToRemove = [];
        foreach ($sheet->getMergeCells() as $mergeRange) {
            foreach ($cellsToUnmerge as $cell) {
                if (Coordinate::coordinateIsInsideRange($mergeRange, $cell)) {
                    $mergesToRemove[$mergeRange] = true;
                    break;
                }
            }
        }
        foreach (array_keys($mergesToRemove) as $mergeRange) {
            $sheet->unmergeCells($mergeRange);
        }

        // 2) Wipe and rebuild: title row
        for ($col = 'B'; $col <= 'F'; $col++) {
            $sheet->setCellValue($col . $breakdownTitleRow, '');
        }
        $sheet->setCellValue('B' . $breakdownTitleRow, 'BREAKDOWN ANALYSIS');
        $sheet->mergeCells('B' . $breakdownTitleRow . ':F' . $breakdownTitleRow);
        $sheet->getStyle('B' . $breakdownTitleRow)->getFont()->setBold(true);
        $sheet->getStyle('B' . $breakdownTitleRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // 3) Headers row: NUCLEAR – NO template width change. Full header text via WRAP only: size 11, shrink OFF, wrap ON.
        $sheet->setCellValue('B' . $breakdownHeaderRow, 'Name of Task');
        $sheet->setCellValue('C' . $breakdownHeaderRow, 'Completed');
        $sheet->setCellValue('D' . $breakdownHeaderRow, 'No. of Task');
        $sheet->setCellValue('E' . $breakdownHeaderRow, 'Frequency');
        $sheet->setCellValue('F' . $breakdownHeaderRow, 'Percentage');
        $headerRange = 'B' . $breakdownHeaderRow . ':F' . $breakdownHeaderRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('B' . $breakdownHeaderRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true)->setShrinkToFit(false);
        $sheet->getStyle('C' . $breakdownHeaderRow . ':F' . $breakdownHeaderRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true)->setShrinkToFit(false);

        // 4) Data rows: normal size 11, blue, shrink OFF so content is never shrunk. Prefix Name with (1), (2), (3)...
        $breakdownRows = $this->breakdownRows($subject->id, $dateFrom, $dateTo);
        $centerAlignNoShrink = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'shrinkToFit' => false]];
        $leftAlignNoShrink = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'shrinkToFit' => false]];
        $blueFont = ['font' => ['color' => ['argb' => Color::COLOR_BLUE], 'size' => 11]];
        foreach ($breakdownRows as $i => $row) {
            $r = $breakdownStartRow + $i;
            $rowNum = $i + 1;
            $nameWithNumber = '(' . $rowNum . ') ' . $row['name'];
            $sheet->setCellValue('B' . $r, $nameWithNumber);
            $sheet->getStyle('B' . $r)->applyFromArray($leftAlignNoShrink);
            $sheet->getStyle('B' . $r)->applyFromArray($blueFont);
            $sheet->getStyle('B' . $r)->getFont()->setUnderline(Font::UNDERLINE_SINGLE);
            $sheet->getCell('C' . $r)->setValueExplicit($row['completed'], DataType::TYPE_NUMERIC);
            $sheet->getCell('D' . $r)->setValueExplicit($row['total'], DataType::TYPE_NUMERIC);
            $sheet->setCellValue('E' . $r, $row['frequency']);
            $sheet->getCell('F' . $r)->setValueExplicit($row['percentage'], DataType::TYPE_STRING);
            $sheet->getStyle('C' . $r . ':F' . $r)->applyFromArray($centerAlignNoShrink);
            $sheet->getStyle('C' . $r . ':F' . $r)->applyFromArray($blueFont);
        }

        // 5) Clear remaining data rows and column G (no spill)
        for ($r = $breakdownStartRow + count($breakdownRows); $r <= $breakdownEndRow; $r++) {
            foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $col) {
                $sheet->setCellValue($col . $r, '');
            }
        }
        for ($r = $breakdownTitleRow; $r <= $breakdownEndRow; $r++) {
            $sheet->setCellValue('G' . $r, '');
        }

        // 6) Table border: B50:F62 so the block is clearly one table
        $tableRange = 'B' . $breakdownTitleRow . ':F' . $breakdownEndRow;
        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // 7) Hide unused columns to the right of the table (G–J) so the sheet doesn’t look empty; template width unchanged
        foreach (['H', 'I', 'J'] as $col) {
            $sheet->getColumnDimension($col)->setVisible(false);
        }

        $filename = sprintf(
            'Performance_Report_%s_%s.xlsx',
            preg_replace('/[^a-z0-9]+/i', '_', $subject->name),
            date('Y-m-d')
        );

        // Final overwrite: period and task counts so export has correct values
        $sheet->getCell('C11')->setValueExplicit($periodLabel, DataType::TYPE_STRING);
        $sheet->setCellValue('C21', $completedTasks);
        $sheet->setCellValue('D21', $completedTasks);
        $sheet->setCellValue('C22', $totalTasks);
        $sheet->setCellValue('D22', $totalTasks);
        $sheet->setCellValue('C23', $totalTasks > 0 ? $completionRatePct . '%' : '0%');
        $sheet->setCellValue('D23', $totalTasks > 0 ? $completionRatePct . '%' : '0%');

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Resolve school head signature_url to local filesystem path.
     * Handles: full URL with /storage/signatures/..., path with app prefix (e.g. /themidtaskapp-server/storage/...), or signatures/...
     */
    private function resolveSignatureUrlToPath(string $signatureUrl): ?string
    {
        $pathPart = parse_url($signatureUrl, PHP_URL_PATH);
        $pathPart = is_string($pathPart) ? ltrim($pathPart, '/') : trim($signatureUrl, '/');
        $relativePath = null;
        // Match .../storage/signatures/... or storage/signatures/... (production may have path prefix)
        if (preg_match('#(?:^|/)storage/(.+)$#', $pathPart, $m)) {
            $relativePath = $m[1];
        } elseif (str_starts_with($pathPart, 'signatures/')) {
            $relativePath = $pathPart;
        } else {
            $basename = basename($pathPart);
            if (preg_match('/\.(png|jpe?g)$/i', $basename) && Storage::disk('public')->exists('signatures/' . $basename)) {
                $relativePath = 'signatures/' . $basename;
            }
        }
        if ($relativePath === null || ! Storage::disk('public')->exists($relativePath)) {
            return null;
        }

        return Storage::disk('public')->path($relativePath);
    }

    /**
     * True if the given URL belongs to this app (same host as APP_URL). Used to allow fetching signature for embedding.
     */
    private function isAppUrl(string $url): bool
    {
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);
        return $appHost !== false && $urlHost !== false && strtolower($appHost) === strtolower($urlHost);
    }

    /**
     * Fetch signature image from app URL to a temp file and return the path. Returns null on failure.
     */
    private function fetchSignatureToTempFile(string $signatureUrl): ?string
    {
        try {
            $context = stream_context_create([
                'http' => ['timeout' => 5],
                'ssl' => ['verify_peer' => true],
            ]);
            $data = @file_get_contents($signatureUrl, false, $context);
            if ($data === false || $data === '') {
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'sig');
            if ($tmp === false) {
                return null;
            }
            $ext = pathinfo(parse_url($signatureUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';
            $tmpFile = $tmp . '.' . $ext;
            rename($tmp, $tmpFile);
            if (file_put_contents($tmpFile, $data) === false) {
                @unlink($tmpFile);
                return null;
            }
            return $tmpFile;
        } catch (\Throwable $e) {
            return null;
        }
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
