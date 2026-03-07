<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillSchoolHeadAo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'midtask:backfill-school-head-ao';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill School Head–Administrative Officer assignments based on matching school_name.';

    public function handle(): int
    {
        $this->info('Backfilling School Head–AO assignments based on school_name…');

        $aos = User::query()
            ->where('role', 'administrative_officer')
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        if ($aos->isEmpty()) {
            $this->info('No Administrative Officers found. Nothing to backfill.');
            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        foreach ($aos as $ao) {
            // Skip if this AO is already assigned to at least one School Head.
            $alreadyAssigned = DB::table('school_head_ao')
                ->where('ao_id', $ao->id)
                ->exists();

            if ($alreadyAssigned) {
                $skipped++;
                continue;
            }

            $schoolName = is_string($ao->school_name) ? trim($ao->school_name) : '';
            if ($schoolName === '') {
                $skipped++;
                continue;
            }

            $schoolHead = User::query()
                ->where('role', 'school_head')
                ->where('status', 'active')
                ->where('school_name', $schoolName)
                ->orderBy('id')
                ->first();

            if (! $schoolHead) {
                $skipped++;
                continue;
            }

            DB::table('school_head_ao')->updateOrInsert(
                [
                    'school_head_id' => $schoolHead->id,
                    'ao_id' => $ao->id,
                ],
                []
            );

            $created++;
        }

        $this->info("Backfill complete. Created {$created} assignments, skipped {$skipped} AOs.");

        return self::SUCCESS;
    }
}

