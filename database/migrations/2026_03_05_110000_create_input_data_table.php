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
        Schema::create('input_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')
                ->constrained('submissions')
                ->cascadeOnDelete();

            // Basic structure for input-type submissions (Phase 5.1)
            $table->string('period', 50)->nullable()->comment('e.g. March 2026, Q1 2026');
            $table->string('reference_no', 100)->nullable()->comment('Form number, payroll reference, etc.');
            $table->decimal('amount', 15, 2)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('submission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('input_data');
    }
};

