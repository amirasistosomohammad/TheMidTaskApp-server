<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Mail\OtpMail;
use App\Mail\ResetPasswordMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    private const OTP_EXPIRY_MINUTES = 15;

    /**
     * Register: create user with status = pending_approval. Login only after Central Admin approves (status = active).
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'employee_id' => ['required', 'string', 'max:100'],
            'position' => ['required', 'string', 'max:255'],
            'division' => ['required', 'string', 'max:255'],
            'school_name' => ['required', 'string', 'max:255'],
        ]);

        $existing = User::where('email', $validated['email'])->first();

        if ($existing && $existing->email_verified_at) {
            throw ValidationException::withMessages([
                'email' => ['This email is already registered. Please sign in.'],
            ]);
        }

        if ($existing && ! $existing->email_verified_at) {
            $existing->update([
                'name' => $validated['name'],
                'password' => $validated['password'],
                'employee_id' => $validated['employee_id'],
                'position' => $validated['position'],
                'division' => $validated['division'],
                'school_name' => $validated['school_name'],
            ]);
            $user = $existing;
        } else {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => 'administrative_officer',
                'status' => 'pending_approval',
                'employee_id' => $validated['employee_id'],
                'position' => $validated['position'],
                'division' => $validated['division'],
                'school_name' => $validated['school_name'],
            ]);
        }

        $this->sendOtpToUser($user);

        return response()->json([
            'message' => 'Registration successful. Check your email for a one-time password (OTP) to verify your account.',
            'email' => $user->email,
            'user' => $user->only(['id', 'name', 'email', 'role', 'status']),
        ], 201);
    }

    /**
     * Verify email with OTP.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => ['No account found for this email.']]);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified. You may log in after admin approval.',
            ]);
        }

        if (! $user->otp || $user->otp !== $validated['otp']) {
            throw ValidationException::withMessages(['otp' => ['Invalid or expired OTP.']]);
        }

        if ($user->otp_expires_at && $user->otp_expires_at->isPast()) {
            $user->update(['otp' => null, 'otp_expires_at' => null]);
            throw ValidationException::withMessages(['otp' => ['OTP has expired. Please request a new one.']]);
        }

        $user->update([
            'email_verified_at' => now(),
            'otp' => null,
            'otp_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Email verified successfully. You can log in after an administrator approves your account.',
        ]);
    }

    /**
     * Resend OTP to email.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => ['No account found for this email.']]);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified. You may log in after admin approval.',
            ]);
        }

        $this->sendOtpToUser($user);

        return response()->json([
            'message' => 'A new OTP has been sent to your email. It expires in ' . self::OTP_EXPIRY_MINUTES . ' minutes.',
        ]);
    }

    private function sendOtpToUser(User $user): void
    {
        $otp = (string) random_int(100000, 999999);
        $user->update([
            'otp' => $otp,
            'otp_expires_at' => now()->addMinutes(self::OTP_EXPIRY_MINUTES),
        ]);

        Mail::to($user->email)->send(new OtpMail($otp, $user->name, self::OTP_EXPIRY_MINUTES));
    }

    /**
     * Forgot password: send reset link only if account exists and email is verified.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Only send reset link if the account exists and email is verified.
        if ($user && $user->email_verified_at) {
            $token = Str::random(64);
            $hashedToken = Hash::make($token);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => $hashedToken,
                    'created_at' => now(),
                ]
            );

            $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
            $resetUrl = $frontendUrl . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($user->email);

            Mail::to($user->email)->send(new ResetPasswordMail(
                $resetUrl,
                $user->name,
                60
            ));
        }

        return response()->json([
            'message' => 'If an account exists with this email, a password reset link has been sent. Please check your inbox.',
            'success' => true,
        ]);
    }

    /**
     * Reset password: validate token and set new password.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $row = DB::table('password_reset_tokens')->where('email', $validated['email'])->first();

        if (! $row || ! Hash::check($validated['token'], $row->token)) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired. Please request a new one.'],
            ]);
        }

        $createdAt = \Carbon\Carbon::parse($row->created_at);
        if ($createdAt->copy()->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();
            throw ValidationException::withMessages([
                'token' => ['This password reset link has expired. Please request a new one.'],
            ]);
        }

        $user = User::where('email', $validated['email'])->first();
        if (! $user) {
            DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();
            throw ValidationException::withMessages([
                'email' => ['No account found for this email.'],
            ]);
        }

        $user->update(['password' => $validated['password']]);
        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        return response()->json([
            'message' => 'Your password has been reset successfully. You can now sign in with your new password.',
            'success' => true,
        ]);
    }

    /**
     * Login: validate email/password, issue Sanctum token. Only when status = active.
     * Same structure as TasDoneNa: { message, token, token_type, user }.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // Administrative Officers must verify email before login.
        if ($user->role === 'administrative_officer' && ! $user->email_verified_at) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if ($user->status === 'rejected') {
            return response()->json([
                'message' => 'Your account has been rejected.',
                'rejection_remarks' => $user->rejection_remarks ? trim($user->rejection_remarks) : null,
            ], 403);
        }

        if ($user->status === 'inactive') {
            return response()->json([
                'message' => 'Your account has been deactivated.',
                'deactivation_remarks' => $user->approved_remarks ? trim($user->approved_remarks) : null,
            ], 403);
        }

        if ($user->status === 'pending_approval') {
            $user->tokens()->where('name', 'auth')->delete();
            $token = $user->createToken('auth')->plainTextToken;
            ActivityLog::log($user->id, 'login', $user->name . ' logged in (pending approval)', null, $request);

            return response()->json([
                'message' => 'Your account is pending approval. You have access to the dashboard until an administrator approves your account.',
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $user->only(['id', 'name', 'email', 'role', 'status', 'employee_id', 'position', 'division', 'school_name', 'avatar_url', 'school_logo_url', 'approved_at', 'approved_remarks', 'rejected_at', 'rejection_remarks']),
            ]);
        }

        // status === 'active' — allow full login
        $user->tokens()->where('name', 'auth')->delete();
        $token = $user->createToken('auth')->plainTextToken;
        ActivityLog::log($user->id, 'login', $user->name . ' logged in', null, $request);

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->only(['id', 'name', 'email', 'role', 'status', 'employee_id', 'position', 'division', 'school_name', 'avatar_url', 'school_logo_url', 'approved_at', 'approved_remarks', 'rejected_at', 'rejection_remarks']),
        ]);
    }

    /**
     * Logout: revoke current Sanctum token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Get authenticated user (TasDoneNa uses GET /user).
     * If user account was rejected or deactivated, return 403 so client can log them out.
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->status === 'rejected') {
            return response()->json([
                'message' => 'Your account has been rejected.',
                'rejection_remarks' => $user->rejection_remarks ? trim($user->rejection_remarks) : null,
            ], 403);
        }

        if ($user->status === 'inactive') {
            return response()->json([
                'message' => 'Your account has been deactivated.',
                'deactivation_remarks' => $user->approved_remarks ? trim($user->approved_remarks) : null,
            ], 403);
        }

        return response()->json([
            'user' => $user->only([
                'id', 'name', 'email', 'role', 'status',
                'employee_id', 'position', 'division', 'school_name', 'avatar_url', 'school_logo_url',
                'approved_at', 'approved_remarks', 'rejected_at', 'rejection_remarks',
            ]),
        ]);
    }

    /**
     * Update authenticated user profile (name, employee_id, position, division, school_name).
     * Email is not editable for security.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $valid = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'employee_id' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:255'],
            'division' => ['nullable', 'string', 'max:255'],
            'school_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user->update($valid);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user->fresh()->only([
                'id', 'name', 'email', 'role', 'status',
                'employee_id', 'position', 'division', 'school_name', 'avatar_url', 'school_logo_url',
                'approved_at', 'approved_remarks', 'rejected_at', 'rejection_remarks',
            ]),
        ]);
    }

    /**
     * Upload authenticated user avatar. Accepts multipart/form-data with 'avatar' file.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
        ]);

        if ($user->avatar_url) {
            $baseUrl = rtrim(Storage::disk('public')->url(''), '/');
            $oldPath = str_starts_with($user->avatar_url, $baseUrl . '/')
                ? substr($user->avatar_url, strlen($baseUrl) + 1)
                : null;
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $url = asset('storage/' . $path);
        $user->update(['avatar_url' => $url]);

        return response()->json([
            'message' => 'Avatar updated successfully.',
            'avatar_url' => $url,
            'user' => $user->fresh()->only([
                'id', 'name', 'email', 'role', 'status',
                'employee_id', 'position', 'division', 'school_name', 'avatar_url', 'school_logo_url',
                'approved_at', 'approved_remarks', 'rejected_at', 'rejection_remarks',
            ]),
        ]);
    }

    public function uploadSchoolLogo(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'school_logo' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
        ]);

        if ($user->school_logo_url) {
            $baseUrl = rtrim(Storage::disk('public')->url(''), '/');
            $oldPath = str_starts_with($user->school_logo_url, $baseUrl . '/')
                ? substr($user->school_logo_url, strlen($baseUrl) + 1)
                : null;
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $path = $request->file('school_logo')->store('school_logos', 'public');
        $url = asset('storage/' . $path);
        $user->update(['school_logo_url' => $url]);

        return response()->json([
            'message' => 'School logo updated successfully.',
            'school_logo_url' => $url,
            'user' => $user->fresh()->only([
                'id', 'name', 'email', 'role', 'status',
                'employee_id', 'position', 'division', 'school_name', 'avatar_url', 'school_logo_url',
                'approved_at', 'approved_remarks', 'rejected_at', 'rejection_remarks',
            ]),
        ]);
    }

    /**
     * Change authenticated user password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $valid = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'],
        ], [
            'new_password.regex' => 'Password must contain at least one letter and one number.',
        ]);

        if (! Hash::check($valid['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $valid['new_password']]);

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
