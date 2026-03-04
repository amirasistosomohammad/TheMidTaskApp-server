<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\InputData;
use App\Models\Validation;
use App\Models\Reminder;
use App\Notifications\SubmissionPendingValidationNotification;
use Illuminate\Support\Facades\Storage;
use App\Services\DueDateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
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

    private function ensureAdministrativeOfficer(Request $request): void
    {
        if ($request->user()?->role !== 'administrative_officer') {
            abort(403, 'Access denied. Administrative Officer only.');
        }
    }

    private function ensureSchoolHead(Request $request): void
    {
        if ($request->user()?->role !== 'school_head') {
            abort(403, 'Access denied. School Head only.');
        }
    }

    /**
     * List all tasks (Central Admin).
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $tasks = Task::orderBy('is_common', 'desc')
            ->orderBy('common_report_no')
            ->orderBy('name')
            ->get();

        return response()->json([
            'tasks' => $tasks->map(fn (Task $t) => $this->taskToArray($t)),
        ]);
    }

    /**
     * Create a new task (Central Admin).
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $valid = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'submission_date_rule' => ['nullable', 'string', 'max:255'],
            'frequency' => ['required', 'string', 'max:50'],
            'mov_description' => ['nullable', 'string'],
            'action' => ['required', Rule::in([Task::ACTION_UPLOAD, Task::ACTION_INPUT])],
            'is_common' => ['boolean'],
            'common_report_no' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $task = Task::create([
            'name' => $valid['name'],
            'submission_date_rule' => $valid['submission_date_rule'] ?? null,
            'frequency' => $valid['frequency'],
            'mov_description' => $valid['mov_description'] ?? null,
            'action' => $valid['action'],
            'is_common' => $valid['is_common'] ?? false,
            'common_report_no' => $valid['common_report_no'] ?? null,
        ]);

        // Automatically assign newly created tasks to all active Administrative Officers
        // so they immediately appear on personnel timelines and dashboards with a
        // full-looking schedule (multiple upcoming due dates where appropriate).
        $today = now()->startOfDay();
        $count = $this->defaultScheduleCount($task);
        $dates = $this->dueDateService->generateDueDates($task, $today, $count);

        $officers = User::where('role', 'administrative_officer')
            ->where('status', 'active')
            ->get();

        foreach ($officers as $officer) {
            foreach ($dates as $d) {
                UserTask::create([
                    'user_id' => $officer->id,
                    'task_id' => $task->id,
                    'due_date' => $d->format('Y-m-d'),
                    'status' => UserTask::STATUS_PENDING,
                    'period_covered' => $d->format('Y-m'),
                ]);
            }
        }

        return response()->json([
            'message' => 'Task created successfully.',
            'task' => $this->taskToArray($task),
        ], 201);
    }

    /**
     * Bulk-assign standard tasks from the central Task list to personnel (Central Admin).
     * Body: { "user_ids": [1,2,3] } — optional; if omitted, assigns to all active personnel.
     */
    public function bulkAssignCommon(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $userIds = $request->input('user_ids');
        $users = $userIds && is_array($userIds)
            ? User::whereIn('id', $userIds)
                ->where('role', 'administrative_officer')
                ->where('status', 'active')
                ->get()
            : User::where('role', 'administrative_officer')
                ->where('status', 'active')
                ->get();

        // Use the same ordering as the Task list; treat all tasks as standard tasks
        // instead of relying on the legacy "common reports" flags.
        $tasks = Task::orderBy('is_common', 'desc')
            ->orderBy('common_report_no')
            ->orderBy('name')
            ->get();

        $created = 0;

        $today = now()->startOfDay();

        DB::transaction(function () use ($users, $tasks, $today, &$created) {
            foreach ($users as $user) {
                foreach ($tasks as $task) {
                    $count = $this->defaultScheduleCount($task);
                    $dates = $this->dueDateService->generateDueDates($task, $today, $count);

                    foreach ($dates as $d) {
                        $due = $d->format('Y-m-d');

                        // Avoid duplicate pending assignments for same user/task/due_date
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
                        $created++;
                    }
                }
            }
        });

        return response()->json([
            'message' => $created . ' assignment(s) created for ' . $users->count() . ' user(s).',
            'created_count' => $created,
            'user_count' => $users->count(),
            'task_count' => $tasks->count(),
        ], 201);
    }

    /**
     * Get a single task (Central Admin).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $task = Task::withCount('userTasks')->findOrFail($id);
        $nextDueDate = $this->dueDateService->computeNextDueDate($task);

        return response()->json([
            'task' => $this->taskToArray($task),
            'assignments_count' => $task->user_tasks_count,
            'next_due_date' => $nextDueDate->format('Y-m-d'),
        ]);
    }

    /**
     * Update a task (Central Admin).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $task = Task::findOrFail($id);

        $valid = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'submission_date_rule' => ['nullable', 'string', 'max:255'],
            'frequency' => ['sometimes', 'required', 'string', 'max:50'],
            'mov_description' => ['nullable', 'string'],
            'action' => ['sometimes', 'required', Rule::in([Task::ACTION_UPLOAD, Task::ACTION_INPUT])],
            'is_common' => ['boolean'],
            'common_report_no' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $task->update($valid);

        // When a task's schedule changes (frequency / submission_date_rule), keep
        // personnel timelines consistent by updating existing assignments:
        // - Recompute due dates for all *pending* user_tasks for this task, based
        //   on their previous due_date so recurring sequences stay aligned.
        // - Completed/submitted assignments keep their original due dates.
        $task->refresh();
        $pendingAssignments = UserTask::where('task_id', $task->id)
            ->where('status', UserTask::STATUS_PENDING)
            ->orderBy('due_date')
            ->get();

        foreach ($pendingAssignments as $userTask) {
            $from = $userTask->due_date ? $userTask->due_date->copy()->subDay() : null;
            $newDueDate = $this->dueDateService->computeNextDueDate($task, $from);
            $userTask->due_date = $newDueDate;

            if (! empty($userTask->period_covered)) {
                $userTask->period_covered = $newDueDate->format('Y-m');
            }

            $userTask->save();
        }

        return response()->json([
            'message' => 'Task updated successfully.',
            'task' => $this->taskToArray($task->fresh()),
        ]);
    }

    /**
     * Delete a task (Central Admin).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $task = Task::findOrFail($id);
        $task->delete();

        return response()->json([
            'message' => 'Task deleted successfully.',
        ]);
    }

    /**
     * Get suggested due dates for a task (Central Admin).
     * Query: ?count=12&from=2025-02-22 (optional; default from=today).
     * Used when assigning tasks to show next N due dates from submission_rule + frequency.
     */
    public function suggestedDueDates(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $task = Task::findOrFail($id);
        $count = min(24, max(1, (int) $request->query('count', 12)));
        $fromStr = $request->query('from');
        $from = $fromStr ? \Carbon\Carbon::parse($fromStr)->startOfDay() : \Carbon\Carbon::today();

        $dates = $this->dueDateService->generateDueDates($task, $from, $count);

        return response()->json([
            'due_dates' => array_map(fn ($d) => $d->format('Y-m-d'), $dates),
        ]);
    }

    /**
     * List assignments for a task (Central Admin).
     */
    public function assignments(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $task = Task::findOrFail($id);
        $userTasks = UserTask::where('task_id', $task->id)
            ->with('user:id,name,email,employee_id,position,division,school_name')
            ->orderBy('due_date')
            ->get();

        return response()->json([
            'assignments' => $userTasks->map(fn (UserTask $ut) => [
                'id' => $ut->id,
                'user_id' => $ut->user_id,
                'due_date' => $ut->due_date->format('Y-m-d'),
                'status' => $ut->status,
                'period_covered' => $ut->period_covered,
                'user' => $ut->user ? [
                    'id' => $ut->user->id,
                    'name' => $ut->user->name,
                    'email' => $ut->user->email,
                    'employee_id' => $ut->user->employee_id,
                    'position' => $ut->user->position,
                    'division' => $ut->user->division,
                    'school_name' => $ut->user->school_name,
                ] : null,
            ]),
        ]);
    }

    /**
     * Assign task to users (Central Admin).
     * Body: { "assignments": [ { "user_id": 1, "due_date": "2025-03-10" }, { "user_id": 2 } ] }
     * When due_date is omitted, it is computed from task submission_date_rule + frequency.
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $task = Task::findOrFail($id);

        $valid = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'assignments.*.due_date' => ['nullable', 'date'],
            'assignments.*.period_covered' => ['nullable', 'string', 'max:50'],
        ]);

        $created = [];

        DB::transaction(function () use ($task, $valid, &$created) {
            foreach ($valid['assignments'] as $a) {
                $user = User::find($a['user_id']);
                if ($user?->role !== 'administrative_officer' || $user?->status !== 'active') {
                    continue;
                }
                $dueDate = isset($a['due_date'])
                    ? \Carbon\Carbon::parse($a['due_date'])->format('Y-m-d')
                    : $this->dueDateService->computeNextDueDate($task)->format('Y-m-d');

                // Avoid creating duplicate pending assignments for the same user/task/due_date.
                $exists = UserTask::where('user_id', $a['user_id'])
                    ->where('task_id', $task->id)
                    ->whereDate('due_date', $dueDate)
                    ->where('status', UserTask::STATUS_PENDING)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $ut = UserTask::create([
                    'user_id' => $a['user_id'],
                    'task_id' => $task->id,
                    'due_date' => $dueDate,
                    'status' => UserTask::STATUS_PENDING,
                    'period_covered' => $a['period_covered'] ?? null,
                ]);
                $created[] = [
                    'id' => $ut->id,
                    'user_id' => $ut->user_id,
                    'due_date' => $ut->due_date->format('Y-m-d'),
                    'period_covered' => $ut->period_covered,
                ];
            }
        });

        return response()->json([
            'message' => count($created) . ' assignment(s) created.',
            'assignments' => $created,
        ], 201);
    }

    /**
     * Generate recurring assignments for a user (Central Admin).
     * Body: { "user_id": 1, "count": 12 }
     * Creates user_tasks with due dates computed from task rule + frequency.
     */
    public function assignRecurring(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $task = Task::findOrFail($id);

        $valid = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'count' => ['required', 'integer', 'min:1', 'max:24'],
        ]);

        $user = User::findOrFail($valid['user_id']);
        if ($user->role !== 'administrative_officer' || $user->status !== 'active') {
            return response()->json([
                'message' => 'User must be an active Administrative Officer.',
            ], 422);
        }

        $dates = $this->dueDateService->generateDueDates($task, null, $valid['count']);

        $created = [];
        DB::transaction(function () use ($task, $user, $dates, &$created) {
            foreach ($dates as $d) {
                $due = $d->format('Y-m-d');

                // Avoid duplicate pending assignments for same user/task/due_date.
                $exists = UserTask::where('user_id', $user->id)
                    ->where('task_id', $task->id)
                    ->whereDate('due_date', $due)
                    ->where('status', UserTask::STATUS_PENDING)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $ut = UserTask::create([
                    'user_id' => $user->id,
                    'task_id' => $task->id,
                    'due_date' => $due,
                    'status' => UserTask::STATUS_PENDING,
                    'period_covered' => $d->format('Y-m'),
                ]);
                $created[] = [
                    'id' => $ut->id,
                    'due_date' => $ut->due_date->format('Y-m-d'),
                    'period_covered' => $ut->period_covered,
                ];
            }
        });

        return response()->json([
            'message' => count($created) . ' recurring assignment(s) created.',
            'assignments' => $created,
        ], 201);
    }

    /**
     * Dashboard: my tasks grouped by pending, missing (overdue), completed (Administrative Officer).
     * Phase 3.1: Used by AO dashboard UI.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $this->ensureAdministrativeOfficer($request);

        $userId = $request->user()->id;
        $today = now()->startOfDay();

        $userTasks = UserTask::where('user_id', $userId)
            ->with('task')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $pending = [];
        $missing = [];
        $submitted = [];
        $completed = [];

        foreach ($userTasks as $ut) {
            $item = $this->userTaskToDashboardItem($ut);
            $dueDate = $ut->due_date;
            $status = $ut->status;

            if ($status === UserTask::STATUS_COMPLETED) {
                $completed[] = $item;
            } elseif ($status === UserTask::STATUS_SUBMITTED) {
                $submitted[] = $item;
            } elseif ($dueDate->lt($today) && $status === UserTask::STATUS_PENDING) {
                $missing[] = $item;
            } else {
                $pending[] = $item;
            }
        }

        return response()->json([
            'pending' => $pending,
            'missing' => $missing,
            'submitted' => $submitted,
            'completed' => $completed,
        ]);
    }

    /**
     * Get a single user task by ID (Administrative Officer, own tasks only).
     * Phase 3.4: Used by task detail page.
     */
    public function showUserTask(Request $request, int $id): JsonResponse
    {
        $this->ensureAdministrativeOfficer($request);

        $userTask = UserTask::where('user_id', $request->user()->id)
            ->with('task')
            ->findOrFail($id);

        return response()->json([
            'user_task' => $this->userTaskToDashboardItem($userTask),
        ]);
    }

    /**
     * List my tasks (Administrative Officer).
     */
    public function myTasks(Request $request): JsonResponse
    {
        $this->ensureAdministrativeOfficer($request);

        $userTasks = UserTask::where('user_id', $request->user()->id)
            ->with('task')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'user_tasks' => $userTasks->map(fn (UserTask $ut) => [
                'id' => $ut->id,
                'task_id' => $ut->task_id,
                'due_date' => $ut->due_date->format('Y-m-d'),
                'status' => $ut->status,
                'period_covered' => $ut->period_covered,
                'completed_at' => $ut->completed_at?->toIso8601String(),
                'task' => $ut->task ? $this->taskToArray($ut->task) : null,
            ]),
        ]);
    }

    /**
     * List submissions (including files) for a given user task (Administrative Officer).
     * Phase 4.2: Used by Task Detail page to show uploaded MOVs.
     */
    public function listSubmissions(Request $request, int $id): JsonResponse
    {
        $this->ensureAdministrativeOfficer($request);

        $userTask = UserTask::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $submissions = Submission::where('user_task_id', $userTask->id)
            ->with(['files', 'inputData'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'submissions' => $submissions->map(fn (Submission $s) => $this->submissionToArray($s)),
        ]);
    }

    /**
     * Submit or update input data for a user task (Administrative Officer).
     * Phase 5.2: Creates/updates submission type=input and input_data record.
     */
    public function submitInput(Request $request, int $id): JsonResponse
    {
        $this->ensureAdministrativeOfficer($request);

        /** @var UserTask $userTask */
        $userTask = UserTask::where('user_id', $request->user()->id)
            ->with('task')
            ->findOrFail($id);

        if ($userTask->task?->action !== Task::ACTION_INPUT) {
            return response()->json([
                'message' => 'Input submission is only allowed for input-type tasks.',
            ], 422);
        }

        if (in_array($userTask->status, [UserTask::STATUS_SUBMITTED, UserTask::STATUS_COMPLETED], true)) {
            return response()->json([
                'message' => 'This task has already been submitted or completed.',
            ], 422);
        }

        $validated = $request->validate([
            'period' => ['nullable', 'string', 'max:50'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $submission = Submission::firstOrCreate(
            [
                'user_task_id' => $userTask->id,
                'type' => 'input',
            ],
            []
        );

        $inputData = $submission->inputData;
        $payload = [
            'period' => $validated['period'] ?? null,
            'reference_no' => $validated['reference_no'] ?? null,
            'amount' => isset($validated['amount']) ? (string) $validated['amount'] : null,
            'notes' => $validated['notes'] ?? null,
        ];

        if ($inputData) {
            $inputData->update($payload);
        } else {
            $inputData = InputData::create(array_merge($payload, ['submission_id' => $submission->id]));
        }

        $submission->load('inputData');

        return response()->json([
            'message' => 'Input data saved successfully.',
            'submission' => $this->submissionToArray($submission),
        ], 200);
    }

    /**
     * Upload MOV file(s) for a user task (Administrative Officer).
     * Phase 4.2: Creates/updates an "upload" submission and attaches files.
     */
    public function uploadMov(Request $request, int $id): JsonResponse
    {
        $this->ensureAdministrativeOfficer($request);

        /** @var UserTask $userTask */
        $userTask = UserTask::where('user_id', $request->user()->id)
            ->with('task')
            ->findOrFail($id);

        $action = $userTask->task?->action;
        if ($action !== Task::ACTION_UPLOAD && $action !== Task::ACTION_INPUT) {
            return response()->json([
                'message' => 'File uploads are only allowed for upload-type or input-type tasks.',
            ], 422);
        }

        $isInputTask = $action === Task::ACTION_INPUT;

        $rules = [
            'files' => ['required', 'array'],
            'files.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'], // max 10 MB per file
        ];
        if ($isInputTask) {
            $rules['files'][] = 'size:1'; // Phase 5.4: one optional file for input tasks
        }
        $request->validate($rules);

        $files = $request->file('files', []);
        if (empty($files)) {
            return response()->json([
                'message' => 'No files were provided.',
            ], 422);
        }

        $submissionType = $isInputTask ? 'input' : 'upload';
        /** @var Submission $submission */
        $submission = Submission::firstOrCreate(
            [
                'user_task_id' => $userTask->id,
                'type' => $submissionType,
            ],
            []
        );

        // Phase 5.4: For input tasks, allow only one file — replace existing if present.
        if ($isInputTask && $submission->files()->exists()) {
            foreach ($submission->files as $existing) {
                if ($existing->path && Storage::disk($existing->disk)->exists($existing->path)) {
                    Storage::disk($existing->disk)->delete($existing->path);
                }
                $existing->delete();
            }
        }

        $disk = 'public';
        $storedFiles = [];

        foreach ($files as $file) {
            $path = $file->store('movs/'.$userTask->user_id, $disk);

            /** @var SubmissionFile $sf */
            $sf = SubmissionFile::create([
                'submission_id' => $submission->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);

            $storedFiles[] = $this->submissionFileToArray($sf);
        }

        $message = $isInputTask
            ? 'Optional MOV file uploaded successfully.'
            : 'File(s) uploaded successfully.';

        return response()->json([
            'message' => $message,
            'submission' => $this->submissionToArray($submission->fresh(['files', 'inputData'])),
            'files' => $storedFiles,
        ], 201);
    }

    /**
     * Mark a user task as submitted for validation (Administrative Officer).
     * Phase 4.4: Used when AO is done uploading MOVs (or input, in Phase 5).
     */
    public function submitUserTask(Request $request, int $id): JsonResponse
    {
        $this->ensureAdministrativeOfficer($request);

        /** @var UserTask $userTask */
        $userTask = UserTask::where('user_id', $request->user()->id)
            ->with('task')
            ->findOrFail($id);

        if (in_array($userTask->status, [UserTask::STATUS_SUBMITTED, UserTask::STATUS_COMPLETED], true)) {
            return response()->json([
                'message' => 'This task has already been submitted.',
                'user_task' => $this->userTaskToDashboardItem($userTask),
            ], 422);
        }

        // For upload-type tasks, require at least one MOV file before submitting.
        if ($userTask->task?->action === Task::ACTION_UPLOAD) {
            $hasFile = SubmissionFile::whereHas('submission', function ($q) use ($userTask) {
                $q->where('user_task_id', $userTask->id)
                    ->where('type', 'upload');
            })->exists();

            if (! $hasFile) {
                return response()->json([
                    'message' => 'Upload at least one MOV file before submitting this task for validation.',
                ], 422);
            }
        }

        // For input-type tasks, require input data to be saved before submitting.
        if ($userTask->task?->action === Task::ACTION_INPUT) {
            $hasInput = Submission::where('user_task_id', $userTask->id)
                ->where('type', 'input')
                ->whereHas('inputData')
                ->exists();

            if (! $hasInput) {
                return response()->json([
                    'message' => 'Save input data before submitting this task for validation.',
                ], 422);
            }
        }

        $userTask->status = UserTask::STATUS_SUBMITTED;
        // completed_at will be set when validation is approved (Phase 4.6/4.7).
        $userTask->save();

        // Phase 6.6 — Notify School Head(s) that a validation is pending.
        // This creates in-app reminders for School Heads, and optionally sends email.
        if (config('reminders.school_head.enabled')) {
            /** @var User $ao */
            $ao = $request->user();

            $schoolHeads = User::query()
                ->where('role', 'school_head')
                ->where('status', 'active')
                ->when(is_string($ao->school_name) && $ao->school_name !== '', function ($q) use ($ao) {
                    $q->where('school_name', $ao->school_name);
                })
                ->get();

            // Use a consistent timestamp so repeated submits are idempotent at the DB level.
            $remindAt = now()->startOfMinute();

            foreach ($schoolHeads as $sh) {
                $reminder = Reminder::firstOrCreate(
                    [
                        'user_id' => $sh->id,
                        'user_task_id' => $userTask->id,
                        'remind_at' => $remindAt,
                        'channel' => config('reminders.default_channel', 'in_app'),
                        'type' => 'submission_pending',
                    ],
                    [
                        'status' => Reminder::STATUS_UNREAD,
                    ]
                );

                if ($reminder->wasRecentlyCreated && config('reminders.email.enabled') && $sh->email) {
                    $userTask->loadMissing('task');
                    $sh->notify(new SubmissionPendingValidationNotification($userTask, $ao));
                }
            }
        }

        return response()->json([
            'message' => 'Task submitted for validation.',
            'user_task' => $this->userTaskToDashboardItem($userTask),
        ]);
    }

    /**
     * List submissions pending validation for School Head.
     * Phase 4.6: Used by School Head validation UI.
     */
    public function pendingValidations(Request $request): JsonResponse
    {
        $this->ensureSchoolHead($request);

        $schoolName = $request->query('school_name');

        $submissions = Submission::with(['files', 'inputData', 'userTask.task', 'userTask.user'])
            ->whereIn('type', ['upload', 'input'])
            // Only tasks that are currently submitted
            ->whereHas('userTask', function ($q) {
                $q->where('status', UserTask::STATUS_SUBMITTED);
            })
            // Optional filter by AO school_name
            ->when(is_string($schoolName) && $schoolName !== '', function ($q) use ($schoolName) {
                $q->whereHas('userTask.user', function ($uq) use ($schoolName) {
                    $uq->where('school_name', $schoolName);
                });
            })
            // Exclude submissions that already have an approved validation
            ->whereDoesntHave('validations', function ($q) {
                $q->where('status', 'approved');
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'submissions' => $submissions->map(fn (Submission $s) => $this->submissionToArray($s)),
        ]);
    }

    /**
     * Approve or reject a submission (School Head).
     * Phase 4.6: Creates a validation record and updates the user task status.
     */
    public function validateSubmission(Request $request, int $id): JsonResponse
    {
        $this->ensureSchoolHead($request);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['approved', 'rejected'])],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var Submission $submission */
        $submission = Submission::with('userTask')->findOrFail($id);

        /** @var UserTask|null $userTask */
        $userTask = $submission->userTask;

        if (! $userTask) {
            return response()->json([
                'message' => 'This submission is not linked to a task.',
            ], 422);
        }

        if ($userTask->status !== UserTask::STATUS_SUBMITTED) {
            return response()->json([
                'message' => 'Only submitted tasks can be validated.',
            ], 422);
        }

        $status = $validated['status'];
        $feedback = isset($validated['feedback']) ? trim($validated['feedback']) : null;

        DB::transaction(function () use ($request, $submission, $userTask, $status, $feedback) {
            Validation::create([
                'submission_id' => $submission->id,
                'validator_id' => $request->user()->id,
                'status' => $status,
                'feedback' => $feedback,
                'validated_at' => now(),
            ]);

            if ($status === 'approved') {
                $userTask->status = UserTask::STATUS_COMPLETED;
                $userTask->completed_at = now();
            } else {
                // Rejected: send back to Pending so AO can re-work and re-submit.
                $userTask->status = UserTask::STATUS_PENDING;
                $userTask->completed_at = null;
            }

            $userTask->save();
        });

        $userTask->refresh();

        return response()->json([
            'message' => $status === 'approved'
                ? 'Submission approved successfully.'
                : 'Submission rejected with feedback.',
            'user_task' => $this->userTaskToDashboardItem($userTask),
        ]);
    }

    private function taskToArray(Task $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'submission_date_rule' => $t->submission_date_rule,
            'frequency' => $t->frequency,
            'mov_description' => $t->mov_description,
            'action' => $t->action,
            'is_common' => $t->is_common,
            'common_report_no' => $t->common_report_no,
            'created_at' => $t->created_at->toIso8601String(),
            'updated_at' => $t->updated_at->toIso8601String(),
        ];
    }

    private function userTaskToDashboardItem(UserTask $ut): array
    {
        return [
            'id' => $ut->id,
            'task_id' => $ut->task_id,
            'due_date' => $ut->due_date->format('Y-m-d'),
            'status' => $ut->status,
            'period_covered' => $ut->period_covered,
            'completed_at' => $ut->completed_at?->toIso8601String(),
            'task' => $ut->task ? $this->taskToArray($ut->task) : null,
            // When loaded for School Head views, also include basic personnel info.
            'user' => $ut->relationLoaded('user') && $ut->user ? [
                'id' => $ut->user->id,
                'name' => $ut->user->name,
                'email' => $ut->user->email,
                'employee_id' => $ut->user->employee_id,
                'position' => $ut->user->position,
                'division' => $ut->user->division,
                'school_name' => $ut->user->school_name,
            ] : null,
        ];
    }

    /**
     * Map a submission (with files) to array for API responses.
     */
    private function submissionToArray(Submission $s): array
    {
        return [
            'id' => $s->id,
            'user_task_id' => $s->user_task_id,
            'type' => $s->type,
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
            'files' => $s->relationLoaded('files')
                ? $s->files->map(fn (SubmissionFile $sf) => $this->submissionFileToArray($sf))->all()
                : [],
            'input_data' => $s->relationLoaded('inputData') && $s->inputData
                ? $this->inputDataToArray($s->inputData)
                : null,
            'user_task' => $s->relationLoaded('userTask') ? $this->userTaskToDashboardItem($s->userTask) : null,
        ];
    }

    /**
     * Map input data to array for API responses (Phase 5.2).
     */
    private function inputDataToArray(InputData $d): array
    {
        return [
            'id' => $d->id,
            'submission_id' => $d->submission_id,
            'period' => $d->period,
            'reference_no' => $d->reference_no,
            'amount' => $d->amount !== null ? (float) $d->amount : null,
            'notes' => $d->notes,
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Map a submission file to array for API responses.
     */
    private function submissionFileToArray(SubmissionFile $sf): array
    {
        return [
            'id' => $sf->id,
            'submission_id' => $sf->submission_id,
            'disk' => $sf->disk,
            'path' => $sf->path,
            'original_name' => $sf->original_name,
            'mime_type' => $sf->mime_type,
            'size' => $sf->size,
            'created_at' => $sf->created_at?->toIso8601String(),
            'updated_at' => $sf->updated_at?->toIso8601String(),
            // Direct URL for downloads/previews (public disk = APP_URL/storage/{path}).
            'url' => Storage::disk($sf->disk)->url($sf->path),
        ];
    }

    /**
     * Serve a file from the public storage disk (MOVs, avatars, etc.).
     * Public (no auth) so img preview and "View file" in new tab work without redirect to login.
     * Paths are unguessable (e.g. movs/{user_id}/{random}.png).
     */
    public function serveStorage(Request $request, string $path): StreamedResponse
    {
        $path = str_replace(['../', '..\\'], '', $path);
        if ($path === '' || str_contains($path, '..')) {
            abort(404, 'File not found.');
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            abort(404, 'File not found.');
        }

        $mimeType = $disk->mimeType($path);
        $name = basename($path);

        return $disk->response($path, $name, [
            'Content-Type' => $mimeType ?: 'application/octet-stream',
        ]);
    }

    /**
     * How many upcoming occurrences to pre-generate for a task's schedule so
     * personnel timelines look "full" and professional.
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
}
