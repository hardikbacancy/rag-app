<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Rag\AnswerService;
use Illuminate\Console\Command;

class RagAsk extends Command
{
    protected $signature = 'rag:ask {question} {--document=* : Document id(s) or title(s) to search}';

    protected $description = 'Ask a question from the command line';

    public function handle(AnswerService $answers): int
    {
        $ids = collect($this->option('document'))->map(fn ($d) => is_numeric($d) ? (int) $d : Document::where('title', $d)->value('id'))->filter()->values()->all();

        $result = $answers->ask($this->argument('question'), $ids ?: null);

        $this->line($result['answer']);
        $this->newLine();
        $this->table(['src', 'doc', 'page', 'type', 'section', 'cited', 'score'], array_map(fn ($s) => [
            $s['label'], $s['document_title'], $s['page'], $s['type'], mb_strimwidth((string) $s['section'], 0, 50, '…'), $s['cited'] ? 'yes' : '', $s['score'],
        ], $result['sources']));

        return self::SUCCESS;
    }
}
