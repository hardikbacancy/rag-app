<?php

namespace App\Providers;

use App\Services\Rag\TextSplitter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Chunk size/overlap come from config/rag.php (RAG_CHUNK_SIZE, RAG_CHUNK_OVERLAP).
        $this->app->bind(TextSplitter::class, fn () => TextSplitter::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
