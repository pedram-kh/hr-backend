<?php

namespace App\Services\Agent;

/**
 * Sprint 13, build step 6 — `escalate`/`finalize` schemas (plan.md §C.8's
 * last two tools). Not `ToolRegistry` entries: `AgentChatService` recognizes
 * them by name in `processRound()` (§B.3.8/§B.5). Shared here so
 * `agent:replay` can send the SAME allowlist the live loop sent, without
 * constructing the whole chat service.
 *
 * @return list<array{name:string,description:string,input_schema:array<string,mixed>}>
 */
final class ControlTools
{
    /**
     * Sprint 13b (plan.md §2.2) — `normalize_question`, offered on the planner's FIRST round only
     * (`AgentChatService::loop()`), never part of `definitions()` so every other round, `agent:replay`
     * of pre-13b traces and the tool allowlist tests are unchanged. The text is mirrored, byte for
     * byte, in hr-ai `app/planner/tools.py` (which is what is actually sent to the model).
     *
     * @return array{name:string,description:string,input_schema:array<string,mixed>}
     */
    public static function normalizationDefinition(): array
    {
        return [
            'name' => 'normalize_question',
            'description' => 'Declara cómo entiendes la pregunta, SOLO para las herramientas. La persona '
                .'nunca lo ve y no cambia lo que se le responde ni qué reglas se aplican. Llámala UNA vez, en la primera '
                .'ronda, junto a tu primera herramienta. Si la pregunta trata varios temas, o es un caso personal más '
                .'que una consulta de dato, pon topic_id y canonical_query a null. '
                .'topic_id: el id del tema de "approved_topics" (en Alcance) que corresponde claramente, o null. '
                .'canonical_query: la MISMA pregunta como sintagma nominal en vocabulario de convenio o de ley '
                .'(máx. 25 palabras), o null. Solo reformula lo que la persona ya dijo. NO añadas cifras, importes, '
                .'fechas ni años. NO uses: "derecho a", "corresponde", "mínimo", "máximo", "plazo de", "al año", "cada". '
                .'NO nombres grupos, niveles, categorías, convenios, provincias ni territorios. '
                .'confidence: entre 0 y 1. reason: una línea (la verá RR. HH., no la persona).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'topic_id' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
                    'canonical_query' => ['anyOf' => [['type' => 'string', 'maxLength' => 220], ['type' => 'null']]],
                    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'reason' => ['type' => 'string', 'maxLength' => 160],
                ],
                'required' => ['topic_id', 'canonical_query', 'confidence', 'reason'],
                'additionalProperties' => false,
            ],
        ];
    }

    public static function definitions(): array
    {
        return [
            [
                'name' => 'escalate',
                'description' => 'Deriva a RR. HH. cuando la pregunta no sea de RR. HH./laboral, pida una '
                    .'valoración de un caso personal, no pueda responderse con las herramientas, o dudes. '
                    .'Indica la categoría y un motivo breve (lo verá RR. HH., no la persona).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => [
                            'type' => 'string',
                            'enum' => ['off_domain', 'unsafe', 'unanswerable', 'needs_human_judgement', 'other'],
                        ],
                        'reason' => ['type' => 'string', 'maxLength' => 300],
                    ],
                    'required' => ['category', 'reason'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'finalize',
                'description' => 'Termina cuando tengas el material necesario. Indica qué resultados usar. '
                    .'No redactes la respuesta: el sistema la redacta solo a partir de las fuentes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'use' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Nombres de las herramientas cuyo resultado se debe usar.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
