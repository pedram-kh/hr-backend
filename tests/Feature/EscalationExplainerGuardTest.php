<?php

namespace Tests\Feature;

use App\Support\EscalationExplainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 7g Item 1 (ADR-0029) — THE guard test. Fails the build if any entry
 * in `EscalationExplainer::MATRIX` (every reason/sub-outcome the live
 * pipeline — or a publish-time 409 — can produce) is missing a registry
 * entry. This is the "no enum value left unexplained" guarantee the sprint
 * spec asks for, checked directly against the registry (not simulated
 * through a constructed trace, which could mask a genuine gap behind the
 * generic fallback).
 *
 * Sprint 13 (plan.md §D.12, item (c) of CP-0's own review note): moved from
 * `tests/Unit/` to `tests/Feature/` and switched `test_every_live_escalation_
 * reason_is_covered_by_at_least_one_matrix_entry`'s hand-copied `$liveReasons`
 * list to the SAME live-CHECK-constraint introspection idiom
 * `EscalationReasonLabelCoverageTest` already uses — a hand-copied list here
 * had already silently drifted (it omitted `estatuto_fallback_gap`, added in
 * an earlier sprint, without failing) and would have drifted again on every
 * one of this sprint's five new reasons had it not been replaced.
 */
class EscalationExplainerGuardTest extends TestCase
{
    use RefreshDatabase;

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

    /** Every escalation_cards.reason the LIVE CHECK constraint accepts must be covered by at least one MATRIX entry. */
    public function test_every_live_escalation_reason_is_covered_by_at_least_one_matrix_entry(): void
    {
        $liveReasons = $this->liveReasonEnumValues();

        $coveredReasons = array_unique(array_map(
            fn (string $key) => explode('.', $key, 2)[0],
            EscalationExplainer::MATRIX
        ));

        foreach ($liveReasons as $reason) {
            // salary_not_in_chat is RETIRED (Correction-02 superseded) — no
            // path emits it any more, but the CHECK constraint's own
            // migration history never dropped the enum value. Deliberately
            // not required here, same as before the introspection switch.
            if ($reason === 'salary_not_in_chat') {
                continue;
            }

            $this->assertContains($reason, $coveredReasons, "no MATRIX entry covers reason '{$reason}'");
        }
    }

    /** @return list<string> */
    private function liveReasonEnumValues(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK-constraint enum introspection is Postgres-specific.');
        }

        $constraint = DB::selectOne(
            'SELECT pg_get_constraintdef(con.oid) AS def FROM pg_constraint con '
            .'JOIN pg_class rel ON rel.oid = con.conrelid '
            ."WHERE rel.relname = 'escalation_cards' AND con.contype = 'c' "
            ."AND pg_get_constraintdef(con.oid) LIKE '%reason%' LIMIT 1"
        );

        $this->assertNotNull($constraint, 'escalation_cards has no reason CHECK constraint to introspect.');

        // constraintdef looks like: CHECK ((reason)::text = ANY (ARRAY['a'::text, 'b'::text, ...]))
        preg_match_all("/'([^']+)'::text/", $constraint->def, $matches);

        $this->assertNotEmpty($matches[1], 'Could not parse any reason values out of the CHECK constraint definition: '.$constraint->def);

        return $matches[1];
    }
}
