<?php

namespace App\Services;

use App\Models\Task;
use Carbon\Carbon;

/**
 * Computes due dates from task submission_date_rule and frequency.
 * Used when Central Admin assigns tasks or generates recurring user_tasks.
 *
 * DepEd PH: School year ends March 31.
 */
class DueDateService
{
    /** DepEd PH school year ends in March. */
    private const END_OF_SY_MONTH = 3;

    /** Default day when rule has no specific day (e.g. monthly with no rule). */
    private const DEFAULT_DAY = 15;

    /**
     * Compute the next due date for a task from a given date.
     *
     * @param  Task  $task
     * @param  Carbon|null  $from  Start from this date (default: today)
     * @return Carbon
     */
    public function computeNextDueDate(Task $task, ?Carbon $from = null): Carbon
    {
        $from = $from ?? Carbon::today();
        $rule = $task->submission_date_rule;
        $frequency = $task->frequency;

        return match ($frequency) {
            Task::FREQUENCY_MONTHLY => $this->nextMonthly($rule, $from),
            Task::FREQUENCY_TWICE_A_YEAR => $this->nextTwiceYearly($rule, $from),
            Task::FREQUENCY_YEARLY => $this->nextYearly($rule, $from),
            Task::FREQUENCY_END_OF_SY => $this->nextEndOfSy($rule, $from),
            Task::FREQUENCY_QUARTERLY => $this->nextQuarterly($rule, $from),
            Task::FREQUENCY_EVERY_TWO_MONTHS => $this->nextEveryTwoMonths($rule, $from),
            'once_or_twice_a_year' => $this->nextTwiceYearly('June & December', $from),
            default => $this->nextYearly($rule, $from),
        };
    }

    /**
     * Generate the next N due dates for a task (for bulk assignment).
     *
     * @param  Task  $task
     * @param  Carbon|null  $from
     * @param  int  $count
     * @return array<Carbon>
     */
    public function generateDueDates(Task $task, ?Carbon $from = null, int $count = 12): array
    {
        $from = $from ?? Carbon::today();
        $dates = [];
        $current = $from->copy();

        for ($i = 0; $i < $count; $i++) {
            $next = $this->computeNextDueDate($task, $current);
            $dates[] = $next->copy();
            $current = $next->copy()->addDay();
        }

        return $dates;
    }

    /**
     * Monthly: "6th day of the month", "5th day of the month", etc.
     */
    private function nextMonthly(?string $rule, Carbon $from): Carbon
    {
        $day = $this->parseDayOfMonth($rule);
        $candidate = $from->copy()->day($day);

        if ($candidate->lte($from)) {
            $candidate->addMonth();
        }

        return $candidate->day(min($day, $candidate->daysInMonth));
    }

    /**
     * Twice a year: "June & December" or similar.
     */
    private function nextTwiceYearly(?string $rule, Carbon $from): Carbon
    {
        $months = $this->parseMonthPair($rule);

        if (empty($months)) {
            return $from->copy()->addMonths(6)->endOfMonth();
        }

        foreach ($months as $month) {
            $candidate = $from->copy()->month($month)->endOfMonth();
            if ($candidate->gt($from)) {
                return $candidate;
            }
        }

        return $from->copy()->addYear()->month($months[0])->endOfMonth();
    }

    /**
     * Yearly: default to end of year (Dec 31) when no rule.
     */
    private function nextYearly(?string $rule, Carbon $from): Carbon
    {
        $months = $this->parseMonthPair($rule);

        if (! empty($months)) {
            $candidate = $from->copy()->month($months[0])->endOfMonth();
            if ($candidate->lte($from)) {
                $candidate->addYear()->month($months[0])->endOfMonth();
            }

            return $candidate;
        }

        $candidate = $from->copy()->month(12)->endOfMonth();
        if ($candidate->lte($from)) {
            $candidate->addYear()->month(12)->endOfMonth();
        }

        return $candidate;
    }

    /**
     * End of school year (DepEd PH: March 31).
     */
    private function nextEndOfSy(?string $rule, Carbon $from): Carbon
    {
        $candidate = $from->copy()->month(self::END_OF_SY_MONTH)->endOfMonth();

        if ($candidate->lte($from)) {
            $candidate->addYear()->month(self::END_OF_SY_MONTH)->endOfMonth();
        }

        return $candidate;
    }

    /**
     * Quarterly: end of quarter (Mar 31, Jun 30, Sep 30, Dec 31).
     */
    private function nextQuarterly(?string $rule, Carbon $from): Carbon
    {
        $quarterMonths = [3, 6, 9, 12];

        foreach ($quarterMonths as $m) {
            $candidate = $from->copy()->month($m)->endOfMonth();
            if ($candidate->gt($from)) {
                return $candidate;
            }
        }

        return $from->copy()->addYear()->month(3)->endOfMonth();
    }

    /**
     * Every 2 months: Jan, Mar, May, Jul, Sep, Nov (odd months, end of month).
     */
    private function nextEveryTwoMonths(?string $rule, Carbon $from): Carbon
    {
        $bimonthlyMonths = [1, 3, 5, 7, 9, 11];

        foreach ($bimonthlyMonths as $m) {
            $candidate = $from->copy()->month($m)->endOfMonth();
            if ($candidate->gt($from)) {
                return $candidate;
            }
        }

        return $from->copy()->addYear()->month(1)->endOfMonth();
    }

    /**
     * Parse "6th day of the month" -> 6, "15th" -> 15.
     */
    private function parseDayOfMonth(?string $rule): int
    {
        if (empty($rule) || ! is_string($rule)) {
            return self::DEFAULT_DAY;
        }

        if (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?\s*(?:day\s+of\s+the\s+month)?/i', trim($rule), $m)) {
            $day = (int) $m[1];

            return max(1, min(31, $day));
        }

        return self::DEFAULT_DAY;
    }

    /**
     * Parse month names in rules like "June & December" -> [6, 12].
     * Supports full and short English month names, matching the client utility.
     */
    private function parseMonthPair(?string $rule): array
    {
        if (empty($rule) || ! is_string($rule)) {
            return [];
        }

        $months = [
            'january' => 1, 'jan' => 1,
            'february' => 2, 'feb' => 2,
            'march' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4,
            'may' => 5,
            'june' => 6, 'jun' => 6,
            'july' => 7, 'jul' => 7,
            'august' => 8, 'aug' => 8,
            'september' => 9, 'sep' => 9,
            'october' => 10, 'oct' => 10,
            'november' => 11, 'nov' => 11,
            'december' => 12, 'dec' => 12,
        ];

        $found = [];
        $lower = strtolower($rule);

        foreach ($months as $name => $num) {
            if (str_contains($lower, $name)) {
                $found[] = $num;
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }
}
