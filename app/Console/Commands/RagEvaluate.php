<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Rag\AnswerService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the sample questions against the ingested reports and records
 * measured retrieval and answer metrics. Nothing here is estimated:
 * every number comes from an actual retrieval + LLM call in this run.
 */
class RagEvaluate extends Command
{
    protected $signature = 'rag:evaluate
        {--dataset=evaluation/dataset.json : Questions with key facts and expected pages}
        {--out=evaluation/results : Directory for the JSON + Markdown report}
        {--only= : Only run questions whose id starts with this prefix (e.g. gpo)}';

    protected $description = 'Evaluate retrieval and answer accuracy on the sample questions';

    public function handle(AnswerService $answers): int
    {
        $dataset = json_decode(file_get_contents(base_path($this->option('dataset'))), true);
        $results = [];

        foreach ($dataset['documents'] as $doc) {
            $document = Document::where('title', $doc['title'])->first();
            if (! $document || $document->status !== 'ready') {
                $this->warn("Skipping \"{$doc['title']}\": not ingested (run rag:ingest first).");

                continue;
            }

            foreach ($doc['questions'] as $q) {
                if ($this->option('only') && ! str_starts_with($q['id'], $this->option('only'))) {
                    continue;
                }
                $this->line("<info>{$q['id']}</info> {$q['question']}");

                try {
                    $started = microtime(true);
                    $result = $answers->ask($q['question'], [$document->id]);
                    $results[] = $this->score($q, $document, $result, microtime(true) - $started);
                } catch (Throwable $e) {
                    $results[] = ['id' => $q['id'], 'document' => $document->title, 'strategy' => $document->strategy, 'question' => $q['question'], 'error' => $e->getMessage()];
                }

                $last = end($results);
                $this->line(isset($last['error']) ? "  <error>error: {$last['error']}</error>" : sprintf(
                    '  retrieval_hit=%s rank=%s cited_correct_page=%s facts=%d/%d pass=%s',
                    $last['retrieval_hit'] ? 'yes' : 'no', $last['first_relevant_rank'] ?? '-',
                    $last['citation_hit'] ? 'yes' : 'no', $last['facts_found'], $last['facts_total'], $last['answer_pass'] ? 'yes' : 'no'
                ));
            }
        }

        if ($results === []) {
            $this->error('Nothing was evaluated.');

            return self::FAILURE;
        }

        $summary = $this->summarize($results);
        $outDir = base_path($this->option('out'));
        @mkdir($outDir, 0775, true);

        $payload = [
            'run_at' => now()->toIso8601String(),
            'config' => [
                'chat_model' => config('rag.gemini.chat_model'),
                'vision_model' => config('rag.gemini.vision_model'),
                'embedding_model' => config('rag.gemini.embedding_model'),
                'embedding_dimensions' => config('rag.embedding_dimensions'),
                'top_k' => config('rag.retrieval.top_k'),
                'chunk_size' => config('rag.chunking.chunk_size'),
                'chunk_overlap' => config('rag.chunking.chunk_overlap'),
            ],
            'summary' => $summary,
            'results' => $results,
        ];
        file_put_contents("{$outDir}/latest.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents("{$outDir}/latest.md", $this->markdown($payload));

        $this->newLine();
        $this->table(['document', 'questions', 'retrieval hit@k', 'MRR', 'cited correct page', 'answer pass', 'key facts'], array_map(fn ($s) => [
            $s['document'], $s['questions'], $s['retrieval_hit_rate'], $s['mrr'], $s['citation_hit_rate'], $s['answer_pass_rate'], $s['fact_recall'],
        ], $summary));
        $this->info("Report written to {$this->option('out')}/latest.md and latest.json");

        return self::SUCCESS;
    }

    private function score(array $q, Document $document, array $result, float $seconds): array
    {
        $expected = $q['expected_pages'];
        $retrievedPages = array_column($result['sources'], 'page');
        $citedPages = array_values(array_unique(array_column($result['citations'], 'page')));

        $rank = null;
        foreach ($retrievedPages as $i => $page) {
            if (in_array($page, $expected, true)) {
                $rank = $i + 1;
                break;
            }
        }

        $answer = mb_strtolower($result['answer']);
        $facts = array_map(fn (array $alternatives) => [
            'alternatives' => $alternatives,
            'found' => collect($alternatives)->contains(fn ($a) => str_contains($answer, mb_strtolower($a))),
        ], $q['key_facts']);
        $found = count(array_filter($facts, fn ($f) => $f['found']));

        $sectionPrecision = null;
        if (! empty($q['expected_section']) && $result['sources']) {
            $inSection = array_filter($result['sources'], fn ($s) => $s['section'] && stripos($s['section'], $q['expected_section']) !== false);
            $sectionPrecision = round(count($inSection) / count($result['sources']), 2);
        }

        return [
            'id' => $q['id'],
            'document' => $document->title,
            'strategy' => $document->strategy,
            'question' => $q['question'],
            'reference_answer' => $q['reference_answer'],
            'answer' => $result['answer'],
            'expected_pages' => $expected,
            'retrieved_pages' => $retrievedPages,
            'retrieved_types' => array_column($result['sources'], 'type'),
            'cited_pages' => $citedPages,
            'retrieval_hit' => $rank !== null,
            'first_relevant_rank' => $rank,
            'citation_hit' => (bool) array_intersect($citedPages, $expected),
            'section_precision' => $sectionPrecision,
            'facts' => $facts,
            'facts_found' => $found,
            'facts_total' => count($facts),
            'answer_pass' => $found === count($facts),
            'seconds' => round($seconds, 1),
        ];
    }

    private function summarize(array $results): array
    {
        $groups = collect($results)->groupBy('document');
        $groups['ALL'] = collect($results);

        return $groups->map(function ($rows, $document) {
            $ok = $rows->filter(fn ($r) => ! isset($r['error']));
            $n = max($ok->count(), 1);

            return [
                'document' => $document,
                'questions' => $rows->count().($rows->count() > $ok->count() ? ' ('.($rows->count() - $ok->count()).' errors)' : ''),
                'retrieval_hit_rate' => round($ok->where('retrieval_hit', true)->count() / $n, 2),
                'mrr' => round($ok->sum(fn ($r) => $r['first_relevant_rank'] ? 1 / $r['first_relevant_rank'] : 0) / $n, 2),
                'citation_hit_rate' => round($ok->where('citation_hit', true)->count() / $n, 2),
                'answer_pass_rate' => round($ok->where('answer_pass', true)->count() / $n, 2),
                'fact_recall' => round($ok->sum('facts_found') / max($ok->sum('facts_total'), 1), 2),
            ];
        })->values()->all();
    }

    private function markdown(array $payload): string
    {
        $c = $payload['config'];
        $md = "# Evaluation results\n\nRun at {$payload['run_at']} with chat model `{$c['chat_model']}`, vision model `{$c['vision_model']}`, embeddings `{$c['embedding_model']}` ({$c['embedding_dimensions']} dims), top_k={$c['top_k']}.\n\n";
        $md .= "Generated by `php artisan rag:evaluate`. Metrics are computed automatically; see the bottom of README.md for definitions.\n\n";
        $md .= "| Document | Questions | Retrieval hit@k | MRR | Cited correct page | Answer pass | Key-fact recall |\n|---|---|---|---|---|---|---|\n";
        foreach ($payload['summary'] as $s) {
            $md .= "| {$s['document']} | {$s['questions']} | {$s['retrieval_hit_rate']} | {$s['mrr']} | {$s['citation_hit_rate']} | {$s['answer_pass_rate']} | {$s['fact_recall']} |\n";
        }

        $md .= "\n## Per question\n";
        foreach ($payload['results'] as $r) {
            $md .= "\n### {$r['id']} ({$r['strategy']}): {$r['question']}\n\n";
            if (isset($r['error'])) {
                $md .= "**Error:** {$r['error']}\n";

                continue;
            }
            $missing = collect($r['facts'])->reject(fn ($f) => $f['found'])->map(fn ($f) => implode(' / ', $f['alternatives']))->implode('; ');
            $md .= '- Pass: **'.($r['answer_pass'] ? 'yes' : 'no')."** (key facts {$r['facts_found']}/{$r['facts_total']}".($missing ? ", missing: {$missing}" : '').")\n";
            $md .= '- Expected pages: '.implode(', ', $r['expected_pages']).' | retrieved: '.implode(', ', $r['retrieved_pages']).' | cited: '.(implode(', ', $r['cited_pages']) ?: 'none')."\n";
            $md .= '- First relevant rank: '.($r['first_relevant_rank'] ?? 'not retrieved').($r['section_precision'] !== null ? " | section precision: {$r['section_precision']}" : '')."\n";
            $md .= "- Reference: {$r['reference_answer']}\n\n";
            $md .= '> '.str_replace("\n", "\n> ", trim($r['answer']))."\n";
        }

        return $md;
    }
}
