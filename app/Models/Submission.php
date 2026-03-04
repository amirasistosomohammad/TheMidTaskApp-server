<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Submission extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_task_id',
        'type',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /**
     * The user task this submission belongs to.
     */
    public function userTask(): BelongsTo
    {
        return $this->belongsTo(UserTask::class);
    }

    /**
     * Files attached to this submission.
     */
    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class);
    }

    /**
     * Validation records for this submission.
     */
    public function validations(): HasMany
    {
        return $this->hasMany(Validation::class);
    }

    /**
     * Input data for this submission (for input-type tasks).
     */
    public function inputData(): HasOne
    {
        return $this->hasOne(InputData::class);
    }
}

