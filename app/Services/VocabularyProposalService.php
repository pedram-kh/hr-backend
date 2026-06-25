<?php

namespace App\Services;

use App\Models\Convenio;
use App\Models\DocumentReviewTask;
use App\Models\Sector;
use App\Models\TagEvent;
use App\Models\Territory;
use App\Models\VocabularyProposal;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Managed vocabulary growth (Sprint 7a, ADR-0011/0020).
 *
 * propose() records that a value should exist (variant→alias suggested first, by
 * the deterministic VocabularyResolver-style similarity — no model dependency,
 * §7.5). approve() writes it into the controlled vocabulary (fold into aliases —
 * the strong default — or create a genuinely-new value — the deliberate action),
 * with provenance, and resolves the originating document's raw_unmatched_value.
 *
 * Authorization is enforced at the route/controller (propose: knowledge.edit;
 * approve: vocabulary.approve / super_admin). The AI can only ever PROPOSE; a
 * human always approves. The AI never creates vocabulary.
 */
class VocabularyProposalService
{
    /** Normalized-similarity threshold above which a value is offered as a variant. */
    public const VARIANT_THRESHOLD = 0.72;

    private const FACET_MODEL = [
        'territory' => Territory::class,
        'sector' => Sector::class,
        'convenio' => Convenio::class,
    ];

    /**
     * Record a proposal. Computes the deterministic variant suggestion so the UI
     * can default to "fold into alias" when something close exists.
     *
     * @param  array<string,mixed>  $opts  source_document_id, review_task_id, proposed_by_source, proposed_by_admin_id, note
     */
    public function propose(string $facet, string $value, array $opts = []): VocabularyProposal
    {
        $this->assertFacet($facet);
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('Proposed value cannot be empty.');
        }

        $variant = $this->suggestVariant($facet, $value);

