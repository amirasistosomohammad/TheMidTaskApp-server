<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 1.2 + profile fields: role, status, employee_id, position, division, school_name.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role', 50)->default('administrative_officer')->after('email');
            }
            if (!Schema::hasColumn('users', 'status')) {
                $table->string('status', 50)->default('active')->after('role');
            }

            if (!Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id', 100)->nullable()->after('status');
            }
            if (!Schema::hasColumn('users', 'position')) {
                $table->string('position', 255)->nullable()->after('employee_id');
            }
            if (!Schema::hasColumn('users', 'division')) {
                $table->string('division', 255)->nullable()->after('position');
            }
            if (!Schema::hasColumn('users', 'school_name')) {
                $table->string('school_name', 255)->nullable()->after('division');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [];
            foreach (['role', 'status', 'employee_id', 'position', 'division', 'school_name'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $columns[] = $col;
                }
            }
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};

