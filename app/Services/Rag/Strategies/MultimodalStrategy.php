<?php

namespace App\Services\Rag\Strategies;

use App\Models\Document;
use App\Services\Rag\GeminiClient;
use App\Services\Rag\PdfExtractor;
use App\Services\Rag\TextSplitter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * For visually complex reports (tables, charts, numbers, footnotes).
 *
 * 1. Runs the hierarchical text pipeline for normal prose.
 * 2. Picks the pages that look visual (embedded images, number-heavy
 *    tables, or almost no text layer) and sends a rendered image of each
 *    to the vision model, which returns tables as Markdown, charts as
 *    described data points, and footnotes. Pages without a text layer get
 *    OCR'd the same way. Each item becomes its own typed chunk.
 */
class MultimodalStrategy extends HierarchicalStrategy
{
    private const PROMPT = <<<'TXT'
You are extracting structured data from one page of a PDF report (page {page}).
The page's machine-readable text layer is given below (it may be empty or out of order). When a number appears in both the image and the text layer, copy it exactly from the text layer.

Return JSON with exactly these keys:
{
  "tables":   [{"title": "...", "markdown": "| col | col |\n|---|---|\n| ... |", "notes": "units, footnote markers, caveats"}],
  "charts":   [{"title": "...", "chart_type": "bar|line|pie|donut|infographic|other", "description": "what it shows", "data_points": ["label: value unit", "..."]}],
  "footnotes": ["1 full footnote text", "..."],
  "ocr_text": "{ocr_instruction}"
}
Rules:
- Only include content that is visibly on the page. Never invent values; if a value is unreadable, write "unreadable".
- Keep every row and column of each table, with units and footnote markers.
- For charts and infographics, list every labelled number shown (percentages, totals, years).
- Footnotes/endnotes: transcribe only note text that is actually printed on this page, word for word. A superscript marker (e.g. "content1") that refers to a note printed elsewhere is NOT a footnote - do not write or guess its text.
- Use empty arrays when there is nothing of that kind.

Text layer:
"""
{text}
"""
TXT;

    public function __construct(
        TextSplitter $splitter,
        PdfExtractor $pdf,
        protected GeminiClient $gemini,
    ) {
        parent::__construct($splitter, $pdf);
    }

    public function chunk(Document $document, array $pages, callable $progress): array
    {
        $path = $document->absolutePath();
        $chunks = parent::chunk($document, $pages, fn ($p, $m) => $progress((int) ($p * 0.2), $m));

        $visualPages = $this->selectVisualPages($path, $pages);
        $total = count($visualPages);
        $failed = 0;

        foreach (array_values($visualPages) as $i => $page) {
            $progress(20 + (int) (80 * $i / max($total, 1)), "Vision extraction: page {$page} (".($i + 1)."/{$total})");

            try {
                $chunks = array_merge($chunks, $this->extractVisualPage($path, $page, $pages[$page] ?? ''));
            } catch (Throwable $e) {
                // One unreadable page should not fail the whole document.
                $failed++;
                Log::warning("Vision extraction failed for document {$document->id} page {$page}: {$e->getMessage()}");
            }
        }

        $progress(100, "Vision processed {$total} pages".($failed ? " ({$failed} failed)" : ''));

        // Keep page order so neighbouring content stays together.
        usort($chunks, fn ($a, $b) => $a['page'] <=> $b['page']);

        return $chunks;
    }

    /** @return int[] page numbers to send to the vision model */
    public function selectVisualPages(string $path, array $pages): array
    {
        $mode = config('rag.vision.mode');
        if ($mode === 'off') {
            return [];
        }

        // Short documents (product sheets, brochures): every page is worth a look.
        if ($mode === 'all' || count($pages) <= config('rag.vision.all_pages_below')) {
            $selected = array_keys($pages);
        } else {
            $images = $this->pdf->imageCounts($path);
            $selected = [];
            foreach ($pages as $page => $text) {
                if ($this->looksVisual($text, $images[$page] ?? 0)) {
                    $selected[] = $page;
                }
            }
        }

        return array_slice($selected, 0, config('rag.vision.max_pages'));
    }

    public function looksVisual(string $text, int $imageCount): bool
    {
        if ($imageCount > 0 || mb_strlen(trim($text)) < 200) {
            return true; // charts/photos, or no usable text layer (needs OCR)
        }

        $lines = array_filter(array_map('trim', explode("\n", $text)));
        $numericLines = count(array_filter($lines, fn ($l) => preg_match('/^[\s\p{Sc}~<>≈+\-–(]*\d[\d.,]*\s*(%|[a-zA-Z₂]{0,6})?\)?[\s*†‡¹²³⁴⁵⁶⁷⁸⁹]*$/u', $l)
            || preg_match_all('/\d[\d,.]*/', $l) >= 3));

        return $numericLines >= 6 || preg_match('/\b(Table|Figure|Chart|Exhibit)\s+\d/i', $text);
    }