        return VocabularyProposal::create([
            'facet' => $facet,
            'proposed_value' => $value,
            'variant_of_type' => $variant['type'] ?? null,
            'variant_of_id' => $variant['id'] ?? null,
            'variant_similarity' => $variant['similarity'] ?? null,
            'source_document_id' => $opts['source_document_id'] ?? null,
            'review_task_id' => $opts['review_task_id'] ?? null,
            'status' => 'proposed',
            'proposed_by_source' => $opts['proposed_by_source'] ?? 'admin_manual',
            'proposed_by_admin_id' => $opts['proposed_by_admin_id'] ?? null,
            'note' => $opts['note'] ?? null,
        ]);
    }

    /**
     * Approve a proposal into the controlled vocabulary. `resolution` ∈ alias |
     * new_value. For `alias`, `targetId` is the existing value to fold into
     * (defaults to the suggested variant). For `new_value`, a fresh vocabulary
     * row is created (sector/territory only; convenios are registry-owned —
     * ADR-0011). Writes provenance and resolves the originating doc's raw value.
     *
     * @param  array<string,mixed>  $opts  target_id, level (territory new_value), approver_id
     * @return array<string,mixed>
     */
    public function approve(VocabularyProposal $proposal, string $resolution, array $opts = []): array
    {
        if ($proposal->status !== 'proposed') {
            throw new RuntimeException("Proposal is already {$proposal->status}.");
        }
        if (! in_array($resolution, ['alias', 'new_value'], true)) {
            throw new RuntimeException("Unknown resolution '{$resolution}'.");
        }

        $approverId = $opts['approver_id'] ?? null;

        return DB::transaction(function () use ($proposal, $resolution, $opts, $approverId) {
            if ($resolution === 'alias') {
                $targetId = $opts['target_id'] ?? $proposal->variant_of_id;
                if ($targetId === null) {
                    throw new RuntimeException('An alias approval needs a target value to fold into.');
                }
                [$type, $row] = $this->foldAlias($proposal->facet, (int) $targetId, $proposal->proposed_value);
            } else {
                [$type, $row] = $this->createValue($proposal->facet, $proposal->proposed_value, $opts);
            }

            $proposal->update([
                'resolution' => $resolution,
                'status' => 'approved',
                'approved_by' => $approverId,
                'resolved_vocab_type' => $type,
                'resolved_vocab_id' => $row->id,
            ]);

            // Provenance: the vocabulary WRITE, attributed to the human approver.
            TagEvent::create([
                'entity_type' => 'vocabulary',
                'entity_id' => $row->id,
                'facet' => $proposal->facet,
                'old_value' => null,
                'new_value' => $resolution === 'alias'
                    ? "alias '{$proposal->proposed_value}' → {$row->name}"
                    : "new {$proposal->facet} '{$row->name}'",
                'source' => 'admin_manual',
                'actor_id' => $approverId,
                'confidence' => null,
                'note' => $resolution === 'alias'
                    ? 'vocabulary proposal approved (folded into aliases)'
                    : 'vocabulary proposal approved (new value created)',
            ]);

            // Resolve the originating document's raw_unmatched_value so the doc
            // becomes resolvable (a human still verifies it — invariant 2: we do
            // NOT auto-write the document's FK columns here).
            $this->resolveOriginatingTask($proposal);

            return [
                'status' => 'approved',
                'resolution' => $resolution,
                'vocab_type' => $type,
                'vocab_id' => $row->id,
                'vocab_name' => $row->name,
            ];
        });
    }

    public function reject(VocabularyProposal $proposal, ?int $approverId = null, ?string $note = null): void
    {
        if ($proposal->status !== 'proposed') {
            throw new RuntimeException("Proposal is already {$proposal->status}.");
        }
        $proposal->update([
            'status' => 'rejected',
            'approved_by' => $approverId,
            'note' => $note ?? $proposal->note,
        ]);
    }

    /**
     * Fold a spelling into an existing value's `aliases` (the safe default). Never
     * creates a row. Idempotent (no duplicate alias).
     *
     * @return array{0:string,1:\Illuminate\Database\Eloquent\Model}
     */
    private function foldAlias(string $facet, int $targetId, string $value): array
    {
        $model = self::FACET_MODEL[$facet];
        $row = $model::findOrFail($targetId);
        $aliases = array_values((array) ($row->aliases ?? []));
        $existsKey = TextNormalizer::key($value);
        $already = collect($aliases)->contains(fn ($a) => TextNormalizer::key((string) $a) === $existsKey)
            || TextNormalizer::key((string) $row->name) === $existsKey;
        if (! $already) {
            $aliases[] = $value;
            $row->aliases = $aliases;
            $row->save();
        }

        return [$facet, $row];
    }

    /**
     * Create a genuinely-new vocabulary value (the deliberate action). Sector and
     * territory only — convenios are created by the registry import (ADR-0011),
     * never the propose flow (alias folding for a convenio is still allowed).
     *
     * @param  array<string,mixed>  $opts
     * @return array{0:string,1:\Illuminate\Database\Eloquent\Model}
     */
    private function createValue(string $facet, string $value, array $opts): array
    {
        if ($facet === 'convenio') {
            throw new RuntimeException('Convenios are created by the registry import, not the propose-new-vocabulary flow (ADR-0011). Fold a spelling into an existing convenio, or import the convenio.');
        }

        if ($facet === 'sector') {
            return ['sector', Sector::create(['name' => $value, 'aliases' => []])];
        }

        // territory: a new territory needs a level. Default conservatively to
        // provincial; the approver may pass an explicit level. code stays null
        // (codeless scope) unless the registry assigns one later.
        $level = $opts['level'] ?? 'provincial';
        if (! in_array($level, ['national', 'regional', 'provincial'], true)) {
            $level = 'provincial';
        }

        return ['territory', Territory::create(['name' => $value, 'level' => $level, 'aliases' => [], 'code' => null])];
    }

    /**
     * Remove the now-resolved raw_unmatched_value from the originating review task
     * (matched by facet+value, case-insensitive). The task itself is left open —
     * a human still verifies the document (invariant 2: no FK auto-write).
     */
    private function resolveOriginatingTask(VocabularyProposal $proposal): void
    {
        if ($proposal->review_task_id === null) {
            return;
        }
        $task = DocumentReviewTask::find($proposal->review_task_id);
        if ($task === null) {
            return;
        }
        $key = strtolower($proposal->facet.'|'.trim($proposal->proposed_value));
        $remaining = collect((array) ($task->raw_unmatched_values ?? []))
            ->reject(fn ($rv) => strtolower(trim((string) ($rv['facet'] ?? '')).'|'.trim((string) ($rv['value'] ?? ''))) === $key)
            ->values()->all();
        $task->raw_unmatched_values = $remaining;
        $task->save();
    }

    /**
     * Deterministic variant suggestion (§7.5): the best normalized-similarity
     * match among the facet's existing names + aliases, above VARIANT_THRESHOLD.
     * No model dependency. Returns null when nothing is close (→ propose-new is
     * the deliberate default).
     *
     * @return array<string,mixed>|null
     */
    public function suggestVariant(string $facet, string $value): ?array
    {
        $this->assertFacet($facet);
        $model = self::FACET_MODEL[$facet];
        $needle = TextNormalizer::key($value);
        if ($needle === '') {
            return null;
        }

        $best = null;
        foreach ($model::all() as $row) {
            $candidates = array_merge([(string) $row->name], array_map('strval', (array) ($row->aliases ?? [])));
            foreach ($candidates as $cand) {
                $sim = $this->similarity($needle, TextNormalizer::key($cand));
                if ($sim >= self::VARIANT_THRESHOLD && ($best === null || $sim > $best['similarity'])) {
                    $best = ['type' => $facet, 'id' => $row->id, 'name' => $row->name, 'similarity' => round($sim, 3)];
                }
            }
        }

        return $best;
    }

    /** Normalized 0..1 similarity (similar_text percent), symmetric-ish. */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $pct = 0.0;
        similar_text($a, $b, $pct);

        return $pct / 100.0;
    }

    private function assertFacet(string $facet): void
    {
        if (! isset(self::FACET_MODEL[$facet])) {
            throw new RuntimeException("Unknown vocabulary facet '{$facet}'. Allowed: ".implode(', ', array_keys(self::FACET_MODEL)));
        }
    }
}
