<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assigns standard (non-personal) tasks from the central Task list to an Administrative Officer.
 * Optimized for inline execution: fewer occurrences per task, single existing-lookup, bulk insert.
 */
class AssignDefaultTasksService
{
    /** Lighter occurrence counts so approval stays fast (no queue). */
    private const SCHEDULE_COUNTS = [
        Task::FREQUENCY_MONTHLY => 6,
        Task::FREQUENCY_TWICE_A_YEAR => 2,
        Task::FREQUENCY_QUARTERLY => 2,
        Task::FREQUENCY_EVERY_TWO_MONTHS => 3,
        Task::FREQUENCY_YEARLY => 1,
        Task::FREQUENCY_END_OF_SY => 1,
        'once_or_twice_a_year' => 2,
    ];

    public function __construct(
        private DueDateService $dueDateService
    ) {}

    /**
     * Create upcoming user_tasks for the officer. One query to load existing, one bulk insert.
     */
    public function assign(User $user): void
    {
        if ($user->role !== 'administrative_officer') {
            return;
        }

        $tasks = Task::where('is_personal', false)
            ->orderBy('is_common', 'desc')
            ->orderBy('common_report_no')
            ->orderBy('name')
            ->get();

        if ($tasks->isEmpty()) {
            return;
        }

        $existing = $this->existingPendingKeys($user->id);

        $today = Carbon::now()->startOfDay();
        $now = now();
        $rows = [];

        /** @var Task $task */
        foreach ($tasks as $task) {
            $count = self::SCHEDULE_COUNTS[$task->frequency] ?? 1;
            $dates = $this->dueDateService->generateDueDates($task, $today, $count);

            foreach ($dates as $d) {
                $due = $d->format('Y-m-d');
                $key = $task->id . '|' . $due;
                if (isset($existing[$key])) {
                    continue;
                }
                $existing[$key] = true;

                $rows[] = [
                    'user_id' => $user->id,
                    'task_id' => $task->id,
                    'due_date' => $due,
                    'status' => UserTask::STATUS_PENDING,
                    'period_covered' => $d->format('Y-m'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            foreach (array_chunk($rows, 100) as $chunk) {
                UserTask::insert($chunk);
            }
        }
    }

    /** @return array<string, true> key = "task_id|due_date" */
    private function existingPendingKeys(int $userId): array
    {
        $pairs = UserTask::where('user_id', $userId)
            ->where('status', UserTask::STATUS_PENDING)
            ->get(['task_id', 'due_date']);

        $out = [];
        foreach ($pairs as $row) {
            $due = $row->due_date instanceof \Carbon\Carbon
                ? $row->due_date->format('Y-m-d')
                : $row->due_date;
            $out[$row->task_id . '|' . $due] = true;
        }
        return $out;
    }
}
