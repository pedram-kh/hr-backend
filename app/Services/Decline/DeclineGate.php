<?php

namespace App\Services\Decline;

/**
 * Slice 13e — THE ONLY place a decline is decided (plan.md §4). Pure: a function of {@see DeclineFacts}.
 *
 * A decline is granted iff EVERY check passes. All checks are evaluated and recorded (no short-circuit) so the trace shows
 * exactly which one denied. The first four D-rules are the spec's, the rest are this slice's additions:
 *
 *   D0 the kill switch is on                      D5 no corpus material was retrieved
 *   D1 the reason is exactly `off_domain`         D6 the previous assistant turn was not an ask / needs_category
 *   D2 no baseline guard, no admin blocked_topic  D7 no workplace vocabulary (planner source only)
 *   D3 not an explicit "ask HR" request           D8 the router independently says off_domain >= floor (planner source only)
 *   D4 no accepted normalization topic/canonical  D9 the guard rule is `admin_off_domain` (guard source only)
 *
 * Nothing about the model's wording, a score, or a count of turns can grant a decline on its own. A new reason is added
 * by changing ONLY_REASON — which the truth-table test pins, so it cannot happen quietly.
 */
final class DeclineGate
{
    public const VERSION = 'dg-1';

    /** The one and only reason a decline can carry. */
    public const ONLY_REASON = 'off_domain';

    public static function decide(DeclineFacts $f): DeclineDecision
    {
        $planner = $f->source === DeclineFacts::SOURCE_PLANNER;
        $guard = $f->source === DeclineFacts::SOURCE_GUARD_ADMIN;
        $confirm = $f->confirm;

        $checks = [];
        $add = function (string $id, bool $pass, ?string $detail = null) use (&$checks): void {
            $checks[] = ['id' => $id, 'pass' => $pass, 'detail' => $detail];
        };

        $add('D0', $f->enabled, $f->enabled ? null : 'kill_switch');
        $add('D1', $f->reason === self::ONLY_REASON && ($planner || $guard), $f->reason === self::ONLY_REASON ? null : 'reason:'.$f->reason);
        $add('D2', ! $f->baselineGuardHit && ! $f->adminSensitiveHit, $f->baselineGuardHit ? 'baseline_guard' : ($f->adminSensitiveHit ? 'admin_blocked_topic' : null));
        $add('D3', ! $f->explicitRequest, $f->explicitRequest ? 'explicit_request' : null);
        $add('D4', ! $f->acceptedNormalization, $f->acceptedNormalization ? 'accepted_normalization' : null);
        $add('D5', ! $f->corpusMaterial, $f->corpusMaterial ? 'corpus_material' : null);
        $add('D6', ! $f->pendingAsk, $f->pendingAsk ? 'previous_turn_ask' : null);
        $add('D7', ! $planner || $f->vetoWord === null, $planner ? $f->vetoWord : 'n/a');
        $confirmOk = $confirm !== null && ($confirm['label'] ?? null) === 'off_domain' && (float) ($confirm['confidence'] ?? 0) >= (float) ($confirm['floor'] ?? 1.1);
        $add('D8', ! $planner || $confirmOk, $planner ? ($confirm === null ? 'not_consulted' : ($confirm['label'] ?? '?').'@'.($confirm['confidence'] ?? '?')) : 'n/a');
        $add('D9', ! $guard || $f->guardRule === 'admin_off_domain', $guard ? $f->guardRule : 'n/a');

        $failed = array_values(array_filter($checks, fn ($c) => ! $c['pass']));
        $granted = $failed === [];

        return DeclineDecision::fromGate(
            $granted, $f->source, $f->reason, $checks, $granted ? null : $failed[0]['id'], $confirm, $f->context,
        );
    }
}
