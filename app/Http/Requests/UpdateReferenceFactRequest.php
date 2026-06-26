<?php

namespace App\Http\Requests;

use App\Models\ReferenceFact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bounded edit of a reference fact (Sprint 7b-1, ADR-0021). Same invariants as
 * the create request: authority can only stay at the structured-reference floor
 * (422 otherwise — INVARIANT 1); territory/sector are prohibited (derived). A
 * scope-affecting edit (convenio / job_category / validity — the columns that
 * decide which employees a fact would answer, once 7c exists) requires
 * `confirm_scope_change=true`, else 409 — reusing the Sprint-3 bounded-edit gate.
 * Only the keys PRESENT are validated/applied (Laravel `validated()` subset).
 */
class UpdateReferenceFactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: ability:knowledge.edit
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'convenio_id' => ['sometimes', 'integer', 'exists:convenios,id'],
            'job_category_id' => ['sometimes', 'nullable', 'integer', 'exists:convenio_job_categories,id'],
            'topic_id' => ['sometimes', 'nullable', 'integer', Rule::exists('topics', 'id')->where('status', 'approved')],
            'value' => ['sometimes', 'string', 'max:5000'],
            'raw_values' => ['sometimes', 'nullable', 'array'],
            'validity_start' => ['sometimes', 'nullable', 'date'],
            'validity_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:validity_start'],
            'authority_level' => ['sometimes', Rule::in([ReferenceFact::AUTHORITY_LEVEL])],
            'source_document_id' => ['sometimes', 'nullable', 'integer', 'exists:documents,id'],
            'source_locator' => ['sometimes', 'nullable', 'string', 'max:255'],
            'confirm_scope_change' => ['sometimes', 'boolean'],
            'territory_id' => ['prohibited'],
            'sector_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'authority_level.in' => 'A reference fact may only carry the structured_reference authority level — it can never outrank an official convenio.',
            'territory_id.prohibited' => 'Territory is derived from the convenio and cannot be set directly.',
            'sector_id.prohibited' => 'Sector is derived from the convenio and cannot be set directly.',
        ];
    }
}
