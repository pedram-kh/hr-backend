<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only provenance log for every facet decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_events', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('facet');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->enum('source', ['filename_parse', 'ai_agent', 'admin_manual', 'system']);
            $table->foreignId('actor_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_events');
    }
};
