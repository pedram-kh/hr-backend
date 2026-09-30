<?php

namespace App\Console\Commands;

use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ReferenceFact;
use App\Models\ReferenceFactGroupScope;
use App\Models\Topic;
use App\Support\FactSetClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Slice 13d (ADR-0037) — READ-ONLY audit of every (convenio, topic, scope) that holds
 * more than one verified, in-validity fact: what will the answer route DO with it?
 *
 * Uses {@see FactSetClassifier} (the same class the answer service uses), so the
 * audit reports what the route will do and the two cannot drift. Writes NOTHING —
 * a test asserts the command issues only SELECT statements.
 *
 * Buckets follow the answer tiers (`ReferenceFactAnswerService`): the convenio-wide
 * tier (null category AND null label), each job category, and each APPROVED group
 * node a fact is bound to. Group-labelled facts bound to no approved node are
 * Tier-4 material that no employee can be answered from; they are counted
 * (`unbound_group_labelled_excluded`), not classified.
 *
 * Classes per bucket with >= 2 candidates:
 *   complementary   same top validity_start, differing values, provably different quantities → answered as a set
 *   contradictory   same top validity_start, differing values, NOT provably different → escalates (as before)
 *   identical_value same top validity_start, byte-identical values (legacy-fine duplicates)
 *   recency_shadowed  one top fact; older still-valid facts are silently dropped by the recency rule (informational)
 *
 * OPERATIONAL RULE (deploy.md go-live checklist, ADR-0037): the `complementary` rows are a
 * human-reviewed item — after every ingestion AND every triage batch, because a human
 * edit can create a colliding pair that the writer's own upsert guard cannot prevent
 * (facts 140/143 on staging arose exactly that way).
 */
class FactsSameTopicAudit extends Command
{
    protected $signature = 'facts:same-topic-audit
                            {--convenio= : limit to one convenio numero}
                            {--as-of= : validity date (Y-m-d), default today}
                            {--json : machine-readable output}';

    protected $description = 'Read-only: classify every (convenio, topic, scope) with >1 verified in-validity fact as complementary / contradictory / identical_value / recency_shadowed.';

