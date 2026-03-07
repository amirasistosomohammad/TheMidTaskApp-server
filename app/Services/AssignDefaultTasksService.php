<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Carbon\Carbon;

/**
 * Assigns standard (non-personal) tasks from the central Task list to an Administrative Officer.
 * Used when approving a user with "Assign standard tasks" checked, and by AssignDefaultTasksJob.
 */
class AssignDefaultTasksService
{
    public function __construct(
        private DueDateService $dueDateService
    ) {}

    /**
     * Create upcoming user_tasks for the officer so their timeline is populated on first sign-in.
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

        $today = Carbon::now()->startOfDay();

        foreach ($tasks as $task) {
            $count = $this->defaultScheduleCount($task);
            $dates = $this->dueDateService->generateDueDates($task, $today, $count);

            foreach ($dates as $d) {
                $due = $d->format('Y-m-d');

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

    private function defaultScheduleCount(Task $task): int
    {
        return match ($task->frequency) {
            Task::FREQUENCY_MONTHLY => 12,
            Task::FREQUENCY_TWICE_A_YEAR => 2,
            Task::FREQUENCY_QUARTERLY => 4,
            Task::FREQUENCY_EVERY_TWO_MONTHS => 6,
            Task::FREQUENCY_YEARLY,
            Task::FREQUENCY_END_OF_SY => 1,
            'once_or_twice_a_year' => 2,
            default => 1,
        };
    }
}
