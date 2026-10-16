<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Rag\IngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600; // large reports + vision calls can take a while

    public function __construct(public Document $document) {}

    public function handle(IngestionService $ingestion): void
    {
        $ingestion->ingest($this->document);
    }
}
