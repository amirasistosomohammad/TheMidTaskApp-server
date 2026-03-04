<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Validation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'submission_id',
        'validator_id',
        'status',
        'feedback',
        'validated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
        ];
    }

    /**
     * The submission being validated.
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * The School Head who performed the validation.
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_id');
    }
}

