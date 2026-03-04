<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Models\UserTask;
use App\Notifications\ReminderDueSoonNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateDueSoonReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:generate-due-soon';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate due-soon reminders for upcoming user tasks based on days_before_due rules.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Reminder rules from config (Phase 6.1).
        $daysBeforeDueRules = config('reminders.days_before_due', [3, 1, 0]);

        $now = CarbonImmutable::now();
        $today = $now->startOfDay();

        $createdCount = 0;

        foreach ($daysBeforeDueRules as $daysBefore) {
            $targetDate = $today->addDays($daysBefore);

            // Use a consistent remind_at time so the command is idempotent;
            // running it multiple times in a day will not create duplicates.
            $remindAt = $targetDate->setTime(8, 0);

            /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserTask> $userTasks */
            $userTasks = UserTask::query()
                ->whereDate('due_date', $targetDate->toDateString())
                ->where('status', UserTask::STATUS_PENDING)
                ->with(['user', 'task'])
                ->get();

            foreach ($userTasks as $userTask) {
                if (! $userTask->user) {
                    continue;
                }

                $reminder = Reminder::firstOrCreate(
                    [
                        'user_task_id' => $userTask->id,
                        'remind_at' => $remindAt,
                        'channel' => 'in_app',
                        'type' => 'due_soon',
                    ],
                    [
                        'user_id' => $userTask->user_id,
                        'days_before_due' => $daysBefore,
                        'status' => Reminder::STATUS_UNREAD,
                    ]
                );

                if ($reminder->wasRecentlyCreated) {
                    $createdCount++;

                    // Optional email channel (Phase 6.5).
                    if (config('reminders.email.enabled') && $userTask->user->email) {
                        $userTask->user->notify(new ReminderDueSoonNotification($userTask, $reminder));
                    }
                }
            }
        }

        $this->info("Generated {$createdCount} due-soon reminders.");

        return Command::SUCCESS;
    }
}

