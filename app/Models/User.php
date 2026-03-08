<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'employee_id',
        'position',
        'division',
        'school_name',
        'avatar_url',
        'signature_url',
        'email_verified_at',
        'otp',
        'otp_expires_at',
        'approved_at',
        'approved_remarks',
        'rejected_at',
        'rejection_remarks',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'otp_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * Get the user's assigned tasks.
     */
    public function userTasks(): HasMany
    {
        return $this->hasMany(UserTask::class);
    }

    /**
     * Administrative Officers supervised by this School Head.
     */
    public function supervisedAdministrativeOfficers(): BelongsToMany
    {
        return $this->belongsToMany(
            related: self::class,
            table: 'school_head_ao',
            foreignPivotKey: 'school_head_id',
            relatedPivotKey: 'ao_id'
        )->where('role', 'administrative_officer');
    }

    /**
     * School Heads supervising this Administrative Officer.
     *
     * For now we expect a single School Head, but we model many-to-many
     * to keep the schema flexible.
     */
    public function supervisingSchoolHeads(): BelongsToMany
    {
        return $this->belongsToMany(
            related: self::class,
            table: 'school_head_ao',
            foreignPivotKey: 'ao_id',
            relatedPivotKey: 'school_head_id'
        )->where('role', 'school_head');
    }
}
