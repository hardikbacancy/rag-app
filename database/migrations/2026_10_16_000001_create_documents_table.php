<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('original_filename');
            $table->string('path');                         // path on the "local" (private) disk
            $table->string('strategy')->default('baseline'); // baseline | hierarchical | multimodal
            $table->string('status')->default('pending');    // pending | processing | ready | failed
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('status_message')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
