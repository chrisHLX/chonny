<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps to the `classes` table. Named GameClass, not Class, because `class` is a reserved word
 * in PHP and can't be used as a model class name.
 */
class GameClass extends Model
{
    protected $table = 'classes';

    protected $fillable = [
        'game_id',
        'name',
        'slug',
        'icon_name',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function specializations()
    {
        return $this->hasMany(Specialization::class, 'class_id');
    }

    public function talentTrees()
    {
        return $this->hasMany(TalentTree::class, 'class_id');
    }
}
