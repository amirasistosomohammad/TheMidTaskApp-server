<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'submission_date_rule',
        'frequency',
        'mov_description',
        'action',
        'is_common',
        'common_report_no',
        'is_personal',
        'owner_user_id',
        'personal_visible_to_central',
        'personal_visible_to_school_head',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_common' => 'boolean',
            'is_personal' => 'boolean',
            'personal_visible_to_central' => 'boolean',
            'personal_visible_to_school_head' => 'boolean',
        ];
    }

    /**
     * Action: upload (file/document) or input (data entry).
     */
    public const ACTION_UPLOAD = 'upload';
    public const ACTION_INPUT = 'input';

    /**
     * Frequency values.
     */
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_TWICE_A_YEAR = 'twice_a_year';
    public const FREQUENCY_YEARLY = 'yearly';
    public const FREQUENCY_END_OF_SY = 'end_of_sy';
    public const FREQUENCY_QUARTERLY = 'quarterly';
    public const FREQUENCY_EVERY_TWO_MONTHS = 'every_two_months';
    public const FREQUENCY_ONE_TIME = 'one_time';

    /**
     * Get the user task assignments for this task.
     */
    public function userTasks(): HasMany
    {
        return $this->hasMany(UserTask::class);
    }
}
