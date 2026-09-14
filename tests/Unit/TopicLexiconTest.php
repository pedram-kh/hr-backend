<?php

namespace Tests\Unit;

use App\Support\TopicLexicon;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 10c — locks `TopicLexicon::TOPIC_NAMES` against the REAL approved
 * `topics` rows (live-DB checked, staging, 2026-09-13 — 12 rows, all
 * approved: periodo de prueba, jornada, vacaciones, festivos, permisos
 * retribuidos, bajas médicas, conciliación, excedencias, retribución,
 * permisos no retribuidos, normativa/derechos, formación).
 *
 * Two of these were previously WRONG — 'permisos' => 'permisos' and
 * 'excedencia' => 'excedencia' — a class of bug that is fail-safe by design
 * (ADR-0016: an unmatched name just falls through silently, never errors),
 * which is exactly why it went undetected until Sprint 10c's driver became
 * the first thing to actually exercise these two mappings. This test exists
 * so a THIRD instance of the same silent-mismatch bug cannot land again:
 * every key this class claims to map to an approved topic is asserted here
 * against the real name, not just spot-checked by hand.
 */
class TopicLexiconTest extends TestCase
{
    /** The 12 real approved `topics.name` values (live-DB checked, staging). */
    private const REAL_APPROVED_TOPIC_NAMES = [
        'periodo de prueba',
        'jornada',
        'vacaciones',
        'festivos',
        'permisos retribuidos',
        'bajas médicas',
        'conciliación',
        'excedencias',
        'retribución',
        'permisos no retribuidos',
        'normativa/derechos',
        'formación',
    ];

    public function test_permisos_maps_to_the_real_approved_name(): void
    {
        // The Sprint 10c CP-A finding: 'permisos' (singular, no such row)
        // fixed to 'permisos retribuidos' (the real row).
        $this->assertSame('permisos retribuidos', TopicLexicon::TOPIC_NAMES['permisos']);
    }

    public function test_excedencia_maps_to_the_real_approved_name(): void
    {
        // The same bug class, fixed under the same discipline: 'excedencia'
        // (singular, no such row) -> 'excedencias' (the real row, plural).
        $this->assertSame('excedencias', TopicLexicon::TOPIC_NAMES['excedencia']);
    }

    /**
     * The subset of TOPIC_NAMES keys that DO correspond to a real approved
     * row today (live-DB checked). This is deliberately NOT "every key must
     * match or be on an exception list": most unmatched keys here
     * (trabajo_distancia, lactancia, maternidad, movilidad, antiguedad,
     * despido, ascensos, horas_extra, preaviso, descanso) are topics that
     * simply do not exist as approved rows YET — that is a normal, safe
     * state by this class's own design (falls through, never answers), not
     * a bug. Only permisos/excedencia were bugs, because in THOSE two cases
     * the real row already existed under a different string. This test
     * locks in exactly which keys are live-resolvable today, so a future
     * topic's creation (or a future rename) is a deliberate, visible diff
     * here rather than a silent behavior change either way.
     */
    public function test_the_currently_live_resolvable_keys_match_the_real_topics_table(): void
    {
        $liveResolvable = [
            'vacaciones', 'jornada', 'permisos', 'excedencia', 'periodo_prueba', 'festivos',
        ];

        foreach ($liveResolvable as $key) {
            $this->assertContains(
                TopicLexicon::TOPIC_NAMES[$key],
                self::REAL_APPROVED_TOPIC_NAMES,
                "TopicLexicon::TOPIC_NAMES['{$key}'] was expected to resolve to a real approved topic.",
            );
        }

        // The remaining keys are the ones NOT yet backed by a real row — this
        // sprint's review.md finding 2 (preaviso/descanso/horas_extra) plus
        // the others never in scope for any tranche checkpoint yet. Asserted
        // as a set-difference so an actual future fix (or a newly-created
        // topic row) shows up here as an intentional test update, not as an
        // untracked change.
        $stillUnresolved = array_values(array_diff(array_keys(TopicLexicon::TOPIC_NAMES), $liveResolvable));
        sort($stillUnresolved);
        $this->assertSame(
            ['antiguedad', 'ascensos', 'descanso', 'despido', 'horas_extra', 'lactancia', 'maternidad', 'movilidad', 'preaviso', 'trabajo_distancia'],
            $stillUnresolved,
        );
    }

    public function test_key_for_topic_name_is_the_exact_reverse_of_topic_names(): void
    {
        foreach (TopicLexicon::TOPIC_NAMES as $key => $name) {
            $this->assertSame(
                $key,
                TopicLexicon::keyForTopicName($name),
                "keyForTopicName('{$name}') should reverse-resolve to '{$key}'.",
            );
        }
    }

    public function test_key_for_topic_name_is_accent_and_case_insensitive(): void
    {
        $this->assertSame('excedencia', TopicLexicon::keyForTopicName('EXCEDENCIAS'));
        $this->assertSame('permisos', TopicLexicon::keyForTopicName('Permisos Retribuidos'));
    }

    public function test_key_for_topic_name_returns_null_for_an_unmapped_name(): void
    {
        $this->assertNull(TopicLexicon::keyForTopicName('some name nobody uses'));
    }
}
