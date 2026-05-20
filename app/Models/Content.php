<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
    protected $fillable = ['content', 'created_by', 'source', 'parent_id'];

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'content_question')->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parent()
    {
        return $this->belongsTo(Content::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Content::class, 'parent_id');
    }
}
