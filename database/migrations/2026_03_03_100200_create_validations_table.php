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
        Schema::create('validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')
                ->constrained('submissions')
                ->cascadeOnDelete();
            $table->foreignId('validator_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('status', 20)->comment('approved | rejected');
            $table->text('feedback')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->index(['submission_id']);
            $table->index(['validator_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validations');
    }
};

