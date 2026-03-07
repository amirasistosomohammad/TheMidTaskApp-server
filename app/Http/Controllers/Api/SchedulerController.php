<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Trigger Laravel scheduler via HTTP for deployments that have no cron (e.g. PaaS).
 * When SCHEDULER_TOKEN is set, GET/POST /api/scheduler/run?token=... runs schedule:run
 * so automated backup and reminders can run. Use an external cron (e.g. every minute) or
 * your platform's scheduled job to call this URL.
 */
class SchedulerController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        $token = env('SCHEDULER_TOKEN');
        if (! $token || $token === '') {
            return response()->json(['message' => 'Scheduler trigger is not configured.'], 404);
        }

        $provided = $request->query('token') ?? $request->header('X-Scheduler-Token');
        if (! is_string($provided) || ! hash_equals($token, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        Artisan::call('schedule:run');

        return response()->json([
            'message' => 'Scheduler run completed.',
            'output' => trim(Artisan::output()),
        ]);
    }
}
