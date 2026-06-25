<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentTopic;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\EscalationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Sprint 5 — Correction-01 acceptance proof.
 *
 * The publish no-override fence (EscalationService::detectConflicts) must be
 * FAIL-CLOSED: when a topic is selected it blocks an active official_convenio in
 * the asker's scope that shares that topic OR that carries no topic tags at all.
 * Before the fix, selecting a topic narrowed the block to topic-tagged convenio
 * docs only — and since the convenio side is almost never tagged, a genuine
 * same-scope conflict slipped through (fail-open). These four cases lock the
 * behaviour so the fail-open cannot regress:
 *
 *   1. no topic                            → BLOCK (scope-only, unchanged)
 *   2. topic + untagged governing convenio → BLOCK (the fix; was ALLOW)
 *   3. topic + convenio tagged same topic  → BLOCK
 *   4. topic + convenio tagged other topic → ALLOW (precise tightening, not "block everything")
 */
class Sprint5Correction01FenceTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Document $convenioDoc;

    private Topic $vacaciones;

    private Topic $jornada;

    protected function setUp(): void
    {
        parent::setUp();

        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Limpieza', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31TEST0022', 'name' => 'Limpieza Navarra (test)',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);

        $officialType = DocumentType::create(['code' => 'official_convenio', 'name' => 'Convenio oficial']);

        $this->vacaciones = Topic::create(['name' => 'vacaciones', 'status' => 'approved']);
        $this->jornada = Topic::create(['name' => 'jornada', 'status' => 'approved']);

        // An ACTIVE official_convenio governing this scope, deliberately left
        // UNtagged — the reality the fence has to survive (sparse topic lens).
        $this->convenioDoc = Document::create([
            'title' => 'Limpieza Navarra 2024-2027',
            'storage_path' => 'documents/test/limpieza-navarra.pdf',
            'convenio_id' => $this->convenio->id,
            'document_type_id' => $officialType->id,
            'authority_level' => 'official_convenio',
            'retrieval_status' => 'active',
            'language' => 'es',
        ]);
    }

    /** Invoke the private fence (pure read query; no publisher needed). */
    private function detect(?int $topicId): Collection
    {
        $service = (new \ReflectionClass(EscalationService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(EscalationService::class, 'detectConflicts');
        $method->setAccessible(true);

        return $method->invoke($service, $this->convenio->id, $topicId);
    }

    private function tag(Topic $topic): void
    {
        DocumentTopic::create([
            'document_id' => $this->convenioDoc->id,
            'topic_id' => $topic->id,
            'source' => 'admin_manual',
        ]);
    }

    public function test_case1_no_topic_blocks_scope_only(): void
    {
        $this->assertTrue(
            $this->detect(null)->isNotEmpty(),
            'No-topic publish must BLOCK on any active official_convenio in scope.'
        );
    }

    public function test_case2_topic_with_untagged_governing_convenio_blocks(): void
    {
        // The convenio doc carries no document_topics rows (reality). Pre-fix this
        // returned 0 rows (ALLOW); fail-closed must now BLOCK.
        $this->assertSame(0, DocumentTopic::where('document_id', $this->convenioDoc->id)->count());

        $this->assertTrue(
            $this->detect($this->vacaciones->id)->isNotEmpty(),
            'Topic selected + untagged-but-governing convenio must BLOCK (fail-closed).'
        );
    }

    public function test_case3_topic_with_convenio_tagged_same_topic_blocks(): void
    {
        $this->tag($this->vacaciones);

        $this->assertTrue(
            $this->detect($this->vacaciones->id)->isNotEmpty(),
            'A convenio tagged with the ruling\'s topic must BLOCK.'
        );
    }

    public function test_case4_topic_with_convenio_tagged_different_topic_allows(): void
    {
        // Tagged, but on a DIFFERENT topic only — a genuine no-conflict. Proves
        // the fix is a precise tightening, not a blunt "always block".
        $this->tag($this->jornada);

        $this->assertTrue(
            $this->detect($this->vacaciones->id)->isEmpty(),
            'A convenio tagged on a different topic must ALLOW (genuine no-conflict).'
        );
    }
}
