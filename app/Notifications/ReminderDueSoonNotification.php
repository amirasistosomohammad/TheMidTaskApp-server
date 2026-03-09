<?php

namespace App\Notifications;

use App\Models\Reminder;
use App\Models\UserTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReminderDueSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public UserTask $userTask,
        public Reminder $reminder,
    ) {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $task = $this->userTask->relationLoaded('task') ? $this->userTask->task : null;
        $taskName = $task?->name ?? 'Task';
        $dueDate = $this->userTask->due_date?->format('F j, Y') ?? 'no due date';

        $subject = "Reminder: {$taskName} due on {$dueDate}";

        $lineWhen = match ($this->reminder->days_before_due) {
            0 => 'This task is due today.',
            1 => 'This task is due in 1 day.',
            default => "This task is due in {$this->reminder->days_before_due} days.",
        };

        $appUrl = rtrim((string) config('app.frontend_url', config('app.url', 'http://localhost:5173')), '/');
        $taskUrl = $appUrl ? "{$appUrl}/dashboard/my-tasks/{$this->userTask->id}" : null;

        return (new MailMessage())
            ->subject($subject)
            ->view('emails.reminder-due-soon', [
                'taskName' => $taskName,
                'dueDate' => $dueDate,
                'lineWhen' => $lineWhen,
                'url' => $taskUrl,
            ]);
    }
}

