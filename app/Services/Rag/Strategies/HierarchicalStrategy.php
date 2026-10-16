<?php

namespace App\Services\Rag\Strategies;

use App\Models\Document;
use App\Services\Rag\PdfExtractor;
use App\Services\Rag\TextSplitter;

/**
 * Section-aware chunking for long structured reports.
 *
 * Headings are detected from font sizes (text noticeably larger than the
 * body font), ranked into levels, and tracked as a "Chapter > Section >
 * Subsection" path that is stored with every chunk and prepended to the
 * embedding text. Chunks still never cross a page boundary.
 */
class HierarchicalStrategy implements ChunkingStrategy
{
    private const MAX_LEVELS = 3;

    public function __construct(
        protected TextSplitter $splitter,
        protected PdfExtractor $pdf,
    ) {}

    public function chunk(Document $document, array $pages, callable $progress): array
    {
        $headings = $this->detectHeadings($this->pdf->textLines($document->absolutePath()));
        $progress(30, count($headings).' headings detected');

        $chunks = [];
        $stack = []; // [['level' => int, 'text' => string], ...]

        foreach ($pages as $page => $text) {
            foreach ($this->segmentsForPage($text, $headings[$page] ?? [], $stack) as [$sectionPath, $level, $segment]) {
                foreach ($this->splitter->split($segment) as $piece) {
                    $chunks[] = [
                        'page' => $page,
                        'type' => 'text',
                        'section' => $sectionPath,
                        'content' => $piece,
                        'metadata' => ['section_level' => $level],
                    ];
                }
            }
        }
        $progress(100, 'Section-aware chunks created');

        return $chunks;
    }

    /**
     * Pick heading lines out of all text lines.
     *
     * @return array<int, array<int, array{level:int, text:string}>> page => headings in reading order
     */
    public function detectHeadings(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        // Body font = the size that carries the most characters.
        $charsBySize = [];
        foreach ($lines as $line) {
            $key = (string) round($line['size']);
            $charsBySize[$key] = ($charsBySize[$key] ?? 0) + mb_strlen($line['text']);
        }
        arsort($charsBySize);
        $bodySize = (float) array_key_first($charsBySize);

        // Candidates: clearly larger than body text, short, containing letters.
        $candidates = [];
        foreach ($lines as $line) {
            $len = mb_strlen($line['text']);
            if ($line['size'] >= $bodySize * 1.2 && $len >= 3 && $len <= 150
                && preg_match('/\p{L}{3,}/u', $line['text'])) {
                $candidates[] = $line;
            }
        }

        $candidates = $this->mergeMultilineHeadings($candidates);

        // Running headers/footers repeat on many pages - not real headings.
        $pagesPerText = [];
        foreach ($candidates as $c) {
            $pagesPerText[mb_strtolower($c['text'])][$c['page']] = true;
        }
        // Pages with lots of large text are usually covers/infographics/chart labels.
        $perPage = array_count_values(array_column($candidates, 'page'));

        $candidates = array_values(array_filter($candidates, fn ($c) => count($pagesPerText[mb_strtolower($c['text'])]) < 3 && $perPage[$c['page']] <= 10));

        $candidates = $this->mergeLabels($candidates);

        // Biggest distinct sizes become levels 1..MAX_LEVELS, everything smaller is the last level.
        $sizes = array_values(array_unique(array_map(fn ($c) => (int) round($c['size']), $candidates)));
        rsort($sizes);
        $levelOf = array_flip(array_slice($sizes, 0, self::MAX_LEVELS));

        $byPage = [];
        foreach ($candidates as $c) {
            $byPage[$c['page']][] = [
                'level' => ($levelOf[(int) round($c['size'])] ?? self::MAX_LEVELS - 1) + 1,
                'text' => $c['text'],
            ];
        }

        return $byPage;
    }

    /** "CHAPTER 1" + "GLOBAL OUTLOOK" -> "CHAPTER 1: GLOBAL OUTLOOK" (keeps the chapter number in the path). */
    private function mergeLabels(array $candidates): array
    {
        $result = [];
        for ($i = 0; $i < count($candidates); $i++) {
            $c = $candidates[$i];
            $next = $candidates[$i + 1] ?? null;
            if ($next && $next['page'] === $c['page']
                && preg_match('/^(chapter|annex|part|section|appendix)\s+[\dIVX]+(\.\d+)?$/i', $c['text'])) {
                $next['text'] = $c['text'].': '.$next['text'];
                $next['size'] = max($next['size'], $c['size']);
                $result[] = $next;
                $i++;

                continue;
            }
            $result[] = $c;
        }

        return $result;
    }

    /** Join heading lines that wrap onto a second line (same page, same size, adjacent). */
    private function mergeMultilineHeadings(array $candidates): array
    {
        $merged = [];
        foreach ($candidates as $c) {
            $last = end($merged);
            if ($last && $last['page'] === $c['page'] && round($last['size']) === round($c['size'])
                && $c['top'] - $last['bottom'] >= 0 && $c['top'] - $last['bottom'] <= $c['size'] * 0.8) {
                $merged[array_key_last($merged)]['text'] .= ' '.$c['text'];
                $merged[array_key_last($merged)]['bottom'] = $c['top'] + $c['size'];

                continue;
            }
            $c['bottom'] = $c['top'] + $c['size'];
            $merged[] = $c;
        }

        return $merged;
    }

    /**
     * Cut one page's text at each heading. $stack carries the section path across pages.
     *
     * @return array<int, array{0:?string, 1:int, 2:string}> [section path, level, text]
     */
    private function segmentsForPage(string $text, array $headings, array &$stack): array
    {
        $cuts = []; // [offset, heading]
        $offset = 0;
        foreach ($headings as $heading) {
            $pattern = '/'.implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), preg_split('/\s+/u', $heading['text']))).'/u';
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $cuts[] = [$m[0][1], $heading];
                $offset = $m[0][1] + strlen($m[0][0]);
            } else {
                // Heading not found in reading-order text: treat it as starting at the cut point.
                $cuts[] = [$offset, $heading];
            }
        }

        $segments = [];
        $start = 0;
        foreach ($cuts as [$cutAt, $heading]) {
            $segments[] = [$this->path($stack), $this->level($stack), substr($text, $start, $cutAt - $start)];
            $this->push($stack, $heading);
            $start = $cutAt;
        }
        $segments[] = [$this->path($stack), $this->level($stack), substr($text, $start)];

        // Fold tiny segments (e.g. a lone "Chapter 1" line) into the following one.
        $result = [];
        $carry = '';
        foreach ($segments as [$path, $level, $segment]) {
            $segment = trim($carry.' '.$segment);
            if (mb_strlen($segment) < 120) {
                $carry = $segment;

                continue;
            }
            $carry = '';
            $result[] = [$path, $level, $segment];
        }
        if ($carry !== '') {
            $result[] = [$this->path($stack), $this->level($stack), $carry];
        }

        return $result;
    }

    private function push(array &$stack, array $heading): void
    {
        while ($stack && end($stack)['level'] >= $heading['level']) {
            array_pop($stack);
        }
        $stack[] = $heading;
    }

    private function path(array $stack): ?string
    {
        return $stack ? mb_substr(implode(' > ', array_column($stack, 'text')), 0, 250) : null;
    }

    private function level(array $stack): int
    {
        return $stack ? end($stack)['level'] : 0;
    }
}
