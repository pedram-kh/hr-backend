<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Sprint 13 (CP-1 decision) — structural guard on the curated general-lane
 * catalogue in `config/hr.php`. The content was approved by a human after a
 * real fetch of every page; this test only stops a later edit from making the
 * catalogue unsafe or ambiguous (non-https, off-allowlist, duplicate ids,
 * empty topics). It never fetches anything.
 */
class Sprint13GeneralLaneCatalogueTest extends TestCase
{
    public function test_every_source_is_https_on_an_allowlisted_domain(): void
    {
        $domains = config('hr.general_lane.domains');
        $this->assertEqualsCanonicalizing(['boe.es', 'mites.gob.es', 'seg-social.es', 'sepe.es'], $domains);

        foreach (config('hr.general_lane.sources') as $source) {
            $parts = parse_url($source['url']);
            $this->assertSame('https', $parts['scheme'] ?? null, $source['id'].' must be https');
            $host = strtolower($parts['host'] ?? '');
            $ok = false;
            foreach ($domains as $d) {
                if ($host === $d || str_ends_with($host, '.'.$d)) {
                    $ok = true;
                }
            }
            $this->assertTrue($ok, $source['id'].' host '.$host.' is not allowlisted');
        }
    }

    public function test_ids_are_unique_and_entries_are_well_formed(): void
    {
        $sources = config('hr.general_lane.sources');
        $ids = array_column($sources, 'id');

        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertCount(5, $sources, 'CP-1 approved exactly rows 1, 2, 4, 5, 6');

        foreach ($sources as $source) {
            $this->assertNotSame('', trim($source['title']));
            $this->assertNotEmpty($source['topics'], $source['id'].' needs topic terms');
            foreach ($source['topics'] as $topic) {
                $this->assertSame(mb_strtolower($topic), $topic, 'topics are lower-case');
                $this->assertGreaterThanOrEqual(4, mb_strlen($topic), 'a topic shorter than 4 chars can never match');
            }
        }
    }

    public function test_the_dead_pre_cp1_entries_are_gone(): void
    {
        $ids = array_column(config('hr.general_lane.sources'), 'id');

        foreach (['sepe-excedencias', 'segsocial-incapacidad-temporal', 'mites-permisos'] as $dead) {
            $this->assertNotContains($dead, $ids);
        }
    }
}
