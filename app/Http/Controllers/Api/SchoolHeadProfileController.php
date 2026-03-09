<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SchoolHeadProfileController extends Controller
{
    /**
     * Ensure the authenticated user is a School Head.
     */
    private function ensureSchoolHead(Request $request): void
    {
        if ($request->user()?->role !== 'school_head') {
            abort(403, 'Access denied. School Head only.');
        }
    }

    /**
     * Get the current School Head's digital signature URL.
     * GET /api/school-head/profile/signature
     */
    public function getSignature(Request $request): JsonResponse
    {
        $this->ensureSchoolHead($request);
        $user = $request->user();

        return response()->json([
            'data' => [
                'signature_url' => $user->signature_url,
            ],
        ]);
    }

    /**
     * Upload or remove the School Head's digital signature.
     * POST /api/school-head/profile/signature
     * - With multipart "signature" file: upload new signature (PNG/JPG, max 2 MB).
     * - With remove_signature=1: remove existing signature.
     */
    public function updateSignature(Request $request): JsonResponse
    {
        $this->ensureSchoolHead($request);
        $user = $request->user();

        if ($request->has('remove_signature') && $request->input('remove_signature') === '1') {
            return $this->removeSignature($user);
        }

        $request->validate([
            'signature' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:2048'],
        ]);

        if ($user->signature_url) {
            $baseUrl = rtrim(\Illuminate\Support\Facades\URL::to('api/storage'), '/');
            $oldPath = str_starts_with($user->signature_url, $baseUrl . '/')
                ? substr($user->signature_url, strlen($baseUrl) + 1)
                : null;
            if ($oldPath) {
                try {
                    if (\Illuminate\Support\Facades\Storage::disk('public')->exists($oldPath)) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
                    }
                } catch (\Throwable $e) {
                    // Ignore deletion errors
                }
            }
        }

        $path = ltrim($request->file('signature')->store('signatures', 'public'), '/');
        if ($path !== '') {
            $url = \Illuminate\Support\Facades\URL::to('api/storage/' . $path);
            $user->update(['signature_url' => $url]);
        } else {
            $url = null;
        }

        return response()->json([
            'message' => 'Signature updated successfully.',
            'data' => [
                'signature_url' => $url,
            ],
        ]);
    }

    private function removeSignature($user): JsonResponse
    {
        if ($user->signature_url) {
            $baseUrl = rtrim(\Illuminate\Support\Facades\URL::to('api/storage'), '/');
            $oldPath = str_starts_with($user->signature_url, $baseUrl . '/')
                ? substr($user->signature_url, strlen($baseUrl) + 1)
                : null;
            if ($oldPath) {
                try {
                    if (\Illuminate\Support\Facades\Storage::disk('public')->exists($oldPath)) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }
        $user->update(['signature_url' => null]);

        return response()->json([
            'message' => 'Signature removed successfully.',
            'data' => [
                'signature_url' => null,
            ],
        ]);
    }
}
