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
        Schema::table('reminders', function (Blueprint $table) {
            // Previous unique constraint did not include user_id which prevents multiple recipients
            // (e.g. multiple School Heads) from receiving the same reminder.
            $table->dropUnique('reminders_task_time_channel_type_unique');

            $table->unique(
                ['user_id', 'user_task_id', 'remind_at', 'channel', 'type'],
                'reminders_user_task_time_channel_type_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropUnique('reminders_user_task_time_channel_type_unique');

            $table->unique(
                ['user_task_id', 'remind_at', 'channel', 'type'],
                'reminders_task_time_channel_type_unique'
            );
        });
    }
};

