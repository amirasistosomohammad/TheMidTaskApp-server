<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BackupSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;

/**
 * Central Admin only: SQL backup (manual + scheduled) and schedule settings.
 * Uses mysqldump when available; falls back to PHP-based dump on Windows when mysqldump is not in PATH.
 */
class BackupController extends Controller
{
    private function ensureCentralAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'central_admin') {
            abort(403, 'Access denied. Central Administrative Officer only.');
        }
    }

    /**
     * Generate SQL backup. Tries mysqldump first; falls back to PHP dump if mysqldump is not available.
     * Returns ['sql' => string] or ['path' => string] when written to file.
     */
    public static function runMysqldump(bool $toFile = false, ?string $targetPath = null): array
    {
        $connection = Config::get('database.default');
        $driver = Config::get("database.connections.{$connection}.driver");

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('SQL backup is only supported for MySQL or MariaDB. Current driver: ' . $driver);
        }

        try {
            return self::runMysqldumpProcess($connection, $toFile, $targetPath);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'not recognized') || str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'command')) {
                return self::runPhpSqlDump($toFile, $targetPath);
            }
            throw $e;
        }
    }

    /**
     * Run mysqldump via Process (requires mysqldump in PATH or MYSQLDUMP_PATH in .env).
     */
    private static function runMysqldumpProcess(string $connection, bool $toFile, ?string $targetPath): array
    {
        $host = Config::get("database.connections.{$connection}.host");
        $port = Config::get("database.connections.{$connection}.port");
        $database = Config::get("database.connections.{$connection}.database");
        $username = Config::get("database.connections.{$connection}.username");
        $password = Config::get("database.connections.{$connection}.password");
        $mysqldumpPath = Config::get("database.connections.{$connection}.mysqldump_path", 'mysqldump');

        $args = [
            $mysqldumpPath,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $username,
            $database,
        ];

        $process = new Process($args);
        $process->setTimeout(3600);
        if ($password !== null && $password !== '') {
            $env = $process->getEnv();
            $process->setEnv(array_merge($env ?: [], ['MYSQL_PWD' => $password]));
        }

        $process->mustRun();
        $sql = $process->getOutput();

        if ($toFile && $targetPath) {
            $dir = dirname($targetPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($targetPath, $sql);
            return ['path' => $targetPath];
        }

        return ['sql' => $sql];
    }

    /**
     * PHP-based SQL dump fallback when mysqldump is not available (e.g. Windows without PATH).
     */
    private static function runPhpSqlDump(bool $toFile, ?string $targetPath): array
    {
        $sql = "-- MidTask SQL Backup (PHP fallback)\n";
        $sql .= "-- Generated: " . now()->toIso8601String() . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        $tables = DB::select('SHOW TABLES');

        foreach ($tables as $row) {
            $rowArr = (array) $row;
            $table = reset($rowArr);
            $create = DB::selectOne('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
            $createVals = array_values((array) $create);
            $createSql = $createVals[1] ?? '';
            $sql .= "DROP TABLE IF EXISTS `" . str_replace('`', '``', $table) . "`;\n";
            $sql .= $createSql . ";\n\n";

            $rows = DB::table($table)->get();
            if ($rows->isEmpty()) {
                continue;
            }

            $columns = array_keys((array) $rows->first());
            $colList = implode('`, `', array_map(fn ($c) => str_replace('`', '``', $c), $columns));
            $sql .= "INSERT INTO `" . str_replace('`', '``', $table) . "` (`" . $colList . "`) VALUES\n";

            $values = [];
            foreach ($rows as $r) {
                $rowArr = (array) $r;
                $vals = array_map(function ($v) {
                    if ($v === null) {
                        return 'NULL';
                    }
                    if (is_array($v) || is_object($v)) {
                        $v = json_encode($v);
                    }
                    return "'" . addslashes((string) $v) . "'";
                }, array_values($rowArr));
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $sql .= implode(",\n", $values) . ";\n\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        if ($toFile && $targetPath) {
            $dir = dirname($targetPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($targetPath, $sql);
            return ['path' => $targetPath];
        }

        return ['sql' => $sql];
    }

    /**
     * GET /api/admin/backup — generate SQL dump and return as file download (Central Admin only).
     */
    public function download(Request $request): Response|JsonResponse
    {
        $this->ensureCentralAdmin($request);

        try {
            $result = self::runMysqldump(false);
            $sql = $result['sql'];
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Backup failed: ' . $e->getMessage(),
            ], 500);
        }

        $filename = 'midtask-backup-' . now()->format('Y-m-d-His') . '.sql';

        ActivityLog::log(
            $request->user()->id,
            'backup_downloaded',
            'Downloaded manual SQL backup: ' . $filename,
            [
                'filename' => $filename,
                'bytes' => strlen($sql),
            ],
            $request
        );

        return response($sql, 200, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string) strlen($sql),
        ]);
    }

    /**
     * GET /api/admin/backup/schedule — get backup schedule (Central Admin only).
     */
    public function getSchedule(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $s = BackupSetting::get();
        $tz = BackupSetting::TIMEZONE_DEFAULT;
        if ($s->timezone !== $tz) {
            $s->timezone = $tz;
            $s->save();
        }

        // Normalize stored timestamps to UTC (older rows may have been saved as PH local time).
        if ($s->frequency !== BackupSetting::FREQUENCY_OFF) {
            $expectedNextPh = self::computeNextRunAt($s->frequency, $s->run_at_time, $tz);
            $expectedNextUtc = $expectedNextPh?->copy()->setTimezone('UTC');
            $currentNextUtc = $s->next_run_at?->copy()->setTimezone('UTC');
            if ($expectedNextUtc && (! $currentNextUtc || $currentNextUtc->toDateTimeString() !== $expectedNextUtc->toDateTimeString())) {
                $s->next_run_at = $expectedNextUtc;
                $s->save();
            }
        } else {
            if ($s->next_run_at !== null) {
                $s->next_run_at = null;
                $s->save();
            }
        }

        // Return timestamps in UTC so the frontend can display in the user's local timezone.
        $lastRunUtc = $s->last_run_at ? Carbon::parse($s->last_run_at)->setTimezone('UTC')->toIso8601String() : null;
        $nextRunUtc = $s->next_run_at ? Carbon::parse($s->next_run_at)->setTimezone('UTC')->toIso8601String() : null;

        return response()->json([
            'frequency' => $s->frequency,
            'run_at_time' => $s->run_at_time,
            'timezone' => $tz,
            'last_run_at' => $lastRunUtc,
            'next_run_at' => $nextRunUtc,
            'has_latest_file' => $s->last_backup_path && Storage::disk('local')->exists($s->last_backup_path),
        ]);
    }

    /**
     * PUT /api/admin/backup/schedule — update backup schedule (Central Admin only).
     */
    public function updateSchedule(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $valid = $request->validate([
            'frequency' => ['required', 'string', 'in:off,daily,weekly'],
            'run_at_time' => ['required', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            // timezone is intentionally fixed to Asia/Manila for this deployment (PH).
            'timezone' => ['nullable', 'string', 'max:50'],
        ]);

        $s = BackupSetting::get();
        $s->frequency = $valid['frequency'];
        $s->run_at_time = $valid['run_at_time'];
        $s->timezone = BackupSetting::TIMEZONE_DEFAULT;

        // Store timestamps in UTC to avoid app timezone (UTC) shifting display by +8 hours.
        $nextPh = self::computeNextRunAt($s->frequency, $s->run_at_time, $s->timezone);
        $s->next_run_at = $nextPh?->copy()->setTimezone('UTC');
        $s->save();

        ActivityLog::log(
            $request->user()->id,
            'backup_schedule_updated',
            'Updated backup schedule: ' . $s->frequency,
            [
                'frequency' => $s->frequency,
                'run_at_time' => $s->run_at_time,
                'timezone' => $s->timezone,
                'next_run_at' => $s->next_run_at?->toIso8601String(),
            ],
            $request
        );

        return response()->json([
            'frequency' => $s->frequency,
            'run_at_time' => $s->run_at_time,
            'timezone' => BackupSetting::TIMEZONE_DEFAULT,
            'next_run_at' => $s->next_run_at ? Carbon::parse($s->next_run_at)->setTimezone('UTC')->toIso8601String() : null,
        ]);
    }

    /**
     * Compute next run timestamp from frequency, time, and timezone.
     */
    public static function computeNextRunAt(string $frequency, string $runAtTime, string $timezone): ?Carbon
    {
        if ($frequency === BackupSetting::FREQUENCY_OFF) {
            return null;
        }

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }

        $now = Carbon::now($tz);
        $parts = explode(':', $runAtTime);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);

        $next = $now->copy()->setTime($hour, $minute, 0);

        if ($next->lte($now)) {
            $next = $frequency === BackupSetting::FREQUENCY_DAILY ? $next->addDay() : $next->addWeek();
        }

        return $next;
    }

    /**
     * GET /api/admin/backup/download/latest — download last scheduled backup file (Central Admin only).
     */
    public function downloadLatest(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $s = BackupSetting::get();
        if (! $s->last_backup_path || ! Storage::disk('local')->exists($s->last_backup_path)) {
            return response()->json(['message' => 'No scheduled backup file available.'], 404);
        }

        $path = Storage::disk('local')->path($s->last_backup_path);
        $filename = basename($s->last_backup_path);

        ActivityLog::log(
            $request->user()->id,
            'backup_downloaded_latest',
            'Downloaded latest scheduled backup: ' . $filename,
            ['filename' => $filename],
            $request
        );

        return response()->download($path, $filename, [
            'Content-Type' => 'application/sql',
        ]);
    }

    /**
     * GET /api/admin/backup/list — list automated backup files with date (Central Admin only).
     */
    public function listBackups(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $dir = 'backups';
        $disk = Storage::disk('local');
        if (! $disk->exists($dir)) {
            return response()->json(['backups' => []]);
        }

        $files = $disk->files($dir);
        $backups = [];
        foreach ($files as $path) {
            $basename = basename($path);
            if (preg_match('/^midtask-\d{4}-\d{2}-\d{2}-\d{6}\.sql$/', $basename) !== 1) {
                continue;
            }
            $fullPath = $disk->path($path);
            $mtime = file_exists($fullPath) ? filemtime($fullPath) : null;
            $createdAtIso = $mtime
                ? Carbon::createFromTimestampUTC($mtime)->toIso8601String()
                : null;
            $backups[] = [
                'filename' => $basename,
                'created_at' => $createdAtIso,
            ];
        }

        usort($backups, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        return response()->json(['backups' => $backups]);
    }

    /**
     * GET /api/admin/backup/download/file/{filename} — download a specific backup file (Central Admin only).
     */
    public function downloadFile(Request $request, string $filename): BinaryFileResponse|JsonResponse
    {
        $this->ensureCentralAdmin($request);

        if (preg_match('/^midtask-\d{4}-\d{2}-\d{2}-\d{6}\.sql$/', $filename) !== 1) {
            return response()->json(['message' => 'Invalid backup filename.'], 400);
        }

        $path = 'backups/' . $filename;
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Backup file not found.'], 404);
        }

        $fullPath = Storage::disk('local')->path($path);

        ActivityLog::log(
            $request->user()->id,
            'backup_downloaded_file',
            'Downloaded scheduled backup file: ' . $filename,
            ['filename' => $filename],
            $request
        );

        return response()->download($fullPath, $filename, [
            'Content-Type' => 'application/sql',
        ]);
    }
}
