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
        Schema::create('task_records', function (Blueprint $table) {
            $table->id();
            $table->string('task_sid')->unique();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->string('workflow_sid');
            $table->string('status');
            $table->string('reservation_sid')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_records');
    }
};
