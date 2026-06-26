<?php

namespace App\Http\Requests;

use App\Models\ReferenceFact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a Structured Reference fact (Sprint 7b-1, ADR-0021).
 *
 * INVARIANT 1 (belt to the schema's braces): `authority_level` is accepted as
 * `structured_reference` ONLY — anything higher is REJECTED 422, never clamped
 * (the Sprint-6 reject-not-clamp discipline, ADR-0019). Combined with the enum
 * column (which physically cannot store official_convenio/national_law), a
 * reference fact can never outrank a convenio.
 *
 * Scope rides the convenio (data-model §5): territory/sector are DERIVED and are
 * `prohibited` from the request — a client can never set scope independently.
 * Facts bind into EXISTING vocabulary only (ADR-0011): every scope FK is
 * `exists:`; the topic must be APPROVED.
 *
 * The ability gate (`knowledge.edit`) is enforced by the route middleware, so
 * authorize() defers to it.
 */
class StoreReferenceFactRequest extends FormRequest
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
            'convenio_id' => ['required', 'integer', 'exists:convenios,id'],
            'job_category_id' => ['nullable', 'integer', 'exists:convenio_job_categories,id'],
            'topic_id' => ['nullable', 'integer', Rule::exists('topics', 'id')->where('status', 'approved')],
            'value' => ['required', 'string', 'max:5000'],
            'raw_values' => ['sometimes', 'nullable', 'array'],
            'validity_start' => ['sometimes', 'nullable', 'date'],
            'validity_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:validity_start'],
            // INVARIANT 1 — never higher than the structured-reference floor.
            'authority_level' => ['sometimes', Rule::in([ReferenceFact::AUTHORITY_LEVEL])],
            'source_document_id' => ['nullable', 'integer', 'exists:documents,id'],
            'source_locator' => ['nullable', 'string', 'max:255'],
            // Territory/sector are DERIVED from the convenio — never client-set.
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
