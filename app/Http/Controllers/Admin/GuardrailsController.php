<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGuardrailConfigRequest;
use App\Models\Admin;
use App\Models\GuardrailBlockedTopic;
use App\Models\GuardrailCataloguePage;
use App\Models\GuardrailConfig;
use App\Models\GuardrailConfigEvent;
use App\Services\ChatService;
use App\Services\GeneralLaneCatalogue;
use App\Services\GuardrailConfigService;
use App\Services\GuardrailPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Sprint-6 Guardrails console API (ADR-0019). READS are open to any admin
 * (auditor browses read-only — the oversight role). WRITES are gated by the
 * `guardrails.manage` ability (super_admin ONLY) in the route group.
 *
 * The SERVER is the boundary: every threshold write is checked against the
 * hardcoded floor and a below-floor value is REJECTED (422, not clamped); the
 * tone string is sanitized; every accepted change is audited; the hardcoded
 * GuardrailService baseline is never reachable from here. Proven by the
 * Sprint-6 invariant tests (hit endpoints directly).
 */
class GuardrailsController extends Controller
{
    public function __construct(
        private readonly GuardrailConfigService $service,
        private readonly GuardrailPolicy $policy,
    ) {}

    /** The full console state: each knob's admin value + inline hardcoded floor + effective value, the add-only lists, the convert policy, and the change history. */
    public function index(Request $request): JsonResponse
    {
        $config = GuardrailConfig::current();
        $actor = $request->user();
        $canManage = $actor !== null && method_exists($actor, 'can') && $actor->can('guardrails.manage');

        return response()->json([
            'can_manage' => $canManage,
            'thresholds' => [
                'retrieval_score_floor' => $this->thresholdView($config->retrieval_score_floor, 'hr.retrieval_score_floor', $this->policy->retrievalFloor()),
                'answer_confidence_floor' => $this->thresholdView($config->answer_confidence_floor, 'hr.answer_confidence_floor', $this->policy->confidenceFloor()),
                'router_confidence_floor' => $this->thresholdView($config->router_confidence_floor, 'hr.router_confidence_floor', $this->policy->routerFloor()),
            ],
            // Check C is a TIEBREAKER, not a primary gate — surfaced honestly so
            // the UI can say "raising it tightens the signal, but A and B are the
            // real gates" (architecture.md §5).
            'confidence_is_tiebreaker' => true,
            'off_domain_message' => [
                'value' => $config->off_domain_message,
                'default' => ChatService::ESCALATION_MESSAGE,
            ],
            'tone_constraints' => [
                'value' => $config->tone_constraints,
                'max_len' => GuardrailConfigService::TONE_MAX_LEN,
            ],
            'convert_by_reason' => [
                'baseline' => GuardrailPolicy::CONVERTIBLE_REASONS_BASELINE,
                'allowed' => $this->policy->convertAllowedReasons(),
                'locked' => ['sensitive_topic'], // never convertible — the floor
            ],
            // Sprint 13, step 9 (plan.md §B.6.6) — RESTRICT-only (the mirror
            // image of the threshold knobs above): `admin` can only narrow
            // `env_baseline`, never widen it. `env_baseline` is surfaced so
            // the UI can explain a toggle that reads as "on" but is actually
            // off because the deploy-level flag is off.
            'general_lane' => [
                'admin' => $config->general_lane_enabled,
                'env_baseline' => (bool) config('hr.general_lane.enabled', false),
                'effective' => $this->policy->generalLaneEnabled(),
            ],
            // Slice 13c (plan.md §2.6) — same restrict-only shape for "model knowledge as a lane source".
            'general_lane_model_knowledge' => [
                'admin' => $config->general_lane_model_knowledge_enabled,
                'env_baseline' => (bool) config('hr.general_lane.model_knowledge', false),
                'effective' => $this->policy->generalLaneModelKnowledgeEnabled(),
            ],
            // Slice 13c (plan.md §6) — the lane's official-page catalogue (data) + the fixed host allowlist (config).
            'general_lane_catalogue' => [
                'allowed_domains' => GeneralLaneCatalogue::domains(),
                'pages' => GuardrailCataloguePage::query()->orderBy('id')->get()->map(fn (GuardrailCataloguePage $p) => $this->cataloguePageView($p))->all(),
            ],
            'blocked_topics' => GuardrailBlockedTopic::query()
                ->orderByDesc('id')
                ->get()
                ->map(fn (GuardrailBlockedTopic $t) => [
                    'id' => $t->id,
                    'pattern' => $t->pattern,
                    'kind' => $t->kind,
                    'enabled' => $t->enabled,
                    'created_at' => $t->created_at?->toIso8601String(),
                    'disabled_at' => $t->disabled_at?->toIso8601String(),
                ])->all(),
            'history' => GuardrailConfigEvent::query()
                ->with('actor:id,full_name')
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(fn (GuardrailConfigEvent $e) => [
                    'field' => $e->field,
                    'old_value' => $e->old_value,
                    'new_value' => $e->new_value,
                    'actor' => $e->actor?->full_name,
                    'note' => $e->note,
                    'created_at' => $e->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    /**
     * Write the scalar/typed config (thresholds, off-domain message, tone,
     * convert-by-reason). RAISE-ONLY: a below-floor threshold is REJECTED (422,
     * not clamped); a tone string with gate-bypass phrasing is REJECTED. No write
     * + no audit row on rejection.
     */
    public function store(StoreGuardrailConfigRequest $request): JsonResponse
    {
        $data = $request->validated();

        // The invariant: reject (never clamp) a threshold below its hardcoded floor.
        $violation = $this->service->floorViolation($data);
        if ($violation !== null) {
            return response()->json([
                'code' => 'threshold_below_floor',
                'message' => 'El valor de «'.$violation['field'].'» ('.$violation['value'].') no puede ser '
                    .'inferior al mínimo de seguridad ('.$violation['floor'].'). Solo puede subirse, '
                    .'nunca bajarse por debajo del mínimo. No se ha guardado ningún cambio.',
                'field' => $violation['field'],
                'floor' => $violation['floor'],
            ], 422);
        }

        // The tone sanitizer: refuse to store a "style" string that tries to
        // unlock a gate (defense in depth; the structural guarantee is separate).
        if (array_key_exists('tone_constraints', $data)) {
            $phrase = $this->service->toneViolation($data['tone_constraints']);
            if ($phrase !== null) {
                return response()->json([
                    'code' => 'tone_override_rejected',
                    'message' => 'Las instrucciones de tono solo pueden definir estilo y formato. '
                        .'Se ha detectado una instrucción que intenta saltarse las comprobaciones de '
                        .'fundamentación («'.$phrase.'»). El tono no puede desbloquear ninguna verificación; '
                        .'no se ha guardado.',
                ], 422);
            }
        }

        /** @var Admin $actor */
        $actor = $request->user();
        $this->service->update($data, $actor);

        return $this->index($request);
    }

    /** Add an admin blocked-topic / off-domain trigger (add-only). */
    public function addBlockedTopic(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pattern' => ['required', 'string', 'min:2', 'max:120'],
            'kind' => ['required', 'string', 'in:blocked_topic,off_domain'],
        ]);

        /** @var Admin $actor */
        $actor = $request->user();
        $this->service->addBlockedTopic($data['pattern'], $data['kind'], $actor);

        return $this->index($request);
    }

    /** Soft-disable a trigger (never a hard delete; the baseline is never here). */
    public function disableBlockedTopic(int $id, Request $request): JsonResponse
    {
        $row = GuardrailBlockedTopic::findOrFail($id);

        /** @var Admin $actor */
        $actor = $request->user();
        $this->service->disableBlockedTopic($row, $actor);

        return $this->index($request);
    }

    /** Add an official page to the lane catalogue. The host must be on the fixed allowlist (422 otherwise). */
    public function addCataloguePage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'url' => ['required', 'string', 'max:500'],
            'topics' => ['required', 'array', 'min:1', 'max:20'],
            'topics.*' => ['string', 'min:2', 'max:80'],
        ]);
        if (($why = GeneralLaneCatalogue::urlViolation($data['url'])) !== null) {
            return response()->json(['code' => 'catalogue_url_rejected', 'message' => $why], 422);
        }

        /** @var Admin $actor */
        $actor = $request->user();
        $this->service->addCataloguePage($data, $actor);

        return $this->index($request);
    }

