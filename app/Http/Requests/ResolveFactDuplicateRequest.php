<?php

namespace App\Http\Requests;

use App\Services\FactResolutionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 7d (ADR-0024) — resolve a flagged reference-fact duplicate pair.
 *
 * The allowed action set is validated HERE rather than as a DB enum: a Laravel
 * enum becomes a Postgres CHECK, and adding a value later would need the
 * introspect-drop-readd migration 7b-2 had to write for `reference_facts.status`.
 * Application-level validation is cheap to extend and equally strict at the edge.
 *
 * `newer_uuid` is REQUIRED for a supersede and is deliberately not inferable: the
 * service refuses (422) if the validity dates do not support the claimed
 * direction, and neither `id` nor `created_at` order tells you which VERSION is
 * newer (a 2024 document can be ingested after a 2026 one).
 */
class ResolveFactDuplicateRequest extends FormRequest
{
    /** Route-level `ability:knowledge.edit` is the gate; no per-object rule here. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:'.implode(',', [
                FactResolutionService::SUPERSEDE,
                FactResolutionService::COEXIST,
                FactResolutionService::REJECT,
            ])],
            // Which of the pair is the NEWER version. Required for supersede so the
            // direction is a human's statement, never a guess.
            'newer_uuid' => ['required_if:action,'.FactResolutionService::SUPERSEDE, 'nullable', 'string', 'uuid'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'newer_uuid.required_if' => 'Indica cuál de los dos hechos es la versión más reciente: '
                .'la dirección de una sustitución nunca se deduce automáticamente.',
        ];
    }
}
