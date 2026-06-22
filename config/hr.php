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

];
