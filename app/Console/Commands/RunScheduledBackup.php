<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\BackupController;
use App\Models\BackupSetting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RunScheduledBackup extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Run scheduled SQL backup if due (frequency is not off and next_run_at <= now).';

    public function handle(): int
    {
        $s = BackupSetting::get();

        if ($s->frequency === BackupSetting::FREQUENCY_OFF) {
            return self::SUCCESS;
        }

        $tz = BackupSetting::TIMEZONE_DEFAULT;
        // Stored timestamps are UTC; compare using UTC.
        $nowUtc = Carbon::now('UTC');
        if ($s->next_run_at && $s->next_run_at->copy()->setTimezone('UTC')->gt($nowUtc)) {
            return self::SUCCESS;
        }

        $dir = 'backups';
        Storage::disk('local')->makeDirectory($dir);
        $nowPh = Carbon::now($tz);
        $filename = 'midtask-' . $nowPh->format('Y-m-d-His') . '.sql';
        $path = $dir . '/' . $filename;
        $fullPath = Storage::disk('local')->path($path);

        try {
            BackupController::runMysqldump(true, $fullPath);
        } catch (\Throwable $e) {
            $this->error('Backup failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Store timestamps in UTC.
        $s->last_run_at = $nowUtc;
        $s->last_backup_path = $path;
        $s->timezone = BackupSetting::TIMEZONE_DEFAULT;
        $nextPh = BackupController::computeNextRunAt($s->frequency, $s->run_at_time, BackupSetting::TIMEZONE_DEFAULT);
        $s->next_run_at = $nextPh?->copy()->setTimezone('UTC');
        $s->save();

        $this->info('Backup completed: ' . $path);

        return self::SUCCESS;
    }
}
