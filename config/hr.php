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
    | CALIBRATED on staging, 2026-09-06, via `php artisan fence:calibrate-semantic
    | --json` against the real corpus (0 published `internal_hr_ruling` docs yet
    | — nothing to measure there) + the labeled synthetic anchors
    | (hr-docs/sprints/sprint-07d/eval/anchors.json, 2 of 5 paraphrase anchors
    | scored on this corpus — a1 Navarra Intervención Social 0.8002, a2 Álava
    | Ocio Educativo/COEAS 0.8116; a3 Vizcaya + a4 Navarra Gestión Deportiva
    | skipped, historical-only convenio with no active successor; a5 Estatal
    | COEAS skipped, successor doc is still under_review, not active).
    |
    | Justifying anchor scores (full report: hr-docs/sprints/sprint-07d/review.md
    | §2):
    |   class (a) near-verbatim/paraphrase — min 0.8002, median 0.8116, max 0.8116 (n=2)
    |   class (b) same-topic-other-point    — min 0.6892, median 0.8751, max 0.8751 (n=2)
    |   class (c) unrelated                 — min 0.5885, median 0.6198, max 0.6198 (n=2)
    |
    | threshold = floor((0.8002 - 0.02) * 100) / 100 = 0.78  (below class-(a) min:
    |   no known-true overlap can ever escape a block)
    | review_band = floor((0.6892 - 0.02) * 100) / 100 = 0.66  (below class-(b)
    |   min: the ENTIRE observed same-topic-other-point range — 0.6892 to
    |   0.8751 — sits at or above the ask-floor; a class-(b) score above 0.78
    |   blocks outright rather than merely asking, which is deliberate: erring
    |   toward blocking more, per the calibration mandate)
    | Sanity check: class-(c) ceiling 0.6198 < review_band 0.66 — genuinely
    |   unrelated text never even triggers "ask". If a future, larger anchor set
    |   ever produces an unrelated score >= 0.66, re-run calibration; the corpus
    |   would be noisier than these 6 anchors assume.
    |
    | n is small (2 anchors per class on this corpus) because only 2 of the 5
    | convenios anchors reference have BOTH an active official_convenio doc to
    | compare against AND a matching paraphrase/other-point/unrelated triple —
    | the other 3 are genuine coverage gaps (historical-only or under_review),
    | not a calibration bug. Re-run and re-tighten once more convenios have
    | active official_convenio text (widen the anchor fixture first, ADR-0024).
    */

    // Band 1 — certain overlap: publish is BLOCKED (409 `semantic_overlap`).
    'semantic_conflict_threshold' => (float) env('HR_SEMANTIC_CONFLICT_THRESHOLD', 0.78),

    // Band 2 — plausible overlap: publish is not blocked, but the human is shown
    // the near-passages and must EXPLICITLY acknowledge before it proceeds.
    // Never a silent "no conflict".
    'semantic_review_band' => (float) env('HR_SEMANTIC_REVIEW_BAND', 0.66),

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
    |
    | MEASURED against the staging corpus, 2026-09-06 (`succession:gold-eval`,
    | 5 labeled pairs; `--discover` over 19 same-convenio pairs first). Both
    | values are LEFT AS THEY WERE, and the measurement is why:
    |
    | - The three true successors scored 0.8903 / 0.9982 / 1.0. overlap_threshold
    |   0.75 sits 0.14 below the weakest of them, so no true successor is lost.
    | - The strongest NON-successor scored 0.9875 (two duplicate ingests of the
    |   same Bizkaia text, identical validity windows) — ABOVE the weakest true
    |   successor. So no threshold value separates the two classes by score on
    |   this corpus, and raising the threshold would buy no safety, only misses.
    |   What actually prevented every wrong successor was the second conjunct,
    |   strictly-later validity_start — which is precisely the reason ADR-0024
    |   made it a conjunction instead of a score cut. Confidently-wrong
    |   successors: 0 of 5.
    | - sibling_ceiling 0.55 was never exercised: the lowest score any real
    |   same-convenio pair produced was 0.8189, so the sibling branch has only
    |   unit-test coverage. Do not tune it from this corpus — there is no
    |   evidence in it either way.
    */
    'succession_overlap_threshold' => (float) env('HR_SUCCESSION_OVERLAP_THRESHOLD', 0.75),
    'succession_sibling_ceiling' => (float) env('HR_SUCCESSION_SIBLING_CEILING', 0.55),
    'succession_probe_max' => (int) env('HR_SUCCESSION_PROBE_MAX', 12),
    'succession_candidate_max' => (int) env('HR_SUCCESSION_CANDIDATE_MAX', 20),

];
