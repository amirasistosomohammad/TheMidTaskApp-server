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
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('is_personal')
                ->default(false)
                ->after('common_report_no')
                ->comment('true when created directly by an Administrative Officer');

            $table->foreignId('owner_user_id')
                ->nullable()
                ->after('is_personal')
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('personal_visible_to_central')
                ->default(false)
                ->after('owner_user_id')
                ->comment('when true, Central Administrative Officer can see this personal task in monitoring views');

            $table->boolean('personal_visible_to_school_head')
                ->default(false)
                ->after('personal_visible_to_central')
                ->comment('when true, School Head can validate submissions for this personal task');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn([
                'is_personal',
                'personal_visible_to_central',
                'personal_visible_to_school_head',
            ]);
        });
    }
};

