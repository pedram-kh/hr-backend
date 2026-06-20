<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('escalation_cards')->cascadeOnDelete();
            $table->foreignId('resolved_by')->constrained('admins');
            $table->text('resolution_text');
            $table->foreignId('converted_to_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_resolutions');
    }
};
