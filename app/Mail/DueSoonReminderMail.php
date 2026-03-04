<?php

namespace App\Mail;

use App\Models\UserTask;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DueSoonReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public UserTask $userTask,
        public int $daysBeforeDue
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $taskName = $this->userTask->task?->name ?? 'Task';

        return new Envelope(
            subject: "Reminder: {$taskName} is due soon",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.due-soon-reminder',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}

