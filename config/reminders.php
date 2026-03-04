<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reminder rules (Phase 6.1)
    |--------------------------------------------------------------------------
    |
    | These rules define when "due soon" reminders should be generated
    | relative to a user_task due_date.
    |
    | Example: [3, 1, 0] means:
    | - 3 days before due date
    | - 1 day before due date
    | - on the due date
    |
    */
    'days_before_due' => [3, 1, 0],

    /*
    |--------------------------------------------------------------------------
    | Default channel
    |--------------------------------------------------------------------------
    |
    | For Phase 6 we start with in-app reminders. Email/push can be added later.
    |
    */
    'default_channel' => 'in_app',

    /*
    |--------------------------------------------------------------------------
    | Email reminders (Phase 6.5)
    |--------------------------------------------------------------------------
    |
    | Optional email channel for due-soon reminders. When enabled, the
    | reminders:generate-due-soon command will also send an email notification
    | to the Administrative Officer for newly created reminders.
    |
    */
    'email' => [
        'enabled' => env('REMINDERS_EMAIL_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | School Head notifications (Phase 6.6)
    |--------------------------------------------------------------------------
    |
    | When an Administrative Officer submits a task for validation, create an
    | in-app reminder for School Head(s). Optionally send email too (reuses
    | the reminders.email.enabled toggle).
    |
    */
    'school_head' => [
        'enabled' => env('SCHOOL_HEAD_NOTIFICATIONS_ENABLED', true),
    ],
];

