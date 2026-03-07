<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class ActivityLog extends Model
{
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'action',
        'description',
        'meta',
        'ip_address',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user who performed the action (null for system).
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Record an activity log entry.
     *
     * @param int|null $actorId User who performed the action (null for system)
     * @param string $action Action key (e.g. login, user_approved, task_assigned)
     * @param string $description Human-readable description
     * @param array<string, mixed>|null $meta Optional extra data
     * @param Request|null $request Optional request for IP
     */
    public static function log(
        ?int $actorId,
        string $action,
        string $description,
        ?array $meta = null,
        ?Request $request = null
    ): void {
        $ip = $request ? $request->ip() : null;
        self::create([
            'actor_id' => $actorId,
            'action' => $action,
            'description' => $description,
            'meta' => $meta,
            'ip_address' => $ip,
        ]);
    }
}
