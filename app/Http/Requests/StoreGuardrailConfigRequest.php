<?php

namespace App\Http\Requests;

use App\Services\GuardrailConfigService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for a guardrail-config write (Sprint 6, ADR-0019). This
 * enforces TYPES and ranges only; the RAISE-ONLY floor check (reject-below-floor,
 * 422 + a clear `code`/message) and the tone sanitizer run in the controller via
 * GuardrailConfigService so they can return the precise invariant message —
 * exactly the established 409/422 `code`+`message` posture (Sprint 3/4/5).
 *
 * The ability gate (`guardrails.manage`, super_admin only) is enforced by the
 * route middleware, so authorize() defers to it.
 *
 * Partial updates: only the keys PRESENT in the request are validated/applied
 * (Laravel's validated() returns the present subset), so a section save touches
 * only its own fields. A `null` value is an explicit "revert/clear".
 */
class StoreGuardrailConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: ability:guardrails.manage
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Numeric thresholds are bounded 0..1 here (a cosine/confidence scale);
        // the FLOOR (lower bound that can't be crossed) is checked in the
        // controller against config/hr.php and rejected with the invariant message.
        return [
            'retrieval_score_floor' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'answer_confidence_floor' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'router_confidence_floor' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'off_domain_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tone_constraints' => ['sometimes', 'nullable', 'string', 'max:'.GuardrailConfigService::TONE_MAX_LEN],
            'convert_allowed_reasons' => ['sometimes', 'nullable', 'array'],
            'convert_allowed_reasons.*' => ['string', 'max:64'],
        ];
    }
}
