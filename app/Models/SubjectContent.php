<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubjectContent extends Model
{
    protected $table = 'subject_content';

    protected $fillable = [
        'subject_id',
        'module_id',
        'ai_request_id',
        'source_material_id',
        'created_by',
        'title',
        'content',
        'source_urls',
    ];

    protected $casts = [
        'source_urls' => 'array',
    ];

    public function subject(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function module(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function aiRequest(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AiRequest::class);
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceMaterial(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SourceMaterial::class);
    }
}
