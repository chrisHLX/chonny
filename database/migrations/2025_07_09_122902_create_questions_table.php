<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('question');                      
            $table->json('answer');                          
            $table->enum('type', ['mcq', 'true_false', 'matching_pairs', 'ordering' ,'open']);
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('easy');
            $table->unsignedBigInteger('parent_id')->nullable(); // New: link to parent question
            $table->foreign('parent_id')->references('id')->on('questions')->onDelete('cascade');
            $table->string('created_by')->nullable();        
            $table->timestamps();
        });

    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
