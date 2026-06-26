<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Structured Reference Knowledge — one scoped fact (Sprint 7b-1, ADR-0021).
 *
 * Generalizes the salary pattern (data-model §6): scoped via the convenio
 * (territory/sector DERIVE — never stored on the row), source-linked for
 * traceability, `value` + `raw_values` verbatim, queried-not-embedded (ADR-0006:
 * NO chunks/embedding). Inert until verified (the 7a/ADR-0020 spine): a fact
 * lands `needs_review` and is not answerable until a human verifies (and since
 * 7c is not built, NO fact is answerable yet — correct for this slice).
 * Provenance is append-only in `tag_events` (entity_type = 'reference_fact').
 *
 * Authority is structurally bounded (INVARIANT 1): `authority_level` can hold
 * ONLY `structured_reference`, so a fact can never outrank a convenio.
 *
 * The `ai_agent` source lane is RESERVED but UNWRITTEN in 7b-1 — the manual path
 * is the only writer (it lights in 7b-2).
 *
 * Logical key (Q7), recorded for the 7b-2 AI upsert (NO hard unique here, a
 * manual create is a single deliberate action):
 *   (convenio_id, topic_id, job_category_id, validity_start, validity_end).
 */
class ReferenceFact extends Model
{
    /** The only authority level a reference fact may express (INVARIANT 1). */
    public const AUTHORITY_LEVEL = 'structured_reference';

    /**
     * The logical-key columns the 7b-2 AI writer upserts on (see class docblock).
     *
     * @var list<string>
     */
    public const LOGICAL_KEY = ['convenio_id', 'topic_id', 'job_category_id', 'validity_start', 'validity_end'];

    protected $fillable = [
        'uuid', 'convenio_id', 'job_category_id', 'topic_id',
        'value', 'raw_values', 'validity_start', 'validity_end',
        'authority_level', 'source', 'status', 'verified_by', 'verified_at',
        'source_document_id', 'source_locator', 'created_by',
    ];

    protected $casts = [
        'raw_values' => 'array',
        'validity_start' => 'date',
        'validity_end' => 'date',
        'verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ReferenceFact $fact) {
            if (empty($fact->uuid)) {
                $fact->uuid = (string) Str::uuid();
            }
        });
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function jobCategory(): BelongsTo
    {
        return $this->belongsTo(ConvenioJobCategory::class, 'job_category_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /** Derived scope — territory rides the convenio (never stored, data-model §5). */
    public function territory(): ?Territory
    {
        return $this->convenio?->territory;
    }

    /** Derived scope — sector rides the convenio (never stored, data-model §5). */
    public function sector(): ?Sector
    {
        return $this->convenio?->sector;
    }

    /** Inert-until-verified gate (ADR-0020): only a verified fact is answerable (7c). */
    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
