<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SchoolHeadAssignmentController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SchedulerController;
use App\Http\Controllers\Api\SchoolHeadProfileController;

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

// System settings (public — used by layout/login for app name & logo)
Route::get('/settings', [SettingsController::class, 'index']);

// Scheduler trigger for deployment: when SCHEDULER_TOKEN is set, call GET/POST /api/scheduler/run?token=YOUR_TOKEN
// (or header X-Scheduler-Token) every minute from an external cron or platform scheduled job so backup/reminders run.
Route::match(['get', 'post'], '/scheduler/run', [SchedulerController::class, 'run']);

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
    Route::get('/admin/users/{id}/school-heads', [AdminController::class, 'userSchoolHeads']);
    Route::get('/admin/monitor-officers', [AdminController::class, 'monitorOfficers']);
    Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/admin/activity-logs', [ActivityLogController::class, 'index']);
    Route::post('/admin/users/{id}/deactivate', [AdminController::class, 'deactivate']);
    Route::post('/admin/users/{id}/activate', [AdminController::class, 'activate']);
    Route::delete('/admin/users/{id}', [AdminController::class, 'destroyUser']);

    // Central Admin: School Head ↔ Administrative Officer assignments
    Route::get('/admin/school-heads/{id}/aos', [SchoolHeadAssignmentController::class, 'index']);
    Route::post('/admin/school-heads/{id}/aos', [SchoolHeadAssignmentController::class, 'store']);
    Route::delete('/admin/school-heads/{id}/aos/{aoId}', [SchoolHeadAssignmentController::class, 'destroy']);

    // Central Admin: system settings (app name, tagline, logo)
    Route::put('/admin/settings', [SettingsController::class, 'update']);
    Route::post('/admin/settings/logo', [SettingsController::class, 'uploadLogo']);

    // Central Admin: SQL backup (manual + scheduled) and schedule
    Route::get('/admin/backup', [BackupController::class, 'download']);
    Route::get('/admin/backup/schedule', [BackupController::class, 'getSchedule']);
    Route::put('/admin/backup/schedule', [BackupController::class, 'updateSchedule']);
    Route::get('/admin/backup/list', [BackupController::class, 'listBackups']);
    Route::get('/admin/backup/download/latest', [BackupController::class, 'downloadLatest']);
    Route::get('/admin/backup/download/file/{filename}', [BackupController::class, 'downloadFile']);

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
    Route::get('/my-submitted-files', [TaskController::class, 'mySubmittedFiles']);
    Route::get('/submission-files/{id}/download', [TaskController::class, 'downloadSubmissionFile']);
    Route::get('/user-tasks/{id}', [TaskController::class, 'showUserTask']);
    Route::get('/user-tasks/{id}/submissions', [TaskController::class, 'listSubmissions']);
    Route::post('/user-tasks/{id}/submissions/upload', [TaskController::class, 'uploadMov']);
    Route::post('/user-tasks/{id}/submissions/input', [TaskController::class, 'submitInput']);
    Route::put('/user-tasks/{id}/submission/notes', [TaskController::class, 'updateSubmissionNotes']);
    Route::post('/user-tasks/{id}/submit', [TaskController::class, 'submitUserTask']);

    // Administrative Officer: reminders (Phase 6.3)
    Route::get('/reminders', [ReminderController::class, 'index']);
    Route::post('/reminders/{id}/read', [ReminderController::class, 'markAsRead']);

    // School Head: validations
    Route::get('/school-head/dashboard', [TaskController::class, 'schoolHeadDashboard']);
    Route::get('/school-head/validations/pending', [TaskController::class, 'pendingValidations']);
    Route::post('/school-head/validations/{id}', [TaskController::class, 'validateSubmission']);
    Route::get('/school-head/validations/report', [TaskController::class, 'validationReport']);

    // School Head: task history
    Route::get('/school-head/task-history', [TaskController::class, 'taskHistory']);

    // School Head: digital signature (upload/remove, used in Excel reports)
    Route::get('/school-head/profile/signature', [SchoolHeadProfileController::class, 'getSignature']);
    Route::post('/school-head/profile/signature', [SchoolHeadProfileController::class, 'updateSignature']);

    // Performance report (Personnel: own report; School Head: report for supervised AO)
    Route::get('/reports/template-status', [ReportController::class, 'templateStatus']);
    Route::get('/reports/performance-report', [ReportController::class, 'performanceReport']);

    // School Head: list supervised AOs for report dropdown
    Route::get('/school-head/supervised-officers', [TaskController::class, 'supervisedOfficers']);

    // Administrative Officer: personal tasks
    Route::post('/my-personal-tasks', [TaskController::class, 'storePersonal']);
});
