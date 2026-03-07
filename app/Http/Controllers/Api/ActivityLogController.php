<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central Admin only: list activity/audit logs with filters and pagination.
 */
class ActivityLogController extends Controller
{
    private function ensureCentralAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'central_admin') {
            abort(403, 'Access denied. Central Administrative Officer only.');
        }
    }

    /**
     * List activity logs with optional filters and pagination.
     * Query: page, per_page, action, date_from (Y-m-d), date_to (Y-m-d), search (description or actor name).
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $query = ActivityLog::query()->with('actor:id,name,email');

        if ($action = $request->query('action')) {
            $query->where('action', (string) $action);
        }

        if ($dateFrom = $request->query('date_from')) {
            try {
                $d = Carbon::parse($dateFrom)->startOfDay();
                $query->where('created_at', '>=', $d);
            } catch (\Throwable $e) {
                // Ignore invalid date_from.
            }
        }

        if ($dateTo = $request->query('date_to')) {
            try {
                $d = Carbon::parse($dateTo)->endOfDay();
                $query->where('created_at', '<=', $d);
            } catch (\Throwable $e) {
                // Ignore invalid date_to.
            }
        }

        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', '%' . $search . '%')
                    ->orWhereHas('actor', function ($aq) use ($search) {
                        $aq->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
            });
        }

        $query->orderBy('created_at', 'desc');
        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection()->map(function (ActivityLog $log) {
            $createdAt = $log->created_at;
            // DB stores server local time (e.g. Asia/Manila); interpret as that then convert to UTC for API.
            $storageTz = 'Asia/Manila';
            $utcIso = $createdAt
                ? Carbon::parse($createdAt->format('Y-m-d H:i:s'), $storageTz)->setTimezone('UTC')->toIso8601String()
                : null;
            return [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'meta' => $log->meta,
                'ip_address' => $log->ip_address,
                'created_at' => $utcIso,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                    'email' => $log->actor->email,
                ] : null,
            ];
        });

        return response()->json([
            'logs' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'actions' => ActivityLog::query()->distinct()->pluck('action')->sort()->values()->toArray(),
        ]);
    }
}
