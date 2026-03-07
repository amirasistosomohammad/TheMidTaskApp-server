<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('school_head_ao', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_head_id');
            $table->unsignedBigInteger('ao_id');
            $table->timestamps();

            $table->unique(['school_head_id', 'ao_id']);
            $table->index('ao_id');

            $table->foreign('school_head_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('ao_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_head_ao');
    }
};

