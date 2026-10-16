<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\IngestDocument;
use App\Models\Document;
use App\Services\Rag\PdfExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Document::latest()->get());
    }

    public function show(Document $document): JsonResponse
    {
        return response()->json($document);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.config('rag.upload_max_kb')],
            'title' => ['nullable', 'string', 'max:200'],
            'strategy' => ['required', Rule::in(Document::STRATEGIES)],
        ], [
            'file.uploaded' => $this->uploadErrorMessage($request),
        ]);

        $file = $data['file'];
        // Random filename on the private disk: never trust or expose the client name as a path.
        $path = $file->store('documents', 'local');

        $document = Document::create([
            'title' => ($data['title'] ?? null) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'strategy' => $data['strategy'],
            'status' => 'pending',
            'status_message' => 'Queued for ingestion',
        ]);

        IngestDocument::dispatch($document);

        return response()->json($document->fresh(), 201);
    }

    /** PHP rejects oversized uploads before Laravel sees them; say why instead of "failed to upload". */
    private function uploadErrorMessage(Request $request): string
    {
        $error = $request->file('file')?->getError();

        return in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The file is larger than PHP allows (upload_max_filesize = '.ini_get('upload_max_filesize').'). '
                .'Start the app with "composer serve", or raise upload_max_filesize and post_max_size in php.ini.'
            : 'The file failed to upload (PHP upload error '.($error ?? 'unknown').').';
    }

    /** Re-run ingestion, optionally with a different strategy. */
    public function reingest(Request $request, Document $document): JsonResponse
    {
        $data = $request->validate(['strategy' => ['nullable', Rule::in(Document::STRATEGIES)]]);

        if ($document->status === 'processing') {
            return response()->json(['message' => 'Document is already being processed.'], 409);
        }

        $document->forceFill([
            'strategy' => $data['strategy'] ?? $document->strategy,
            'status' => 'pending', 'progress' => 0, 'status_message' => 'Queued for ingestion', 'error' => null,
        ])->save();

        IngestDocument::dispatch($document);

        return response()->json($document);
    }

    public function destroy(Document $document): JsonResponse
    {
        Storage::disk('local')->delete($document->path);
        Storage::disk('local')->deleteDirectory("page-cache/{$document->id}");
        $document->delete(); // chunks cascade

        return response()->json(null, 204);
    }

    /** Stream the original PDF (inline) so the UI can open it at a cited page. */
    public function file(Document $document): BinaryFileResponse
    {
        abort_unless(is_file($document->absolutePath()), 404);

        return response()->file($document->absolutePath(), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.addcslashes(basename($document->original_filename), '"\\').'"',
        ]);
    }

    /** PNG preview of one page (rendered once, then cached on disk). */
    public function page(Document $document, int $page, PdfExtractor $pdf): BinaryFileResponse
    {
        abort_unless($document->page_count && $page >= 1 && $page <= $document->page_count, 404);

        $cache = "page-cache/{$document->id}/{$page}.png";
        $disk = Storage::disk('local');
        if (! $disk->exists($cache)) {
            $disk->put($cache, $pdf->renderPage($document->absolutePath(), $page, 100));
        }

        return response()->file($disk->path($cache), ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400']);
    }
}
