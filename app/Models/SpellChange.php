<?php

namespace App\Models;

use App\Http\Services\SpellChangeRecorder;
use Illuminate\Database\Eloquent\Model;

/** One field of one spell that an import changed. See SpellChangeRecorder. */
class SpellChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['spell_data_update_id', 'spell_id', 'field', 'old_value', 'new_value'];

    public function dataUpdate()
    {
        return $this->belongsTo(SpellDataUpdate::class, 'spell_data_update_id');
    }

    public function spell()
    {
        return $this->belongsTo(Spell::class);
    }

    /** True for the numeric fields a player reads as a balance change (cooldown, charges, duration). */
    public function isNumeric(): bool
    {
        return $this->field !== 'description';
    }

    /** "Cooldown", "Charges", "Duration". */
    public function label(): string
    {
        return SpellChangeRecorder::TRACKED_FIELDS[$this->field] ?? $this->field;
    }

    /** A value as a player reads it: 30 → "30s" for seconds fields, "none" for a removed value. */
    public function display(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'none';
        }

        $number = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return str_ends_with($this->field, '_seconds') ? $number.'s' : $number;
    }
}
