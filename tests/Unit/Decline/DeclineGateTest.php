<?php

namespace Tests\Unit\Decline;

use App\Services\Decline\DeclineFacts;
use App\Services\Decline\DeclineGate;
use App\Services\Decline\DeclineGrant;
use App\Services\Decline\DeclineVeto;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Slice 13e (plan.md §4, §8.4) — the decline predicate is a pure truth table. "Declining must be impossible for any reason
 * other than off_domain" is proven here for EVERY reason the system can carry, not for the ones somebody remembered.
 */
final class DeclineGateTest extends TestCase
{
    private const CONFIRM_OK = ['label' => 'off_domain', 'confidence' => 0.95, 'floor' => 0.90, 'source' => 'llm'];

    /** @param  array<string,mixed>  $o */
    private static function planner(array $o = []): DeclineFacts
    {
        return new DeclineFacts(
            enabled: $o['enabled'] ?? true,
            source: $o['source'] ?? DeclineFacts::SOURCE_PLANNER,
            reason: $o['reason'] ?? 'off_domain',
            baselineGuardHit: $o['baseline'] ?? false,
            adminSensitiveHit: $o['admin'] ?? false,
            explicitRequest: $o['explicit'] ?? false,
            acceptedNormalization: $o['norm'] ?? false,
            corpusMaterial: $o['material'] ?? false,
            pendingAsk: $o['ask'] ?? false,
            vetoWord: $o['veto'] ?? null,
            guardRule: $o['rule'] ?? null,
            confirm: array_key_exists('confirm', $o) ? $o['confirm'] : self::CONFIRM_OK,
        );
    }

    /** @param  array<string,mixed>  $o */
    private static function guard(array $o = []): DeclineFacts
    {
        return self::planner(array_merge(['source' => DeclineFacts::SOURCE_GUARD_ADMIN, 'rule' => 'admin_off_domain', 'confirm' => null], $o));
    }

    public function test_a_clean_planner_off_domain_with_router_agreement_is_granted(): void
    {
        $d = DeclineGate::decide(self::planner());
        $this->assertTrue($d->granted);
        $this->assertNull($d->deniedBy);
        $this->assertSame(['D0', 'D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9'], array_column($d->checks, 'id'));
        $this->assertSame([], $d->failedIds());
        $this->assertSame('dg-1', $d->toTrace()['gate_version']);
    }

    public function test_a_clean_admin_guard_off_domain_is_granted_without_router_or_veto(): void
    {
        $this->assertTrue(DeclineGate::decide(self::guard())->granted);
        // D7/D8 do not apply to the admin's own pattern, even when the words look like work.
        $this->assertTrue(DeclineGate::decide(self::guard(['veto' => 'trabajo']))->granted);
    }

    /** Every reason the system can carry: the 8 `escalation_cards.reason` values and every planner category. Only `off_domain` passes D1. */
    public static function allReasons(): array
    {
        $reasons = [
            // escalation_cards.reason CHECK enum (Sprint 13 + 13b + 13c migrations)
            'low_confidence', 'no_source', 'needs_category', 'sensitive_topic', 'off_domain', 'unanswerable',
            'employee_requested_review', 'employee_requested', 'planner_escalated', 'legal_medical', 'other_employee_data',
            'explicit_request', 'unsafe',
            // planner `escalate.category` enum (ControlTools)
            'needs_human_judgement', 'other',
            // things that are not reasons at all
            '', 'OFF_DOMAIN', 'off-domain', ' off_domain', 'answer', 'ask', 'decline',
        ];
        $out = [];
        foreach (array_unique($reasons) as $r) {
            $out[$r === '' ? '(empty)' : $r] = [$r];
        }

        return $out;
    }

