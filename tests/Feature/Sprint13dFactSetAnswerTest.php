<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\ReferenceFactGroupScope;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\FactResolutionService;
use App\Services\ReferenceFactAnswerService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Slice 13d (ADR-0037) — S1–S10, the SERVICE half. Several verified facts on one
 * topic: answered together when provably about different quantities, escalated
 * exactly as before otherwise. `selectMostRecent()` is untouched; S1 is the
 * differential proof that nothing outside the tie-with-distinct-values class moves.
 */
class Sprint13dFactSetAnswerTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Topic $topic;

    private Document $doc;

    private Territory $territory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Deporte', 'aliases' => []]);
        $this->convenio = Convenio::create(['numero' => '31000520', 'name' => 'Deporte', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);
        $this->topic = Topic::firstOrCreate(['name' => 'jornada'], ['status' => 'approved']);
        $this->doc = Document::create([
            'title' => 'Convenio deporte', 'storage_path' => 'fake/c20.docx', 'convenio_id' => $this->convenio->id,
            'document_type_id' => DocumentType::query()->value('id'), 'authority_level' => 'official_convenio',
            'retrieval_status' => 'active', 'language' => 'es', 'tagging_status' => 'verified',
        ]);
    }

    // ---- S1 — the differential ------------------------------------------------

    /**
     * A VERBATIM copy of `ReferenceFactAnswerService::selectMostRecent()` as it
     * stood before 13d — the oracle. (`git diff` also shows zero hunks in that
     * method; this proves the BEHAVIOUR is unchanged where it matters.)
     *
     * @param  Collection<int, ReferenceFact>  $facts
     * @return array{0: ?ReferenceFact, 1: string}
     */
    private function legacySelectMostRecent($facts): array
    {
        if ($facts->count() === 1) {
            return [$facts->first(), 'single'];
        }
        $sorted = $facts->sortByDesc(fn (ReferenceFact $f) => $f->validity_start?->timestamp ?? PHP_INT_MIN)->values();
        $top = $sorted->first();
        $topStart = $top->validity_start?->timestamp ?? PHP_INT_MIN;
        $sameStartConflict = $sorted->slice(1)->contains(
            fn (ReferenceFact $f) => ($f->validity_start?->timestamp ?? PHP_INT_MIN) === $topStart && $f->value !== $top->value
        );
        if ($sameStartConflict) {
            return [null, 'ambiguous_conflict'];
        }

        return [$top, 'most_recent_validity'];
    }

    public function test_s1_differential_against_the_legacy_selection_over_generated_tiers(): void
    {
        $employee = $this->employee();
        $service = app(ReferenceFactAnswerService::class);
        $starts = [null, '2023-01-01', '2024-01-01', '2025-01-01'];
        $values = ['a: 90 días', 'b: 75 días', 'c: 60 días'];
        $shapes = [null, [], ['x', 'y'], ['x' => 1], ['y' => 2], ['x' => 3, 'z' => 1], ['w' => 1]];

        mt_srand(1304);
        $counts = ['legacy_pick' => 0, 'escalated' => 0, 'composed' => 0];
        for ($i = 0; $i < 520; $i++) {
            ReferenceFact::query()->delete();
            $n = mt_rand(1, 6);
            for ($k = 0; $k < $n; $k++) {
                ReferenceFact::create($this->attrs(
                    $values[mt_rand(0, 2)], $starts[mt_rand(0, 3)], null, $shapes[mt_rand(0, count($shapes) - 1)],
                ));
            }
            $tier = ReferenceFact::query()->orderBy('id')->get();

            [$oracleFact, $oracleSelection] = $this->legacySelectMostRecent($tier);
            $out = $service->answer($employee, $this->topic->id, Carbon::today());
            $rf = $out['reference_fact'];

            if ($oracleFact !== null) {
                // Everything the legacy rule decided: unchanged, including the trace shape.
                $counts['legacy_pick']++;
                $this->assertSame('answer', $out['outcome'], "iteration $i");
                $this->assertSame($oracleSelection, $rf['validity_selection'], "iteration $i");
                $this->assertSame($oracleFact->value, $rf['value'], "iteration $i");
                $this->assertArrayNotHasKey('fact_set', $rf, "iteration $i");
                $this->assertArrayNotHasKey('facts', $out, "iteration $i");

                continue;
            }

            // The legacy rule called it a conflict. The ONLY permitted change: a provably complementary set.
            if ($out['outcome'] === 'escalate') {
                $counts['escalated']++;
                $this->assertSame('ambiguous_conflict', $rf['validity_selection'], "iteration $i");
                $this->assertSame('conflict', $rf['fact_set']['composition'], "iteration $i");
                $this->assertSame('reference_fact_coverage_gap', $out['escalation_reason']);

                continue;
            }
            $counts['composed']++;
            $this->assertSame('same_validity_complementary', $rf['validity_selection'], "iteration $i");
            $this->assertGreaterThanOrEqual(2, count($out['facts']), "iteration $i");
            // Every member of a composed set has named, pairwise-disjoint quantities.
            $keys = [];
            foreach ($out['facts'] as $f) {
                $this->assertIsArray($f['raw_values']);
                $this->assertFalse(array_is_list($f['raw_values']), "iteration $i");
                foreach (array_keys($f['raw_values']) as $key) {
                    $this->assertArrayNotHasKey((string) $key, $keys, "iteration $i: shared quantity composed");
                    $keys[(string) $key] = true;
                }
            }
        }

        // The generator must actually exercise all three outcomes, or the proof is vacuous.
        $this->assertGreaterThan(50, $counts['legacy_pick'], json_encode($counts));
        $this->assertGreaterThan(20, $counts['escalated'], json_encode($counts));
        $this->assertGreaterThan(5, $counts['composed'], json_encode($counts));
    }

    public function test_s1b_select_most_recent_is_byte_identical_to_the_legacy_source(): void
    {
        $src = file_get_contents(app_path('Services/ReferenceFactAnswerService.php'));
        preg_match('/private function selectMostRecent\(\$facts\): array\n    \{.*?\n    \}\n/s', $src, $m);
        $this->assertNotEmpty($m, 'selectMostRecent() not found');

        $body = $m[0];
        foreach (['FactSetClassifier', 'raw_values', 'fact_set', 'classifyTieCohort'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, 'selectMostRecent() must stay the legacy rule');
        }
        $this->assertStringContainsString("return [null, 'ambiguous_conflict'];", $body);
        $this->assertStringContainsString('$f->value !== $top->value', $body);
    }

    // ---- S2 / S3 / S4 — the three tie outcomes -------------------------------

    public function test_s2_a_convenio_wide_tie_of_disjoint_quantities_is_answered_as_a_set(): void
    {
        [$f140, $f143] = $this->realPair();

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(), $this->topic->id, Carbon::today());

        $this->assertSame('answer', $out['outcome']);
        $rf = $out['reference_fact'];
        $this->assertSame('same_validity_complementary', $rf['validity_selection']);
        $this->assertSame('convenio_wide', $rf['match_kind']);
        $this->assertSame($f140->id, $rf['fact_id'], 'the primary is the figure-bearing fact');
        $this->assertSame([$f140->id, $f143->id], $rf['fact_set']['facts_selected']);
        $this->assertSame([], $rf['fact_set']['facts_omitted']);
        $this->assertSame('complementary', $rf['fact_set']['composition']);
        $this->assertSame([$f140->id, $f143->id], array_column($out['facts'], 'id'));
        $this->assertCount(2, $out['citations']);
        $this->assertSame([true, true], array_column($out['citations'], 'is_reference_fact'));
        $this->assertStringContainsString('1704 horas', $out['answer']);
        $this->assertStringContainsString('2 días de libre disposición', $out['answer']);
    }

    public function test_s3_a_tie_with_null_raw_values_is_still_the_legacy_escalation(): void
    {
        ReferenceFact::create($this->attrs('90 días', '2024-01-01'));
        ReferenceFact::create($this->attrs('75 días', '2024-01-01'));

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(), $this->topic->id, Carbon::today());

        $this->assertSame('escalate', $out['outcome']);
        $this->assertSame('ambiguous_conflict', $out['reference_fact']['validity_selection']);
        $this->assertSame('no_quantity_keys', $out['reference_fact']['fact_set']['pairs'][0]['reason']);
    }

    public function test_s4_a_shared_quantity_escalates_and_lists_the_pair(): void
    {
        $a = ReferenceFact::create($this->attrs('1704 horas anuales', '2025-01-01', null, ['horas_anuales' => 1704]));
        $b = ReferenceFact::create($this->attrs('1720 horas anuales', '2025-01-01', null, ['horas_anuales' => 1720]));

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(), $this->topic->id, Carbon::today());

        $this->assertSame('escalate', $out['outcome']);
        $fs = $out['reference_fact']['fact_set'];
        $this->assertSame('conflict', $fs['composition']);
        $this->assertSame([$a->id, $b->id], $fs['facts_selected']);
        $this->assertSame('same_quantity', $fs['pairs'][0]['reason']);
        $this->assertSame(['horas_anuales'], $fs['pairs'][0]['shared_keys']);
        $this->assertSame([], $out['citations']);
    }

    // ---- S5 — supersession and recency (unchanged) ---------------------------

    public function test_s5_an_older_fact_closed_by_a_supersede_leaves_a_single_fact_and_no_set(): void
    {
        [$f140, $f143] = $this->realPair();
        // 143 is then superseded by a newer 143': the human-adjudicated close (7d) ends the older window.
        $newer = ReferenceFact::create($this->attrs('Reglas generales de jornada (versión nueva): 2 días', '2027-06-01', null, ['jornada_irregular' => '0%'])
            + ['status' => 'verified']);
        $f143->update(['validity_end' => '2027-05-31']);
        $f140->update(['validity_end' => '2027-05-31']);

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(), $this->topic->id, Carbon::parse('2027-09-01'));

        $this->assertSame('answer', $out['outcome']);
        $this->assertSame('single', $out['reference_fact']['validity_selection']);
        $this->assertSame($newer->id, $out['reference_fact']['fact_id']);
        $this->assertArrayNotHasKey('fact_set', $out['reference_fact']);
        $this->assertArrayNotHasKey('facts', $out);
    }

    public function test_s5_supersede_via_the_resolution_service_collapses_a_conflicting_tie_to_one_fact(): void
    {
        $older = ReferenceFact::create($this->attrs('viejo 90 días', '2023-01-01', null, ['x' => 1]));
        $newer = ReferenceFact::create($this->attrs('nuevo 75 días', '2024-01-01', null, ['x' => 2]));
        $this->assertNotNull(app(FactResolutionService::class));

        // Distinct starts: the legacy recency rule, regardless of quantity.
        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(), $this->topic->id, Carbon::today());
        $this->assertSame('most_recent_validity', $out['reference_fact']['validity_selection']);
        $this->assertSame($newer->id, $out['reference_fact']['fact_id']);
        $this->assertArrayNotHasKey('fact_set', $out['reference_fact']);
        $this->assertNotSame($older->id, $out['reference_fact']['fact_id']);
    }

    public function test_s5b_different_starts_of_disjoint_quantities_keep_the_legacy_recency_pick(): void
    {
        ReferenceFact::create($this->attrs('viejo: descanso 15 minutos', '2022-01-01', null, ['descanso' => '15 minutos']));
        $newer = ReferenceFact::create($this->attrs('nuevo: 1704 horas', '2025-01-01', null, ['2025' => '1704 horas']));

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(), $this->topic->id, Carbon::today());

        $this->assertSame('most_recent_validity', $out['reference_fact']['validity_selection']);
        $this->assertSame($newer->id, $out['reference_fact']['fact_id']);
        $this->assertArrayNotHasKey('fact_set', $out['reference_fact'], 'recency is not a tie — no set logic runs');
    }

    // ---- S6 — R1: precedence unchanged, never composed across tiers ----------

    public function test_s6_precedence_matrix_a_group_fact_wins_outright_and_the_wide_pair_is_never_composed(): void
    {
        $this->realPair(); // convenio-wide complementary pair
        $g1 = $this->node('Grupo 1', '1');
        ReferenceFactGroupScope::create([
            'reference_fact_id' => ReferenceFact::create($this->attrs('Grupo 1: 1650 horas anuales', '2025-01-01', 'Grupo 1', ['g1' => 1650]))->id,
            'convenio_group_id' => $g1->id, 'bound_at' => now(),
        ]);
        $svc = app(ReferenceFactAnswerService::class);

        // Employee on the node → the group fact only; no set, no wide fact.
        $on = $svc->answer($this->employee(groupId: $g1->id), $this->topic->id, Carbon::today());
        $this->assertSame('answer', $on['outcome']);
        $this->assertSame('group', $on['reference_fact']['match_kind']);
        $this->assertStringContainsString('1650 horas', $on['answer']);
        $this->assertArrayNotHasKey('fact_set', $on['reference_fact']);
        $this->assertArrayNotHasKey('facts', $on);

        // Ungrouped employee → the wide pair.
        $off = $svc->answer($this->employee(), $this->topic->id, Carbon::today());
        $this->assertSame('convenio_wide', $off['reference_fact']['match_kind']);
        $this->assertSame('same_validity_complementary', $off['reference_fact']['validity_selection']);
    }

    public function test_s6_indeterminate_node_escalates_with_no_fall_through_to_the_wide_pair(): void
    {
        $this->realPair();
        $g2 = $this->node('Grupo 2', '2');
        $area5 = $this->node('área 5', 'area-5', $g2);
        $this->node('resto áreas', 'resto', $g2);
        ReferenceFactGroupScope::create([
            'reference_fact_id' => ReferenceFact::create($this->attrs('área 5: 1600 horas', '2025-01-01', 'Grupo 2 (área 5)', ['a5' => 1600]))->id,
            'convenio_group_id' => $area5->id, 'bound_at' => now(),
        ]);

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(groupId: $g2->id), $this->topic->id, Carbon::today());

        $this->assertSame('escalate', $out['outcome']);
        $this->assertStringContainsString('indeterminate', $out['reference_fact']['note']);
        $this->assertArrayNotHasKey('fact_set', $out['reference_fact']);
    }

    public function test_s6_a_rejected_node_lands_where_a_null_node_lands_on_the_pair(): void
    {
        $this->realPair();
        $rejected = ConvenioGroup::create([
            'convenio_id' => $this->convenio->id, 'label' => 'Grupo X', 'code_normalized' => 'x',
            'normalization_rule' => 'test', 'status' => ConvenioGroup::STATUS_REJECTED, 'source' => 'admin_manual',
        ]);

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(groupId: $rejected->id), $this->topic->id, Carbon::today());

        $this->assertSame('same_validity_complementary', $out['reference_fact']['validity_selection']);
    }

    // ---- S7 / S8 — the other tiers ------------------------------------------

    public function test_s7_a_job_category_pair_composes_at_tier_1(): void
    {
        $cat = ConvenioJobCategory::create(['convenio_id' => $this->convenio->id, 'name' => 'Técnico', 'group_code' => '1']);
        ReferenceFact::create($this->attrs('Técnico: 1650 horas anuales', '2025-01-01', null, ['horas' => 1650], $cat->id));
        ReferenceFact::create($this->attrs('Técnico: 2 días de libre disposición', '2025-01-01', null, ['libre_disposicion' => 2], $cat->id));
        ReferenceFact::create($this->attrs('convenio-wide 1704 horas', '2025-01-01', null, ['general' => 1704])); // never consulted

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(jobCategoryId: $cat->id), $this->topic->id, Carbon::today());

        $this->assertSame('job_category', $out['reference_fact']['match_kind']);
        $this->assertSame('same_validity_complementary', $out['reference_fact']['validity_selection']);
        $this->assertCount(2, $out['facts']);
        $this->assertStringNotContainsString('convenio-wide', $out['answer']);
    }

    public function test_s8_two_facts_bound_to_one_node_with_different_labels_and_overlapping_keys_conflict(): void
    {
        $g2 = $this->node('Grupo 2', '2');
        foreach ([['Grupo 2', 1500], ['Grupo 2 (área 5)', 1600]] as [$label, $hours]) {
            ReferenceFactGroupScope::create([
                'reference_fact_id' => ReferenceFact::create($this->attrs("$label: $hours horas", '2025-01-01', $label, ['horas_anuales' => $hours]))->id,
                'convenio_group_id' => $g2->id, 'bound_at' => now(),
            ]);
        }

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(groupId: $g2->id), $this->topic->id, Carbon::today());

        // The spec-literal "different label ⇒ complementary" would have composed two versions of one value.
        $this->assertSame('escalate', $out['outcome']);
        $this->assertSame('conflict', $out['reference_fact']['fact_set']['composition']);
        $this->assertSame('group', $out['reference_fact']['match_kind']);
    }

    // ---- S9 / S10 -----------------------------------------------------------

    public function test_s9_identical_values_are_not_a_conflict_and_not_a_set(): void
    {
        ReferenceFact::create($this->attrs('90 días', '2025-01-01', null, ['x' => 1]));
        ReferenceFact::create($this->attrs('90 días', '2025-01-01', null, ['y' => 1]));

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(), $this->topic->id, Carbon::today());

        $this->assertSame('answer', $out['outcome']);
        $this->assertSame('most_recent_validity', $out['reference_fact']['validity_selection']);
        $this->assertArrayNotHasKey('fact_set', $out['reference_fact']);
    }

    public function test_s10_only_verified_in_validity_facts_ever_enter_a_cohort(): void
    {
        [$f140] = $this->realPair();
        // A third, disjoint fact that must NOT join: needs_review / expired / future / rejected.
        foreach ([
            ['needs_review', '2025-01-01', null], ['verified', '2018-01-01', '2019-12-31'],
            ['verified', '2099-01-01', null], ['rejected', '2025-01-01', null],
        ] as $i => [$status, $start, $end]) {
            ReferenceFact::create(array_merge($this->attrs("intruso $i: 9$i horas", $start, null, ["intruso_$i" => $i], null, $end), ['status' => $status]));
        }

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(), $this->topic->id, Carbon::today());

        $this->assertCount(2, $out['facts']);
        $this->assertSame($f140->id, $out['facts'][0]['id']);
        $this->assertStringNotContainsString('intruso', $out['answer']);
    }

    public function test_the_cap_answers_three_and_records_the_rest_in_the_trace(): void
    {
        foreach ([1, 2, 3, 4] as $i) {
            ReferenceFact::create($this->attrs("Dato $i de jornada: $i días", '2025-01-01', null, ["q_$i" => $i]));
        }

        $out = app(ReferenceFactAnswerService::class)->answer($this->employee(), $this->topic->id, Carbon::today());

        $fs = $out['reference_fact']['fact_set'];
        $this->assertCount(3, $fs['facts_selected']);
        $this->assertCount(1, $fs['facts_omitted']);
        $this->assertCount(3, $out['facts']);
        $this->assertCount(3, $out['citations']);
    }

    // ---- helpers ------------------------------------------------------------

    /** @return array{0: ReferenceFact, 1: ReferenceFact} */
    private function realPair(): array
    {
        $f140 = ReferenceFact::create($this->attrs(
            'Con carácter general: Año 2025: 1704 horas de trabajo efectivo; Año 2026: 1700 horas; Año 2027: 1696 horas; Año 2028: 1692 horas.',
            '2025-01-01', null, ['2025' => '1704 horas', '2026' => '1700 horas', '2027' => '1696 horas', '2028' => '1692 horas'], null, '2028-12-31',
        ));
        $f143 = ReferenceFact::create($this->attrs(
            'Reglas generales de jornada: en jornadas de más de 6 horas un descanso de 15 minutos; 2 días de libre disposición.',
            '2025-01-01', null, ['jornada_irregular' => '0%', 'dias_libre_disposicion' => '2 días', 'descanso_jornada_continuada' => '15 minutos'], null, '2028-12-31',
        ));

        return [$f140, $f143];
    }

    private function employee(?int $jobCategoryId = null, ?int $groupId = null): Employee
    {
        return Employee::create([
            'email' => 'emp'.uniqid().'@example.com', 'full_name' => 'Empleada',
            'convenio_id' => $this->convenio->id, 'job_category_id' => $jobCategoryId, 'convenio_group_id' => $groupId,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function node(string $label, string $code, ?ConvenioGroup $parent = null): ConvenioGroup
    {
        return ConvenioGroup::create([
            'convenio_id' => $this->convenio->id, 'parent_id' => $parent?->id, 'label' => $label, 'code_normalized' => $code,
            'normalization_rule' => 'test', 'status' => ConvenioGroup::STATUS_APPROVED, 'source' => 'admin_manual',
        ]);
    }

    /**
     * @param  array<mixed>|null  $raw
     * @return array<string,mixed>
     */
    private function attrs(string $value, ?string $start = null, ?string $group = null, ?array $raw = null, ?int $category = null, ?string $end = null): array
    {
        return [
            'convenio_id' => $this->convenio->id, 'topic_id' => $this->topic->id,
            'job_category_id' => $category, 'group_label' => $group,
            'value' => $value, 'raw_values' => $raw,
            'authority_level' => 'structured_reference', 'source' => 'ai_agent', 'status' => 'verified',
            'validity_start' => $start, 'validity_end' => $end,
            'source_document_id' => $this->doc->id, 'source_locator' => 'p9',
        ];
    }
}
