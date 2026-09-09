<?php

namespace App\Console\Commands;

use App\Models\ConvenioGroup;
use Illuminate\Console\Command;

/**
 * Sprint 7f (ADR-0028) — dump the proposed group trees as the JSON the eval
 * scorer reads (`hr-docs/sprints/sprint-07f/eval/score_trees.py`).
 *
 * Separate from `groups:propose` so the eval can be re-scored without spending
 * another LLM call, and so scoring never has a side effect on what it measures.
 */
class GroupsExportTrees extends Command
{
    protected $signature = 'groups:export-trees
                            {--convenio=* : convenio id (repeatable); omit for all with a tree}';

    protected $description = 'Export proposed convenio group trees as eval JSON (Sprint 7f).';

    public function handle(): int
    {
        $ids = array_map('intval', (array) $this->option('convenio'));

        $query = ConvenioGroup::query()->with('jobCategories:id')->orderBy('convenio_id')->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('convenio_id', $ids);
        }

        $byConvenio = $query->get()->groupBy('convenio_id');
        $codeById = $byConvenio->flatten()->pluck('code_normalized', 'id');

        $out = $byConvenio->map(fn ($nodes, $convenioId) => [
            'convenio_id' => (int) $convenioId,
            'groups' => $nodes->map(fn (ConvenioGroup $n) => [
                'code_normalized' => $n->code_normalized,
                'label' => $n->label,
                // The scorer joins children to parents by CODE, not id, so the
                // export stays readable and diffable across runs.
                'parent_code' => $n->parent_id !== null ? ($codeById[$n->parent_id] ?? null) : null,
                'status' => $n->status,
                'source' => $n->source,
                'has_excerpt' => trim((string) $n->source_excerpt) !== '',
                'category_ids' => $n->jobCategories->pluck('id')->values()->all(),
            ])->values()->all(),
        ])->values();

        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
