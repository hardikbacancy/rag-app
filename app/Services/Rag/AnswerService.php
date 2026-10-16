<?php

namespace App\Services\Rag;

use Illuminate\Support\Collection;

/**
 * Retrieves context and asks the LLM for an answer grounded in it,
 * with [S#] citations that map back to real chunks and pages.
 */
class AnswerService
{
    public const NOT_FOUND = "I couldn't find this in the selected documents.";

    private const SYSTEM = <<<'TXT'
You answer questions using ONLY the numbered sources provided. Rules:
- Every factual sentence must end with citations like [S1] or [S2][S4], referring to the sources you used.
- Copy numbers, units, dates and names exactly as written in the sources. Do not round or convert.
- Sources may be prose, Markdown tables, chart descriptions or footnotes. Read tables row by row; mention footnote caveats when they qualify a figure.
- When a question is about a specific chapter, section, region or year, use only sources from that part of the document and do not mix in facts about other regions/sections.
- If the sources do not contain the answer, reply exactly: "I couldn't find this in the selected documents." Do not use outside knowledge.
- Be concise: a direct answer first, then brief supporting detail.
TXT;

    public function __construct(
        private Retriever $retriever,
        private GeminiClient $gemini,
    ) {}

    /**
     * @return array{answer:string, citations:array, sources:array}
     */
    public function ask(string $question, ?array $documentIds = null): array
    {
        $chunks = $this->retriever->search($question, $documentIds);

        if ($chunks->isEmpty()) {
            return ['answer' => self::NOT_FOUND, 'citations' => [], 'sources' => []];
        }

        $answer = trim($this->gemini->generate($this->prompt($question, $chunks), self::SYSTEM));

        // Keep only citations that point at a source we actually supplied.
        preg_match_all('/\[S(\d+)\]/', $answer, $m);
        $cited = array_values(array_unique(array_filter(array_map('intval', $m[1]), fn ($n) => $n >= 1 && $n <= $chunks->count())));
        $answer = preg_replace_callback('/\[S(\d+)\]/', fn ($x) => in_array((int) $x[1], $cited) ? $x[0] : '', $answer);

        $sources = $chunks->values()->map(fn ($chunk, $i) => [
            'label' => 'S'.($i + 1),
            'chunk_id' => $chunk->id,
            'document_id' => $chunk->document_id,
            'document_title' => $chunk->document->title,
            'page' => $chunk->page,
            'section' => $chunk->section,
            'type' => $chunk->type,
            'score' => $chunk->score,
            'vector_similarity' => $chunk->vector_similarity,
            'content' => $chunk->content,
            'cited' => in_array($i + 1, $cited),
        ])->all();

        return [
            'answer' => $answer,
            'citations' => array_values(array_filter($sources, fn ($s) => $s['cited'])),
            'sources' => $sources,
        ];
    }

    private function prompt(string $question, Collection $chunks): string
    {
        $blocks = $chunks->values()->map(function ($chunk, $i) {
            $meta = "Document: {$chunk->document->title} | Page {$chunk->page} | Type: {$chunk->type}";
            if ($chunk->section) {
                $meta .= " | Section: {$chunk->section}";
            }

            return '[S'.($i + 1)."] {$meta}\n{$chunk->content}";
        })->implode("\n\n---\n\n");

        return "Sources:\n\n{$blocks}\n\n===\nQuestion: {$question}\nAnswer (with [S#] citations):";
    }
}
