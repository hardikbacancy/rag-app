<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Rag\AnswerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueryController extends Controller
{
    public function ask(Request $request, AnswerService $answers): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer', 'exists:documents,id'],
        ]);

        try {
            return response()->json($answers->ask($data['question'], $data['document_ids'] ?? null));
        } catch (Throwable $e) {
            Log::error('RAG query failed: '.$e->getMessage());

            // Don't leak provider error details (may include request info) to the browser.
            return response()->json(['message' => 'Could not generate an answer. Check the server logs and your Gemini configuration.'], 502);
        }
    }
}
