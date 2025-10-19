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
            Schema::create('contents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('content'); // The main knowledge block
                $table->string('source')->nullable(); // e.g. "AI-generated" or "User input"
                $table->unsignedBigInteger('parent_id')->nullable(); // Optional: content hierarchy for advanced structuring
                $table->timestamps();

                $table->foreign('parent_id')->references('id')->on('contents')->onDelete('cascade');
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
