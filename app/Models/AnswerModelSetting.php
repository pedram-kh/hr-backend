<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Single-row external answer-model config (ADR-0015).
 *
 * The raw key is NEVER an attribute, NEVER serialized, NEVER returned by any
 * endpoint. It is stored encrypted (`api_key_encrypted`) and decrypted only on
 * demand, server-side, immediately before a synthesis call. `key_last_four`
 * lets the UI render ••••1234 without ever decrypting.
 */
class AnswerModelSetting extends Model
{
    protected $fillable = [
        'provider', 'api_key_encrypted', 'key_last_four', 'configured_at', 'updated_by',
    ];

    protected $casts = [
        'configured_at' => 'datetime',
    ];

    /**
     * Never let the ciphertext or its derived fields leak through JSON.
     *
     * @var list<string>
     */
    protected $hidden = ['api_key_encrypted'];

    /** The single settings row (id = 1), created on first access. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['provider' => config('services.hr_ai.answer_provider', 'claude')]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->api_key_encrypted);
    }

    /** The masked display value, reconstructed WITHOUT decrypting. */
    public function maskedKey(): ?string
    {
        if (! $this->isConfigured() || empty($this->key_last_four)) {
            return null;
        }

        return '••••'.$this->key_last_four;
    }

    /**
     * Encrypt and store a fresh key (set or rotate). Records the last four chars
     * for masking. The plaintext is discarded after encryption — never stored.
     */
    public function setKey(string $plaintextKey, ?int $adminId = null): void
    {
        $this->api_key_encrypted = Crypt::encryptString($plaintextKey);
        $this->key_last_four = substr($plaintextKey, -4);
        $this->configured_at = now();
        $this->updated_by = $adminId;
        $this->save();
    }

    /**
     * Decrypt the key for ONE server-side use (a synthesis call). The return
     * value must not be persisted, logged, or bound beyond the immediate call.
     */
    public function decryptKey(): string
    {
        return Crypt::decryptString($this->api_key_encrypted);
    }

    /** Clear the key (de-configure). */
    public function clearKey(?int $adminId = null): void
    {
        $this->api_key_encrypted = null;
        $this->key_last_four = null;
        $this->configured_at = null;
        $this->updated_by = $adminId;
        $this->save();
    }
}
