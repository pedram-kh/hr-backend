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
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('source_filename')->nullable();
            $table->string('storage_path');
            $table->foreignId('convenio_id')->nullable()->constrained('convenios')->nullOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types');
            $table->date('validity_start')->nullable();
            $table->date('validity_end')->nullable();
            $table->enum('retrieval_status', ['draft', 'active', 'historical'])->default('draft');
            $table->enum('authority_level', ['national_law', 'official_convenio', 'internal_hr_ruling']);
            $table->foreignId('predecessor_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('language');
            $table->enum('tagging_status', ['auto_proposed', 'under_review', 'verified'])->default('under_review');
            $table->decimal('tagging_confidence', 4, 3)->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->foreignId('ingested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
