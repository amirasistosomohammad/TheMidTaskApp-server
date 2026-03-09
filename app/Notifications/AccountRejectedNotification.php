<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $rejectionRemarks = null
    ) {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $name = $notifiable->name ?: 'User';

        return (new MailMessage())
            ->subject('Your account registration was not approved')
            ->view('emails.account-rejected', [
                'name' => $name,
                'remarks' => $this->rejectionRemarks,
            ]);
    }
}
