<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Reads PDFs with poppler-utils (pdftotext, pdftohtml, pdfimages, pdftoppm).
 * Commands are passed as arrays, so file paths are never interpreted by a shell.
 */
class PdfExtractor
{
    public function pageCount(string $path): int
    {
        $out = $this->run([config('rag.bin.pdfinfo'), $path]);

        if (! preg_match('/^Pages:\s+(\d+)/m', $out, $m)) {
            throw new RuntimeException('Could not read page count; is this a valid PDF?');
        }

        return (int) $m[1];
    }

    /**
     * Plain text of every page in reading order.
     *
     * @return array<int, string> 1-based page number => text
     */
    public function pages(string $path): array
    {
        // pdftotext separates pages with a form feed (\f).
        $out = $this->run([config('rag.bin.pdftotext'), '-enc', 'UTF-8', $path, '-']);
        $parts = explode("\f", $out);

        // Trailing form feed produces one empty element at the end.
        if (count($parts) > 1 && trim(end($parts)) === '') {
            array_pop($parts);
        }

        $pages = [];
        foreach ($parts as $i => $text) {
            $pages[$i + 1] = $this->clean($text);
        }

        return $pages;
    }

    /**
     * Text of one page with its physical layout kept (columns stay aligned) - useful for tables.
     */
    public function pageLayoutText(string $path, int $page): string
    {
        return $this->clean($this->run([
            config('rag.bin.pdftotext'), '-layout', '-enc', 'UTF-8', '-f', (string) $page, '-l', (string) $page, $path, '-',
        ]));
    }

    /**
     * Every text line with its font size, from pdftohtml's XML output.
     * Used to detect headings for section-aware chunking.
     *
     * @return array<int, array{page:int, top:int, size:float, bold:bool, text:string}>
     */
    public function textLines(string $path): array
    {
        $xml = $this->run([config('rag.bin.pdftohtml'), '-xml', '-i', '-q', '-stdout', '-enc', 'UTF-8', $path]);

        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE | LIBXML_NONET);
        if ($doc === false) {
            return [];
        }

        $fontSizes = [];
        foreach ($doc->xpath('//fontspec') as $font) {
            $fontSizes[(string) $font['id']] = (float) $font['size'];
        }

        $lines = [];
        foreach ($doc->page as $page) {
            foreach ($page->text as $text) {
                $raw = $text->asXML();
                $content = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($content === '') {
                    continue;
                }
                $lines[] = [
                    'page' => (int) $page['number'],
                    'top' => (int) $text['top'],
                    'size' => $fontSizes[(string) $text['font']] ?? 0.0,
                    'bold' => str_contains($raw, '<b>'),
                    'text' => preg_replace('/\s+/u', ' ', self::normalize($content)),
                ];
            }
        }

        return $lines;
    }

    /**
     * Number of embedded images per page (ignores tiny ones such as icons/bullets).
     *
     * @return array<int, int> page => image count
     */
    public function imageCounts(string $path, int $minSide = 80): array
    {
        $out = $this->run([config('rag.bin.pdfimages'), '-list', $path]);
        $counts = [];

        foreach (array_slice(explode("\n", $out), 2) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            // columns: page num type width height ...
            if (count($cols) >= 5 && ctype_digit($cols[0]) && $cols[2] === 'image'
                && (int) $cols[3] >= $minSide && (int) $cols[4] >= $minSide) {
                $counts[(int) $cols[0]] = ($counts[(int) $cols[0]] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Render one page to PNG and return the PNG bytes.
     */
    public function renderPage(string $path, int $page, int $dpi = 110): string
    {
        $prefix = tempnam(sys_get_temp_dir(), 'ragpage');
        try {
            $this->run([
                config('rag.bin.pdftoppm'), '-png', '-singlefile', '-r', (string) $dpi,
                '-f', (string) $page, '-l', (string) $page, $path, $prefix,
            ]);

            $png = @file_get_contents($prefix.'.png');
            if ($png === false) {
                throw new RuntimeException("Failed to render page {$page}.");
            }

            return $png;
        } finally {
            @unlink($prefix);
            @unlink($prefix.'.png');
        }
    }

    /**
     * Unicode NFKC: turns typographic ligatures ("ﬁ", "ﬂ") and non-breaking spaces
     * into plain characters so keyword search and embeddings see normal words.
     */
    public static function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            return \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        return strtr($text, ['ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬀ' => 'ff', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl', "\u{00A0}" => ' ']);
    }

    private function run(array $command): string
    {
        $result = Process::timeout(600)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(basename($command[0]).' failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $result->output();
    }

    private function clean(string $text): string
    {
        $text = self::normalize($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text; // strip control chars
        $text = preg_replace("/[ \t]+\n/", "\n", $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
