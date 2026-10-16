<?php

namespace Tests\Unit;

use App\Services\Rag\Strategies\HierarchicalStrategy;
use App\Services\Rag\Strategies\MultimodalStrategy;
use Tests\TestCase;

class StrategyHeuristicsTest extends TestCase
{
    private function line(int $page, int $top, float $size, string $text): array
    {
        return ['page' => $page, 'top' => $top, 'size' => $size, 'bold' => false, 'text' => $text];
    }

    public function test_detects_headings_merges_chapter_labels_and_drops_running_headers(): void
    {
        $body = str_repeat('Body text that is long enough to dominate the font statistics. ', 3);
        $lines = [];
        foreach (range(1, 4) as $p) {
            $lines[] = $this->line($p, 20, 14, 'GLOBAL ECONOMIC PROSPECTS'); // running header
            $lines[] = $this->line($p, 300, 10, $body);
        }
        $lines[] = $this->line(1, 100, 20, 'CHAPTER 1');
        $lines[] = $this->line(1, 130, 24, 'GLOBAL OUTLOOK');
        $lines[] = $this->line(2, 100, 14, 'South Asia');

        $headings = app(HierarchicalStrategy::class)->detectHeadings($lines);

        $this->assertSame([['level' => 1, 'text' => 'CHAPTER 1: GLOBAL OUTLOOK']], $headings[1]);
        $this->assertSame([['level' => 2, 'text' => 'South Asia']], $headings[2]);
        $this->assertArrayNotHasKey(3, $headings); // header repeated on every page is ignored
    }

    public function test_visual_page_detection(): void
    {
        $strategy = app(MultimodalStrategy::class);
        $prose = str_repeat('This is ordinary narrative text without many numbers at all. ', 10);

        $this->assertFalse($strategy->looksVisual($prose, 0));
        $this->assertTrue($strategy->looksVisual($prose, 2), 'embedded images');
        $this->assertTrue($strategy->looksVisual('tiny', 0), 'no text layer -> OCR');
        $this->assertTrue($strategy->looksVisual($prose."\n".implode("\n", ['0.27W', '0.56W', '20.5W', '91.1%', '346 kg', '50%']), 0), 'numeric table');
    }

    public function test_grounded_ratio_rejects_invented_footnotes(): void
    {
        $strategy = app(MultimodalStrategy::class);
        $page = 'iMac uses 58 percent less energy. 18 Energy consumption values are based on the ENERGY STAR Program Requirements.';

        $this->assertGreaterThanOrEqual(0.7, $strategy->groundedRatio('18 Energy consumption values are based on the ENERGY STAR Program Requirements', $page));
        $this->assertLessThan(0.7, $strategy->groundedRatio('3 Details regarding packaging sourcing commitments', $page));
    }
}