    #[DataProvider('allReasons')]
    public function test_only_the_exact_off_domain_reason_can_ever_be_granted(string $reason): void
    {
        foreach ([self::planner(['reason' => $reason]), self::guard(['reason' => $reason])] as $facts) {
            $d = DeclineGate::decide($facts);
            $this->assertSame($reason === 'off_domain', $d->granted, "reason '{$reason}' via {$facts->source}");
            if ($reason !== 'off_domain') {
                $this->assertSame('D1', $d->deniedBy);
            }
        }
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function denials(): array
    {
        return [
            'D0 kill switch' => [['enabled' => false], 'D0'],
            'D2 baseline guard' => [['baseline' => true], 'D2'],
            'D2 admin blocked_topic' => [['admin' => true], 'D2'],
            'D3 explicit request' => [['explicit' => true], 'D3'],
            'D4 accepted normalization' => [['norm' => true], 'D4'],
            'D5 corpus material' => [['material' => true], 'D5'],
            'D6 pending ask' => [['ask' => true], 'D6'],
            'D7 veto (planner)' => [['veto' => 'trabajo'], 'D7'],
            'D8 router said prose' => [['confirm' => ['label' => 'prose', 'confidence' => 0.95, 'floor' => 0.90, 'source' => 'llm']], 'D8'],
            'D8 router said salary' => [['confirm' => ['label' => 'salary', 'confidence' => 1.0, 'floor' => 0.90, 'source' => 'deterministic_salary']], 'D8'],
            'D8 router 0.89' => [['confirm' => ['label' => 'off_domain', 'confidence' => 0.89, 'floor' => 0.90, 'source' => 'llm']], 'D8'],
            'D8 router error' => [['confirm' => ['label' => 'error', 'confidence' => 0.0, 'floor' => 0.90, 'source' => 'exception']], 'D8'],
            'D8 not consulted' => [['confirm' => null], 'D8'],
        ];
    }

    /** @param  array<string,mixed>  $o */
    #[DataProvider('denials')]
    public function test_each_rule_denies_on_its_own(array $o, string $id): void
    {
        $d = DeclineGate::decide(self::planner($o));
        $this->assertFalse($d->granted);
        $this->assertSame($id, $d->deniedBy);
        $this->assertContains($id, $d->failedIds());
    }

    public function test_router_exactly_at_the_floor_confirms(): void
    {
        $at = ['label' => 'off_domain', 'confidence' => 0.90, 'floor' => 0.90, 'source' => 'llm'];
        $this->assertTrue(DeclineGate::decide(self::planner(['confirm' => $at]))->granted);
    }

    public function test_guard_source_needs_the_admin_off_domain_rule(): void
    {
        foreach (['legal_medical', 'other_employee_data', 'sensitive_topic', 'admin_blocked_topic', '', null] as $rule) {
            $d = DeclineGate::decide(self::guard(['rule' => $rule]));
            $this->assertFalse($d->granted, (string) $rule);
            $this->assertSame('D9', $d->deniedBy);
        }
    }

    public function test_an_unknown_source_is_never_granted(): void
    {
        $this->assertFalse(DeclineGate::decide(self::planner(['source' => 'router']))->granted);
        $this->assertFalse(DeclineGate::decide(self::planner(['source' => '']))->granted);
    }

    public function test_every_combination_of_the_boolean_rules_is_granted_only_when_all_pass(): void
    {
        $keys = ['baseline', 'admin', 'explicit', 'norm', 'material', 'ask'];
        for ($mask = 0; $mask < (1 << count($keys)); $mask++) {
            $o = [];
            foreach ($keys as $i => $k) {
                $o[$k] = (bool) ($mask & (1 << $i));
            }
            foreach ([self::planner($o), self::guard($o)] as $facts) {
                $this->assertSame($mask === 0, DeclineGate::decide($facts)->granted, "mask {$mask} via {$facts->source}");
            }
        }
    }

    public function test_the_grant_exists_only_for_a_granted_off_domain_decision(): void
    {
        $granted = DeclineGate::decide(self::planner());
        $this->assertSame('planner', DeclineGrant::fromDecision($granted)->source);

        foreach ([self::planner(['reason' => 'unanswerable']), self::planner(['baseline' => true]), self::guard(['rule' => 'legal_medical'])] as $facts) {
            try {
                DeclineGrant::fromDecision(DeclineGate::decide($facts));
                $this->fail('a denied decision produced a grant');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_only_reason_constant_is_off_domain(): void
    {
        // Widening this is a product decision; this assertion makes it loud.
        $this->assertSame('off_domain', DeclineGate::ONLY_REASON);
    }

    /** @return array<string,array{0:string}> one case per veto word */
    public static function vetoWords(): array
    {
        $out = [];
        foreach (DeclineVeto::WORDS as $w) {
            $out[$w] = [$w];
        }

        return $out;
    }

    #[DataProvider('vetoWords')]
    public function test_each_veto_word_fires_whole_word_and_accent_insensitive(string $word): void
    {
        $this->assertSame($word, DeclineVeto::match("¿Qué pasa con el {$word} de mi tío?"), $word);
        $this->assertSame($word, DeclineVeto::match(mb_strtoupper("hola, {$word}")), $word.' upper');
    }

    public function test_veto_words_are_matched_whole_word_only(): void
    {
        $this->assertNull(DeclineVeto::match('el paraguas es mío'));      // not "paro"
        $this->assertNull(DeclineVeto::match('trabajosamente'));          // not "trabajo"
        $this->assertSame('baja', DeclineVeto::match('¿Qué es una BAJA?'));
        $this->assertSame('antiguedad', DeclineVeto::match('mi antigüedad'));
        $this->assertSame('jubilacion', DeclineVeto::match('¿Cuándo es la jubilación?'));
        $this->assertSame('horas extra', DeclineVeto::match('mis horas extra'));
    }

    public function test_the_veto_also_uses_the_topic_lexicon(): void
    {
        $m = DeclineVeto::match('¿Qué pasa con la lactancia?');
        $this->assertNotNull($m);
        $this->assertStringStartsWith('topic:', (string) $m);
    }

    public function test_the_english_staging_trigger_still_declines(): void
    {
        $this->assertNull(DeclineVeto::match('what is the definition of job?'));
    }
}
