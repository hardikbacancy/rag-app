<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end pipeline against the real pgvector test database.
 * Gemini is faked; poppler-utils must be installed.
 */
class RagPipelineTest extends TestCase
{
    use RefreshDatabase;

    private array $generatedPrompts = [];

    private bool $geminiDown = false;

    private int $embeddedTexts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['rag.gemini.api_key' => 'test-key', 'rag.vision.mode' => 'off']);

        Http::fake([
            '*:batchEmbedContents' => function (Request $request) {
                if ($this->geminiDown) {
                    return Http::response(['error' => ['message' => 'API key not valid']], 400);
                }

                // Deterministic fake embeddings: texts mentioning "South Asia" point one way, everything else another.
                $this->embeddedTexts += count($request['requests']);

                return Http::response(['embeddings' => array_map(function ($r) {
                    $text = $r['content']['parts'][0]['text'];
                    $v = array_fill(0, 768, 0.0);
                    $v[str_contains($text, 'South Asia') ? 0 : 1] = 1.0;
                    $v[2] = 0.1;

                    return ['values' => $v];
                }, $request['requests'])]);
            },
            '*:generateContent' => function (Request $request) {
                $this->generatedPrompts[] = $request['contents'][0]['parts'][0]['text'];

                return Http::response(['candidates' => [['content' => ['parts' => [
                    ['text' => 'Growth in South Asia is expected to soften to 6.3 percent in 2026 [S1]. Made-up source [S99].'],
                ]]]]]);
            },
        ]);
    }

    private function upload(string $strategy = 'baseline'): Document
    {
        $file = new UploadedFile(base_path('tests/Fixtures/sample.pdf'), 'sample.pdf', 'application/pdf', null, true);

        $response = $this->postJson('/api/documents', ['file' => $file, 'title' => 'Sample', 'strategy' => $strategy]);
        $response->assertCreated()->assertJsonMissingPath('path');

        return Document::findOrFail($response->json('id'));
    }

    public function test_upload_ingests_document_into_pgvector(): void
    {
        $document = $this->upload(); // queue is sync in tests

        $document->refresh();
        $this->assertSame('ready', $document->status, (string) $document->error);
        $this->assertSame(2, $document->page_count);
        $this->assertGreaterThan(0, $document->chunk_count);
        $this->assertEqualsCanonicalizing([1, 2], $document->chunks()->distinct()->pluck('page')->all());
        $this->assertSame(0, $document->chunks()->whereNull('embedding')->count());
    }

    public function test_reingest_reuses_cached_embeddings(): void
    {
        $document = $this->upload();
        $chunks = $document->refresh()->chunk_count;
        $this->assertSame($chunks, $this->embeddedTexts);

        $this->postJson("/api/documents/{$document->id}/reingest")->assertOk();

        $this->assertSame('ready', $document->refresh()->status);
        $this->assertSame($chunks, $this->embeddedTexts, 'no new embedding calls for unchanged chunks');
        $this->assertSame($chunks, $document->chunks()->where('embedding_model', config('rag.gemini.embedding_model'))->count());
    }

    public function test_hierarchical_strategy_records_section_paths(): void
    {
        $document = $this->upload('hierarchical')->refresh();

        $this->assertSame('ready', $document->status, (string) $document->error);
        $this->assertSame('Regional Outlook', $document->chunks()->where('page', 2)->value('section'));
    }

    public function test_ask_returns_answer_with_only_valid_page_citations(): void
    {
        $document = $this->upload();

        $response = $this->postJson('/api/ask', ['question' => 'What is the growth outlook for South Asia?', 'document_ids' => [$document->id]]);

        $response->assertOk();
        $this->assertStringContainsString('[S1]', $response->json('answer'));
        $this->assertStringNotContainsString('[S99]', $response->json('answer'), 'citations to non-existent sources are removed');
        $this->assertSame(2, $response->json('citations.0.page'), 'top source is the South Asia page');
        $this->assertTrue($response->json('citations.0.cited'));
        $this->assertStringContainsString('[S1] Document: Sample | Page 2', end($this->generatedPrompts));
    }

    public function test_upload_validation_rejects_non_pdf_and_bad_strategy(): void
    {
        $this->postJson('/api/documents', ['file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'), 'strategy' => 'baseline'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $file = new UploadedFile(base_path('tests/Fixtures/sample.pdf'), 'sample.pdf', 'application/pdf', null, true);
        $this->postJson('/api/documents', ['file' => $file, 'strategy' => 'magic'])
            ->assertUnprocessable()->assertJsonValidationErrors('strategy');
    }

    public function test_upload_over_php_limit_explains_the_fix(): void
    {
        $file = new UploadedFile(base_path('tests/Fixtures/sample.pdf'), 'big.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

        $this->postJson('/api/documents', ['file' => $file, 'strategy' => 'baseline'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', fn ($message) => str_contains($message, 'upload_max_filesize') && str_contains($message, 'composer serve'));
    }

    public function test_ask_validation(): void
    {
        $this->postJson('/api/ask', ['question' => ''])->assertUnprocessable();
        $this->postJson('/api/ask', ['question' => 'Valid question?', 'document_ids' => [999]])->assertUnprocessable();
    }

    public function test_page_preview_file_and_delete(): void
    {
        $document = $this->upload();

        $this->get("/api/documents/{$document->id}/pages/1")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("/api/documents/{$document->id}/pages/99")->assertNotFound();
        $this->get("/api/documents/{$document->id}/file")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->deleteJson("/api/documents/{$document->id}")->assertNoContent();
        $this->assertDatabaseCount('chunks', 0);
        Storage::disk('local')->assertMissing($document->path);
    }

    public function test_failed_ingestion_is_reported(): void
    {
        $this->geminiDown = true;

        $file = new UploadedFile(base_path('tests/Fixtures/sample.pdf'), 'sample.pdf', 'application/pdf', null, true);
        try {
            $this->postJson('/api/documents', ['file' => $file, 'strategy' => 'baseline']);
        } catch (\Throwable) {
            // sync queue rethrows the job failure
        }

        $document = Document::latest('id')->first();
        $this->assertSame('failed', $document->status);
        $this->assertStringContainsString('400', $document->error);
        $this->assertSame('sample', $document->title, 'title defaults to the file name');
    }
}
