<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('cards'));
        $exists = $indexes->contains(fn($i) => $i['name'] === 'cards_module_mint_unique');

        if (! $exists) {
            Schema::table('cards', function (Blueprint $table) {
                $table->unique(['module_id', 'mint_number'], 'cards_module_mint_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropUnique('cards_module_mint_unique');
        });
    }
};
