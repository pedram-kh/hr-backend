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
     * EXTENDED in 7b-2 (Q1) with `group_label`: because `convenio_job_categories`
     * is salary-derived and unseeded for the periodo convenios, `job_category_id`
     * is usually null, so the group ("Grupo 1/2/3") MUST be a first-class identity
     * discriminator or per-group facts collide on the key and the upsert clobbers
     * them. `group_label` carries that (common) case; `job_category_id` is used
     * too when a real category resolves.
     *
     * @var list<string>
     */
    public const LOGICAL_KEY = ['convenio_id', 'topic_id', 'job_category_id', 'group_label', 'validity_start', 'validity_end'];

    /**
     * Sprint 7d (ADR-0024) — the human's verdict on a flagged duplicate pair.
     * Validated at the FormRequest, NOT as a DB enum: an enum becomes a Postgres
     * CHECK and extending it later would need the introspect-drop-readd dance
     * 7b-2 had to perform for `status`.
     *
     * @var list<string>
     */
    public const RESOLUTIONS = ['supersedes', 'superseded', 'coexists', 'rejected_duplicate'];

    protected $fillable = [
        'uuid', 'convenio_id', 'job_category_id', 'group_label', 'topic_id',
        'value', 'raw_values', 'confidence', 'uncertainty', 'validity_start', 'validity_end',
        'authority_level', 'source', 'status', 'verified_by', 'verified_at',
        'source_document_id', 'source_locator', 'source_excerpt',
        'proposal_batch_id', 'duplicate_of_id', 'created_by',
        // Sprint 7d: the resolution verdict + version lineage. `superseded_by_id`
        // is the fact-level sibling of documents.predecessor_document_id.
        'resolution', 'superseded_by_id', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'raw_values' => 'array',
        'uncertainty' => 'array',
        'confidence' => 'float',
        'validity_start' => 'date',
        'validity_end' => 'date',
        'verified_at' => 'datetime',
        'resolved_at' => 'datetime',
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

    /**
     * The existing fact this AI proposal looks like an updated version of — set
     * ONLY as a flag when the logical key collides with a differing value across
     * source versions (Sprint 7b-2, Q4). A SIGNAL for the human, never a
     * resolution: nothing is merged or retired here (that is Sprint 7d).
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(ReferenceFact::class, 'duplicate_of_id');
    }

    /**
     * Version lineage (Sprint 7d): the NEWER fact whose `validity_start` closed
     * this one's window on a human-confirmed supersede. Set only by
     * FactResolutionService; the older fact is NEVER deleted and stays `verified`
     * for its own window, so a question dated inside that window still gets the
     * old value.
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(ReferenceFact::class, 'superseded_by_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by');
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
