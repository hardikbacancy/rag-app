<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            // Which model produced the vector: vectors from different models must never be compared.
            $table->string('embedding_model')->nullable()->index();
            // sha1 of the exact text that was embedded; lets re-ingestion reuse vectors instead of paying for them again.
            $table->string('content_hash', 40)->nullable()->index();
        });

        // Rows that already exist were embedded with the model configured at the time.
        DB::table('chunks')->whereNull('embedding_model')->update(['embedding_model' => config('rag.gemini.embedding_model')]);
    }

    public function down(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            $table->dropColumn(['embedding_model', 'content_hash']);
        });
    }
};
