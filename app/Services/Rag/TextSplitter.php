<?php

namespace App\Services\Rag;

/**
 * Splits text into overlapping chunks of roughly $size characters,
 * preferring paragraph and sentence boundaries over hard cuts.
 */
class TextSplitter
{
    public function __construct(
        private int $size = 1200,
        private int $overlap = 200,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('rag.chunking.chunk_size'), config('rag.chunking.chunk_overlap'));
    }

    /** @return string[] */
    public function split(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $this->size) {
            return [$text];
        }

        $chunks = [];
        $current = '';
        $hasNewText = false; // false while $current only holds overlap from the previous chunk

        foreach ($this->pieces($text) as $piece) {
            if ($hasNewText && mb_strlen($current) + mb_strlen($piece) + 1 > $this->size) {
                $chunks[] = trim($current);
                $current = $this->tail($current);
                $hasNewText = false;
            }
            $current .= ($current === '' ? '' : ' ').$piece;
            $hasNewText = true;
        }

        if ($hasNewText) {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /** Break text into paragraph/sentence pieces no longer than $size. */
    private function pieces(string $text): array
    {
        $pieces = [];

        foreach (preg_split("/\n\s*\n/u", $text) as $paragraph) {
            $paragraph = trim(preg_replace('/\s*\n\s*/u', ' ', $paragraph));
            if ($paragraph === '') {
                continue;
            }
            if (mb_strlen($paragraph) <= $this->size) {
                $pieces[] = $paragraph;

                continue;
            }
            foreach (preg_split('/(?<=[.!?;])\s+/u', $paragraph) as $sentence) {
                // A single "sentence" longer than the chunk size (e.g. a table row dump) gets hard-cut.
                foreach (mb_str_split($sentence, $this->size) as $part) {
                    $pieces[] = $part;
                }
            }
        }

        return $pieces;
    }

    /** Last ~$overlap characters, starting at a word boundary, carried into the next chunk. */
    private function tail(string $text): string
    {
        if ($this->overlap <= 0) {
            return '';
        }
        $tail = mb_substr($text, -$this->overlap);
        $space = mb_strpos($tail, ' ');

        return trim($space === false ? $tail : mb_substr($tail, $space + 1));
    }
}
