<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\UserTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubmissionPendingValidationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public UserTask $userTask,
        public User $submittedBy
    ) {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $task = $this->userTask->task;
        $taskName = $task?->name ?? 'Task';
        $aoName = $this->submittedBy->name ?: 'Administrative Officer';
        $school = $this->submittedBy->school_name ?: '—';
        // due_date is cast as 'date' but can vary by tooling; cast to string safely.
        $dueDate = $this->userTask->due_date ? (string) $this->userTask->due_date : '—';

        return (new MailMessage())
            ->subject("Validation needed: {$taskName}")
            ->greeting('Good day,')
            ->line("A task has been submitted for validation.")
            ->line("Task: {$taskName}")
            ->line("Submitted by: {$aoName}")
            ->line("School: {$school}")
            ->line("Due date: {$dueDate}")
            ->action('Open validations', url(config('app.url') . '/dashboard'))
            ->line('This is an automated notification from The Mid-Task App.');
    }
}

