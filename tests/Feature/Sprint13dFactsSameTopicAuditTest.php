<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ReferenceFact;
use App\Models\ReferenceFactGroupScope;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Slice 13d (ADR-0037) — A1–A4: `facts:same-topic-audit`, the read-only report of
 * what the answer route will do with every (convenio, topic, scope) holding more
 * than one verified in-validity fact. Shares `FactSetClassifier` with the route.
 */
class Sprint13dFactsSameTopicAuditTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Deporte', 'aliases' => []]);
    }

    // ---- A1 — every class -----------------------------------------------------

    public function test_a1_every_class_is_reported_with_its_reason(): void
    {
        $c = $this->convenio('20');
        $doc = $this->doc($c);
        $jornada = Topic::firstOrCreate(['name' => 'jornada'], ['status' => 'approved']);
        $vacaciones = Topic::firstOrCreate(['name' => 'vacaciones'], ['status' => 'approved']);
        $permisos = Topic::firstOrCreate(['name' => 'permisos retribuidos'], ['status' => 'approved']);
        $excedencia = Topic::firstOrCreate(['name' => 'excedencia'], ['status' => 'approved']);

        // complementary — the real 140/143 shape
        $f140 = $this->fact($c, $doc, $jornada, '1704 horas', ['2025' => 1704]);
        $f143 = $this->fact($c, $doc, $jornada, '2 días libres', ['dias_libre_disposicion' => 2]);
        // contradictory — shared quantity
        $a = $this->fact($c, $doc, $vacaciones, '30 días', ['dias' => 30]);
        $b = $this->fact($c, $doc, $vacaciones, '31 días', ['dias' => 31]);
        // identical_value
        $this->fact($c, $doc, $permisos, '15 días', ['x' => 1]);
        $this->fact($c, $doc, $permisos, '15 días', ['y' => 1]);
        // recency_shadowed — one newer fact, one older still valid
        $this->fact($c, $doc, $excedencia, 'viejo 1 año', ['a' => 1], start: '2022-01-01');
        $newer = $this->fact($c, $doc, $excedencia, 'nuevo 2 años', ['b' => 2], start: '2024-01-01');
        // over_cap — four complementary members
        $c2 = $this->convenio('21');
        $doc2 = $this->doc($c2);
        foreach ([1, 2, 3, 4] as $i) {
            $this->fact($c2, $doc2, $jornada, "dato $i", ["q$i" => $i]);
        }
        // unbound — group-labelled, no category, bound to no approved node: counted, never classified
        $this->fact($c2, $doc2, $vacaciones, 'Grupo 9: 40 días', ['g' => 1], group: 'Grupo 9');

        $report = $this->report();
        $byTopic = fn (string $conv, string $topic) => collect($report['cohorts'])->first(fn ($r) => $r['convenio'] === $conv && $r['topic'] === $topic);

        $comp = $byTopic('31TEST-20', 'jornada');
        $this->assertSame('complementary', $comp['class']);
        $this->assertSame('disjoint_quantity_keys', $comp['reason']);
        $this->assertSame([$f140->id, $f143->id], $comp['fact_ids']);
        $this->assertFalse($comp['over_cap']);

        $bad = $byTopic('31TEST-20', 'vacaciones');
        $this->assertSame('contradictory', $bad['class']);
        $this->assertSame('same_quantity', $bad['reason']);
        $this->assertSame(['dias'], $bad['shared_keys']);
        $this->assertSame([$a->id, $b->id], $bad['fact_ids']);
        $this->assertFalse($bad['dup_flagged'], 'an edit-created pair is unflagged — "Resolver versión" cannot reach it');

        $this->assertSame('identical_value', $byTopic('31TEST-20', 'permisos retribuidos')['class']);
        $shadow = $byTopic('31TEST-20', 'excedencia');
        $this->assertSame('recency_shadowed', $shadow['class']);
        $this->assertCount(1, $shadow['shadowed_fact_ids']);
        $this->assertNotContains($newer->id, $shadow['shadowed_fact_ids']);

        $capped = $byTopic('31TEST-21', 'jornada');
        $this->assertSame('complementary', $capped['class']);
        $this->assertTrue($capped['over_cap']);

        $s = $report['summary'];
        $this->assertSame(['complementary' => 2, 'contradictory' => 1, 'recency_shadowed' => 1, 'identical_value' => 1], $s['cohorts']);
        $this->assertSame(1, $s['over_cap']);
        $this->assertSame(1, $s['unbound_group_labelled_excluded']);
        $this->assertNull($byTopic('31TEST-21', 'vacaciones'), 'a single unbound fact forms no cohort');
    }

    public function test_a1b_a_flagged_unresolved_pair_is_contradictory_and_marked_dup_flagged(): void
    {
        $c = $this->convenio('20');
        $doc = $this->doc($c);
        $t = Topic::firstOrCreate(['name' => 'jornada'], ['status' => 'approved']);
        $a = $this->fact($c, $doc, $t, 'a', ['x' => 1]);
        $b = $this->fact($c, $doc, $t, 'b', ['y' => 1]);
        $b->update(['duplicate_of_id' => $a->id]);

        $row = $this->report()['cohorts'][0];

        $this->assertSame('contradictory', $row['class']);
        $this->assertSame('flagged_duplicate_unresolved', $row['reason']);
        $this->assertTrue($row['dup_flagged']);
    }

    public function test_a1c_group_bucket_uses_the_approved_bound_node(): void
    {
        $c = $this->convenio('21');
        $doc = $this->doc($c);
        $t = Topic::firstOrCreate(['name' => 'jornada'], ['status' => 'approved']);
        $node = ConvenioGroup::create([
            'convenio_id' => $c->id, 'label' => 'Grupo 1', 'code_normalized' => '1', 'normalization_rule' => 'test',
            'status' => ConvenioGroup::STATUS_APPROVED, 'source' => 'admin_manual',
        ]);
        foreach ([['g1 anual', ['anual' => 1]], ['g1 libre', ['libre' => 2]]] as [$v, $raw]) {
            $f = $this->fact($c, $doc, $t, $v, $raw, group: 'Grupo 1');
            ReferenceFactGroupScope::create(['reference_fact_id' => $f->id, 'convenio_group_id' => $node->id, 'bound_at' => now()]);
        }

        $report = $this->report();

        $this->assertCount(1, $report['cohorts']);
        $this->assertStringStartsWith('group:', $report['cohorts'][0]['tier']);
        $this->assertSame('complementary', $report['cohorts'][0]['class']);
        $this->assertSame(0, $report['summary']['unbound_group_labelled_excluded']);
    }

    // ---- A2 — the 13b figures, staging-shaped ---------------------------------

    public function test_a2_the_ticket_shape_one_complementary_pair_and_no_contradiction_among_singles(): void
    {
        $topics = collect(['jornada', 'vacaciones', 'permisos retribuidos', 'excedencia', 'periodo de prueba'])
            ->map(fn ($n) => Topic::firstOrCreate(['name' => $n], ['status' => 'approved']));
        $expectedFacts = 0;
        $pairs = 0;
        foreach (['13', '14', '20', '21'] as $num) {
            $c = $this->convenio($num);
            $doc = $this->doc($c);
            foreach ($topics as $t) {
                if ($num === '21' && $t->name === 'excedencia') {
                    continue;
                }
                $this->fact($c, $doc, $t, "{$num} {$t->name}", ['v' => 1]);
                $expectedFacts++;
                $pairs++;
            }
        }
        $c20 = Convenio::where('numero', '31TEST-20')->first();
        $this->fact($c20, Document::first(), Topic::where('name', 'jornada')->first(), 'reglas', ['jornada_irregular' => '0%']);
        $expectedFacts++;

        $s = $this->report()['summary'];

        $this->assertSame($pairs, $s['convenio_topic_pairs']);
        $this->assertSame($expectedFacts, $s['verified_in_validity_facts']);
        $this->assertSame($expectedFacts, $s['convenio_wide_facts']);
        $this->assertSame(['complementary' => 1, 'contradictory' => 0, 'recency_shadowed' => 0, 'identical_value' => 0], $s['cohorts']);
    }

    // ---- A3 — output shapes ---------------------------------------------------

    public function test_a3_json_and_table_output(): void
    {
        $c = $this->convenio('20');
        $doc = $this->doc($c);
        $t = Topic::firstOrCreate(['name' => 'jornada'], ['status' => 'approved']);
        $this->fact($c, $doc, $t, 'a', ['x' => 1]);
        $this->fact($c, $doc, $t, 'b', ['y' => 1]);

        Artisan::call('facts:same-topic-audit', ['--json' => true, '--as-of' => now()->toDateString()]);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['as_of', 'summary', 'cohorts'], array_keys($json));
        $this->assertSame(
            ['convenio', 'topic', 'tier', 'top_validity_start', 'fact_ids', 'class', 'reason', 'shared_keys', 'over_cap', 'dup_flagged', 'shadowed_fact_ids'],
            array_keys($json['cohorts'][0]),
        );

        $this->assertSame(0, Artisan::call('facts:same-topic-audit', ['--convenio' => '31TEST-20']));
        $out = Artisan::output();
        $this->assertStringContainsString('complementary=1', $out);
        $this->assertStringContainsString('human should read each pair', $out);
        $this->assertSame(1, Artisan::call('facts:same-topic-audit', ['--convenio' => 'nope']));
    }

    // ---- A4 — read-only -------------------------------------------------------

    public function test_a4_the_audit_issues_only_select_statements_and_writes_nothing(): void
    {
        $c = $this->convenio('20');
        $doc = $this->doc($c);
        $t = Topic::firstOrCreate(['name' => 'jornada'], ['status' => 'approved']);
        $this->fact($c, $doc, $t, 'a', ['x' => 1]);
        $this->fact($c, $doc, $t, 'b', ['x' => 2]);
        $before = ReferenceFact::query()->get()->map->getAttributes()->all();

        $statements = [];
        DB::listen(function ($q) use (&$statements) {
            $statements[] = $q->sql;
        });
        Artisan::call('facts:same-topic-audit', ['--json' => true]);

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql, 'the audit must be SELECT-only: '.$sql);
        }
        $this->assertSame($before, ReferenceFact::query()->get()->map->getAttributes()->all());
    }

    // ---- helpers --------------------------------------------------------------

    /** @return array<string,mixed> */
    private function report(): array
    {
        Artisan::call('facts:same-topic-audit', ['--json' => true]);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function convenio(string $numero): Convenio
    {
        return Convenio::create([
            'numero' => '31TEST-'.$numero, 'name' => 'Convenio '.$numero,
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
    }

    private function doc(Convenio $convenio): Document
    {
        return Document::create([
            'title' => 'Convenio '.$convenio->numero, 'storage_path' => 'fake/'.$convenio->numero.'.docx', 'convenio_id' => $convenio->id,
            'document_type_id' => DocumentType::query()->value('id'), 'authority_level' => 'official_convenio',
            'retrieval_status' => 'active', 'language' => 'es', 'tagging_status' => 'verified',
        ]);
    }

    /** @param  array<mixed>|null  $raw */
    private function fact(Convenio $c, Document $doc, Topic $t, string $value, ?array $raw, ?string $group = null, ?string $start = null): ReferenceFact
    {
        return ReferenceFact::create([
            'convenio_id' => $c->id, 'topic_id' => $t->id, 'job_category_id' => null, 'group_label' => $group,
            'value' => $value, 'raw_values' => $raw, 'authority_level' => 'structured_reference',
            'source' => 'ai_agent', 'status' => 'verified', 'validity_start' => $start, 'source_document_id' => $doc->id,
        ]);
    }
}
