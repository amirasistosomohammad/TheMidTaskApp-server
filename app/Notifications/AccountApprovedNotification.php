<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $approvedRemarks = null
    ) {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $name = $notifiable->name ?: 'User';
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $loginUrl = $frontendUrl ? "{$frontendUrl}/login" : null;

        return (new MailMessage())
            ->subject('Your account has been approved')
            ->view('emails.account-approved', [
                'name' => $name,
                'remarks' => $this->approvedRemarks,
                'url' => $loginUrl,
            ]);
    }
}
