<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ModulePage extends Model
{
    use HasFactory;

    protected $fillable = [
        'module_id',
        'title',
        'slug',
        'page_number',
        'content',
        'created_by',
        'updated_by',
    ];

    // Auto-generate slug if not provided
    protected static function booted()
    {
        static::creating(function ($page) {
            if (empty($page->slug)) {
                $slugBase = Str::slug($page->title ?? 'page');
                $slug = $slugBase;
                $counter = 1;

                while (self::where('slug', $slug)->exists()) {
                    $slug = $slugBase . '-' . $counter++;
                }

                $page->slug = $slug;
            }
        });
    }


    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
