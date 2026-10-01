<?php

namespace App\Services;

use App\Models\AnswerModelSetting;
use App\Models\Employee;
use App\Services\Answer\PreModelGuards;
use App\Services\Answer\ProsePath;
use App\Services\Answer\ReferenceFactPath;
use App\Services\Answer\SalaryPath;
use App\Services\Answer\SessionResolver;
use App\Services\Answer\TurnOutcome;
use App\Services\Answer\TurnPersister;
use Illuminate\Support\Carbon;

/**
 * The answer pipeline orchestrator. Sprint 2b-2 completes the answer surface:
 * scope resolve → guardrail baseline → ROUTER → {salary SQL | prose | off-domain}.
 * Everything here runs in hr-backend EXCEPT the hr-ai calls (/route, /retrieve,
 * /synthesise, /ground). hr-backend resolves scope deterministically, owns the
 * answer-or-escalate DECISION (legal weight), and owns ALL DB writes (ADR-0007).
 *
 * Pipeline (architecture.md §2/§5, ADR-0007/0015/0016):
 *   1. resolve scope (deterministic SQL — no LLM)
 *   2. guardrail baseline (deterministic; fires BEFORE any hr-ai call — sensitive
 *      / legal-medical / other-employee escalate first; the router never sees them)
 *   3. ROUTER (ADR-0016): salary | prose | off_domain. A deterministic salary
 *      pre-classifier short-circuits obvious salary (no LLM); else the small-model
 *      /route call. FAIL-SAFE: uncertainty/error/no-key → safe prose path.
 *   4a. SALARY → SalaryAnswerService (SQL; exact, year-aligned; ADR-0006) → answer
 *       / constrained category pick / coverage-gap escalate.
 *   4b. PROSE → recall-hardened /retrieve (subquery union + national-law pass) →
 *       pre-synthesis floor (Check A) → /synthesise → A∧B → figure-guard pre-check
 *       → /ground per-claim entailment GATE (§5).
 *   4c. OFF-DOMAIN → escalate (off_domain).
 *   5. persist (session, messages, citations, trace incl. router_decision +
 *      grounding + salary blocks; escalation_card on escalate — NOT on a pick).
 *
 * Sprint 13, build step 1 (plan.md §B.1): this class is now a thin orchestrator.
 * Every path that used to end in `return $this->persistTurn(...)` is extracted,
 * VERBATIM, into `App\Services\Answer\*` — {@see PreModelGuards} (the guardrail
 * baseline / admin block / explicit_request pre-checks), {@see SalaryPath},
 * {@see ReferenceFactPath}, {@see ProsePath}, and {@see TurnPersister} (the old
 * `persistTurn()` + `decorate()`). `handleMessage()` keeps its exact original
 * order — guards → reference-fact pre-check → router → one of the three paths →
 * persist — it just delegates each step instead of inlining it. No condition,
 * constant, or trace key was edited; see `hr-docs/sprints/sprint-13/review.md`
 * for the extraction diff summary and the golden-trace proof this holds.
 */
class ChatService
{
    /** Surfaced to the employee on escalation (design-system voice). */
    public const ESCALATION_MESSAGE = 'No estoy seguro de la respuesta a esta pregunta, '
        .'así que la estoy pasando a una persona del equipo de Recursos Humanos.';

    /**
     * Sprint 7g Item 1 (ADR-0029) — THE ONE fixed neutral message shown to the
     * employee on EVERY escalation, regardless of reason. `TurnPersister::persist()`
     * is the single point that enforces this: it OVERRIDES whatever per-reason
     * copy a caller passed in (this constant, AGGREGATION_MESSAGE,
     * CROSSPATH_MESSAGE, COMPOSITION_CONFLICT_MESSAGE, either
     * COVERAGE_GAP_MESSAGE, an admin-configured off_domain refusal, …) with
     * this string before it is persisted or returned. No reason code, no
     * document id, no convenio/topic name, no other person's name — nothing
     * that hints at scope or coverage ever reaches the employee. The per-reason
     * strings above remain as historical/internal call-site documentation of
     * WHY each path escalates; only THIS constant is ever shown.
     */
    public const EMPLOYEE_ESCALATION_MESSAGE = 'Un/a compañero/a de Recursos Humanos revisará tu '
        .'consulta y te responderá.';

