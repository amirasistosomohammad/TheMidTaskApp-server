<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolHeadAssignmentController extends Controller
{
    private function ensureCentralAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'central_admin') {
            abort(403, 'Access denied. Central Administrative Officer only.');
        }
    }

    public function index(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        /** @var User $schoolHead */
        $schoolHead = User::where('id', $id)
            ->where('role', 'school_head')
            ->firstOrFail();

        $aos = $schoolHead->supervisedAdministrativeOfficers()
            ->orderBy('name')
            ->get([
                'users.id',
                'users.name',
                'users.email',
                'users.employee_id',
                'users.position',
                'users.division',
                'users.school_name',
                'users.status',
            ]);

        return response()->json([
            'school_head_id' => $schoolHead->id,
            'administrative_officers' => $aos,
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $validated = $request->validate([
            'ao_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        /** @var User $schoolHead */
        $schoolHead = User::where('id', $id)
            ->where('role', 'school_head')
            ->firstOrFail();

        /** @var User $ao */
        $ao = User::where('id', $validated['ao_id'])
            ->where('role', 'administrative_officer')
            ->where('status', 'active')
            ->firstOrFail();

        $schoolHead->supervisedAdministrativeOfficers()->syncWithoutDetaching([$ao->id]);

        return $this->index($request, $schoolHead->id);
    }

    public function destroy(Request $request, int $id, int $aoId): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        /** @var User $schoolHead */
        $schoolHead = User::where('id', $id)
            ->where('role', 'school_head')
            ->firstOrFail();

        $schoolHead->supervisedAdministrativeOfficers()->detach($aoId);

        return response()->json([
            'message' => 'Assignment removed successfully.',
        ]);
    }
}

