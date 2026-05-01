<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    //This is for our pages, if we have to create one by the system we need to have a system user
    public function up(): void
    {
        Schema::create('module_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')
                  ->constrained('modules')
                  ->onDelete('cascade'); // Delete pages if module is deleted
            $table->string('title')->nullable();
            $table->string('slug')->nullable()->unique(); // optional for clean URLs
            $table->unsignedInteger('page_number')->default(1);
            $table->longText('content'); // can store big HTML/Markdown from AI
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('module_pages');
    }
};
