<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('chat_session_id')->nullable()->constrained('chat_sessions')->nullOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->enum('reason', ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict']);
            $table->enum('status', ['new', 'assigned', 'in_progress', 'resolved', 'closed'])->default('new');
            $table->foreignId('assigned_to')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_cards');
    }
};
