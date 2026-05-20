
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('module_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('type');   // e.g. 'quiz_completion'
            $table->string('status'); // pending | running | completed | failed

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'module_id']);
            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipelines');
    }
};