    /**
     * Slice 13e (ADR-0039) — the employee-visible text of a DECLINED turn (a confirmed off-domain question; no card). The
     * persister is the only place that picks it: `GuardrailPolicy::offDomainMessage()` (the admin's own Guardarraíles copy,
     * live again for the first time since ADR-0029) or this constant. It names no reason, rule, pattern or person; the
     * "if you think it is a work question, ask HR to review it" line is frontend-only, so an admin edit can never remove it.
     */
    public const DECLINE_MESSAGE = 'Soy el asistente de RR. HH. y solo puedo ayudarte con dudas sobre tu trabajo y tu convenio. '
        .'Esta consulta queda fuera de lo que puedo responder.';

    /** `floor_decision.fallback` value — the only one there is (Sprint 10a). */
    public const FALLBACK_ESTATUTO_GAP = 'estatuto_gap';

    /**
     * Sprint 10a (ADR-0032) — appended to every answer built on the Estatuto
     * fallback, verbatim, by deterministic hr-backend code.
     *
     * The AI never writes, edits or decides to include this (ADR-0015/0016).
     * It is a fixed string concatenated after synthesis and after every gate,
     * so it cannot influence `/ground` (it is not a claim the model made and is
     * not checked as one) and cannot be paraphrased away by the model.
     *
     * What it must convey, and why each part is there: the answer comes from
     * the Estatuto de los Trabajadores (source), that is the legal MINIMUM
     * (ceiling caveat — a convenio may only improve on it), and where to go to
     * confirm the concrete case. It names no document id, no convenio, and no
     * internal system state (Correction-01/E2 — the earlier wording ("todavía
     * no está cargado en el sistema") named internal state; the earlier
     * `---`/`**…**` separator and bold were raw markdown in a plain-text
     * employee surface. Plain text, no markdown, no separator line.)
     */
    public const FALLBACK_CAVEAT = "\n\n"
        .'Esta respuesta se basa en el Estatuto de los Trabajadores, que establece los '
        .'mínimos legales para cualquier persona trabajadora. Tu convenio colectivo puede '
        .'mejorar estas condiciones (nunca empeorarlas). Para confirmar lo que se aplica en '
        .'tu caso concreto, consulta con Recursos Humanos.';

    /** `floor_decision.path` value for a `general_knowledge`-lane answer (Sprint 13, step 9). */
    public const GENERAL_LANE_PATH = 'general_knowledge';

    /**
     * Sprint 13, build step 9 (plan.md §B.6.6) — appended to every
     * `general_knowledge`-lane answer, verbatim, by deterministic
     * hr-backend code, same discipline/placement as `FALLBACK_CAVEAT`
     * (`TurnPersister::decorate()`): after synthesis, after every gate
     * (grounding, `GeneralLanePostCheck`) has already passed on the
     * UNDECORATED answer, so it can never be mistaken for a model claim,
     * checked by `/ground`, or paraphrased away. The frontend strips this
     * trailing sentence from the DISPLAYED bubble and renders it as a badge
     * instead (`stripSourceMarkers` precedent) — the persisted `answer`
     * column always carries the full, undecorated-then-decorated text.
     */
    public const GENERAL_LANE_CAVEAT = "\n\n"
        .'Información general — no procede de tu convenio ni de la normativa cargada.';

    /**
     * Slice 13c (plan.md §2.5) — the caveat for a `basis = model_knowledge` lane answer (no page was consulted). Same
     * placement/discipline as {@see self::GENERAL_LANE_CAVEAT}. Deliberately free of digits and of the entitlement vocabulary
     * the post-check watches (`derecho`, `corresponde`, …): a unit test scans it with `scan()` and `audit()`.
     */
    public const GENERAL_LANE_MODEL_CAVEAT = "\n\n"
        .'Información general, redactada sin consultar tu convenio ni la normativa cargada y sin una fuente verificable. '
        .'No describe lo que se te aplica a ti: consúltalo en tu convenio o con Recursos Humanos.';

