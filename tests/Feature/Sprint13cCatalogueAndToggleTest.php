<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GuardrailCataloguePage;
use App\Models\GuardrailConfig;
use App\Services\GeneralLaneCatalogue;
use App\Services\GuardrailPolicy;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Slice 13c (plan.md §2.6, §6): the model-knowledge sub-flag (restrict-only admin toggle, audited) and the official-page
 * catalogue (data, allowlist enforced at write, soft-disable, seeded from config, read path with config fallback).
 */
class Sprint13cCatalogueAndToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        config(['hr.general_lane.enabled' => true, 'hr.general_lane.model_knowledge' => true]);
        GuardrailPolicy::flush();
    }

    private function adminWithRole(string $role): Admin
    {
        $admin = Admin::create(['email' => $role.'-'.uniqid().'@example.com', 'full_name' => ucfirst($role), 'status' => 'active']);
        $admin->assignRole($role);

        return $admin;
    }

    /** @return array<string,string> */
    private function auth(Admin $admin): array
    {
        $this->app['auth']->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function api(string $method, string $uri, Admin $admin, array $body = []): TestResponse
    {
        return $this->json($method, $uri, $body, $this->auth($admin));
    }

    // ---- sub-flag: restrict-only, audited ------------------------------------

    public function test_the_sub_flag_is_env_baseline_and_lane_and_admin_and_only_narrows(): void
    {
        $super = $this->adminWithRole('super_admin');
        $eff = fn () => (new GuardrailPolicy)->generalLaneModelKnowledgeEnabled();

        $this->assertTrue($eff());

        $this->api('POST', '/admin/guardrails', $super, ['general_lane_model_knowledge_enabled' => false])->assertOk();
        GuardrailPolicy::flush();
        $this->assertFalse($eff(), 'admin=false narrows it off (no deploy)');
        $this->assertDatabaseHas('guardrail_config_events', ['field' => 'general_lane_model_knowledge_enabled', 'new_value' => 'false']);

        $this->api('POST', '/admin/guardrails', $super, ['general_lane_model_knowledge_enabled' => true])->assertOk();
        GuardrailPolicy::flush();
        $this->assertTrue($eff());

        config(['hr.general_lane.model_knowledge' => false]);
        GuardrailPolicy::flush();
        $this->assertFalse($eff(), 'admin=true can never force it on over an env baseline that is off');

        config(['hr.general_lane.model_knowledge' => true, 'hr.general_lane.enabled' => false]);
        GuardrailPolicy::flush();
        $this->assertFalse($eff(), 'lane off ⇒ sub-flag off, whatever it says');
    }

    public function test_the_sub_flag_write_needs_guardrails_manage_and_the_console_reports_it(): void
    {
        foreach (['auditor', 'hr_agent', 'knowledge_editor'] as $role) {
            $this->api('POST', '/admin/guardrails', $this->adminWithRole($role), ['general_lane_model_knowledge_enabled' => false])->assertStatus(403);
        }
        $this->api('GET', '/admin/guardrails', $this->adminWithRole('auditor'))->assertOk()
            ->assertJsonStructure(['general_lane_model_knowledge' => ['admin', 'env_baseline', 'effective'], 'general_lane_catalogue' => ['allowed_domains', 'pages']]);
        $this->assertNull(GuardrailConfig::current()->general_lane_model_knowledge_enabled);
    }

    // ---- catalogue: seeded, read path -----------------------------------------

    public function test_the_migration_seeds_the_five_sprint_13_pages_from_config(): void
    {
        $this->assertSame(5, GuardrailCataloguePage::query()->where('baseline', true)->count());
        $this->assertEqualsCanonicalizing(
            array_column(config('hr.general_lane.sources'), 'id'),
            GuardrailCataloguePage::query()->pluck('slug')->all(),
        );
        // and the read path returns them exactly as the config array (id, url, title, topics), in the same order
        $this->assertSame(
            array_map(fn ($p) => ['id' => $p['id'], 'url' => $p['url'], 'title' => $p['title'], 'topics' => $p['topics']], config('hr.general_lane.sources')),
            GeneralLaneCatalogue::pages(),
        );
    }

    public function test_the_read_path_falls_back_to_config_when_the_table_is_empty_and_hides_disabled_rows(): void
    {
        $count = count(config('hr.general_lane.sources'));
        GuardrailCataloguePage::query()->first()->update(['enabled' => false]);
        $this->assertCount($count - 1, GeneralLaneCatalogue::pages());

        GuardrailCataloguePage::query()->delete();
        $this->assertCount($count, GeneralLaneCatalogue::pages(), 'an empty table is "never configured", not "everything off"');
    }

    // ---- catalogue: writes ------------------------------------------------------

    public function test_add_edit_and_soft_disable_are_audited_and_only_for_guardrails_manage(): void
    {
        $super = $this->adminWithRole('super_admin');
        $body = ['title' => 'SEPE — Prestación por desempleo', 'url' => 'https://www.sepe.es/HomeSepe/Personas/distributiva-prestaciones/prestacion-desempleo.html', 'topics' => ['paro', 'Prestación por desempleo', 'paro']];

        $this->api('POST', '/admin/guardrails/catalogue', $this->adminWithRole('hr_agent'), $body)->assertStatus(403);

        $res = $this->api('POST', '/admin/guardrails/catalogue', $super, $body)->assertOk();
        $page = collect($res->json('general_lane_catalogue.pages'))->firstWhere('title', $body['title']);
        $this->assertFalse($page['baseline']);
        $this->assertSame(['paro', 'prestación por desempleo'], $page['topics'], 'lower-cased and de-duplicated');
        $this->assertDatabaseHas('guardrail_config_events', ['field' => 'catalogue_page_added']);
        $this->assertContains($page['slug'], array_column(GeneralLaneCatalogue::pages(), 'id'));

        $this->api('PATCH', "/admin/guardrails/catalogue/{$page['id']}", $super, ['topics' => ['paro', 'desempleo']])->assertOk();
        $this->assertDatabaseHas('guardrail_config_events', ['field' => 'catalogue_page_edited']);

        $this->api('DELETE', "/admin/guardrails/catalogue/{$page['id']}", $super)->assertOk();
        $this->assertDatabaseHas('guardrail_catalogue_pages', ['id' => $page['id'], 'enabled' => false]);
        $this->assertDatabaseHas('guardrail_config_events', ['field' => 'catalogue_page_disabled']);
        $this->assertNotContains($page['slug'], array_column(GeneralLaneCatalogue::pages(), 'id'));
    }

    /** @return array<string,array{0:string}> */
    public static function rejectedUrls(): array
    {
        return [
            'host not on the allowlist' => ['https://www.example.com/page'],
            'lookalike suffix' => ['https://evilboe.es/page'],
            'allowlisted name as a path, not a host' => ['https://evil.example/boe.es/page'],
            'http, not https' => ['http://www.boe.es/page'],
            'credentials' => ['https://user:pass@www.boe.es/page'],
            'explicit port' => ['https://www.boe.es:8443/page'],
            'ip literal' => ['https://127.0.0.1/page'],
            'not a url' => ['boe.es/page'],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function test_a_url_outside_the_allowlist_is_rejected_at_write_and_nothing_is_stored(string $url): void
    {
        $super = $this->adminWithRole('super_admin');
        $before = GuardrailCataloguePage::count();

        $this->api('POST', '/admin/guardrails/catalogue', $super, ['title' => 'Página de prueba', 'url' => $url, 'topics' => ['algo']])
            ->assertStatus(422)->assertJsonPath('code', 'catalogue_url_rejected');
        $this->assertSame($before, GuardrailCataloguePage::count());

        $page = GuardrailCataloguePage::query()->first();
        $this->api('PATCH', "/admin/guardrails/catalogue/{$page->id}", $super, ['url' => $url])->assertStatus(422);
        $this->assertNotSame($url, $page->fresh()->url);
    }

    public function test_subdomains_of_an_allowlisted_domain_are_accepted(): void
    {
        $this->assertNull(GeneralLaneCatalogue::urlViolation('https://www.boe.es/buscar/act.php?id=X'));
        $this->assertNull(GeneralLaneCatalogue::urlViolation('https://sede.seg-social.es/x'));
        $this->assertNotNull(GeneralLaneCatalogue::urlViolation('https://seg-social.es.evil.com/x'));
    }

    public function test_the_allowlist_is_not_editable_through_any_endpoint(): void
    {
        $super = $this->adminWithRole('super_admin');
        $before = config('hr.general_lane.domains');
        $this->api('POST', '/admin/guardrails', $super, ['general_lane_domains' => ['evil.example']])->assertOk();
        $this->assertSame($before, GeneralLaneCatalogue::domains());
    }
}
