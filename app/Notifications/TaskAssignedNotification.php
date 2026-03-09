<?php

namespace App\Notifications;

use App\Models\UserTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public UserTask $userTask
    ) {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $task = $this->userTask->relationLoaded('task') ? $this->userTask->task : null;
        $taskName = $task?->name ?? 'Task';
        $dueDate = $this->userTask->due_date ? \Carbon\Carbon::parse($this->userTask->due_date)->format('F j, Y') : '—';
        
        $periodCovered = null;
        if ($this->userTask->period_covered) {
            try {
                $periodCovered = \Carbon\Carbon::createFromFormat('Y-m', $this->userTask->period_covered)->format('F Y');
            } catch (\Exception $e) {
                $periodCovered = $this->userTask->period_covered;
            }
        }
        
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $taskUrl = $frontendUrl ? "{$frontendUrl}/dashboard/my-tasks/{$this->userTask->id}" : null;

        return (new MailMessage())
            ->subject("New task assigned: {$taskName}")
            ->view('emails.task-assigned', [
                'taskName' => $taskName,
                'dueDate' => $dueDate,
                'periodCovered' => $periodCovered,
                'url' => $taskUrl,
            ]);
    }
}
