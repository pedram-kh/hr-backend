<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentTopic;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 11c (plan.md §C.3) — the `/admin/knowledge-graph` read: the SAME gate
 * as the rest of Map (admin group, no extra ability — mirrors
 * {@see Sprint8AnalyticsAccessTest}'s role-matrix pattern), a deny-list on the
 * payload, and end-to-end counts against a known fixture world (the builder's
 * OWN rules are proven separately, with no DB, in
 * `tests/Unit/KnowledgeGraphBuilderTest.php` — this file proves the
 * CONTROLLER wires the right queries to those rules).
 */
class Sprint11cKnowledgeGraphTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/knowledge-graph';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);
        $this->buildWorld();
    }

    /**
     * Two convenios sharing one territory and one sector — exactly
     * `KnowledgeGraphBuilderTest::baseWorld()`'s shape, so both hubs clear the
     * >=2 sparsity threshold and every one of the six edge kinds appears once
     * per convenio: 9 nodes (2 convenio + 2 document + 2 fact + 1 territory +
     * 1 sector + 1 topic), 12 edges (6 kinds × 2 convenios).
     */
    private function buildWorld(): void
    {
        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Deporte', 'aliases' => []]);
        $topic = Topic::create(['name' => 'Jornada', 'status' => 'approved']);
        $documentType = DocumentType::where('code', 'convenio_text')->firstOrFail();

        foreach ([1, 2] as $n) {
            $convenio = Convenio::create([
                'numero' => "3100050{$n}", 'name' => "Convenio Test {$n}",
                'territory_id' => $territory->id, 'sector_id' => $sector->id,
            ]);
            $document = Document::create([
                'uuid' => (string) Str::uuid(), 'title' => "Documento {$n}", 'storage_path' => "docs/test-{$n}.pdf",
                'convenio_id' => $convenio->id, 'document_type_id' => $documentType->id,
                'retrieval_status' => 'active', 'tagging_status' => 'verified',
                'authority_level' => 'official_convenio', 'language' => 'es',
            ]);
            DocumentTopic::create(['document_id' => $document->id, 'topic_id' => $topic->id, 'source' => 'admin_manual']);
            ReferenceFact::create([
                'uuid' => (string) Str::uuid(), 'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
                'value' => "Fact {$n}", 'source' => 'admin_manual', 'status' => 'verified',
            ]);
        }
    }

    private function adminWithRole(string $role): Admin
    {
        $admin = Admin::create(['email' => $role.'-'.uniqid().'@example.com', 'full_name' => ucfirst($role), 'status' => 'active']);
        $admin->assignRole($role);

        return $admin;
    }

    private function auth(Admin|Employee $account): array
    {
        return ['Authorization' => 'Bearer '.$account->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function resetPermCache(): void
    {
        $this->app['auth']->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function getAs(Admin|Employee $account, string $url): TestResponse
    {
        $this->resetPermCache();

        return $this->getJson($url, $this->auth($account));
    }

    // ---- gate: the admin group, no extra ability -----------------------------

    public function test_every_admin_role_reads_the_graph(): void
    {
        foreach (['super_admin', 'knowledge_editor', 'hr_agent', 'auditor'] as $role) {
            $this->getAs($this->adminWithRole($role), self::URL)->assertStatus(200);
        }
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::URL, ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_employee_token_is_forbidden(): void
    {
        $territory = Territory::first();
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'email' => 'emp@example.com', 'full_name' => 'Test Employee',
            'convenio_id' => Convenio::first()->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $this->getAs($employee, self::URL)->assertStatus(403);
    }

    // ---- payload shape --------------------------------------------------------

    public function test_response_carries_no_chunk_text_or_employee_data(): void
    {
        $body = $this->getAs($this->adminWithRole('super_admin'), self::URL)->assertStatus(200)->getContent();

        foreach (['chunk', 'chunks', 'embedding', 'employee', 'employees', 'email', 'salary'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                "\"{$forbidden}\"", $body, "response must not carry a '{$forbidden}' key",
            );
        }
    }

    // ---- counts wire up to the builder's rules ---------------------------------

    public function test_counts_and_shape_match_the_fixture_world(): void
    {
        $json = $this->getAs($this->adminWithRole('super_admin'), self::URL)->assertStatus(200)->json();

        $this->assertSame(9, $json['counts']['nodes']);
        $this->assertSame(12, $json['counts']['edges']);
        $this->assertCount(9, $json['nodes']);
        $this->assertCount(12, $json['edges']);
        $this->assertSame(0, $json['counts']['hidden']['documents_orphan']);
        $this->assertSame(0, $json['counts']['hidden']['facts_rejected']);
        $this->assertSame(0, $json['counts']['hidden']['convenios_excluded']);
        $this->assertArrayHasKey('generated_at', $json);

        $kinds = array_unique(array_column($json['edges'], 'kind'));
        sort($kinds);
        $this->assertSame(
            ['convenio_sector', 'convenio_territory', 'document_convenio', 'document_topic', 'fact_convenio', 'fact_topic'],
            $kinds,
        );
    }

    public function test_dev_fixture_convenio_never_appears_in_the_live_response(): void
    {
        Convenio::create(['numero' => 'DEV-FIXTURE-0001', 'name' => 'Dev Fixture', 'territory_id' => Territory::first()->id, 'sector_id' => Sector::first()->id]);

        $json = $this->getAs($this->adminWithRole('super_admin'), self::URL)->assertStatus(200)->json();

        $this->assertSame(1, $json['counts']['hidden']['convenios_excluded']);
        foreach ($json['nodes'] as $node) {
            $this->assertNotSame('Dev Fixture', $node['label']);
        }
    }

    // ---- deterministic order ----------------------------------------------------

    public function test_nodes_and_edges_arrive_in_the_documented_order(): void
    {
        $json = $this->getAs($this->adminWithRole('super_admin'), self::URL)->assertStatus(200)->json();

        $types = array_column($json['nodes'], 'type');
        $expectedGroupOrder = ['convenio', 'document', 'fact', 'sector', 'territory', 'topic'];
        $seen = array_values(array_unique($types));
        $this->assertSame(
            array_values(array_intersect($expectedGroupOrder, $seen)),
            $seen,
            'nodes must be grouped by type in the documented order',
        );

        $edgeKinds = array_column($json['edges'], 'kind');
        $sorted = $edgeKinds;
        sort($sorted);
        // Not a strict equality (ties within a kind are broken by source/target,
        // asserted structurally instead): every kind-group must already be
        // contiguous, i.e. the response was not re-shuffled after sorting.
        $this->assertSame($this->collapseConsecutive($edgeKinds), $this->collapseConsecutive($sorted));
    }

    /** @return list<string> */
    private function collapseConsecutive(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (end($out) !== $v) {
                $out[] = $v;
            }
        }

        return array_values($out);
    }
}
