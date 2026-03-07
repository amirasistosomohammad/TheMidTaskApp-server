<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * CA-SET-1.1: Single-row system settings (app name, logo, tagline) for Central Admin.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_name', 255)->default('MID-TASK APP');
            $table->string('logo_path', 500)->nullable();
            $table->string('tagline', 500)->nullable();
            $table->timestamps();
        });

        // Seed the single row so GET has something to return.
        DB::table('system_settings')->insert([
            'app_name' => 'MID-TASK APP',
            'logo_path' => null,
            'tagline' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
