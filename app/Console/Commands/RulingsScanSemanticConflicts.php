<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\SemanticRecheckService;
use Illuminate\Console\Command;

/**
 * The §8.5 reverse re-check BACKFILL (Sprint 7d, ADR-0024).
 *
 * `RecheckRulingsForConvenio` handles convenios that become active from now on;
 * this command covers the ones already in the corpus. Mirrors
 * `reviews:scan-expiry`'s shape: idempotent, reports what it would do, and NEVER
 * changes a document's status — it only writes `conflict` review-task flags.
 *
 * Selection: every active `official_convenio` whose convenio scope also holds at
 * least one active `internal_hr_ruling` (there is nothing to re-check otherwise,
 * and the narrow trigger keeps the embedding cost proportional).
 */
class RulingsScanSemanticConflicts extends Command
{
    protected $signature = 'rulings:scan-semantic-conflicts
                            {--convenio= : limit to one convenio numero}
                            {--dry-run : list the selection and the scores, write no flag}';

    protected $description = 'Flag published rulings that a now-active official convenio appears to have overtaken (flag only — never demotes, never retires).';

    public function handle(SemanticRecheckService $recheck): int
    {
        // The scopes worth checking: those holding BOTH an active convenio and at
        // least one active ruling.
        $rulingConvenioIds = Document::query()
            ->where('authority_level', 'internal_hr_ruling')
            ->where('retrieval_status', 'active')
            ->whereNotNull('convenio_id')
            ->distinct()->pluck('convenio_id');

        $convenios = Document::query()
            ->with('convenio')
            ->where('authority_level', 'official_convenio')
            ->where('retrieval_status', 'active')
            ->whereIn('convenio_id', $rulingConvenioIds)
            ->when($this->option('convenio'), fn ($q, $numero) => $q->whereHas('convenio', fn ($c) => $c->where('numero', $numero)))
            ->orderBy('id')
            ->get();

        if ($convenios->isEmpty()) {
            $this->info('No scope holds both an active official convenio and an active internal ruling — nothing to re-check.');

            return self::SUCCESS;
        }

        $flagged = 0;
        foreach ($convenios as $doc) {
            $this->line("  [{$doc->id}] {$doc->title} (convenio {$doc->convenio?->numero})");

            if ($this->option('dry-run')) {
                // Dry run still COMPARES (it is read-only anyway) so the operator can
                // see the scores before any flag is written.
                $comparison = app(\App\Services\SemanticFenceService::class)->compareConvenioToRulings($doc);
                $this->line('      max_score='.($comparison->maxScore ?? 'n/a')
                    .'  outcome='.$comparison->outcome
                    .'  reason='.$comparison->reason
                    .'  would_flag='.count(array_filter($comparison->matches, fn ($m) => ($m['score'] ?? 0) >= $comparison->reviewBand)));

                continue;
            }

            $summary = $recheck->recheck($doc);
            $flagged += (int) ($summary['flagged'] ?? 0);
            foreach ($summary['rulings'] ?? [] as $r) {
                $this->warn("      → flagged ruling [{$r['document_id']}] {$r['title']} (score {$r['max_score']})");
            }
            if (($summary['flagged'] ?? 0) === 0) {
                $this->line('      no ruling above the review band (reason: '.($summary['reason'] ?? '—').')');
            }
        }

        $this->newLine();
        $this->info("Reverse re-check complete: {$flagged} ruling flag(s) written across {$convenios->count()} convenio document(s). "
            .'No retrieval_status was changed and no document was retired.');

        return self::SUCCESS;
    }
}
