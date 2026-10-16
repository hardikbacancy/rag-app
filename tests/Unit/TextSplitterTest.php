<?php

namespace Tests\Unit;

use App\Services\Rag\TextSplitter;
use PHPUnit\Framework\TestCase;

class TextSplitterTest extends TestCase
{
    public function test_short_text_is_a_single_chunk(): void
    {
        $this->assertSame(['Hello world.'], (new TextSplitter(100, 20))->split('  Hello world.  '));
    }

    public function test_empty_text_gives_no_chunks(): void
    {
        $this->assertSame([], (new TextSplitter(100, 20))->split("  \n "));
    }

    public function test_long_text_is_split_with_overlap_and_bounded_size(): void
    {
        $sentences = [];
        for ($i = 1; $i <= 40; $i++) {
            $sentences[] = "Sentence number {$i} talks about topic {$i}.";
        }
        $chunks = (new TextSplitter(200, 50))->split(implode(' ', $sentences));

        $this->assertGreaterThan(5, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(260, mb_strlen($chunk)); // size + overlap
        }
        // Consecutive chunks share text (overlap).
        $tail = mb_substr($chunks[0], -20);
        $this->assertStringContainsString(trim(mb_substr($tail, mb_strpos($tail, ' ') ?: 0)), $chunks[1]);
        // Nothing lost: last sentence is present.
        $this->assertStringContainsString('Sentence number 40', end($chunks));
    }
}
