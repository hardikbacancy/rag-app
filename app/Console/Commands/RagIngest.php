<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Rag\IngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RagIngest extends Command
{
    protected $signature = 'rag:ingest
        {path : Path to a PDF file}
        {--strategy=baseline : baseline | hierarchical | multimodal}
        {--title= : Display title (defaults to file name)}';

    protected $description = 'Add a PDF and ingest it synchronously (re-ingests if the same title exists)';

    public function handle(IngestionService $ingestion): int
    {
        $path = $this->argument('path');
        $strategy = $this->option('strategy');

        if (! is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
            $this->error("Not a PDF file: {$path}");

            return self::FAILURE;
        }
        if (! in_array($strategy, Document::STRATEGIES, true)) {
            $this->error('Strategy must be one of: '.implode(', ', Document::STRATEGIES));

            return self::FAILURE;
        }

        $title = $this->option('title') ?: pathinfo($path, PATHINFO_FILENAME);
        $stored = 'documents/'.sha1_file($path).'.pdf';
        Storage::disk('local')->put($stored, file_get_contents($path));

        $document = Document::updateOrCreate(['title' => $title], [
            'original_filename' => basename($path),
            'path' => $stored,
            'strategy' => $strategy,
            'status' => 'pending',
            'progress' => 0,
        ]);

        $this->info("Ingesting #{$document->id} \"{$title}\" with {$strategy} strategy...");
        $started = microtime(true);
        $ingestion->ingest($document);
        $document->refresh();

        $this->info(sprintf('%s (%.1fs)', $document->status_message, microtime(true) - $started));
        $this->table(['type', 'chunks'], $document->chunks()->selectRaw('type, count(*) as n')->groupBy('type')->get()->map(fn ($r) => [$r->type, $r->n]));

        return self::SUCCESS;
    }
}
