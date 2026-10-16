<?php

/*
|--------------------------------------------------------------------------
| RAG pipeline settings
|--------------------------------------------------------------------------
|
| Everything that tunes ingestion, retrieval and answer generation lives
| here so it can be changed from .env without touching code.
|
*/

return [

    // LLM / embedding provider (Google Gemini via REST).
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-3.5-flash-lite'),
        'vision_model' => env('GEMINI_VISION_MODEL', env('GEMINI_CHAT_MODEL', 'gemini-3.5-flash-lite')),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 120),
        'retries' => (int) env('GEMINI_RETRIES', 8),
        'embedding_batch_size' => (int) env('GEMINI_EMBEDDING_BATCH_SIZE', 50),
    ],

    // Must match the vector(...) column size in the chunks migration.
    // pgvector HNSW indexes support at most 2000 dimensions.
    'embedding_dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 768),

    'chunking' => [
        'chunk_size' => (int) env('RAG_CHUNK_SIZE', 1200),     // characters
        'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 200), // characters
    ],

    'retrieval' => [
        'top_k' => (int) env('RAG_TOP_K', 8),
        // Candidates fetched from each search (vector + keyword) before fusion.
        'candidates' => (int) env('RAG_CANDIDATES', 30),
    ],

    // Multimodal strategy: which pages get sent to the vision model.
    // auto = only pages that look like tables/charts/images, all = every page, off = never.
    'vision' => [
        'mode' => env('RAG_VISION_MODE', 'auto'),
        'max_pages' => (int) env('RAG_VISION_MAX_PAGES', 120),
        // In auto mode, documents with at most this many pages get every page processed.
        'all_pages_below' => (int) env('RAG_VISION_ALL_PAGES_BELOW', 30),
        'dpi' => (int) env('RAG_VISION_DPI', 110),
    ],

    'upload_max_kb' => (int) env('RAG_UPLOAD_MAX_KB', 102400), // 100 MB

    // Poppler binaries (apt install poppler-utils).
    'bin' => [
        'pdfinfo' => env('PDFINFO_BIN', 'pdfinfo'),
        'pdftotext' => env('PDFTOTEXT_BIN', 'pdftotext'),
        'pdftohtml' => env('PDFTOHTML_BIN', 'pdftohtml'),
        'pdfimages' => env('PDFIMAGES_BIN', 'pdfimages'),
        'pdftoppm' => env('PDFTOPPM_BIN', 'pdftoppm'),
    ],
];
