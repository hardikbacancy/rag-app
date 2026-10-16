<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    public const STRATEGIES = ['baseline', 'hierarchical', 'multimodal'];

    protected $fillable = [
        'title', 'original_filename', 'path', 'strategy', 'status', 'progress',
        'status_message', 'error', 'page_count', 'chunk_count', 'ingested_at',
    ];

    // Never leak the storage path to the API.
    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'ingested_at' => 'datetime',
            'progress' => 'integer',
            'page_count' => 'integer',
            'chunk_count' => 'integer',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->path);
    }

    public function updateStatus(string $status, int $progress, ?string $message = null): void
    {
        $this->forceFill(['status' => $status, 'progress' => $progress, 'status_message' => $message])->save();
    }
}
