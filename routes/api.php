<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\ReminderController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Base URL for client: VITE_LARAVEL_API (e.g. http://localhost:8000/api)
| Structure aligned with TasDoneNa: https://github.com/amirasistosomohammad/TasDoneNa-server
|--------------------------------------------------------------------------
*/

// Public
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Serve stored files (MOVs, avatars) – public so img preview and "View file" work without auth redirect.
// Paths are unguessable (e.g. movs/78/random-string.png). Must be registered before auth group.
Route::get('/storage/{path}', [TaskController::class, 'serveStorage'])->where('path', '.*');

// Protected (Laravel Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/user/avatar', [AuthController::class, 'uploadAvatar']);
    Route::put('/user/password', [AuthController::class, 'updatePassword']);

    // Central Admin: account approvals + create user (School Head, etc.)
    Route::get('/admin/pending-users', [AdminController::class, 'pendingUsers']);
    Route::post('/admin/users', [AdminController::class, 'createUser']);
    Route::post('/admin/users/{id}/avatar', [AdminController::class, 'uploadUserAvatar']);
    Route::post('/admin/users/{id}/approve', [AdminController::class, 'approve']);
    Route::post('/admin/users/{id}/reject', [AdminController::class, 'reject']);

    // Central Admin: personnel directory (approved, rejected, inactive)
    Route::get('/admin/personnel', [AdminController::class, 'personnel']);
    Route::get('/admin/monitor-officers', [AdminController::class, 'monitorOfficers']);
    Route::post('/admin/users/{id}/deactivate', [AdminController::class, 'deactivate']);
    Route::post('/admin/users/{id}/activate', [AdminController::class, 'activate']);
    Route::delete('/admin/users/{id}', [AdminController::class, 'destroyUser']);

    // Central Admin: tasks (create/edit/delete, assign)
    Route::get('/admin/tasks', [TaskController::class, 'index']);
    Route::post('/admin/tasks', [TaskController::class, 'store']);
    Route::post('/admin/tasks/bulk-assign-common', [TaskController::class, 'bulkAssignCommon']);
    Route::get('/admin/tasks/{id}', [TaskController::class, 'show']);
    Route::get('/admin/tasks/{id}/assignments', [TaskController::class, 'assignments']);
    Route::get('/admin/tasks/{id}/suggested-due-dates', [TaskController::class, 'suggestedDueDates']);
    Route::put('/admin/tasks/{id}', [TaskController::class, 'update']);
    Route::delete('/admin/tasks/{id}', [TaskController::class, 'destroy']);
    Route::post('/admin/tasks/{id}/assign', [TaskController::class, 'assign']);
    Route::post('/admin/tasks/{id}/assign-recurring', [TaskController::class, 'assignRecurring']);

    // Administrative Officer: dashboard (grouped pending/missing/completed), my tasks, task detail, submissions
    Route::get('/dashboard', [TaskController::class, 'dashboard']);
    Route::get('/my-tasks', [TaskController::class, 'myTasks']);
    Route::get('/user-tasks/{id}', [TaskController::class, 'showUserTask']);
    Route::get('/user-tasks/{id}/submissions', [TaskController::class, 'listSubmissions']);
    Route::post('/user-tasks/{id}/submissions/upload', [TaskController::class, 'uploadMov']);
    Route::post('/user-tasks/{id}/submissions/input', [TaskController::class, 'submitInput']);
    Route::post('/user-tasks/{id}/submit', [TaskController::class, 'submitUserTask']);

    // Administrative Officer: reminders (Phase 6.3)
    Route::get('/reminders', [ReminderController::class, 'index']);
    Route::post('/reminders/{id}/read', [ReminderController::class, 'markAsRead']);

    // School Head: validations
    Route::get('/school-head/validations/pending', [TaskController::class, 'pendingValidations']);
    Route::post('/school-head/validations/{id}', [TaskController::class, 'validateSubmission']);
});
