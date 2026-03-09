<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BackupSetting;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Services\AssignDefaultTasksService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Notifications\AccountApprovedNotification;
use App\Notifications\AccountRejectedNotification;

/**
 * Central Admin only: account approvals (list pending, approve, reject).
 */
class AdminController extends Controller
{
    public function __construct(
        private AssignDefaultTasksService $assignDefaultTasksService
    ) {}

    private function ensureCentralAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'central_admin') {
            abort(403, 'Access denied. Central Administrative Officer only.');
        }
    }

    /**
     * Central Admin dashboard summary: KPI counts + recent activity.
     * GET /api/admin/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $today = Carbon::now()->startOfDay();

        $pendingApprovals = User::where('status', 'pending_approval')
            ->whereNotNull('email_verified_at')
            ->count();

        $activeOfficers = User::where('role', 'administrative_officer')
            ->where('status', 'active')
            ->count();

        $activeSchoolHeads = User::where('role', 'school_head')
            ->where('status', 'active')
            ->count();

        $totalTasks = Task::where(function ($q) {
                $q->where('is_personal', false)
                    ->orWhere('personal_visible_to_central', true);
            })->count();

        $visibleAssignments = function ($status, ?bool $overdue = null) use ($today) {
            $query = UserTask::where('status', $status)
                ->whereHas('task', function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('is_personal', false)
                            ->orWhere('personal_visible_to_central', true);
                    });
                });

            if ($overdue === true) {
                $query->whereDate('due_date', '<', $today->toDateString());
            }

            return $query->count();
        };

        $pendingAssignments = $visibleAssignments(UserTask::STATUS_PENDING);
        $overdueAssignments = $visibleAssignments(UserTask::STATUS_PENDING, true);
        $submittedAssignments = $visibleAssignments(UserTask::STATUS_SUBMITTED);
        $completedAssignments = $visibleAssignments(UserTask::STATUS_COMPLETED);

        $tz = BackupSetting::TIMEZONE_DEFAULT;
        $backup = BackupSetting::get();

        $recent = ActivityLog::query()
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(function (ActivityLog $log) {
                $createdAt = $log->created_at;
                $storageTz = config('app.activity_log_storage_timezone', 'UTC');
                $utcIso = $createdAt
                    ? Carbon::parse($createdAt->format('Y-m-d H:i:s'), $storageTz)->setTimezone('UTC')->toIso8601String()
                    : null;
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'ip_address' => $log->ip_address,
                    'created_at' => $utcIso,
                    'actor' => $log->actor ? [
                        'id' => $log->actor->id,
                        'name' => $log->actor->name,
                        'email' => $log->actor->email,
                    ] : null,
                ];
            });

        return response()->json([
            'kpis' => [
                'pending_approvals' => $pendingApprovals,
                'active_officers' => $activeOfficers,
                'active_school_heads' => $activeSchoolHeads,
                'total_tasks' => $totalTasks,
                'assignments' => [
                    'pending' => $pendingAssignments,
                    'overdue' => $overdueAssignments,
                    'submitted' => $submittedAssignments,
                    'completed' => $completedAssignments,
                ],
            ],
            'backup' => [
                'frequency' => $backup->frequency,
                'run_at_time' => $backup->run_at_time,
                'timezone' => $tz,
                'last_run_at' => $backup->last_run_at ? Carbon::parse($backup->last_run_at)->setTimezone('UTC')->toIso8601String() : null,
                'next_run_at' => $backup->next_run_at ? Carbon::parse($backup->next_run_at)->setTimezone('UTC')->toIso8601String() : null,
                'has_latest_file' => (bool) ($backup->last_backup_path && Storage::disk('local')->exists($backup->last_backup_path)),
            ],
            'recent_activity' => $recent,
        ]);
    }

    /**
     * Create a user (School Head or Administrative Officer) — Central Admin only.
     * Body: name, email, password, role (school_head|administrative_officer), employee_id, position, division, school_name.
     * User is created as active with email_verified_at set. Admin shares credentials manually.
     */
    public function createUser(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $valid = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'],
            'role' => ['required', 'string', 'in:school_head,administrative_officer'],
            'employee_id' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:255'],
            'division' => ['nullable', 'string', 'max:255'],
            'school_name' => ['nullable', 'string', 'max:255'],
            // Optional: assign this Administrative Officer to a School Head immediately.
            'school_head_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'password.regex' => 'Password must contain at least one letter and one number.',
        ]);

        $user = User::create([
            'name' => $valid['name'],
            'email' => $valid['email'],
            'password' => $valid['password'],
            'role' => $valid['role'],
            'status' => 'active',
            'email_verified_at' => now(),
            'employee_id' => $valid['employee_id'] ?? null,
            'position' => $valid['position'] ?? null,
            'division' => $valid['division'] ?? null,
            'school_name' => $valid['school_name'] ?? null,
        ]);

        // If an Administrative Officer is being created and a School Head was provided,
        // create the supervision mapping immediately so validations and reminders are scoped.
        if ($user->role === 'administrative_officer' && isset($valid['school_head_id'])) {
            $schoolHead = User::where('id', $valid['school_head_id'])
                ->where('role', 'school_head')
                ->where('status', 'active')
                ->first();

            if ($schoolHead) {
                $schoolHead->supervisedAdministrativeOfficers()->syncWithoutDetaching([$user->id]);
            }
        }
        ActivityLog::log($request->user()->id, 'user_created', 'Created user: ' . $user->name . ' (' . $user->email . ', ' . $user->role . ')', ['user_id' => $user->id], $request);

        return response()->json([
            'message' => 'User created successfully. Share the credentials with the user.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
            ],
            'credentials' => [
                'email' => $user->email,
                'password' => $valid['password'],
            ],
        ], 201);
    }

    /**
     * Upload avatar for a user (Central Admin only). Used when creating School Head with avatar.
     */
    public function uploadUserAvatar(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $user = User::findOrFail($id);

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
        ]);

        if ($user->avatar_url) {
            $baseUrl = rtrim(\Illuminate\Support\Facades\Storage::disk('public')->url(''), '/');
            $oldPath = str_starts_with($user->avatar_url, $baseUrl . '/')
                ? substr($user->avatar_url, strlen($baseUrl) + 1)
                : null;
            if ($oldPath) {
                try {
                    if (\Illuminate\Support\Facades\Storage::disk('public')->exists($oldPath)) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
                    }
                } catch (\Throwable $e) {
                    // Ignore deletion errors
                }
            }
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $url = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        $user->update(['avatar_url' => $url]);

        return response()->json([
            'message' => 'Avatar updated successfully.',
            'avatar_url' => $url,
        ]);
    }

    /**
     * List users with status = pending_approval (email verified, awaiting admin approval).
     */
    public function pendingUsers(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $users = User::where('status', 'pending_approval')
            ->whereNotNull('email_verified_at')
            ->orderBy('created_at', 'desc')
            ->get([
                'id',
                'name',
                'email',
                'role',
                'status',
                'employee_id',
                'position',
                'division',
                'school_name',
                'email_verified_at',
                'created_at',
            ]);

        return response()->json([
            'users' => $users->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'status' => $u->status,
                    'employee_id' => $u->employee_id,
                    'position' => $u->position,
                    'division' => $u->division,
                    'school_name' => $u->school_name,
                    'email_verified_at' => $u->email_verified_at?->toIso8601String(),
                    'created_at' => $u->created_at->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Approve a pending or rejected user (set status = active, store remarks and timestamp).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $user = User::whereIn('status', ['pending_approval', 'rejected'])->findOrFail($id);
        $remarks = $request->input('remarks');
        $assignDefaultTasks = filter_var($request->input('assign_default_tasks'), FILTER_VALIDATE_BOOLEAN);

        DB::transaction(function () use ($user, $remarks, $assignDefaultTasks) {
            $user->update([
                'status' => 'active',
                'approved_at' => now(),
                'approved_remarks' => is_string($remarks) ? trim($remarks) : null,
                'rejected_at' => null,
                'rejection_remarks' => null,
            ]);

            if ($assignDefaultTasks && $user->role === 'administrative_officer') {
                $this->assignDefaultTasksService->assign($user);
            }
        });

        ActivityLog::log($request->user()->id, 'user_approved', 'Approved account: ' . $user->name . ' (' . $user->email . ')', ['user_id' => $user->id], $request);

        if ($user->email) {
            $user->notify(new AccountApprovedNotification($user->approved_remarks));
        }

        return response()->json([
            'message' => 'Account approved successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'approved_at' => $user->approved_at?->toIso8601String(),
                'approved_remarks' => $user->approved_remarks,
            ],
        ]);
    }

    /**
     * Reject a pending user (set status = rejected, store remarks and timestamp).
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $user = User::where('status', 'pending_approval')->findOrFail($id);
        $remarks = $request->input('remarks');
        $user->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_remarks' => is_string($remarks) ? trim($remarks) : null,
            'approved_at' => null,
            'approved_remarks' => null,
        ]);
        ActivityLog::log($request->user()->id, 'user_rejected', 'Rejected account: ' . $user->name . ' (' . $user->email . ')', ['user_id' => $user->id], $request);

        if ($user->email) {
            $user->notify(new AccountRejectedNotification($user->rejection_remarks));
        }

        return response()->json([
            'message' => 'Account rejected.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'rejected_at' => $user->rejected_at?->toIso8601String(),
                'rejection_remarks' => $user->rejection_remarks,
            ],
        ]);
    }

    /**
     * List personnel: approved (active), rejected, and inactive. Central Admin only.
     * Query: ?status=all|active|rejected|inactive (default all except pending_approval).
     * Query: ?role=school_head|administrative_officer (optional; filter by role).
     */
    public function personnel(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $statusFilter = $request->query('status', 'all');
        $roleFilter = $request->query('role');
        $query = User::whereNotIn('status', ['pending_approval'])
            ->whereNotNull('email_verified_at')
            ->orderByRaw("CASE status WHEN 'rejected' THEN 1 WHEN 'inactive' THEN 2 ELSE 0 END")
            ->orderBy('updated_at', 'desc');

        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        if (is_string($roleFilter) && in_array($roleFilter, ['school_head', 'administrative_officer'], true)) {
            $query->where('role', $roleFilter);
        }

        $users = $query->get([
            'id', 'name', 'email', 'role', 'status',
            'employee_id', 'position', 'division', 'school_name', 'avatar_url', 'school_logo_url',
            'approved_at', 'approved_remarks', 'rejected_at', 'rejection_remarks',
            'email_verified_at', 'created_at', 'updated_at',
        ]);

        return response()->json([
            'personnel' => $users->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'status' => $u->status,
                    'employee_id' => $u->employee_id,
                    'position' => $u->position,
                    'division' => $u->division,
                    'school_name' => $u->school_name,
                    'avatar_url' => $u->avatar_url,
                    'school_logo_url' => $u->school_logo_url,
                    'approved_at' => $u->approved_at?->toIso8601String(),
                    'approved_remarks' => $u->approved_remarks,
                    'rejected_at' => $u->rejected_at?->toIso8601String(),
                    'rejection_remarks' => $u->rejection_remarks,
                    'email_verified_at' => $u->email_verified_at?->toIso8601String(),
                    'created_at' => $u->created_at->toIso8601String(),
                    'updated_at' => $u->updated_at->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * List School Heads assigned to a user (Administrative Officer). Central Admin only.
     * Returns empty list if user is not an AO or has no assigned School Heads.
     */
    public function userSchoolHeads(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $user = User::find($id);
        if (! $user || $user->role !== 'administrative_officer') {
            return response()->json(['school_heads' => []]);
        }

        $schoolHeads = $user->supervisingSchoolHeads()
            ->orderBy('name')
            ->get([
                'users.id',
                'users.name',
                'users.email',
                'users.employee_id',
                'users.position',
                'users.division',
                'users.school_name',
                'users.status',
            ]);

        return response()->json([
            'school_heads' => $schoolHeads->map(fn ($sh) => [
                'id' => $sh->id,
                'name' => $sh->name,
                'email' => $sh->email,
                'employee_id' => $sh->employee_id,
                'position' => $sh->position,
                'division' => $sh->division,
                'school_name' => $sh->school_name,
                'status' => $sh->status,
            ]),
        ]);
    }

    /**
     * Monitor task progress of all Administrative Officers (Central Admin).
     * Query: ?school=... to filter by school_name.
     * Returns per-user: pending, missing, completed counts and user_tasks list.
     */
    public function monitorOfficers(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $schoolFilter = $request->query('school');
        $query = User::where('role', 'administrative_officer')
            ->where('status', 'active')
            ->whereNotNull('email_verified_at')
            ->with(['userTasks' => fn ($q) => $q->with('task')->orderBy('due_date')->orderBy('id')]);

        if (is_string($schoolFilter) && trim($schoolFilter) !== '') {
            $query->where('school_name', trim($schoolFilter));
        }

        $users = $query->orderBy('name')->get([
                'id', 'name', 'email', 'employee_id', 'position', 'division', 'school_name', 'avatar_url', 'school_logo_url', 'school_logo_url',
        ]);

        $today = now()->startOfDay();
        $officers = [];

        foreach ($users as $user) {
            $pending = [];
            $missing = [];
            $completed = [];

            foreach ($user->userTasks as $ut) {
                $task = $ut->task;
                if ($task && $task->is_personal && ! $task->personal_visible_to_central) {
                    continue;
                }

                $item = [
                    'id' => $ut->id,
                    'task_id' => $ut->task_id,
                    'due_date' => $ut->due_date->format('Y-m-d'),
                    'status' => $ut->status,
                    'period_covered' => $ut->period_covered,
                    'completed_at' => $ut->completed_at?->toIso8601String(),
                    'task' => $task ? [
                        'id' => $task->id,
                        'name' => $task->name,
                        'frequency' => $task->frequency,
                        'action' => $task->action,
                        'mov_description' => $task->mov_description,
                    ] : null,
                ];

                if (in_array($ut->status, [UserTask::STATUS_COMPLETED, UserTask::STATUS_SUBMITTED], true)) {
                    $completed[] = $item;
                } elseif ($ut->due_date->lt($today) && $ut->status === UserTask::STATUS_PENDING) {
                    $missing[] = $item;
                } else {
                    $pending[] = $item;
                }
            }

            $officers[] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_id' => $user->employee_id,
                'position' => $user->position,
                'division' => $user->division,
                'school_name' => $user->school_name,
                'avatar_url' => $user->avatar_url,
                'school_logo_url' => $user->school_logo_url,
                'pending_count' => count($pending),
                'missing_count' => count($missing),
                'completed_count' => count($completed),
                'pending' => $pending,
                'missing' => $missing,
                'completed' => $completed,
            ];
        }

        $schools = User::where('role', 'administrative_officer')
            ->where('status', 'active')
            ->whereNotNull('school_name')
            ->where('school_name', '!=', '')
            ->distinct()
            ->pluck('school_name')
            ->sort()
            ->values()
            ->toArray();

        return response()->json([
            'officers' => $officers,
            'schools' => $schools,
        ]);
    }

    /**
     * Deactivate an active user (set status = inactive). Central Admin only.
     * Revokes all tokens so the user is immediately logged out on their end.
     */
    public function deactivate(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $user = User::where('status', 'active')->findOrFail($id);
        $remarks = $request->input('remarks');
        $user->update([
            'status' => 'inactive',
            'approved_remarks' => is_string($remarks) ? trim($remarks) : $user->approved_remarks,
        ]);

        // Revoke all tokens so the deactivated user is immediately logged out
        $user->tokens()->delete();
        ActivityLog::log($request->user()->id, 'user_deactivated', 'Deactivated account: ' . $user->name . ' (' . $user->email . ')', ['user_id' => $user->id], $request);

        return response()->json([
            'message' => 'Account deactivated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
            ],
        ]);
    }

    /**
     * Activate an inactive user (set status = active). Central Admin only.
     */
    public function activate(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $user = User::where('status', 'inactive')->findOrFail($id);
        $remarks = $request->input('remarks');
        $user->update([
            'status' => 'active',
            'approved_remarks' => is_string($remarks) ? trim($remarks) : $user->approved_remarks,
        ]);

        ActivityLog::log(
            $request->user()->id,
            'user_activated',
            'Activated account: ' . $user->name . ' (' . $user->email . ')',
            ['user_id' => $user->id],
            $request
        );

        return response()->json([
            'message' => 'Account activated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
            ],
        ]);
    }

    /**
     * Delete a user permanently. Central Admin only.
     * Allows deletion of active, inactive, or rejected users (e.g. School Heads created by admin).
     */
    public function destroyUser(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $user = User::whereIn('status', ['active', 'inactive', 'rejected'])->findOrFail($id);
        $userName = $user->name;
        $userEmail = $user->email;
        $userRole = $user->role;
        $user->tokens()->delete();
        $user->delete();

        ActivityLog::log(
            $request->user()->id,
            'user_deleted',
            'Deleted user: ' . $userName . ' (' . $userEmail . ', ' . $userRole . ')',
            ['user_id' => $id, 'role' => $userRole],
            $request
        );

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }
}
