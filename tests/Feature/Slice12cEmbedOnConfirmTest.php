<?php

namespace Tests\Feature;

use App\Jobs\EmbedDocument;
use App\Models\Admin;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentReviewTask;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\TagEvent;
use App\Models\Territory;
use App\Services\DocumentEmbedder;
use App\Services\ExtractionClient;
use App\Support\EmbedEligibility;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

/**
 * Slice 12c. Confirm queues one document's embed and returns without calling
 * hr-ai. The job refuses under_review, replaces rather than inserts beside,
 * and a lock release is not a failure.
 */
class Slice12cEmbedOnConfirmTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Convenio $convenio;

    private Admin $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->territory = Territory::create(['code' => '28', 'name' => 'Madrid', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Ocio educativo', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '28102145012018',
            'name' => 'COEAS Madrid',
            'territory_id' => $this->territory->id,
            'sector_id' => $sector->id,
        ]);

        foreach ([
            'convenio_text' => 'Texto',
            'partial_agreement' => 'Acuerdo',
            'national_law' => 'Ley',
            'internal_hr_ruling' => 'Resolución',
            'salary_tables' => 'Tablas',
            'reference_source' => 'Fuente',
            'summary' => 'Resumen',
        ] as $code => $name) {
            DocumentType::firstOrCreate(['code' => $code], ['name' => $name]);
        }

        $this->admin = Admin::create([
            'email' => 'slice12c@example.com',
            'full_name' => 'Slice 12c',
            'status' => 'active',
        ]);
        $this->admin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->token = $this->admin->createToken('test')->plainTextToken;
    }

    public function test_confirm_queues_one_document_and_does_not_call_the_embed_client(): void
    {
        Queue::fake();
        $this->mock(ExtractionClient::class, function ($mock) {
            $mock->shouldReceive('embed')->never();
        });

        $doc = $this->prose();
        DocumentReviewTask::create([
            'document_id' => $doc->id,
            'type' => 'tag_review',
            'reason' => 'unresolved',
            'status' => 'open',
        ]);

        $this->confirm($doc)
            ->assertOk()
            ->assertJson(['status' => 'ok', 'tagging_status' => 'verified', 'embed_queued' => true]);

        $doc->refresh();
        $this->assertSame('verified', $doc->tagging_status);
        $this->assertSame('pending', $doc->embed_state);
        $this->assertNull($doc->embed_error_code);
        $this->assertNotNull($doc->embed_state_at);
        $this->assertSame(1, TagEvent::where('entity_id', $doc->id)->where('note', 'tags confirmed')->count());
        $this->assertSame(0, TagEvent::where('facet', 'embed')->count());
        $this->assertSame('resolved', $doc->reviewTasks()->first()->status);
        Queue::assertPushed(EmbedDocument::class, fn (EmbedDocument $job) => $job->documentId === $doc->id);
    }

    public function test_confirm_does_not_queue_ineligible_documents(): void
    {
        Queue::fake();

        $cases = [
            'salary' => ['type' => 'salary_tables'],
            'reference' => ['type' => 'reference_source'],
            'summary' => ['type' => 'summary'],
            'draft' => ['retrieval_status' => 'draft'],
            'historical' => ['retrieval_status' => 'historical'],
            'blank' => ['page_text' => '   '],
            'unscoped' => ['convenio_id' => null],
        ];

        foreach ($cases as $label => $overrides) {
            $doc = $this->prose($overrides);
            $this->confirm($doc)->assertOk()->assertJson(['embed_queued' => false]);
            $doc->refresh();
            $this->assertNull($doc->embed_state, $label);
        }

        Queue::assertNotPushed(EmbedDocument::class);
    }

    public function test_national_law_partial_agreement_and_ruling_are_queued(): void
    {
        Queue::fake();

        $national = $this->prose([
            'type' => 'national_law',
            'authority_level' => 'national_law',
            'convenio_id' => null,
        ]);
        $partial = $this->prose(['type' => 'partial_agreement']);
        $ruling = $this->prose([
            'type' => 'internal_hr_ruling',
            'authority_level' => 'internal_hr_ruling',
        ]);

        foreach ([$national, $partial, $ruling] as $doc) {
            $this->confirm($doc)->assertOk()->assertJson(['embed_queued' => true]);
        }

        Queue::assertPushed(EmbedDocument::class, 3);
    }

    public function test_a_retry_on_a_verified_document_only_queues(): void
    {
        Queue::fake();
        $doc = $this->prose(['tagging_status' => 'verified']);
        TagEvent::create([
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'facet' => 'document',
            'old_value' => null,
            'new_value' => 'verified',
            'source' => 'admin_manual',
            'actor_id' => $this->admin->id,
            'note' => 'tags confirmed',
        ]);
        $task = DocumentReviewTask::create([
            'document_id' => $doc->id,
            'type' => 'conflict',
            'reason' => 'conflict',
            'status' => 'open',
        ]);

        $this->confirm($doc)->assertOk()->assertJson(['embed_queued' => true, 'tagging_status' => 'verified']);

        $this->assertSame(1, TagEvent::where('entity_id', $doc->id)->count());
        $this->assertSame('open', $task->fresh()->status);
        $this->assertNull($task->fresh()->resolved_at);
        Queue::assertPushed(EmbedDocument::class, 1);
    }

    public function test_the_job_embeds_only_the_given_document(): void
    {
        $target = $this->prose(['tagging_status' => 'verified']);
        $otherA = $this->prose(['tagging_status' => 'verified']);
        $otherB = $this->prose(['tagging_status' => 'verified']);

        $this->mock(ExtractionClient::class, function ($mock) use ($target) {
            $mock->shouldReceive('embed')->once()->withArgs(function (int $id, string $uuid, string $path, array $scope) use ($target) {
                return $id === $target->id
                    && $uuid === $target->uuid
                    && $scope['convenio_id'] === $target->convenio_id
                    && $scope['territory_id'] === $this->territory->id
                    && $scope['retrieval_status'] === 'active'
                    && $scope['authority_level'] === 'official_convenio';
            })->andReturn($this->embedResult());
        });

        (new EmbedDocument($target->id))->handle(app(DocumentEmbedder::class), app(EmbedEligibility::class));

        $target->refresh();
        $this->assertSame('searchable', $target->embed_state);
        $this->assertNull($target->embed_error_code);
        $this->assertSame(0, TagEvent::where('facet', 'embed')->count());
        $this->assertNotContains($otherA->id, [$target->id]);
        $this->assertNotSame($otherB->id, $target->id);
    }

    public function test_a_second_run_calls_the_same_replace_path(): void
    {
        $doc = $this->prose(['tagging_status' => 'verified']);
        $ids = [];

        $this->mock(ExtractionClient::class, function ($mock) use (&$ids) {
            $mock->shouldReceive('embed')->twice()->andReturnUsing(function (int $id) use (&$ids) {
                $ids[] = $id;

                return $this->embedResult();
            });
        });

        $embedder = app(DocumentEmbedder::class);
        $eligibility = app(EmbedEligibility::class);
        (new EmbedDocument($doc->id))->handle($embedder, $eligibility);
        (new EmbedDocument($doc->id))->handle($embedder, $eligibility);

        $this->assertSame([$doc->id, $doc->id], $ids);
        $this->assertSame('searchable', $doc->fresh()->embed_state);
    }

    public function test_under_review_is_not_embedded(): void
    {
        $doc = $this->prose([
            'tagging_status' => 'under_review',
            'embed_state' => 'pending',
            'embed_state_at' => now(),
        ]);

        $this->mock(ExtractionClient::class, function ($mock) {
            $mock->shouldReceive('embed')->never();
        });

        $embedder = app(DocumentEmbedder::class);
        $this->assertNull($embedder->embed($doc));

        (new EmbedDocument($doc->id))->handle($embedder, app(EmbedEligibility::class));

        $doc->refresh();
        $this->assertNull($doc->embed_state);
        $this->assertNull($doc->embed_error_code);
        $this->assertNotSame('failed', $doc->embed_state);
    }

    public function test_a_held_overlap_lock_releases_the_job_and_writes_no_failed_state(): void
    {
        $doc = $this->prose([
            'tagging_status' => 'verified',
            'embed_state' => 'pending',
            'embed_state_at' => now(),
        ]);

        $job = (new EmbedDocument($doc->id))->withFakeQueueInteractions();
        $middleware = $job->middleware()[0];
        $other = new EmbedDocument($doc->id + 1);
        $this->assertSame('embed-documents', $middleware->key);
        $this->assertSame(60, $middleware->releaseAfter);
        $this->assertSame(1200, $middleware->expiresAfter);
        $this->assertSame($middleware->getLockKey($job), $other->middleware()[0]->getLockKey($other));

        $this->assertTrue(Cache::lock($middleware->getLockKey($job), 1200)->get());

        $ran = false;
        $middleware->handle($job, function () use (&$ran) {
            $ran = true;
        });

        $this->assertFalse($ran);
        $job->assertReleased(60);
        $job->assertNotFailed();
        $doc->refresh();
        $this->assertSame('pending', $doc->embed_state);
        $this->assertNull($doc->embed_error_code);
    }

    public function test_a_retry_can_be_queued_once_the_unique_lock_expires(): void
    {
        Queue::fake();
        $job = new EmbedDocument(7);
        $this->assertSame(1800, $job->uniqueFor);
        $this->assertFalse((new \ReflectionClass(EmbedDocument::class))->hasProperty('tries'));
        $this->assertSame(1, $job->maxExceptions);
        $this->assertSame(1200, $job->timeout);
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $job->retryUntil()->getTimestamp(), 5);

        EmbedDocument::dispatch(7);
        EmbedDocument::dispatch(7);
        Queue::assertPushed(EmbedDocument::class, 1);

        $this->travel($job->uniqueFor + 1)->seconds();

        EmbedDocument::dispatch(7);
        Queue::assertPushed(EmbedDocument::class, 2);
    }

    public function test_real_exceptions_record_a_code_and_are_not_swallowed(): void
    {
        $cases = [
            [new ConnectionException('cURL error 28: Operation timed out'), 'timed_out'],
            [new ConnectionException('cURL error 7: Failed to connect'), 'service_unreachable'],
            [new RuntimeException('hr-ai /embed failed (500): no'), 'failed'],
        ];

        foreach ($cases as [$exception, $code]) {
            $doc = $this->prose([
                'tagging_status' => 'verified',
                'embed_state' => 'pending',
                'embed_state_at' => now(),
            ]);
            $this->mock(ExtractionClient::class, function ($mock) use ($exception) {
                $mock->shouldReceive('embed')->once()->andThrow($exception);
            });
            if ($code === 'timed_out') {
                Log::spy();
            }

            try {
                (new EmbedDocument($doc->id))->handle(app(DocumentEmbedder::class), app(EmbedEligibility::class));
                $this->fail('the embed exception must propagate');
            } catch (Throwable $caught) {
                $this->assertSame($exception->getMessage(), $caught->getMessage());
            }

            $doc->refresh();
            $this->assertSame('failed', $doc->embed_state);
            $this->assertSame($code, $doc->embed_error_code);
            $this->assertSame(0, TagEvent::where('facet', 'embed')->where('entity_id', $doc->id)->count());
            if ($code === 'timed_out') {
                Log::shouldHaveReceived('error')->atLeast()->once();
            }
        }
    }

    public function test_show_computes_embed_status_without_a_provenance_row(): void
    {
        $pending = $this->prose([
            'tagging_status' => 'verified',
            'embed_state' => 'pending',
            'embed_state_at' => now()->subMinutes(21),
        ]);

        $this->show($pending)
            ->assertOk()
            ->assertJsonPath('embed.state', 'failed')
            ->assertJsonPath('embed.error_code', 'not_finished');

        $this->assertSame('pending', $pending->fresh()->embed_state);
        $this->assertSame(0, TagEvent::where('facet', 'embed')->count());

        $withChunks = $this->prose(['tagging_status' => 'verified']);
        DB::table('document_chunks')->insert([
            'document_id' => $withChunks->id,
            'chunk_index' => 0,
            'content' => 'Artículo único.',
            'token_count' => 3,
            'retrieval_status' => 'active',
            'authority_level' => 'official_convenio',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->show($withChunks)
            ->assertOk()
            ->assertJsonPath('embed.state', 'searchable')
            ->assertJsonPath('embed.chunk_count', 1)
            ->assertJsonPath('embed.error_code', null);
    }

    public function test_a_lifecycle_flip_to_eligible_queues_the_same_job(): void
    {
        Queue::fake();
        $historical = $this->prose([
            'tagging_status' => 'verified',
            'retrieval_status' => 'historical',
        ]);

        $this->patchDoc($historical, [
            'retrieval_status' => 'active',
            'confirm_scope_change' => true,
        ])->assertOk()->assertJson(['embed_queued' => true]);

        Queue::assertPushed(EmbedDocument::class, fn (EmbedDocument $job) => $job->documentId === $historical->id);

        Queue::fake();
        $already = $this->prose(['tagging_status' => 'verified']);
        $this->patchDoc($already, [
            'validity_end' => '2027-12-31',
            'confirm_scope_change' => true,
        ])->assertOk()->assertJson(['embed_queued' => false]);
        Queue::assertNotPushed(EmbedDocument::class);

        $stillHistorical = $this->prose([
            'tagging_status' => 'verified',
            'retrieval_status' => 'historical',
        ]);
        $this->patchDoc($stillHistorical, [
            'validity_end' => '2020-12-31',
            'confirm_scope_change' => true,
        ])->assertOk()->assertJson(['embed_queued' => false]);
        Queue::assertNotPushed(EmbedDocument::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function prose(array $overrides = []): Document
    {
        $typeCode = $overrides['type'] ?? 'convenio_text';
        $pageText = array_key_exists('page_text', $overrides) ? $overrides['page_text'] : 'Artículo 1. Jornada.';
        unset($overrides['type'], $overrides['page_text']);

        $doc = Document::create(array_merge([
            'title' => 'Texto',
            'source_filename' => 'texto.pdf',
            'storage_path' => 'documents/texto.pdf',
            'convenio_id' => $this->convenio->id,
            'document_type_id' => DocumentType::where('code', $typeCode)->value('id'),
            'retrieval_status' => 'active',
            'authority_level' => 'official_convenio',
            'language' => 'es',
            'tagging_status' => 'under_review',
            'validity_start' => '2024-01-01',
            'validity_end' => '2026-12-31',
        ], $overrides));

        DocumentPage::create([
            'document_id' => $doc->id,
            'page_number' => 1,
            'text' => $pageText,
        ]);

        return $doc->fresh();
    }

    private function confirm(Document $doc): TestResponse
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->postJson('/admin/documents/'.$doc->uuid.'/confirm', [], $this->auth());
    }

    /** @param array<string,mixed> $payload */
    private function patchDoc(Document $doc, array $payload): TestResponse
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->patchJson('/admin/documents/'.$doc->uuid, $payload, $this->auth());
    }

    private function show(Document $doc): TestResponse
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->getJson('/admin/documents/'.$doc->uuid, $this->auth());
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    /** @return array<string,mixed> */
    private function embedResult(): array
    {
        return [
            'chunks_written' => 2,
            'language_streams' => ['es' => 2, 'eu' => 0],
            'stats' => [],
        ];
    }
}
