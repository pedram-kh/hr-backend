<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The external answer-model configuration (Sprint 2b-1, ADR-0015).
 *
 * Single-row table (id = 1). The API key is set ONCE via the admin "Answer model"
 * screen, ENCRYPTED at rest with Laravel Crypt (the APP_KEY — never committed),
 * shown MASKED (••••<last4>, reconstructed from key_last_four WITHOUT decrypting),
 * ROTATABLE, and NEVER read back by any endpoint. A running app cannot safely
 * rewrite its own .env, so the secret lives encrypted in the DB and is read
 * server-side, decrypted only in ChatService immediately before each synthesis
 * call (ADR-0015). The browser never sees the key and never calls the provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answer_model_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('claude');
            // Crypt::encryptString(...) ciphertext — never the plaintext key.
            $table->text('api_key_encrypted')->nullable();
            // Last 4 chars of the raw key, stored to render ••••1234 without ever
            // decrypting the ciphertext.
            $table->string('key_last_four', 4)->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_model_settings');
    }
};
