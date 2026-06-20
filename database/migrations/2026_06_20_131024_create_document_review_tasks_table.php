<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_review_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->enum('type', ['expiry', 'tag_review', 'conflict']);
            $table->enum('status', ['open', 'resolved', 'dismissed'])->default('open');
            $table->date('due_date')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_review_tasks');
    }
};
