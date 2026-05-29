<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompts', function (Blueprint $table) {
            $table->string('model')->default('gpt')->after('promptable_id'); // gpt | gemini
            $table->json('sources')->nullable()->after('answer');             // web sources returned by gemini
        });
    }

    public function down(): void
    {
        Schema::table('prompts', function (Blueprint $table) {
            $table->dropColumn(['model', 'sources']);
        });
    }
};