    private function extractVisualPage(string $path, int $page, string $text): array
    {
        $needsOcr = mb_strlen(trim($text)) < 200;
        $png = $this->pdf->renderPage($path, $page, config('rag.vision.dpi'));

        $prompt = strtr(self::PROMPT, [
            '{page}' => $page,
            '{text}' => mb_substr($text, 0, 12000),
            '{ocr_instruction}' => $needsOcr
                ? 'all readable body text on the page, in reading order'
                : 'leave empty',
        ]);

        $raw = $this->gemini->generate($prompt, images: [['mime' => 'image/png', 'data' => $png]], json: true, model: config('rag.gemini.vision_model'));
        $data = json_decode($this->stripFences($raw), true);
        if (! is_array($data)) {
            throw new \RuntimeException('Vision model did not return valid JSON.');
        }

        $chunks = [];
        $meta = ['source' => 'vision'];

        foreach ($data['tables'] ?? [] as $i => $table) {
            $title = trim($table['title'] ?? '') ?: "Table on page {$page}";
            foreach ($this->splitTable((string) ($table['markdown'] ?? '')) as $part) {
                $content = "Table: {$title}\n{$part}".(! empty($table['notes']) ? "\nNotes: {$table['notes']}" : '');
                $chunks[] = $this->make($page, 'table', $content, $meta + ['title' => $title, 'numbers_in_text_layer' => $this->numbersVerified($part, $text)]);
            }
        }

        foreach ($data['charts'] ?? [] as $chart) {
            $title = trim($chart['title'] ?? '') ?: "Chart on page {$page}";
            $points = implode("\n", array_map(fn ($p) => '- '.(is_string($p) ? $p : json_encode($p)), $chart['data_points'] ?? []));
            $content = "Chart: {$title} (".($chart['chart_type'] ?? 'chart').")\n".trim($chart['description'] ?? '').($points ? "\nData:\n{$points}" : '');
            $chunks[] = $this->make($page, 'figure', $content, $meta + ['title' => $title, 'numbers_in_text_layer' => $this->numbersVerified($content, $text)]);
        }

        // Only keep footnotes we can find in the text layer (guards against invented notes).
        $footnotes = array_unique(array_filter(array_map(fn ($f) => trim(is_string($f) ? $f : json_encode($f)), $data['footnotes'] ?? [])));
        if (! $needsOcr) {
            $footnotes = array_filter($footnotes, fn ($f) => $this->groundedRatio($f, $text) >= 0.7);
        }
        if ($footnotes) {
            foreach ($this->splitter->split("Footnotes (page {$page}):\n".implode("\n\n", $footnotes)) as $piece) {
                $chunks[] = $this->make($page, 'footnote', $piece, $meta);
            }
        }

        if ($needsOcr && trim($data['ocr_text'] ?? '') !== '') {
            foreach ($this->splitter->split($data['ocr_text']) as $piece) {
                $chunks[] = $this->make($page, 'text', $piece, ['source' => 'ocr']);
            }
        }

        return $chunks;
    }

    /** Share of the words in $candidate that also occur in the page text layer (0..1). */
    public function groundedRatio(string $candidate, string $pageText): float
    {
        $words = fn (string $t) => preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY);
        $candidateWords = $words($candidate);
        if ($candidateWords === []) {
            return 0.0;
        }
        $pageWords = array_flip($words($pageText));

        return count(array_filter($candidateWords, fn ($w) => isset($pageWords[$w]))) / count($candidateWords);
    }

    /** Share of numbers in vision output that also appear in the text layer (null when there are none). */
    private function numbersVerified(string $content, string $pageText): ?float
    {
        preg_match_all('/\d+(?:[.,]\d+)*/', $content, $m);
        $numbers = array_unique($m[0]);
        if ($numbers === []) {
            return null;
        }
        $found = count(array_filter($numbers, fn ($n) => str_contains($pageText, $n)));

        return round($found / count($numbers), 2);
    }

    /** Split a long Markdown table into parts that each repeat the header rows. */
    private function splitTable(string $markdown, int $maxChars = 2400): array
    {
        $markdown = trim($markdown);
        if ($markdown === '' || mb_strlen($markdown) <= $maxChars) {
            return $markdown === '' ? [] : [$markdown];
        }

        $rows = explode("\n", $markdown);
        $header = implode("\n", array_slice($rows, 0, 2));
        $parts = [];
        $current = $header;
        foreach (array_slice($rows, 2) as $row) {
            if (mb_strlen($current) + mb_strlen($row) > $maxChars && $current !== $header) {
                $parts[] = $current;
                $current = $header;
            }
            $current .= "\n".$row;
        }
        $parts[] = $current;

        return $parts;
    }

    private function make(int $page, string $type, string $content, array $meta): array
    {
        return ['page' => $page, 'type' => $type, 'section' => null, 'content' => $content, 'metadata' => $meta];
    }

    private function stripFences(string $raw): string
    {
        return trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($raw)));
    }
}
