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

        $appUrl = rtrim((string) config('app.url'), '/');
        $taskUrl = $appUrl ? "{$appUrl}/dashboard/my-tasks/{$this->userTask->id}" : null;

        $mail = (new MailMessage())
            ->subject($subject)
            ->greeting('Good day,')
            ->line("This is an automatic reminder for your assigned task \"{$taskName}\".")
            ->line($lineWhen)
            ->line("Due date: {$dueDate}")
            ->line('This message was sent automatically by The Mid-Task App.');

        if ($taskUrl) {
            $mail->action('View task', $taskUrl);
        }

        return $mail;
    }
}

