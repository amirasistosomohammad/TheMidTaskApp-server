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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'otp')) {
                $table->string('otp', 6)->nullable()->after('school_name');
            }
            if (! Schema::hasColumn('users', 'otp_expires_at')) {
                $table->timestamp('otp_expires_at')->nullable()->after('otp');
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
            foreach (['otp', 'otp_expires_at'] as $col) {
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
