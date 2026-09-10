<?php

namespace Tests\Unit;

use App\Support\EscalationExplainer;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 7g Item 1 (ADR-0029) — THE guard test. Fails the build if any entry
 * in `EscalationExplainer::MATRIX` (every reason/sub-outcome the live
 * pipeline — or a publish-time 409 — can produce) is missing a registry
 * entry. This is the "no enum value left unexplained" guarantee the sprint
 * spec asks for, checked directly against the registry (not simulated
 * through a constructed trace, which could mask a genuine gap behind the
 * generic fallback).
 */
class EscalationExplainerGuardTest extends TestCase
{
    public function test_every_matrix_entry_has_a_registry_entry(): void
    {
        $missing = [];
        foreach (EscalationExplainer::MATRIX as $key) {
            if (! EscalationExplainer::registryHasEntry($key)) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'EscalationExplainer::registry() is missing an entry for: '.implode(', ', $missing));
    }

    public function test_matrix_has_no_duplicate_keys(): void
    {
        $this->assertSame(
            EscalationExplainer::MATRIX,
            array_values(array_unique(EscalationExplainer::MATRIX)),
            'MATRIX must not list the same reason.sub_outcome key twice'
        );
    }

    /** Every escalation_cards.reason the live CHECK constraint accepts must be covered by at least one MATRIX entry. */
    public function test_every_live_escalation_reason_is_covered_by_at_least_one_matrix_entry(): void
    {
        $liveReasons = [
            'low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request',
            'conflict', 'salary_coverage_gap', 'reference_fact_coverage_gap',
            // salary_not_in_chat is RETIRED (Correction-02 superseded) — no
            // path emits it any more; deliberately not required here.
        ];

        $coveredReasons = array_unique(array_map(
            fn (string $key) => explode('.', $key, 2)[0],
            EscalationExplainer::MATRIX
        ));

        foreach ($liveReasons as $reason) {
            $this->assertContains($reason, $coveredReasons, "no MATRIX entry covers reason '{$reason}'");
        }
    }
}
