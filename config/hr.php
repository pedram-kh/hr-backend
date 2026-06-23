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

];
