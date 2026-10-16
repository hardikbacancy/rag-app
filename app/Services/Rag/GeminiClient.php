<?php

namespace App\Services\Rag;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the Google Gemini REST API.
 * Only the two calls the pipeline needs: embeddings and content generation.
 */
class GeminiClient
{
    public function __construct(
        private ?string $apiKey = null,
        private ?string $baseUrl = null,
    ) {
        $this->apiKey ??= config('rag.gemini.api_key');
        $this->baseUrl ??= rtrim(config('rag.gemini.base_url'), '/');
    }

    /**
     * Embed many texts. Returns one float[] per input, in the same order.
     *
     * @param  string[]  $texts
     * @param  string  $taskType  RETRIEVAL_DOCUMENT for chunks, RETRIEVAL_QUERY for questions
     * @return array<int, float[]>
     */
    public function embed(array $texts, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        $model = config('rag.gemini.embedding_model');
        $dims = config('rag.embedding_dimensions');
        $vectors = [];

        // The batch endpoint accepts up to 100 inputs per call; smaller batches suit free-tier quotas.
        foreach (array_chunk($texts, min(100, config('rag.gemini.embedding_batch_size'))) as $batch) {
            $requests = array_map(fn (string $text) => [
                'model' => "models/{$model}",
                'content' => ['parts' => [['text' => $text]]],
                'taskType' => $taskType,
                'outputDimensionality' => $dims,
            ], $batch);

            $response = $this->request()->post("{$this->baseUrl}/models/{$model}:batchEmbedContents", [
                'requests' => $requests,
            ]);

            foreach ($response->json('embeddings', []) as $embedding) {
                $vectors[] = $this->normalize($embedding['values']);
            }
        }

        if (count($vectors) !== count($texts)) {
            throw new RuntimeException('Embedding API returned '.count($vectors).' vectors for '.count($texts).' inputs.');
        }

        return $vectors;
    }

    /**
     * Generate text. $images is a list of ['mime' => 'image/png', 'data' => <raw bytes>].
     */
    public function generate(string $prompt, ?string $system = null, array $images = [], bool $json = false, ?string $model = null): string
    {
        $model ??= config('rag.gemini.chat_model');

        $parts = [];
        foreach ($images as $image) {
            $parts[] = ['inline_data' => ['mime_type' => $image['mime'], 'data' => base64_encode($image['data'])]];
        }
        $parts[] = ['text' => $prompt];

        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['temperature' => 0.1],
        ];
        if ($system) {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        if ($json) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        $response = $this->request()->post("{$this->baseUrl}/models/{$model}:generateContent", $payload);

        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')->filter()->implode('');

        if ($text === '') {
            $reason = $response->json('candidates.0.finishReason') ?? $response->json('promptFeedback.blockReason') ?? 'unknown';
            throw new RuntimeException("Gemini returned an empty response (reason: {$reason}).");
        }

        return $text;
    }

    private function request(): PendingRequest
    {
        if (! $this->apiKey) {
            throw new RuntimeException('GEMINI_API_KEY is not set. Add it to your .env file.');
        }

        return Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout(config('rag.gemini.timeout'))
            // Retry rate limits (429) and transient server errors with backoff.
            ->retry(config('rag.gemini.retries'), fn (int $attempt, $e) => $this->retryDelayMs($attempt, $e), function ($e) {
                if (! $e instanceof RequestException) {
                    return true; // connection errors
                }
                // A daily quota asks us to wait hours: fail now with a clear message instead of retrying.
                if ($e->response->status() === 429 && $this->requestedDelaySeconds($e) > 120) {
                    throw new RuntimeException('Gemini quota exhausted: '.mb_substr((string) $e->response->json('error.message'), 0, 400));
                }

                return in_array($e->response->status(), [429, 500, 502, 503, 504]);
            })
            ->throw();
    }

    /** Wait as long as Gemini asks (RetryInfo.retryDelay, e.g. "37s"), else exponential backoff. */
    private function retryDelayMs(int $attempt, $e): int
    {
        $requested = $e instanceof RequestException ? $this->requestedDelaySeconds($e) : null;

        return $requested !== null
            ? (int) min(($requested + 1) * 1000, 90000)
            : min(2 ** $attempt * 1000, 60000);
    }

    private function requestedDelaySeconds(RequestException $e): ?float
    {
        foreach ((array) $e->response->json('error.details', []) as $detail) {
            if (isset($detail['retryDelay']) && preg_match('/^([\d.]+)s$/', $detail['retryDelay'], $m)) {
                return (float) $m[1];
            }
        }

        return null;
    }

    /** Truncated Gemini embeddings (< 3072 dims) must be re-normalised for cosine search. */
    private function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

        return $norm > 0 ? array_map(fn ($v) => $v / $norm, $vector) : $vector;
    }
}
