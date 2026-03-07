<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AssignDefaultTasksService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued job to assign default tasks to an approved Administrative Officer.
 * Dispatched from account approval so the HTTP request returns quickly and avoids gateway timeouts.
 */
class AssignDefaultTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public int $userId
    ) {}

    public function handle(AssignDefaultTasksService $service): void
    {
        $user = User::find($this->userId);
        if (! $user || $user->role !== 'administrative_officer') {
            return;
        }

        $service->assign($user);
    }
}
