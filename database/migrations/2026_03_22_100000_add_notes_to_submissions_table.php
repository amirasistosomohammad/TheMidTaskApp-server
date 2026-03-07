<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Notes for upload-type submissions (input-type uses input_data.notes).
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
