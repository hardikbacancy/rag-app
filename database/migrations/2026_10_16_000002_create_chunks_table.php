<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page');               // 1-based page the chunk comes from
            $table->unsignedInteger('position');           // order within the document
            $table->string('type')->default('text');       // text | table | figure | footnote
            $table->string('section')->nullable();         // "Chapter 1 > 1.2 Outlook" (hierarchical)
            $table->text('content');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'page']);
        });

        $dims = (int) config('rag.embedding_dimensions', 768);
        DB::statement("ALTER TABLE chunks ADD COLUMN embedding vector({$dims})");
        // Generated full-text column for keyword (hybrid) search.
        DB::statement("ALTER TABLE chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(section, '') || ' ' || content)) STORED");
        DB::statement('CREATE INDEX chunks_embedding_hnsw ON chunks USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX chunks_search_gin ON chunks USING gin (search)');
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
