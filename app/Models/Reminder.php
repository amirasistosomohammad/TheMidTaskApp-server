<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'user_task_id',
        'remind_at',
        'channel',
        'type',
        'days_before_due',
        'status',
        'read_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'read_at' => 'datetime',
            'days_before_due' => 'integer',
        ];
    }

    public const STATUS_UNREAD = 'unread';
    public const STATUS_READ = 'read';

    /**
     * The user who owns this reminder.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user task this reminder is about.
     */
    public function userTask(): BelongsTo
    {
        return $this->belongsTo(UserTask::class);
    }
}

