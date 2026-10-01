<?php

namespace Tests\Unit\Decline;

use App\Services\Answer\TurnOutcome;
use App\Services\Decline\DeclineGate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Slice 13e (plan.md §4.2, L1 + L3) — "declining is impossible for any reason other than off_domain" is a property of how
 * the code is built, and this is the test that fails the build if someone builds around it.
 */
final class DeclineStructuralTest extends TestCase
{
    /** @return array<string,string> relative path => source */
    private static function appSources(): array
    {
        $root = dirname(__DIR__, 3).'/app';
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    public function test_the_outcome_list_is_pinned(): void
    {
        // Adding an outcome must be a conscious edit of this test AND of every consumer (Historial, Analítica, the trace panel).
        $this->assertSame(['answer', 'escalate', 'needs_category', 'ask', 'decline'], TurnOutcome::OUTCOMES);
    }

    public function test_a_decline_cannot_be_constructed_without_a_grant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TurnOutcome('decline', 'x', [], [], null);
    }

    public function test_an_unknown_outcome_cannot_be_constructed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TurnOutcome('declined', 'x', [], [], null);
    }

    public function test_no_code_builds_a_decline_outcome_through_the_constructor(): void
    {
        foreach (self::appSources() as $path => $src) {
            $this->assertDoesNotMatchRegularExpression("/new\s+TurnOutcome\(\s*['\"]decline['\"]/", $src, "{$path} builds a decline by hand");
        }
    }

    public function test_every_turn_outcome_construction_names_its_outcome_as_a_literal(): void
    {
        // A variable first argument could smuggle 'decline' past the check above.
        foreach (self::appSources() as $path => $src) {
            preg_match_all('/new\s+TurnOutcome\(\s*([^,\)]+)/', $src, $m);
            foreach ($m[1] as $first) {
                $this->assertMatchesRegularExpression("/^'(answer|escalate|needs_category|ask)'$/", trim($first), "{$path}: new TurnOutcome({$first}…) is not a plain literal");
            }
        }
    }

    public function test_only_the_two_known_producers_call_the_decline_factory_and_the_grant(): void
    {
        $allowed = ['Services/Agent/Rules/OffDomainDeclineRule.php', 'Services/Answer/PreModelGuards.php'];
        foreach (self::appSources() as $path => $src) {
            if (in_array($path, ['Services/Answer/TurnOutcome.php', 'Services/Decline/DeclineGrant.php'], true)) {
                continue;
            }
            $builds = str_contains($src, 'TurnOutcome::decline(') || str_contains($src, 'DeclineGrant::fromDecision(');
            $this->assertSame(in_array($path, $allowed, true) && $builds, $builds, "{$path} must not produce a decline");
        }
        // And the two producers both do, so the allow-list is not vacuous.
        $src = self::appSources();
        foreach ($allowed as $p) {
            $this->assertStringContainsString('DeclineGrant::fromDecision(', $src[$p], $p);
        }
    }

    public function test_only_the_gate_creates_a_decision(): void
    {
        foreach (self::appSources() as $path => $src) {
            if ($path === 'Services/Decline/DeclineGate.php' || $path === 'Services/Decline/DeclineDecision.php') {
                continue;
            }
            $this->assertStringNotContainsString('DeclineDecision::fromGate(', $src, "{$path} fabricates a decision");
        }
        $this->assertStringContainsString('DeclineDecision::fromGate(', self::appSources()['Services/Decline/DeclineGate.php']);
    }

    public function test_the_gate_is_the_only_place_that_names_the_declinable_reason(): void
    {
        $this->assertSame('off_domain', DeclineGate::ONLY_REASON);
        // The persister's re-check and the grant read the constant, never their own literal comparison with a second reason.
        foreach (self::appSources() as $path => $src) {
            $this->assertDoesNotMatchRegularExpression("/decline_reason'\s*=>\s*'(?!off_domain')/", $src, "{$path} sets a decline_reason other than off_domain");
        }
    }
}
