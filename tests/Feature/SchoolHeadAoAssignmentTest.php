<?php

use App\Models\Reminder;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a Central Admin for authorization
    $this->centralAdmin = User::create([
        'name' => 'Central Admin',
        'email' => 'admin@test.com',
        'password' => bcrypt('password'),
        'role' => 'central_admin',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
});

test('central admin can assign an AO to a School Head', function () {
    Sanctum::actingAs($this->centralAdmin);

    $schoolHead = User::create([
        'name' => 'School Head',
        'email' => 'sh@test.com',
        'password' => bcrypt('password'),
        'role' => 'school_head',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $ao = User::create([
        'name' => 'Test AO',
        'email' => 'ao@test.com',
        'password' => bcrypt('password'),
        'role' => 'administrative_officer',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $response = $this->postJson("/api/admin/school-heads/{$schoolHead->id}/aos", [
        'ao_id' => $ao->id,
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'school_head_id',
        'administrative_officers' => [
            '*' => ['id', 'name', 'email', 'school_name', 'position'],
        ],
    ]);

    expect($schoolHead->supervisedAdministrativeOfficers)->toHaveCount(1);
    expect($schoolHead->supervisedAdministrativeOfficers->first()->id)->toBe($ao->id);
});

test('central admin can list AOs assigned to a School Head', function () {
    Sanctum::actingAs($this->centralAdmin);

    $schoolHead = User::create([
        'name' => 'School Head',
        'email' => 'sh@test.com',
        'password' => bcrypt('password'),
        'role' => 'school_head',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $ao1 = User::create([
        'name' => 'AO 1',
        'email' => 'ao1@test.com',
        'password' => bcrypt('password'),
        'role' => 'administrative_officer',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $ao2 = User::create([
        'name' => 'AO 2',
        'email' => 'ao2@test.com',
        'password' => bcrypt('password'),
        'role' => 'administrative_officer',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $schoolHead->supervisedAdministrativeOfficers()->attach([$ao1->id, $ao2->id]);

    $response = $this->getJson("/api/admin/school-heads/{$schoolHead->id}/aos");

    $response->assertStatus(200);
    $response->assertJsonCount(2, 'administrative_officers');
});

test('central admin can remove an AO assignment from a School Head', function () {
    Sanctum::actingAs($this->centralAdmin);

    $schoolHead = User::create([
        'name' => 'School Head',
        'email' => 'sh@test.com',
        'password' => bcrypt('password'),
        'role' => 'school_head',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $ao = User::create([
        'name' => 'Test AO',
        'email' => 'ao@test.com',
        'password' => bcrypt('password'),
        'role' => 'administrative_officer',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $schoolHead->supervisedAdministrativeOfficers()->attach($ao->id);

    $response = $this->deleteJson("/api/admin/school-heads/{$schoolHead->id}/aos/{$ao->id}");

    $response->assertStatus(200);
    expect($schoolHead->fresh()->supervisedAdministrativeOfficers)->toHaveCount(0);
});

test('AO submission creates reminder only for assigned School Head', function () {
    $schoolHeadA = User::create([
        'name' => 'School Head A',
        'email' => 'sha@test.com',
        'password' => bcrypt('password'),
        'role' => 'school_head',
        'status' => 'active',
        'email_verified_at' => now(),
        'school_name' => 'School A',
    ]);

    $schoolHeadB = User::create([
        'name' => 'School Head B',
        'email' => 'shb@test.com',
        'password' => bcrypt('password'),
        'role' => 'school_head',
        'status' => 'active',
        'email_verified_at' => now(),
        'school_name' => 'School A', // Same school but different assignment
    ]);

    $ao = User::create([
        'name' => 'Test AO',
        'email' => 'ao@test.com',
        'password' => bcrypt('password'),
        'role' => 'administrative_officer',
        'status' => 'active',
        'email_verified_at' => now(),
        'school_name' => 'School A',
    ]);

    // Assign AO only to School Head A
    $schoolHeadA->supervisedAdministrativeOfficers()->attach($ao->id);

    $task = Task::create([
        'name' => 'Test Task',
        'action' => 'upload',
        'frequency' => Task::FREQUENCY_MONTHLY,
    ]);

    $userTask = UserTask::create([
        'user_id' => $ao->id,
        'task_id' => $task->id,
        'status' => 'pending',
        'due_date' => now()->addDays(5),
    ]);

    $submission = Submission::create([
        'user_task_id' => $userTask->id,
        'type' => 'upload',
    ]);
    SubmissionFile::create([
        'submission_id' => $submission->id,
        'disk' => 'public',
        'path' => 'movs/' . $ao->id . '/test-file.pdf',
        'original_name' => 'test-file.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
    ]);

    Sanctum::actingAs($ao);

    // Submit the task
    $response = $this->postJson("/api/user-tasks/{$userTask->id}/submit");

    $response->assertStatus(200);

    // Verify reminder was created only for School Head A
    $reminders = Reminder::where('user_task_id', $userTask->id)
        ->where('type', 'submission_pending')
        ->get();

    expect($reminders)->toHaveCount(1);
    expect($reminders->first()->user_id)->toBe($schoolHeadA->id);
    expect($reminders->first()->user_id)->not->toBe($schoolHeadB->id);
});

test('School Head pendingValidations returns only submissions from assigned AOs', function () {
    $schoolHeadA = User::create([
        'name' => 'School Head A',
        'email' => 'sha@test.com',
        'password' => bcrypt('password'),
        'role' => 'school_head',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $schoolHeadB = User::create([
        'name' => 'School Head B',
        'email' => 'shb@test.com',
        'password' => bcrypt('password'),
        'role' => 'school_head',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $ao1 = User::create([
        'name' => 'AO 1',
        'email' => 'ao1@test.com',
        'password' => bcrypt('password'),
        'role' => 'administrative_officer',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $ao2 = User::create([
        'name' => 'AO 2',
        'email' => 'ao2@test.com',
        'password' => bcrypt('password'),
        'role' => 'administrative_officer',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    // Assign AO1 to School Head A, AO2 to School Head B
    $schoolHeadA->supervisedAdministrativeOfficers()->attach($ao1->id);
    $schoolHeadB->supervisedAdministrativeOfficers()->attach($ao2->id);

    $task = Task::create([
        'name' => 'Test Task',
        'action' => 'upload',
        'frequency' => Task::FREQUENCY_MONTHLY,
    ]);

    $userTask1 = UserTask::create([
        'user_id' => $ao1->id,
        'task_id' => $task->id,
        'status' => 'submitted',
        'due_date' => now()->addDays(5),
    ]);

    $userTask2 = UserTask::create([
        'user_id' => $ao2->id,
        'task_id' => $task->id,
        'status' => 'submitted',
        'due_date' => now()->addDays(5),
    ]);

    Submission::create([
        'user_task_id' => $userTask1->id,
        'type' => 'upload',
    ]);

    Submission::create([
        'user_task_id' => $userTask2->id,
        'type' => 'upload',
    ]);

    Sanctum::actingAs($schoolHeadA);

    $response = $this->getJson('/api/school-head/validations/pending');

    $response->assertStatus(200);
    $submissions = $response->json('submissions');
    expect($submissions)->toHaveCount(1);
    expect($submissions[0]['user_task']['user']['id'])->toBe($ao1->id);
    expect($submissions[0]['user_task']['user']['id'])->not->toBe($ao2->id);
});

test('AO creation with school_head_id creates assignment', function () {
    Sanctum::actingAs($this->centralAdmin);

    $schoolHead = User::create([
        'name' => 'School Head',
        'email' => 'sh@test.com',
        'password' => bcrypt('password'),
        'role' => 'school_head',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $response = $this->postJson('/api/admin/users', [
        'name' => 'Test AO',
        'email' => 'ao@test.com',
        'password' => 'Password123',
        'role' => 'administrative_officer',
        'school_head_id' => $schoolHead->id,
    ]);

    $response->assertStatus(201);
    $aoId = $response->json('user.id');

    expect($schoolHead->fresh()->supervisedAdministrativeOfficers)->toHaveCount(1);
    expect($schoolHead->supervisedAdministrativeOfficers->first()->id)->toBe($aoId);
});

test('non-central-admin cannot manage assignments', function () {
    $regularUser = User::create([
        'name' => 'Regular User',
        'email' => 'user@test.com',
        'password' => bcrypt('password'),
        'role' => 'administrative_officer',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $schoolHead = User::create([
        'name' => 'School Head',
        'email' => 'sh@test.com',
        'password' => bcrypt('password'),
        'role' => 'school_head',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    Sanctum::actingAs($regularUser);

    $response = $this->getJson("/api/admin/school-heads/{$schoolHead->id}/aos");
    $response->assertStatus(403);
});

test('fallback to school_name when no explicit assignment exists', function () {
    $schoolHead = User::create([
        'name' => 'School Head',
        'email' => 'sh@test.com',
        'password' => bcrypt('password'),
        'role' => 'school_head',
        'status' => 'active',
        'email_verified_at' => now(),
        'school_name' => 'Test School',
    ]);

    $ao = User::create([
        'name' => 'Test AO',
        'email' => 'ao@test.com',
        'password' => bcrypt('password'),
        'role' => 'administrative_officer',
        'status' => 'active',
        'email_verified_at' => now(),
        'school_name' => 'Test School',
    ]);

    // No explicit assignment
    expect($ao->supervisingSchoolHeads)->toHaveCount(0);

    $task = Task::create([
        'name' => 'Test Task',
        'action' => 'upload',
        'frequency' => Task::FREQUENCY_MONTHLY,
    ]);

    $userTask = UserTask::create([
        'user_id' => $ao->id,
        'task_id' => $task->id,
        'status' => 'pending',
        'due_date' => now()->addDays(5),
    ]);

    $submission = Submission::create([
        'user_task_id' => $userTask->id,
        'type' => 'upload',
    ]);
    SubmissionFile::create([
        'submission_id' => $submission->id,
        'disk' => 'public',
        'path' => 'movs/' . $ao->id . '/test-file.pdf',
        'original_name' => 'test-file.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
    ]);

    Sanctum::actingAs($ao);

    $response = $this->postJson("/api/user-tasks/{$userTask->id}/submit");
    $response->assertStatus(200);

    // Should fallback to school_name matching
    $reminders = Reminder::where('user_task_id', $userTask->id)->get();
    expect($reminders)->toHaveCount(1);
    expect($reminders->first()->user_id)->toBe($schoolHead->id);
});
