<?php

use App\Models\Task;
use App\Services\DueDateService;
use Carbon\Carbon;

beforeEach(function () {
    $this->service = new DueDateService();
});

test('monthly 6th day computes next due date', function () {
    $task = new Task([
        'submission_date_rule' => '6th day of the month',
        'frequency' => Task::FREQUENCY_MONTHLY,
    ]);
    $from = Carbon::parse('2025-02-01');
    $next = $this->service->computeNextDueDate($task, $from);
    expect($next->format('Y-m-d'))->toBe('2025-02-06');

    $from = Carbon::parse('2025-02-10');
    $next = $this->service->computeNextDueDate($task, $from);
    expect($next->format('Y-m-d'))->toBe('2025-03-06');
});

test('twice yearly June and December computes next due date', function () {
    $task = new Task([
        'submission_date_rule' => 'June & December',
        'frequency' => Task::FREQUENCY_TWICE_A_YEAR,
    ]);
    $from = Carbon::parse('2025-02-01');
    $next = $this->service->computeNextDueDate($task, $from);
    expect($next->format('Y-m-d'))->toBe('2025-06-30');

    $from = Carbon::parse('2025-07-01');
    $next = $this->service->computeNextDueDate($task, $from);
    expect($next->format('Y-m-d'))->toBe('2025-12-31');
});

test('end of school year computes March 31', function () {
    $task = new Task([
        'submission_date_rule' => 'End of school year (SY)',
        'frequency' => Task::FREQUENCY_END_OF_SY,
    ]);
    $from = Carbon::parse('2025-02-01');
    $next = $this->service->computeNextDueDate($task, $from);
    expect($next->format('Y-m-d'))->toBe('2025-03-31');

    $from = Carbon::parse('2025-04-01');
    $next = $this->service->computeNextDueDate($task, $from);
    expect($next->format('Y-m-d'))->toBe('2026-03-31');
});

test('quarterly computes end of quarter', function () {
    $task = new Task([
        'submission_date_rule' => null,
        'frequency' => Task::FREQUENCY_QUARTERLY,
    ]);
    $from = Carbon::parse('2025-02-15');
    $next = $this->service->computeNextDueDate($task, $from);
    expect($next->format('Y-m-d'))->toBe('2025-03-31');
});

test('generateDueDates returns multiple dates for monthly task', function () {
    $task = new Task([
        'submission_date_rule' => '10th day of the month',
        'frequency' => Task::FREQUENCY_MONTHLY,
    ]);
    $from = Carbon::parse('2025-01-01');
    $dates = $this->service->generateDueDates($task, $from, 3);
    expect($dates)->toHaveCount(3);
    expect($dates[0]->format('Y-m-d'))->toBe('2025-01-10');
    expect($dates[1]->format('Y-m-d'))->toBe('2025-02-10');
    expect($dates[2]->format('Y-m-d'))->toBe('2025-03-10');
});
