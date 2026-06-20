<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * document_chunks is the pgvector table. It is owned (migrated) by hr-backend,
 * but read & written at runtime by hr-ai only. EMBED_DIM = 1024 (BGE-M3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->integer('chunk_index');
            $table->integer('page_from')->nullable();
            $table->integer('page_to')->nullable();
            $table->text('content');
            $table->integer('token_count');
            // embedding vector(1024) added via raw DDL below (no native Blueprint type).

            // Denormalized scope columns for pre-filtering before similarity ranking.
            $table->unsignedBigInteger('convenio_id')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->unsignedBigInteger('sector_id')->nullable();
            $table->date('validity_start')->nullable();
            $table->date('validity_end')->nullable();
            $table->enum('retrieval_status', ['draft', 'active', 'historical'])->default('draft');
            $table->enum('authority_level', ['national_law', 'official_convenio', 'internal_hr_ruling'])->nullable();
            $table->timestamps();

            // btree indexes on the denormalized scope filters.
            $table->index('convenio_id');
            $table->index('province_id');
            $table->index('sector_id');
            $table->index('retrieval_status');
            $table->index('authority_level');
        });

        // vector(1024) column + HNSW ANN index (valid on an empty table).
        // Created here so all DDL stays in hr-backend; hr-ai never migrates.
        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(1024)');
        DB::statement('CREATE INDEX document_chunks_embedding_hnsw ON document_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
