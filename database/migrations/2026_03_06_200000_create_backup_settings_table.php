<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Single-row table for automated backup schedule (Central Admin).
     */
    public function up(): void
    {
        Schema::create('backup_settings', function (Blueprint $table) {
            $table->id();
            $table->string('frequency', 20)->default('off'); // off, daily, weekly
            $table->string('run_at_time', 5)->default('02:00'); // HH:MM
            $table->string('timezone', 50)->default('UTC');
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_backup_path', 500)->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });

        \DB::table('backup_settings')->insert([
            'frequency' => 'off',
            'run_at_time' => '02:00',
            'timezone' => 'UTC',
            'last_run_at' => null,
            'last_backup_path' => null,
            'next_run_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_settings');
    }
};