    /** Edit title / url / topics, or enable / soft-disable, a catalogue page (never a hard delete). */
    public function updateCataloguePage(int $id, Request $request): JsonResponse
    {
        $row = GuardrailCataloguePage::findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:3', 'max:160'],
            'url' => ['sometimes', 'string', 'max:500'],
            'topics' => ['sometimes', 'array', 'min:1', 'max:20'],
            'topics.*' => ['string', 'min:2', 'max:80'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['url']) && ($why = GeneralLaneCatalogue::urlViolation($data['url'])) !== null) {
            return response()->json(['code' => 'catalogue_url_rejected', 'message' => $why], 422);
        }

        /** @var Admin $actor */
        $actor = $request->user();
        $this->service->updateCataloguePage($row, $data, $actor);

        return $this->index($request);
    }

    /** DELETE = soft-disable (the row and its history stay). */
    public function disableCataloguePage(int $id, Request $request): JsonResponse
    {
        $row = GuardrailCataloguePage::findOrFail($id);

        /** @var Admin $actor */
        $actor = $request->user();
        $this->service->disableCataloguePage($row, $actor);

        return $this->index($request);
    }

    /** @return array<string,mixed> */
    private function cataloguePageView(GuardrailCataloguePage $p): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'title' => $p->title,
            'url' => $p->url,
            'topics' => $p->topics ?? [],
            'enabled' => $p->enabled,
            'baseline' => $p->baseline,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{admin:?float, floor:float, effective:float}
     */
    private function thresholdView(?float $admin, string $configKey, float $effective): array
    {
        return [
            'admin' => $admin,
            'floor' => (float) config($configKey),
            'effective' => $effective,
        ];
    }
}