    /** Surfaced on a vague "total días libres" aggregation (Correction-03, Fix 2). */
    public const AGGREGATION_MESSAGE = 'Para darte una cifra fiable necesito que me preguntes por un '
        .'tipo concreto de días libres (por ejemplo, las vacaciones, los días de asuntos propios o un '
        .'permiso específico). Sumar todos los tipos en un único "total" no es un dato que pueda '
        .'fundamentar con exactitud en tu convenio, así que te derivo con una persona del equipo de '
        .'Recursos Humanos.';

    /** Surfaced on a salary+prose cross-path compound (Correction-03, Fix 3). */
    public const CROSSPATH_MESSAGE = 'Tu pregunta combina dos cosas: la parte salarial puedo '
        .'consultarla en las tablas, pero también preguntas por otro tema (por ejemplo, las vacaciones) '
        .'que necesita una persona del equipo de Recursos Humanos. Te derivo para que te respondan ambas '
        .'partes correctamente.';

    /**
     * Surfaced on a fact-vs-convenio same-point conflict during composition
     * (Sprint 7c Phase 2, ADR-0023). The convenio always governs; a genuine
     * same-point conflict escalates — NEVER blends, never silently prefers the
     * structured fact over the governing convenio.
     */
    public const COMPOSITION_CONFLICT_MESSAGE = 'Sobre este tema tengo un dato de referencia y también '
        .'lo que dice tu convenio, y no coinciden. Como tu convenio es el que manda, no quiero darte una '
        .'cifra mezclada o equivocada: te derivo con una persona del equipo de Recursos Humanos para que '
        .'te lo confirme con exactitud.';

    /**
     * Surfaced on a statutory salary figure (SMI/salario mínimo), Sprint 10b
     * Correction-01. Deliberately distinct from `SalaryAnswerService::
     * COVERAGE_GAP_MESSAGE` — that message says "no tengo tu tabla salarial",
     * which would be FALSE here (the employee may well have one); this says
     * the figure asked for was never going to be in it.
     */
    public const STATUTORY_SALARY_MESSAGE = 'El SMI (salario mínimo interprofesional) es una cifra '
        .'legal general que fija el Estado, no un dato de tu tabla salarial estructurada — no quiero '
        .'confirmártelo desde aquí con una cifra que no sea la tuya. Te derivo con una persona del '
        .'equipo de Recursos Humanos.';

    public function __construct(
        private readonly RouterService $router,
        private readonly GuardrailPolicy $policy,
        private readonly ReferenceFactRouter $referenceFactRouter,
        private readonly PreModelGuards $preModelGuards,
        private readonly SalaryPath $salaryPath,
        private readonly ReferenceFactPath $referenceFactPath,
        private readonly ProsePath $prosePath,
        private readonly TurnPersister $persister,
        private readonly SessionResolver $sessionResolver,
    ) {}