    public function handle(): int
    {
        $asOf = $this->option('as-of') ? Carbon::parse((string) $this->option('as-of')) : Carbon::today();
        $convenioId = null;
        if ($numero = $this->option('convenio')) {
            $convenioId = Convenio::where('numero', $numero)->value('id');
            if ($convenioId === null) {
                $this->error("No convenio with numero {$numero}.");

                return self::FAILURE;
            }
        }

        $report = $this->audit($asOf, $convenioId);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $s = $report['summary'];
        if ($report['cohorts'] !== []) {
            $this->table(
                ['convenio', 'topic', 'tier', 'top start', 'fact ids', 'class', 'reason', 'shared keys', 'over cap', 'dup flagged'],
                array_map(fn (array $r) => [
                    $r['convenio'], $r['topic'], $r['tier'], $r['top_validity_start'] ?? '-', implode(',', $r['fact_ids']),
                    $r['class'], $r['reason'] ?? '-', implode(',', $r['shared_keys']) ?: '-',
                    $r['over_cap'] ? 'yes' : 'no', $r['dup_flagged'] ? 'yes' : 'no',
                ], $report['cohorts']),
            );
        }
        $c = $s['cohorts'];
        $this->line("{$s['convenio_topic_pairs']} convenio×topic pairs with a verified in-validity fact; {$s['verified_in_validity_facts']} verified in-validity facts; "
            ."{$s['convenio_wide_facts']} convenio-wide; cohorts: complementary={$c['complementary']}, contradictory={$c['contradictory']}, "
            ."recency_shadowed={$c['recency_shadowed']}, identical_value={$c['identical_value']}; over_cap={$s['over_cap']}; "
            ."unbound_group_labelled_excluded={$s['unbound_group_labelled_excluded']}  (as of {$report['as_of']})");
        if ($c['contradictory'] > 0) {
            $this->warn('contradictory rows escalate today. dup_flagged=no means "Resolver versión" cannot reach the pair (it needs a duplicate_of_id link).');
        }
        if ($c['complementary'] > 0) {
            $this->info('complementary rows are ANSWERED TOGETHER: a human should read each pair (are the two key sets really different quantities?).');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{as_of:string, summary:array<string,mixed>, cohorts:list<array<string,mixed>>}
     */
    public function audit(Carbon $asOf, ?int $convenioId): array
    {
        $date = $asOf->toDateString();
        $facts = ReferenceFact::query()
            ->where('status', 'verified')
            ->when($convenioId !== null, fn ($q) => $q->where('convenio_id', $convenioId))
            ->where(fn ($q) => $q->whereNull('validity_start')->orWhere('validity_start', '<=', $date))
            ->where(fn ($q) => $q->whereNull('validity_end')->orWhere('validity_end', '>=', $date))
            ->orderBy('id')
            ->get();

        $convenios = Convenio::whereIn('id', $facts->pluck('convenio_id')->unique())->pluck('numero', 'id');
        $topics = Topic::whereIn('id', $facts->pluck('topic_id')->unique())->pluck('name', 'id');

        $bindings = ReferenceFactGroupScope::query()->whereIn('reference_fact_id', $facts->pluck('id'))->get()->groupBy('reference_fact_id');
        $nodes = ConvenioGroup::whereIn('id', $bindings->flatten()->pluck('convenio_group_id')->unique())
            ->where('status', ConvenioGroup::STATUS_APPROVED)->get()->keyBy('id');

        $cohorts = [];
        $unbound = 0;
        $over = 0;

        foreach ($facts->groupBy(fn (ReferenceFact $f) => $f->convenio_id.':'.$f->topic_id) as $group) {
            /** @var Collection<int, ReferenceFact> $group */
            $buckets = [];
            foreach ($group as $f) {
                $isWide = $f->job_category_id === null && $f->group_label === null;
                if ($isWide) {
                    $buckets['convenio_wide'][] = $f;
                }
                if ($f->job_category_id !== null) {
                    $buckets['job_category:'.$f->job_category_id][] = $f;
                }
                $boundNodes = ($bindings[$f->id] ?? collect())->map(fn ($b) => $nodes->get($b->convenio_group_id))->filter();
                foreach ($boundNodes as $node) {
                    $buckets['group:'.$node->id.' '.$node->label][] = $f;
                }
                if (! $isWide && $f->job_category_id === null && $boundNodes->isEmpty()) {
                    $unbound++; // group-labelled, bound to no approved node: no employee can be answered from it
                }
            }

            foreach ($buckets as $tier => $members) {
                if (count($members) < 2) {
                    continue;
                }
                $row = $this->classifyBucket($members);
                $row = ['convenio' => (string) ($convenios[$group->first()->convenio_id] ?? $group->first()->convenio_id),
                    'topic' => (string) ($topics[$group->first()->topic_id] ?? $group->first()->topic_id),
                    'tier' => $tier] + $row;
                // A stable, documented column order for the table and the JSON.
                $row = array_replace(array_flip(['convenio', 'topic', 'tier', 'top_validity_start', 'fact_ids', 'class', 'reason', 'shared_keys', 'over_cap', 'dup_flagged', 'shadowed_fact_ids']), $row);
                $over += $row['over_cap'] ? 1 : 0;
                $cohorts[] = $row;
            }
        }

        $count = fn (string $class) => count(array_filter($cohorts, fn (array $r) => $r['class'] === $class));

        return [
            'as_of' => $date,
            'summary' => [
                'convenio_topic_pairs' => $facts->groupBy(fn (ReferenceFact $f) => $f->convenio_id.':'.$f->topic_id)->count(),
                'verified_in_validity_facts' => $facts->count(),
                'convenio_wide_facts' => $facts->filter(fn (ReferenceFact $f) => $f->job_category_id === null && $f->group_label === null)->count(),
                'cohorts' => [
                    'complementary' => $count('complementary'),
                    'contradictory' => $count('contradictory'),
                    'recency_shadowed' => $count('recency_shadowed'),
                    'identical_value' => $count('identical_value'),
                ],
                'over_cap' => $over,
                'unbound_group_labelled_excluded' => $unbound,
            ],
            'cohorts' => $cohorts,
        ];
    }

    /**
     * Same reading of a tier as `ReferenceFactAnswerService`: the most-recent
     * `validity_start` cohort decides; older still-valid facts are dropped by recency.
     *
     * @param  list<ReferenceFact>  $members
     * @return array<string,mixed>
     */
    private function classifyBucket(array $members): array
    {
        $startOf = fn (ReferenceFact $f) => $f->validity_start?->timestamp ?? PHP_INT_MIN;
        $topStart = max(array_map($startOf, $members));
        $top = array_values(array_filter($members, fn (ReferenceFact $f) => $startOf($f) === $topStart));
        $older = array_values(array_filter($members, fn (ReferenceFact $f) => $startOf($f) !== $topStart));
        $distinct = FactSetClassifier::distinctByValue($top);

        $base = [
            'top_validity_start' => $top[0]->validity_start?->toDateString(),
            'fact_ids' => array_map(fn (ReferenceFact $f) => (int) $f->id, $members),
            'reason' => null, 'shared_keys' => [], 'over_cap' => false,
            'dup_flagged' => $this->anyDuplicateLink($members),
            'shadowed_fact_ids' => array_map(fn (ReferenceFact $f) => (int) $f->id, $older),
        ];

        if (count($distinct) >= 2) {
            $verdict = FactSetClassifier::classifySet($distinct);
            $bad = array_values(array_filter($verdict['pairs'], fn (array $p) => $p['relation'] === FactSetClassifier::CONTRADICTORY));
            $complementary = $verdict['composition'] === FactSetClassifier::SET_COMPLEMENTARY;

            return ['class' => $complementary ? 'complementary' : 'contradictory']
                + ['reason' => $complementary ? 'disjoint_quantity_keys' : $bad[0]['reason'],
                    'shared_keys' => $complementary ? [] : array_values(array_unique(array_merge(...array_map(fn ($p) => $p['shared_keys'], $bad)))),
                    'over_cap' => $complementary && count($distinct) > FactSetClassifier::MAX_SET,
                    'fact_ids' => array_map(fn (ReferenceFact $f) => (int) $f->id, $members)]
                + $base;
        }

        // One distinct value at the top. Older valid facts → the recency rule silently drops them.
        if ($older !== []) {
            return ['class' => 'recency_shadowed'] + $base;
        }

        return ['class' => 'identical_value'] + $base;
    }

    /** @param list<ReferenceFact> $members */
    private function anyDuplicateLink(array $members): bool
    {
        $ids = array_map(fn (ReferenceFact $f) => (int) $f->id, $members);
        foreach ($members as $f) {
            if ($f->duplicate_of_id !== null && in_array((int) $f->duplicate_of_id, $ids, true)) {
                return true;
            }
        }

        return false;
    }
}
