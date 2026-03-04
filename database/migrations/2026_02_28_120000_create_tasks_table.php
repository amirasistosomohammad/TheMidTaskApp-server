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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('submission_date_rule')->nullable()->comment('e.g. "6th of month", "June & December"');
            $table->string('frequency')->comment('monthly, twice_a_year, yearly, end_of_sy, quarterly, every_two_months, etc.');
            $table->text('mov_description')->nullable()->comment('Means of Verification description');
            $table->string('action', 20)->comment('upload | input');
            $table->boolean('is_common')->default(false)->comment('true for the 10 common reports');
            $table->unsignedTinyInteger('common_report_no')->nullable()->comment('1-10 for common reports');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