    /**
     * Handle one employee turn end to end. Always persists both messages + a full
     * trace; creates an escalation_card on any escalate outcome (NOT on a category
     * pick). `$selectedJobCategoryId` is the unverified category from a salary
     * disambiguation follow-up (§4).
     *
     * @return array<string,mixed> the response payload for the API/UI
     */
    public function handleMessage(Employee $employee, string $question, ?string $sessionUuid = null, ?int $selectedJobCategoryId = null): array
    {
        $asOfDate = Carbon::today();
        $session = $this->sessionResolver->resolve($employee, $sessionUuid);

        $employee->loadMissing('convenio');

        $trace = [
            'profile' => [
                'employee_uuid' => $employee->uuid,
                'convenio_id' => $employee->convenio_id,
                'convenio_numero' => $employee->convenio?->numero,
                'territory_id' => $employee->territory_id,
                'job_category_id' => $employee->job_category_id,
            ],
            'scope_filters' => [
                'convenio_id' => $employee->convenio_id,
                'include_national_law' => true,
                'retrieval_status' => ['active'],
                'as_of_date' => $asOfDate->toDateString(),
            ],
            'router_decision' => null,
            'guardrail_check' => ['fired' => false, 'reason' => null, 'rule' => null],
        ];

        // --- Steps 2/2b/2c: guardrail baseline, admin block, explicit_request --
        // Deterministic, no hr-ai call, in that exact order — see PreModelGuards.
        $guarded = $this->preModelGuards->check($question, $trace, $session);
        if ($guarded !== null) {
            return $this->persister->persist($session, $employee, $question, $guarded);
        }

        // --- Step 2d: reference-fact pre-check (Sprint 7c Phase 1, ADR-0023) ----
        // Deterministic, NO LLM. Runs in the salary-pre-classifier layer, on a
        // NON-salary question only (Q4: salary keeps its exact path). It adds the
        // reference-fact route ONLY when a VERIFIED, in-scope, in-validity,
        // topic-matching fact exists; otherwise it FALLS THROUGH to the existing
        // router → prose/salary path (byte-for-byte unchanged — the golden-trace
        // gate). Mirrors the salary routed path: exact value, chunk_id=null
        // citation, authority_used=structured_reference, SKIP /ground.
        if (! $this->router->matchesSalary($question)) {
            $refDetection = $this->referenceFactRouter->detectTopic($employee, $question, $asOfDate);
            if ($refDetection !== null) {
                $outcome = $this->referenceFactPath->handle($employee, $question, $refDetection, $asOfDate, $trace);

                return $this->persister->persist($session, $employee, $question, $outcome);
            }
        }

        // --- Step 3: router (ADR-0016) ------------------------------------------
        // Decrypt the answer-model key ONCE for this turn (if configured); reused
        // for the router (LLM), synthesis, and grounding; dropped at the end. The
        // deterministic salary pre-classifier inside the router needs no key.
        $settings = AnswerModelSetting::current();
        $decryptedKey = $settings->isConfigured() ? $settings->decryptKey() : null;

        $routerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.router_model'),
            'endpoint' => config('services.hr_ai.router_endpoint'),
        ];
        $decision = $this->router->classify($question, $decryptedKey, $routerConfig);
        $trace['router_decision'] = [
            'label' => $decision['label'],
            'confidence' => $decision['confidence'],
            'source' => $decision['source'],
            'subqueries' => $decision['subqueries'],
            'model' => $decision['model'],
            'note' => $decision['note'],
            'cross_path' => $decision['cross_path'] ?? false,
            'trace_fragment' => $decision['trace_fragment'] ?? [],
            // Sprint 10b (ADR-0033): situational/colloquial retrieval rephrasings
            // — [] for every turn today except a prose turn hr-ai chose to
            // rephrase. Admin TracePanel renders this alongside subqueries.
            'decomposed_queries' => $decision['decomposed_queries'] ?? [],
        ];

        // --- Step 4c: off-domain → escalate -------------------------------------
        if ($decision['label'] === RouterService::OFF_DOMAIN) {
            unset($decryptedKey);
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $this->policy->retrievalFloor(),
                'answer_confidence_floor' => $this->policy->confidenceFloor(),
                'outcome' => 'escalate',
                'escalation_reason' => 'off_domain',
                'note' => 'router classified off_domain',
            ];

            // The off-domain refusal copy is admin-configurable (Sprint 6, narrow-
            // only knob) — display text only, it changes no decision. Falls back to
            // the default escalation voice when unset.
            $offDomainMessage = $this->policy->offDomainMessage() ?? self::ESCALATION_MESSAGE;

            $outcome = new TurnOutcome('escalate', $offDomainMessage, [], $trace, 'off_domain');

            return $this->persister->persist($session, $employee, $question, $outcome);
        }

        // --- Step 4a: salary → SQL path (exact, year-aligned; ADR-0006) ---------
        if ($decision['label'] === RouterService::SALARY) {
            unset($decryptedKey); // the salary path is pure SQL — no provider call

            $outcome = $this->salaryPath->handle($employee, $question, $decision, $asOfDate, $selectedJobCategoryId, $trace);

            return $this->persister->persist($session, $employee, $question, $outcome);
        }

        // --- Step 4b: prose path -------------------------------------------------
        $outcome = $this->prosePath->handle($employee, $question, $decision['subqueries'], $asOfDate, $decryptedKey, $trace, $decision['decomposed_queries'] ?? []);

        return $this->persister->persist($session, $employee, $question, $outcome);
    }
}
