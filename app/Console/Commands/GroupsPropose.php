<?php

namespace App\Console\Commands;

use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Services\ConvenioGroupProposalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 7f (ADR-0028) — run the group-structure proposer over one or more
 * convenios.
 *
 * Runs the service INLINE rather than dispatching the job, because the point of
 * a CLI backfill is to watch the cost and the tree land: this prints the
 * per-convenio token cost and elapsed time, which is what the sprint reports.
 * The queued `ProposeConvenioGroups` path exists for the admin UI action.
 *
 * Every node it writes is `ai_agent`/`needs_review` — inert. There is no
 * `--approve` option and there will not be one: approval is a human act in the
 * review surface, with the fact-binding diff in front of them.
 */
class GroupsPropose extends Command
{
    protected $signature = 'groups:propose
                            {--convenio=* : convenio id (repeatable); omit for every convenio with text}
                            {--dry-run : list what would be proposed for, call nothing}';

    protected $description = 'Propose convenio group structure with the AI (Sprint 7f) — inert needs_review nodes, never approved.';

    public function handle(ConvenioGroupProposalService $proposer): int
    {
        $ids = array_map('intval', (array) $this->option('convenio'));

        $query = Convenio::query()->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        $convenios = $query->get();

        if ($convenios->isEmpty()) {
            $this->error('No convenios matched.');

            return self::FAILURE;
        }

        if ($ids !== []) {
            $missing = array_diff($ids, $convenios->pluck('id')->all());
            if ($missing !== []) {
                $this->error('No convenio with id: '.implode(', ', $missing));

                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['id', 'convenio', 'pages', 'categories', 'approved nodes', 'pending nodes'],
                $convenios->map(fn (Convenio $c) => [
                    $c->id,
                    $c->name,
                    $this->pageCount($c),
                    $c->jobCategories()->count(),
                    ConvenioGroup::where('convenio_id', $c->id)->where('status', ConvenioGroup::STATUS_APPROVED)->count(),
                    ConvenioGroup::where('convenio_id', $c->id)->where('status', ConvenioGroup::STATUS_NEEDS_REVIEW)->count(),
                ])->all(),
            );

            return self::SUCCESS;
        }

        $totalCost = 0.0;
        $totalMs = 0;
        $rows = [];

        foreach ($convenios as $convenio) {
            $this->line("→ #{$convenio->id} {$convenio->name}");

            $started = microtime(true);
            $summary = $proposer->propose($convenio);
            $wallMs = (int) round((microtime(true) - $started) * 1000);

            $trace = $summary['trace_fragment'] ?? [];
            $cost = (float) ($trace['cost_usd'] ?? 0);
            $totalCost += $cost;
            $totalMs += $wallMs;

            if (($summary['status'] ?? '') !== 'ok') {
                $this->warn("   {$summary['status']}: ".($summary['reason'] ?? '?'));
                $rows[] = [$convenio->id, $convenio->name, $summary['status'], '-', '-', '-', '-', $wallMs.' ms', '-'];

                continue;
            }

            $this->info(sprintf(
                '   %d roots + %d sub-areas · %d created, %d updated · %d categories proposed · %s in · %s out · $%.4f · %d ms',
                $trace['group_count'] ?? 0,
                $trace['sub_area_count'] ?? 0,
                $summary['created'] ?? 0,
                $summary['updated'] ?? 0,
                $summary['categories_proposed'] ?? 0,
                $trace['prompt_tokens'] ?? '?',
                $trace['completion_tokens'] ?? '?',
                $cost,
                $wallMs,
            ));

            foreach (['skipped_locked', 'skipped_unnormalizable', 'dropped_foreign_categories'] as $k) {
                if (($summary[$k] ?? 0) > 0) {
                    $this->warn("   {$k}: {$summary[$k]}");
                }
            }
            foreach (['dropped_orphan_areas', 'dropped_unsupported_areas', 'dropped_category_ids', 'parse_error', 'text_truncated'] as $k) {
                if (! empty($trace[$k])) {
                    $this->warn("   hr-ai {$k}: ".(is_bool($trace[$k]) ? 'true' : $trace[$k]));
                }
            }
            if (! empty($trace['notes'])) {
                $this->line('   notes: '.$trace['notes']);
            }

            $rows[] = [
                $convenio->id,
                $convenio->name,
                'ok',
                ($trace['group_count'] ?? 0).' + '.($trace['sub_area_count'] ?? 0),
                $summary['created'] ?? 0,
                $summary['updated'] ?? 0,
                $summary['categories_proposed'] ?? 0,
                $wallMs.' ms',
                sprintf('$%.4f', $cost),
            ];
        }

        $this->newLine();
        $this->table(
            ['id', 'convenio', 'status', 'roots + areas', 'created', 'updated', 'cats', 'time', 'cost'],
            $rows,
        );
        $this->info(sprintf('TOTAL: %d convenios · %d ms · $%.4f', $convenios->count(), $totalMs, $totalCost));
        $this->comment('All nodes are needs_review. Nothing is comparable by the answer path until a human approves it.');

        return self::SUCCESS;
    }

    private function pageCount(Convenio $convenio): int
    {
        return DB::table('document_pages')
            ->join('documents', 'documents.id', '=', 'document_pages.document_id')
            ->where('documents.convenio_id', $convenio->id)
            ->count();
    }
}
