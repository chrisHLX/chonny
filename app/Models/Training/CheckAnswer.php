<?php

namespace App\Models\Training;

use Illuminate\Database\Eloquent\Model;

/** One answer in the reasoning check (form A before the lessons, form B later). */
class CheckAnswer extends Model
{
    protected $table = 'training_check_answers';

    protected $fillable = ['form', 'item', 'choice', 'correct'];

    protected function casts(): array
    {
        return ['correct' => 'boolean', 'choice' => 'integer'];
    }
}
