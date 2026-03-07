<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row settings for automated SQL backup schedule (Central Admin).
 */
class BackupSetting extends Model
{
    protected $table = 'backup_settings';

    protected $fillable = [
        'frequency',
        'run_at_time',
        'timezone',
        'last_run_at',
        'last_backup_path',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public const FREQUENCY_OFF = 'off';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const TIMEZONE_DEFAULT = 'Asia/Manila';

    /** Get the single backup settings row. */
    public static function get(): self
    {
        $row = self::query()->first();
        if (!$row) {
            $row = self::query()->create([
                'frequency' => self::FREQUENCY_OFF,
                'run_at_time' => '02:00',
                'timezone' => self::TIMEZONE_DEFAULT,
            ]);
        }
        if (! $row->timezone) {
            $row->timezone = self::TIMEZONE_DEFAULT;
            $row->save();
        }
        return $row;
    }
}
