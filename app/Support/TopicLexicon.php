<?php

namespace App\Support;

/**
 * The shared HR topic lexicon (Sprint 2b-2 Correction-03, relocated in 7c).
 *
 * The anchor map was introduced for the widened-pool precedence re-rank
 * (ChatService): two chunks "cover the same topic" when both contain an anchor
 * term of the same topic. Sprint 7c's deterministic reference-fact pre-check
 * reuses the SAME lexicon to map a QUESTION to a topic — no new classifier, no
 * LLM (ADR-0023, Q1). Lifting the const here is a PURE RELOCATION: the re-rank's
 * inputs/outputs are unchanged (ChatService::chunkTopics now delegates here), as
 * the golden-trace regression test proves.
 *
 * Matching is accent-insensitive on lowercased text.
 */
class TopicLexicon
{
    /**
     * topic_key => anchor terms. Used by the precedence re-rank (chunk content)
     * AND the 7c reference-fact pre-check (question text).
     *
     * @var array<string, list<string>>
     */
    public const ANCHORS = [
        'vacaciones' => ['vacaciones', 'vacacional', 'periodo vacacional'],
        'jornada' => ['jornada', 'horas anuales', 'computo anual', 'horario de trabajo'],
        'permisos' => ['permiso', 'permisos', 'licencia', 'licencias', 'dias de asuntos propios', 'asuntos propios'],
        'excedencia' => ['excedencia', 'excedencias'],
        'periodo_prueba' => ['periodo de prueba', 'período de prueba'],
        'trabajo_distancia' => ['trabajo a distancia', 'teletrabajo'],
        'horas_extra' => ['horas extraordinarias', 'horas extra'],
        'preaviso' => ['preaviso'],
        'lactancia' => ['lactancia'],
        'maternidad' => ['maternidad', 'paternidad', 'nacimiento', 'adopcion'],
        'descanso' => ['descanso semanal', 'descanso diario', 'dias de descanso'],
        'festivos' => ['festivos', 'dias festivos', 'fiestas laborales'],
        'movilidad' => ['movilidad geografica', 'traslado', 'desplazamiento'],
        'antiguedad' => ['antiguedad', 'trienios', 'quinquenios'],
        'despido' => ['despido', 'extincion del contrato', 'finiquito'],
        'ascensos' => ['ascensos', 'promocion', 'clasificacion profesional'],
    ];

    /**
     * topic_key => the approved `topics.name` it maps to (Sprint 7c, Q1).
     *
     * The bridge between an anchored question and a real `reference_facts.topic_id`:
     * the pre-check looks up an APPROVED topic by these names (accent-insensitive).
     * A topic_key with no approved topic row simply falls through safely (no fact
     * is reachable). No alias/slug column is added to `topics` (Q1).
     *
     * @var array<string, string>
     */
    public const TOPIC_NAMES = [
        'vacaciones' => 'vacaciones',
        'jornada' => 'jornada',
        'permisos' => 'permisos',
        'excedencia' => 'excedencia',
        'periodo_prueba' => 'periodo de prueba',
        'trabajo_distancia' => 'trabajo a distancia',
        'horas_extra' => 'horas extraordinarias',
        'preaviso' => 'preaviso',
        'lactancia' => 'lactancia',
        'maternidad' => 'maternidad',
        'descanso' => 'descanso',
        'festivos' => 'festivos',
        'movilidad' => 'movilidad geografica',
        'antiguedad' => 'antiguedad',
        'despido' => 'despido',
        'ascensos' => 'ascensos',
    ];

    /**
     * The set of topic_keys whose anchors appear in the text (accent-insensitive,
     * lowercased). Returns a map topic_key => true.
     *
     * @return array<string, true>
     */
    public static function matchTopicKeys(string $text): array
    {
        $hay = self::stripAccents(mb_strtolower($text));
        $topics = [];
        foreach (self::ANCHORS as $topic => $anchors) {
            foreach ($anchors as $anchor) {
                if (str_contains($hay, self::stripAccents($anchor))) {
                    $topics[$topic] = true;
                    break;
                }
            }
        }

        return $topics;
    }

    /**
     * The approved-topic NAMES a question anchors to (Sprint 7c pre-check). Maps
     * each matched topic_key through TOPIC_NAMES; a key with no name is skipped.
     *
     * @return list<string> lowercased approved-topic names (for a case-insensitive lookup)
     */
    public static function candidateTopicNames(string $text): array
    {
        $names = [];
        foreach (array_keys(self::matchTopicKeys($text)) as $key) {
            if (isset(self::TOPIC_NAMES[$key])) {
                $names[] = self::TOPIC_NAMES[$key];
            }
        }

        return array_values(array_unique($names));
    }

    /** Lowercase, accent-stripped form for matching (treinta = treinta). */
    public static function stripAccents(string $s): string
    {
        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
    }
}
