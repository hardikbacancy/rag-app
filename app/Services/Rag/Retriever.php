<?php

namespace App\Services\Rag;

use App\Models\Chunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid retrieval: pgvector cosine similarity + Postgres full-text search,
 * merged with Reciprocal Rank Fusion (RRF). Keyword search helps with exact
 * numbers, names and acronyms that embeddings can blur.
 */
class Retriever
{
    private const RRF_K = 60;

    public function __construct(private GeminiClient $gemini) {}

    /**
     * @param  int[]|null  $documentIds  null = search every ready document
     * @return Collection<int, Chunk> chunks with ->score, ->vector_similarity attributes
     */
    public function search(string $question, ?array $documentIds = null, ?int $topK = null): Collection
    {
        $topK ??= config('rag.retrieval.top_k');
        $candidates = max($topK, config('rag.retrieval.candidates'));

        $queryVector = Vector::literal($this->gemini->embed([$question], 'RETRIEVAL_QUERY')[0]);
        $model = config('rag.gemini.embedding_model');

        [$vectorHits, $keywordHits, $sectionHits] = DB::transaction(function () use ($queryVector, $model, $question, $documentIds, $candidates) {
            // Search deeper in the HNSW graph so the document filter still leaves enough results.
            DB::statement('SET LOCAL hnsw.ef_search = 200');

            $vector = DB::table('chunks')
                ->join('documents', 'documents.id', '=', 'chunks.document_id')
                ->where('documents.status', 'ready')
                ->when($documentIds, fn ($q) => $q->whereIn('chunks.document_id', $documentIds))
                ->where('chunks.embedding_model', $model) // only compare vectors from the same model
                ->selectRaw('chunks.id, 1 - (chunks.embedding <=> ?::vector) AS similarity', [$queryVector])
                ->orderByRaw('chunks.embedding <=> ?::vector', [$queryVector])
                ->limit($candidates)
                ->get();

            $tsQuery = $this->orQuery($question);
            $keyword = $tsQuery === '' ? collect() : DB::table('chunks')
                ->join('documents', 'documents.id', '=', 'chunks.document_id')
                ->where('documents.status', 'ready')
                ->when($documentIds, fn ($q) => $q->whereIn('chunks.document_id', $documentIds))
                ->whereRaw("chunks.search @@ to_tsquery('english', ?)", [$tsQuery])
                ->selectRaw("chunks.id, ts_rank_cd(chunks.search, to_tsquery('english', ?), 32) AS rank", [$tsQuery])
                ->orderByDesc('rank')
                ->limit($candidates)
                ->get();

            // Section-aware search: question names a chapter/section/region -> search inside it too.
            $sections = $this->sectionsNamedIn($question, $documentIds);
            $inSection = $sections === [] ? collect() : DB::table('chunks')
                ->join('documents', 'documents.id', '=', 'chunks.document_id')
                ->where('documents.status', 'ready')
                ->when($documentIds, fn ($q) => $q->whereIn('chunks.document_id', $documentIds))
                ->whereIn('chunks.section', $sections)
                ->where('chunks.embedding_model', $model)
                ->selectRaw('chunks.id')
                ->orderByRaw('chunks.embedding <=> ?::vector', [$queryVector])
                ->limit($candidates)
                ->get();

            return [$vector, $keyword, $inSection];
        });

        // Reciprocal Rank Fusion
        $scores = [];
        foreach ($vectorHits->values() as $rank => $hit) {
            $scores[$hit->id] = ($scores[$hit->id] ?? 0) + 1 / (self::RRF_K + $rank + 1);
        }
        foreach ([$keywordHits, $sectionHits] as $hits) {
            foreach ($hits->values() as $rank => $hit) {
                $scores[$hit->id] = ($scores[$hit->id] ?? 0) + 1 / (self::RRF_K + $rank + 1);
            }
        }
        arsort($scores);
        $ids = array_slice(array_keys($scores), 0, $topK);

        $similarity = $vectorHits->pluck('similarity', 'id');
        $chunks = Chunk::with('document:id,title')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(function ($id) use ($chunks, $scores, $similarity) {
            $chunk = $chunks[$id];
            $chunk->score = round($scores[$id], 5);
            $chunk->vector_similarity = isset($similarity[$id]) ? round((float) $similarity[$id], 4) : null;

            return $chunk;
        })->values();
    }

    /**
     * Section paths whose headings are mentioned in the question, e.g.
     * "Chapter 3" -> "CHAPTER 3: A RISING CHALLENGE > ...", "South Asia" -> "... > South Asia".
     * Only the longest matching heading wins, so "executive summary" beats a generic "Summary".
     *
     * @return string[]
     */
    public function sectionsNamedIn(string $question, ?array $documentIds): array
    {
        $q = ' '.mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $question)).' ';
        $paths = DB::table('chunks')
            ->when($documentIds, fn ($x) => $x->whereIn('document_id', $documentIds))
            ->whereNotNull('section')->distinct()->pluck('section');

        $best = 0;
        $matches = [];
        foreach ($paths as $path) {
            foreach (explode(' > ', $path) as $heading) {
                $h = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $heading)));
                // "chapter 3 a rising challenge" -> also try the "chapter 3" label on its own
                $variants = preg_match('/^((?:chapter|annex|part|section|appendix) \d+(?: \d+)?)\b/', $h, $m) ? [$h, $m[1]] : [$h];
                foreach ($variants as $v) {
                    if (mb_strlen($v) >= 5 && str_contains($q, ' '.$v.' ')) {
                        $len = mb_strlen($v);
                        if ($len > $best) {
                            [$best, $matches] = [$len, []];
                        }
                        if ($len === $best) {
                            $matches[] = $path;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($matches));
    }

    /** "What is X's growth?" -> "x | growth" (any word may match; ranking rewards more matches). */
    private function orQuery(string $question): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($question), $m);
        $words = array_unique(array_filter($m[0], fn ($w) => mb_strlen($w) > 2 || ctype_digit($w)));

        return implode(' | ', $words);
    }
}
