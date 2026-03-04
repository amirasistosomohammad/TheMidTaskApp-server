<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_task_id')->constrained('user_tasks')->cascadeOnDelete();

            // When the reminder should be shown/triggered.
            $table->dateTime('remind_at')->index();

            // in_app for now; email/push can be added later.
            $table->string('channel', 30)->default('in_app');

            // e.g. "due_soon"
            $table->string('type', 30)->default('due_soon');

            // For due_soon reminders: 3, 1, 0 (days before due_date).
            $table->unsignedSmallInteger('days_before_due')->nullable();

            // unread | read
            $table->string('status', 20)->default('unread')->index();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // Prevent duplicates for the same task at the same reminder time (per channel/type).
            $table->unique(['user_task_id', 'remind_at', 'channel', 'type'], 'reminders_task_time_channel_type_unique');
            $table->index(['user_id', 'status', 'remind_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};

