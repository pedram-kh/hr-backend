<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained('topics');
            $table->enum('source', ['filename_parse', 'ai_agent', 'admin_manual']);
            $table->decimal('confidence', 4, 3)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_topics');
    }
};
