<?php

namespace App\Services\Rag;

use App\Models\Document;
use App\Services\Rag\Strategies\BaselineStrategy;
use App\Services\Rag\Strategies\ChunkingStrategy;
use App\Services\Rag\Strategies\HierarchicalStrategy;
use App\Services\Rag\Strategies\MultimodalStrategy;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The shared pipeline every document goes through:
 * extract text -> chunk (strategy-specific) -> embed -> store in pgvector.
 */
class IngestionService
{
    public function __construct(
        private PdfExtractor $pdf,
        private GeminiClient $gemini,
    ) {}

    public function strategyFor(Document $document): ChunkingStrategy
    {
        return match ($document->strategy) {
            'hierarchical' => app(HierarchicalStrategy::class),
            'multimodal' => app(MultimodalStrategy::class),
            default => app(BaselineStrategy::class),
        };
    }

    public function ingest(Document $document): void
    {
        try {
            $document->forceFill(['error' => null])->save();
            $document->updateStatus('processing', 2, 'Extracting text');

            $path = $document->absolutePath();
            $pageCount = $this->pdf->pageCount($path);
            $pages = $this->pdf->pages($path);
            $document->forceFill(['page_count' => $pageCount])->save();
            $document->updateStatus('processing', 10, "Extracted {$pageCount} pages");

            // Chunking: 10% -> 50%
            $chunks = $this->strategyFor($document)->chunk($document, $pages, function (int $percent, string $message) use ($document) {
                $document->updateStatus('processing', 10 + (int) ($percent * 0.4), $message);
            });

            if ($chunks === []) {
                throw new \RuntimeException('No text could be extracted from this PDF.');
            }

            // Embedding: 50% -> 95%. Vectors already stored for identical text are reused.
            $model = config('rag.gemini.embedding_model');
            foreach ($chunks as $i => $chunk) {
                $chunks[$i]['content_hash'] = sha1($this->embeddingText($document, $chunk));
            }
            $cached = DB::table('chunks')
                ->where('embedding_model', $model)
                ->whereIn('content_hash', array_unique(array_column($chunks, 'content_hash')))
                ->pluck('embedding', 'content_hash');

            $missing = array_keys(array_filter($chunks, fn ($c) => ! isset($cached[$c['content_hash']])));
            $batches = array_chunk($missing, 50);
            foreach ($batches as $b => $batch) {
                $document->updateStatus('processing', 50 + (int) (45 * $b / count($batches)),
                    'Embedding chunks ('.($b * 50).'/'.count($missing).' new, '.(count($chunks) - count($missing)).' reused)');
                $vectors = $this->gemini->embed(array_map(fn ($i) => $this->embeddingText($document, $chunks[$i]), $batch));
                foreach ($batch as $j => $i) {
                    $chunks[$i]['embedding'] = Vector::literal($vectors[$j]);
                }
            }

            $rows = [];
            foreach ($chunks as $chunk) {
                $rows[] = $chunk + ['embedding' => $cached[$chunk['content_hash']] ?? null, 'embedding_model' => $model];
            }

            $this->store($document, $rows);

            $document->forceFill([
                'chunk_count' => count($rows),
                'ingested_at' => now(),
            ])->save();
            $document->updateStatus('ready', 100, 'Ready: '.count($rows).' chunks from '.$pageCount.' pages');
        } catch (Throwable $e) {
            $document->forceFill(['error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            $document->updateStatus('failed', $document->progress, 'Ingestion failed');

            throw $e;
        }
    }

    /**
     * Text that gets embedded: the chunk plus its context (document, section, type),
     * so "growth in South Asia" lands near chunks from the South Asia section.
     */
    public function embeddingText(Document $document, array $chunk): string
    {
        $header = "Document: {$document->title}";
        if (! empty($chunk['section'])) {
            $header .= "\nSection: {$chunk['section']}";
        }
        if (($chunk['type'] ?? 'text') !== 'text') {
            $header .= "\nContent type: {$chunk['type']}";
        }

        return $header."\n\n".$chunk['content'];
    }

    /** Replace the document's chunks in one transaction (re-ingestion is safe). */
    private function store(Document $document, array $rows): void
    {
        DB::transaction(function () use ($document, $rows) {
            $document->chunks()->delete();
            $now = now();

            foreach (array_chunk($rows, 200) as $batch) {
                DB::table('chunks')->insert(array_map(fn ($row, $i) => [
                    'document_id' => $document->id,
                    'page' => $row['page'],
                    'position' => $i,
                    'type' => $row['type'] ?? 'text',
                    'section' => $row['section'] ?? null,
                    'content' => $row['content'],
                    'metadata' => json_encode($row['metadata'] ?? []),
                    'embedding' => $row['embedding'], // pgvector literal "[...]"
                    'embedding_model' => $row['embedding_model'],
                    'content_hash' => $row['content_hash'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $batch, array_keys($batch)));
            }
        });
    }
}
