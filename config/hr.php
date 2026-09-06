<?php

/*
| HR answer-pipeline configuration (Sprint 2b-1).
|
| These are the answer-or-escalate FLOOR knobs. They are NAMED config values —
| never inline literals in the pipeline code. Both are CONSERVATIVE defaults.
|
| SPRINT-6-EXPOSABLE, ADDITIVE-ONLY: the Sprint-6 guardrails UI will let an admin
| RAISE these (more caution) but NEVER lower them below the hardcoded defaults
| here, and never weaken the hardcoded guardrail baseline (GuardrailService).
| Treat the values below as the floor of the floor.
*/

return [

    // Check A (load-bearing): minimum cosine similarity for the top eligible
    // chunk for retrieval to count as meaningful. If the best chunk scores below
    // this, the question escalates BEFORE synthesis (no/weak retrieval → no guess).
    'retrieval_score_floor' => (float) env('HR_RETRIEVAL_SCORE_FLOOR', 0.40),

    // The answer-confidence floor. NOTE (Sprint 2b-1 design): the model's
    // self-reported confidence is NOT a primary gate — LLM self-confidence is
    // poorly calibrated, and an externally-hosted model that is confidently wrong
    // is the exact ADR-0015 risk. The load-bearing gates are Check A (retrieval
    // score) and Check B (citations present + every cited chunk in the provided
    // set). This value is kept in the trace and used ONLY as a tiebreaker; it can
    // never, on its own, pass an answer that A/B did not already support.
    'answer_confidence_floor' => (float) env('HR_ANSWER_CONFIDENCE_FLOOR', 0.65),

    // Session-continuation window: a new turn appends to the employee's most
    // recent session if it was active within this many hours, else a new session
    // is started (no session list/picker until Sprint 5).
    'session_window_hours' => (int) env('HR_SESSION_WINDOW_HOURS', 24),

    // Router confidence floor (Sprint 2b-2, ADR-0016). The router classifies a
    // question salary | prose | off_domain. When the LLM router's confidence is
    // BELOW this, the router is treated as uncertain and FAILS SAFE to the prose
    // path (which can still escalate via the answer-or-escalate floor) — never a
    // silent misroute. Conservative; an additive Sprint-6 knob like the floors
    // above. A confident off_domain still escalates; a confident salary still
    // routes to SQL. Only the uncertain middle defaults to the safe prose path.
    'router_confidence_floor' => (float) env('HR_ROUTER_CONFIDENCE_FLOOR', 0.50),

    // --- Widened-pool precedence re-rank (Sprint 2b-2, Correction-03) ----------
    // The prose recall-hardening union retrieves a WIDER candidate pool per pass
    // (this many chunks) BEFORE the precedence re-rank + truncation to
    // SYNTHESIS_CHUNK_CAP. The bug it fixes (review.md §15): a governing convenio
    // chunk that ranks ~#15 by raw cosine (e.g. Navarra 7721 "37 días laborables",
    // buried at a chunk tail) was discarded at k=8 before the re-rank could ever
    // promote it, so the Estatuto baseline reached synthesis instead. A wider pool
    // gives the re-rank a chance to see and promote it. Interim compensation for
    // the buried-grant chunking artifact; the durable fix is the article-boundary
    // re-chunk parked in roadmap §7.
    'retrieval_pool_k' => (int) env('HR_RETRIEVAL_POOL_K', 25),

    // The national-law-only recall pass depth (kept modest — it only needs to
    // surface the on-topic Estatuto article for a silent-convenio topic, and to
    // give the precedence re-rank the baseline chunks to pair convenio against).
    'retrieval_national_law_k' => (int) env('HR_RETRIEVAL_NATIONAL_LAW_K', 8),

    /*
    |--------------------------------------------------------------------------
    | Sprint 7d — the semantic publish fence (ADR-0024)
    |--------------------------------------------------------------------------
    |
    | ⚠ THE DIRECTION OF "STRICTER" IS INVERTED HERE. Every knob above is a
    | FLOOR: raising it means more caution, which is why GuardrailPolicy combines
    | them with max(floor, admin) (ADR-0019). These two are BLOCK-TRIGGERING
    | THRESHOLDS: a LOWER value blocks MORE. Feeding them through
    | GuardrailPolicy::maxFloor would therefore let an admin LOOSEN the publish
    | fence under a mechanism whose whole promise is that it can only tighten.
    |
    | Consequence, recorded in ADR-0024: these are NOT exposed in the Sprint-6
    | guardrails UI. Any future admin exposure must combine with
    | min(baseline, admin), never max. Until then they are code config only.
    |
    | ⚠ PROVISIONAL VALUES — pending the mandatory calibration run. Set them from
    | the output of `php artisan fence:calibrate-semantic` (real published-ruling
    | distribution + the labeled synthetic anchors in
    | hr-docs/sprints/sprint-07d/eval/anchors.json), choosing a block threshold
    | BELOW the lowest score any known-true-overlap anchor produced. The defaults
    | below are deliberately conservative (they block more than a measured value
    | probably needs to) because over-blocking routes to a human and
    | under-blocking is the harm the fence exists to prevent.
    */

    // Band 1 — certain overlap: publish is BLOCKED (409 `semantic_overlap`).
    'semantic_conflict_threshold' => (float) env('HR_SEMANTIC_CONFLICT_THRESHOLD', 0.75),

    // Band 2 — plausible overlap: publish is not blocked, but the human is shown
    // the near-passages and must EXPLICITLY acknowledge before it proceeds.
    // Never a silent "no conflict".
    'semantic_review_band' => (float) env('HR_SEMANTIC_REVIEW_BAND', 0.60),

    // How many passages are RETURNED per probe for the human to read. NOT a gate:
    // the block decision reads the top score of an exactly-filtered, exactly-
    // ordered set (authority filtered in SQL), so it is k-independent.
    'semantic_compare_k' => (int) env('HR_SEMANTIC_COMPARE_K', 5),

    // Probe splitting (the embedder silently truncates a long text, which would
    // leave a ruling's tail uncompared — a fail-open). The ruling is split into
    // paragraph-sized probes and the fence takes max over ALL probes.
    'semantic_probe_max' => (int) env('HR_SEMANTIC_PROBE_MAX', 12),
    'semantic_probe_min_chars' => (int) env('HR_SEMANTIC_PROBE_MIN_CHARS', 120),
    'semantic_probe_max_chars' => (int) env('HR_SEMANTIC_PROBE_MAX_CHARS', 600),

    /*
    |--------------------------------------------------------------------------
    | Sprint 7d — the succession proposal (ADR-0024, part C)
    |--------------------------------------------------------------------------
    |
    | A `successor` proposal needs BOTH high overlap AND a strictly-later
    | validity_start — a conjunction, because a confidently-wrong successor is
    | the one output that would tempt a human to retire a live document. These
    | drive a PROPOSAL a human confirms, not a gate, so they are ordinary knobs.
    */
    'succession_overlap_threshold' => (float) env('HR_SUCCESSION_OVERLAP_THRESHOLD', 0.75),
    'succession_sibling_ceiling' => (float) env('HR_SUCCESSION_SIBLING_CEILING', 0.55),
    'succession_probe_max' => (int) env('HR_SUCCESSION_PROBE_MAX', 12),
    'succession_candidate_max' => (int) env('HR_SUCCESSION_CANDIDATE_MAX', 20),

];
