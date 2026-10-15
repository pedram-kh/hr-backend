<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\Territory;
use App\Support\DocumentTagger;
use App\Support\FilenameParser;
use App\Support\VocabularyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 12c option B. COEAS, DEPORTE and HOSTELERIA do not raise a sector
 * conflict when the filename numero already matched a convenio. Any other
 * disagreeing sector still does. A file with no numero is unchanged.
 */
class Slice12cSectorAliasTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    protected function setUp(): void
    {
        parent::setUp();

        DocumentType::firstOrCreate(['code' => 'convenio_text'], ['name' => 'Texto']);
        $territory = Territory::create(['code' => '01', 'name' => 'Araba', 'level' => 'provincial', 'aliases' => []]);
        $registry = Sector::create(['name' => 'OCIO EDUCATIVO Y ANIMACION SOCIOCULTURAL', 'aliases' => []]);
        foreach (['COEAS', 'DEPORTE', 'HOSTELERIA', 'METALURGIA'] as $name) {
            Sector::create(['name' => $name, 'aliases' => []]);
        }
        $this->convenio = Convenio::create([
            'numero' => '99100055012011',
            'name' => 'COEAS Estatal',
            'territory_id' => $territory->id,
            'sector_id' => $registry->id,
        ]);
    }

    public function test_each_reviewed_alias_skips_the_sector_conflict(): void
    {
        foreach (DocumentTagger::SECTOR_CONFLICT_ALIASES as $alias) {
            $tag = $this->tag("99100055012011_{$alias}_20252027.pdf");

            $this->assertSame($this->convenio->id, $tag['convenio_id'], $alias);
            $this->assertNull($tag['review'], $alias);
            $this->assertSame('auto_proposed', $tag['tagging_status'], $alias);
        }
    }

    public function test_a_non_alias_disagreeing_sector_still_conflicts(): void
    {
        $tag = $this->tag('99100055012011_METALURGIA_20252027.pdf');

        $this->assertSame($this->convenio->id, $tag['convenio_id']);
        $this->assertSame('under_review', $tag['tagging_status']);
        $this->assertSame('conflict', $tag['review']['reason']);
        $this->assertSame('sector', $tag['review']['conflict_facets'][0]['facet']);
        $this->assertStringContainsString('METALURGIA', $tag['review']['conflict_facets'][0]['note']);
    }

    public function test_a_numero_less_file_is_unaffected(): void
    {
        $tag = $this->tag('HOSTELERIA_20252027.pdf');

        $this->assertNull($tag['convenio_id']);
        $this->assertSame('under_review', $tag['tagging_status']);
        $this->assertSame('unresolved', $tag['review']['reason']);
        $this->assertSame([], $tag['review']['conflict_facets']);
        $notes = array_column($tag['review']['raw_unmatched_values'], 'value');
        foreach ($notes as $note) {
            $this->assertStringNotContainsString('disagrees with registry', (string) $note);
        }
    }

    /** @return array<string,mixed> */
    private function tag(string $filename): array
    {
        $parsed = (new FilenameParser)->parse($filename, null);

        return (new DocumentTagger(new VocabularyResolver))->tag($parsed);
    }
}
