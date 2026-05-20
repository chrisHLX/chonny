<?php
namespace App\Http\Services;
use App\Models\User;
use App\Models\Module;
use App\Models\ModuleSuggestions;

class SuggestionsService
{
    public function getSuggestions(Module $module, string $hash)
    {
        return ModuleSuggestions::where('module_id', $module->id)
            ->where('stats_hash', $hash)
            ->first();
    }

    public function getSuggestionsDB(Module $module, string $hash)
    {
        $response = ModuleSuggestions::where('module_id', $module->id)
            ->where('stats_hash', $hash)
            ->first()->suggestions_json;
        return $response['recommendations'];
    }

    public function storeSuggestions(Module $module, string $hash, array $data)
    {
        return ModuleSuggestions::create([
            'module_id' => $module->id,
            'stats_hash' => $hash,
            'suggestions_json' => $data,
        ]);
    }
}
