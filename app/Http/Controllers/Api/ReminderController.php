<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    /**
     * List reminders for the authenticated Administrative Officer.
     * Phase 6.3: Used by AO dashboard "Upcoming reminders / Due soon".
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $status = $request->query('status', Reminder::STATUS_UNREAD);

        $query = Reminder::with(['userTask.task'])
            ->where('user_id', $user->id)
            ->orderBy('remind_at');

        if ($status === Reminder::STATUS_UNREAD) {
            $query->where('status', Reminder::STATUS_UNREAD);
        } elseif ($status === Reminder::STATUS_READ) {
            $query->where('status', Reminder::STATUS_READ);
        }

        $limit = (int) $request->query('limit', 50);
        if ($limit <= 0) {
            $limit = 50;
        } elseif ($limit > 200) {
            $limit = 200;
        }

        $reminders = $query->limit($limit)->get();

        return response()->json([
            'reminders' => $reminders->map(function (Reminder $r): array {
                $userTask = $r->relationLoaded('userTask') ? $r->userTask : null;
                $task = $userTask?->relationLoaded('task') ? $userTask->task : null;

                return [
                    'id' => $r->id,
                    'user_task_id' => $r->user_task_id,
                    'remind_at' => $r->remind_at?->toIso8601String(),
                    'channel' => $r->channel,
                    'type' => $r->type,
                    'days_before_due' => $r->days_before_due,
                    'status' => $r->status,
                    'read_at' => $r->read_at?->toIso8601String(),
                    'created_at' => $r->created_at?->toIso8601String(),
                    'updated_at' => $r->updated_at?->toIso8601String(),
                    'task' => $task ? [
                        'id' => $task->id,
                        'name' => $task->name,
                    ] : null,
                    'user_task' => $userTask ? [
                        'id' => $userTask->id,
                        'due_date' => $userTask->due_date?->toDateString(),
                        'status' => $userTask->status,
                    ] : null,
                ];
            }),
        ]);
    }

    /**
     * Mark a reminder as read.
     * Phase 6.3: AO dismisses a reminder after viewing it.
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        /** @var Reminder $reminder */
        $reminder = Reminder::where('user_id', $user->id)->findOrFail($id);

        if ($reminder->status === Reminder::STATUS_UNREAD) {
            $reminder->status = Reminder::STATUS_READ;
            $reminder->read_at = now();
            $reminder->save();
        }

        return response()->json([
            'reminder' => [
                'id' => $reminder->id,
                'status' => $reminder->status,
                'read_at' => $reminder->read_at?->toIso8601String(),
            ],
        ]);
    }
}

