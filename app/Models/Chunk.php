<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
    protected $fillable = ['document_id', 'page', 'position', 'type', 'section', 'content', 'metadata'];

    // The raw vector and tsvector columns are large and useless to the UI.
    protected $hidden = ['embedding', 'search'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
