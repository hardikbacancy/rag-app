<?php

namespace App\Services\Rag\Strategies;

use App\Models\Document;

/**
 * Turns the pages of a PDF into chunks ready for embedding.
 *
 * Each chunk is an array:
 *   ['page' => int, 'type' => 'text'|'table'|'figure'|'footnote',
 *    'section' => ?string, 'content' => string, 'metadata' => array]
 */
interface ChunkingStrategy
{
    /**
     * @param  array<int, string>  $pages  1-based page number => page text
     * @param  callable(int $percent, string $message): void  $progress  reports 0-100 within this step
     */
    public function chunk(Document $document, array $pages, callable $progress): array;
}
