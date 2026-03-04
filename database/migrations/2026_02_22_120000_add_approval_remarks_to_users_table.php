<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add approval/rejection timestamps and remarks for personnel visibility.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->text('approved_remarks')->nullable()->after('approved_at');
            $table->timestamp('rejected_at')->nullable()->after('approved_remarks');
            $table->text('rejection_remarks')->nullable()->after('rejected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'approved_remarks', 'rejected_at', 'rejection_remarks']);
        });
    }
};
