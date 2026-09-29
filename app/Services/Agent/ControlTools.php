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
