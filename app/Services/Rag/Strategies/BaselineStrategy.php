<?php

namespace App\Services\Rag\Strategies;

use App\Models\Document;
use App\Services\Rag\TextSplitter;

/**
 * Simplest strategy: fixed-size overlapping chunks, never crossing a page
 * boundary so every chunk cites exactly one page.
 */
class BaselineStrategy implements ChunkingStrategy
{
    public function __construct(protected TextSplitter $splitter) {}

    public function chunk(Document $document, array $pages, callable $progress): array
    {
        $chunks = [];

        foreach ($pages as $page => $text) {
            foreach ($this->splitter->split($text) as $piece) {
                $chunks[] = ['page' => $page, 'type' => 'text', 'section' => null, 'content' => $piece, 'metadata' => []];
            }
        }
        $progress(100, 'Text chunked');

        return $chunks;
    }
}
