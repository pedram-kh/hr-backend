<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EscalationController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Correction-02 (CP-4 step 6, C2-1). A guard against exactly the bug found on
 * eyes-on: `estatuto_fallback_gap` shared its display label with a generic
 * gap reason (fixed by renaming it — see `EscalationController::REASON_LABELS`),
 * and the audit that followed found `quality_sample_wrong` had NO label at
 * all (silently falling through to the raw reason string on the badge).
 *
 * This test reads the LIVE `escalation_cards_reason` CHECK constraint from
 * Postgres — the same introspection idiom the reason-enum migrations use
 * (never a hand-copied list here that could itself drift) — so a future
 * migration that adds a new reason value is caught automatically, with no
 * test file to remember to update:
 *
 *   1. Every enum value must have an EXPLICIT entry in `REASON_LABELS` (no
 *      silent `?? $card->reason` fallback to the raw string).
 *   2. No two enum values may resolve to the IDENTICAL label string (the
 *      literal shape of the C2-1 bug).
 */
class EscalationReasonLabelCoverageTest extends TestCase
{
    use RefreshDatabase;

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

    /** @return array<string,string> */
    private function reasonLabels(): array
    {
        $ref = new ReflectionClass(EscalationController::class);

        return $ref->getConstant('REASON_LABELS');
    }

    public function test_every_live_reason_value_has_an_explicit_label(): void
    {
        $enumValues = $this->liveReasonEnumValues();
        $labels = $this->reasonLabels();

        $missing = array_values(array_diff($enumValues, array_keys($labels)));

        $this->assertSame(
            [],
            $missing,
            'These escalation_cards.reason enum values have no entry in '
            .'EscalationController::REASON_LABELS, so they would silently '
            .'display as their raw reason string on the board badge: '
            .implode(', ', $missing)
        );
    }

    public function test_no_two_reasons_share_a_display_label(): void
    {
        $enumValues = $this->liveReasonEnumValues();
        $labels = $this->reasonLabels();

        // Only check labels for reasons actually in the live enum — REASON_LABELS
        // also carries a couple of legacy/defensive keys (`sensitive`,
        // `legal_medical`, `other_employee`) that are not, or are no longer,
        // CHECK-constraint values; those are out of scope for this guard.
        $liveLabels = [];
        foreach ($enumValues as $reason) {
            if (isset($labels[$reason])) {
                $liveLabels[$reason] = $labels[$reason];
            }
        }

        $seen = [];
        $collisions = [];
        foreach ($liveLabels as $reason => $label) {
            if (isset($seen[$label])) {
                $collisions[] = sprintf("'%s' and '%s' both display as '%s'", $seen[$label], $reason, $label);
            } else {
                $seen[$label] = $reason;
            }
        }

        $this->assertSame(
            [],
            $collisions,
            "Two different escalation reasons resolve to the identical display label, so a reviewer can't tell them apart on the board badge or filter: "
            .implode('; ', $collisions)
        );
    }
}
