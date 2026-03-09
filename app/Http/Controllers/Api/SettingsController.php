<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * CA-SET-1.3: GET system settings (public, for layout).
 * CA-SET-1.4: PUT system settings (Central Admin only). CA-SET-1.5: logo upload.
 */
class SettingsController extends Controller
{
    private function ensureCentralAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'central_admin') {
            abort(403, 'Access denied. Central Administrative Officer only.');
        }
    }

    /**
     * Build logo URL so it works in deployment. Use the API storage route (GET /api/storage/{path})
     * so the file is served by Laravel instead of the web server—avoids 403 when public/storage
     * symlink is missing or blocked (e.g. DigitalOcean App Platform).
     */
    private function settingsResponse(SystemSetting $s): JsonResponse
    {
        $logoUrl = null;
        if ($s->logo_path) {
            $path = ltrim(str_replace(['../', '..\\'], '', $s->logo_path), '/');
            if ($path !== '' && ! str_contains($path, '..')) {
                $logoUrl = \Illuminate\Support\Facades\URL::to('api/storage/' . $path);
            }
        }

        return response()->json([
            'app_name' => $s->app_name,
            'logo_url' => $logoUrl,
            'tagline' => $s->tagline,
        ]);
    }

    /**
     * GET /api/settings — app_name, logo_url, tagline. Public for layout/login.
     */
    public function index(): JsonResponse
    {
        $s = SystemSetting::get();
        return $this->settingsResponse($s);
    }

    /**
     * PUT /api/admin/settings — update app_name, tagline. Central Admin only.
     */
    public function update(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $valid = $request->validate([
            'app_name' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:500'],
        ]);

        $s = SystemSetting::get();
        $before = [
            'app_name' => $s->app_name,
            'tagline' => $s->tagline,
        ];
        $s->update([
            'app_name' => $valid['app_name'],
            'tagline' => $valid['tagline'] ?? null,
        ]);
        SystemSetting::clearCache();

        ActivityLog::log(
            $request->user()->id,
            'settings_updated',
            'Updated system settings (app name / tagline)',
            [
                'before' => $before,
                'after' => [
                    'app_name' => $valid['app_name'],
                    'tagline' => $valid['tagline'] ?? null,
                ],
            ],
            $request
        );

        return $this->settingsResponse($s->fresh());
    }

    /**
     * POST /api/admin/settings/logo — upload logo image. Central Admin only.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $this->ensureCentralAdmin($request);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp,svg', 'max:2048'],
        ]);

        $s = SystemSetting::get();

        if ($s->logo_path && Storage::disk('public')->exists($s->logo_path)) {
            Storage::disk('public')->delete($s->logo_path);
        }

        $path = $request->file('logo')->store('settings', 'public');
        $s->update(['logo_path' => $path]);
        SystemSetting::clearCache();

        ActivityLog::log(
            $request->user()->id,
            'settings_logo_updated',
            'Updated system logo',
            ['logo_path' => $path],
            $request
        );

        return $this->settingsResponse($s->fresh());
    }
}
