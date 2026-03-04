<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Services\DueDateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Central Admin only: account approvals (list pending, approve, reject).
 */
class AdminController extends Controller
{
    public function __construct(
        private DueDateService $dueDateService
    ) {}

    private function ensureCentralAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'central_admin') {
            abort(403, 'Access denied. Central Administrative Officer only.');
        }
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
            $baseUrl = rtrim(Storage::disk('public')->url(''), '/');
            $oldPath = str_starts_with($user->avatar_url, $baseUrl . '/')
                ? substr($user->avatar_url, strlen($baseUrl) + 1)
                : null;
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $url = Storage::disk('public')->url($path);
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
                $this->assignDefaultTasksToUser($user);
            }
        });

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
     * Assign standard tasks from the central Task list to an Administrative Officer.
     *
     * Creates multiple upcoming user_tasks per task (based on frequency) so the
     * officer's timeline is populated on first sign-in.
     */
    private function assignDefaultTasksToUser(User $user): void
    {
        // Use the same ordering as the Task list for a predictable set.
        $tasks = Task::orderBy('is_common', 'desc')
            ->orderBy('common_report_no')
            ->orderBy('name')
            ->get();

        $today = now()->startOfDay();

        /** @var Task $task */
        foreach ($tasks as $task) {
            $count = $this->defaultScheduleCount($task);
            $dates = $this->dueDateService->generateDueDates($task, $today, $count);

            foreach ($dates as $d) {
                $due = $d->format('Y-m-d');

                // Avoid duplicate pending assignments for same user/task/due_date
                // when approving users multiple times or re-running default assignment.
                $exists = UserTask::where('user_id', $user->id)
                    ->where('task_id', $task->id)
                    ->whereDate('due_date', $due)
                    ->where('status', UserTask::STATUS_PENDING)
                    ->exists();
                if ($exists) {
                    continue;
                }

                UserTask::create([
                    'user_id' => $user->id,
                    'task_id' => $task->id,
                    'due_date' => $due,
                    'status' => UserTask::STATUS_PENDING,
                    'period_covered' => $d->format('Y-m'),
                ]);
            }
        }
    }

    /**
     * How many upcoming occurrences to pre-generate for a task's schedule so
     * personnel timelines look complete and professional.
     */
    private function defaultScheduleCount(Task $task): int
    {
        return match ($task->frequency) {
            Task::FREQUENCY_MONTHLY => 12,          // next 12 months
            Task::FREQUENCY_TWICE_A_YEAR => 2,      // this year's two months
            Task::FREQUENCY_QUARTERLY => 4,         // four quarters
            Task::FREQUENCY_EVERY_TWO_MONTHS => 6,  // six bimonthly periods
            Task::FREQUENCY_YEARLY,
            Task::FREQUENCY_END_OF_SY => 1,         // one per year
            'once_or_twice_a_year' => 2,
            default => 1,
        };
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
            'employee_id', 'position', 'division', 'school_name', 'avatar_url',
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
            'id', 'name', 'email', 'employee_id', 'position', 'division', 'school_name',
        ]);

        $today = now()->startOfDay();
        $officers = [];

        foreach ($users as $user) {
            $pending = [];
            $missing = [];
            $completed = [];

            foreach ($user->userTasks as $ut) {
                $item = [
                    'id' => $ut->id,
                    'task_id' => $ut->task_id,
                    'due_date' => $ut->due_date->format('Y-m-d'),
                    'status' => $ut->status,
                    'period_covered' => $ut->period_covered,
                    'completed_at' => $ut->completed_at?->toIso8601String(),
                    'task' => $ut->task ? [
                        'id' => $ut->task->id,
                        'name' => $ut->task->name,
                        'frequency' => $ut->task->frequency,
                        'action' => $ut->task->action,
                        'mov_description' => $ut->task->mov_description,
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
        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }
}
